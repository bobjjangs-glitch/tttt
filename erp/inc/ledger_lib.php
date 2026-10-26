<?php
// inc/ledger_lib.php - 외상(미수금) 원장 공용 함수. 미실행 코드
// 전제: bootstrap.php(functions.php) 로드 후 사용. 금액은 전부 정수(원).
// erp_partner_openings 실제 구조: partner_id, as_of_date(NOT NULL), ar_amount, ap_amount, updated_at

const LG_METHODS = ['cash' => '현금', 'transfer' => '계좌이체', 'card' => '카드', 'other' => '기타'];

/** "1,234,000" → 1234000. 숫자 12자리 이하만 허용, 아니면 null */
function lg_won(string $s): ?int
{
    $s = str_replace([',', ' '], '', trim($s));
    return preg_match('/^\d{1,12}$/', $s) ? (int)$s : null;
}

function lg_has_table(string $t): bool
{
    $st = db()->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $st->execute([$t]);
    return (bool)$st->fetchColumn();
}

/** 테이블 컬럼명 목록 (요청당 1회 캐시). 내부 상수 테이블명만 넘길 것 */
function lg_cols(string $t): array
{
    static $cache = [];
    if (!isset($cache[$t])) {
        $cache[$t] = [];
        if (lg_has_table($t)) {
            foreach (db()->query('SHOW COLUMNS FROM ' . $t)->fetchAll() as $r) {
                $cache[$t][] = (string)$r['Field'];
            }
        }
    }
    return $cache[$t];
}

/** erp_trades 의 합계금액 컬럼명 자동 탐지. 못 찾으면 null */
function lg_amount_col(): ?string
{
    $cols = lg_cols('erp_trades');
    foreach (['total_amount', 'total', 'grand_total', 'total_price', 'amount_total', 'total_amt', 'sum_total'] as $c) {
        if (in_array($c, $cols, true)) {
            return $c;
        }
    }
    return null;
}

/** 준비가 안 됐으면 사유 문자열, 됐으면 null. 테이블뿐 아니라 필요한 컬럼까지 검사 */
function lg_ready_error(): ?string
{
    $need = [
        'erp_partners'         => ['id', 'name', 'business_no', 'is_credit', 'is_active'],
        'erp_trades'           => ['id', 'trade_no', 'trade_type', 'channel', 'trade_date', 'partner_id', 'status'],
        'erp_payments'         => ['id', 'partner_id', 'direction', 'pay_date', 'amount', 'method', 'memo',
                                   'status', 'cancel_reason', 'created_by', 'created_at', 'canceled_at'],
        'erp_partner_openings' => ['partner_id', 'as_of_date', 'ar_amount', 'updated_at'],
    ];
    foreach ($need as $t => $cols) {
        if (!lg_has_table($t)) {
            return '테이블이 없습니다: ' . $t;
        }
        $miss = array_values(array_diff($cols, lg_cols($t)));
        if ($miss) {
            return $t . ' 테이블에 필요한 컬럼이 없습니다: ' . implode(', ', $miss);
        }
    }
    if (lg_amount_col() === null) {
        return '전표 합계금액 컬럼을 찾지 못했습니다. erp_trades 컬럼: ' . implode(', ', lg_cols('erp_trades'));
    }
    return null;
}

/** 기초잔액 행 [ar_amount(int), as_of_date(string|null)] */
function lg_opening(int $pid): array
{
    $st = db()->prepare('SELECT ar_amount, as_of_date FROM erp_partner_openings WHERE partner_id = ?');
    $st->execute([$pid]);
    $r = $st->fetch();
    return $r ? [(int)$r['ar_amount'], $r['as_of_date'] !== null ? (string)$r['as_of_date'] : null] : [0, null];
}

/**
 * 거래처 1곳의 매출 합계 또는 입금 합계.
 * $kind: 'sale' | 'pay'. $after: 날짜 > after, $before: 날짜 < before (둘 다 null 가능)
 */
function lg_sum(string $kind, int $pid, ?string $after, ?string $before): int
{
    if ($kind === 'sale') {
        $col = lg_amount_col();
        if ($col === null) {
            throw new RuntimeException('합계금액 컬럼을 찾지 못했습니다.');
        }
        $sql = "SELECT COALESCE(SUM(`$col`), 0) FROM erp_trades WHERE partner_id = ? AND trade_type = 'sale' AND status = 'confirmed'";
        $dc  = 'trade_date';
    } else {
        $sql = "SELECT COALESCE(SUM(amount), 0) FROM erp_payments WHERE partner_id = ? AND direction = 'in' AND status = 'active'";
        $dc  = 'pay_date';
    }
    $args = [$pid];
    if ($after !== null) {
        $sql .= " AND $dc > ?";
        $args[] = $after;
    }
    if ($before !== null) {
        $sql .= " AND $dc < ?";
        $args[] = $before;
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
}

/** 현재 미수금 = 기초잔액 + 기준일 이후 확정 매출 - 기준일 이후 유효 입금 */
function lg_balance(int $pid): int
{
    [$open, $asOf] = lg_opening($pid);
    return $open + lg_sum('sale', $pid, $asOf, null) - lg_sum('pay', $pid, $asOf, null);
}

/** 기간 원장. 반환: carry(이월), rows[], sale_sum, pay_sum, close(기말) */
function lg_ledger(int $pid, string $from, string $to): array
{
    $pdo = db();
    $col = lg_amount_col();
    [$open, $asOf] = lg_opening($pid);

    $carry = $open + lg_sum('sale', $pid, $asOf, $from) - lg_sum('pay', $pid, $asOf, $from);

    $rows = [];
    $sql = "SELECT id, trade_no, trade_date, channel, `$col` AS amt FROM erp_trades
            WHERE partner_id = ? AND trade_type = 'sale' AND status = 'confirmed'
              AND trade_date >= ? AND trade_date <= ?";
    $args = [$pid, $from, $to];
    if ($asOf !== null) {
        $sql .= ' AND trade_date > ?';
        $args[] = $asOf;
    }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['date' => (string)$r['trade_date'], 'ord' => 0, 'id' => (int)$r['id'],
                   'kind' => '매출', 'no' => (string)$r['trade_no'], 'channel' => (string)$r['channel'],
                   'memo' => '', 'sale' => (int)$r['amt'], 'pay' => 0];
    }

    $sql = "SELECT id, pay_date, amount, method, memo FROM erp_payments
            WHERE partner_id = ? AND direction = 'in' AND status = 'active'
              AND pay_date >= ? AND pay_date <= ?";
    $args = [$pid, $from, $to];
    if ($asOf !== null) {
        $sql .= ' AND pay_date > ?';
        $args[] = $asOf;
    }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    foreach ($st->fetchAll() as $r) {
        $m = LG_METHODS[(string)$r['method']] ?? (string)$r['method'];
        $rows[] = ['date' => (string)$r['pay_date'], 'ord' => 1, 'id' => (int)$r['id'],
                   'kind' => '입금', 'no' => '', 'channel' => '',
                   'memo' => trim($m . ' ' . (string)$r['memo']), 'sale' => 0, 'pay' => (int)$r['amount']];
    }

    usort($rows, fn($a, $b) => [$a['date'], $a['ord'], $a['id']] <=> [$b['date'], $b['ord'], $b['id']]);

    $bal = $carry;
    $ss = 0;
    $ps = 0;
    foreach ($rows as &$r) {
        $bal += $r['sale'] - $r['pay'];
        $r['bal'] = $bal;
        $ss += $r['sale'];
        $ps += $r['pay'];
    }
    unset($r);

    return ['carry' => $carry, 'rows' => $rows, 'sale_sum' => $ss, 'pay_sum' => $ps, 'close' => $bal];
}

/** 외상 거래처 전체의 현재 미수금 요약 */
function lg_summary(): array
{
    $pdo = db();
    $col = lg_amount_col();

    $out = [];
    foreach ($pdo->query('SELECT id, name, business_no FROM erp_partners WHERE is_credit = 1 ORDER BY name')->fetchAll() as $p) {
        $out[(int)$p['id']] = ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'biz' => (string)($p['business_no'] ?? ''),
                               'open' => 0, 'as_of' => null, 'sale' => 0, 'pay' => 0, 'bal' => 0];
    }
    foreach ($pdo->query('SELECT partner_id, ar_amount, as_of_date FROM erp_partner_openings')->fetchAll() as $r) {
        $id = (int)$r['partner_id'];
        if (isset($out[$id])) {
            $out[$id]['open']  = (int)$r['ar_amount'];
            $out[$id]['as_of'] = $r['as_of_date'];
        }
    }
    $sql = "SELECT t.partner_id AS pid, COALESCE(SUM(t.`$col`), 0) AS s
            FROM erp_trades t LEFT JOIN erp_partner_openings o ON o.partner_id = t.partner_id
            WHERE t.trade_type = 'sale' AND t.status = 'confirmed' AND t.partner_id IS NOT NULL
              AND (o.as_of_date IS NULL OR t.trade_date > o.as_of_date)
            GROUP BY t.partner_id";
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        if (isset($out[(int)$r['pid']])) {
            $out[(int)$r['pid']]['sale'] = (int)$r['s'];
        }
    }
    $sql = "SELECT y.partner_id AS pid, COALESCE(SUM(y.amount), 0) AS s
            FROM erp_payments y LEFT JOIN erp_partner_openings o ON o.partner_id = y.partner_id
            WHERE y.direction = 'in' AND y.status = 'active'
              AND (o.as_of_date IS NULL OR y.pay_date > o.as_of_date)
            GROUP BY y.partner_id";
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        if (isset($out[(int)$r['pid']])) {
            $out[(int)$r['pid']]['pay'] = (int)$r['s'];
        }
    }
    foreach ($out as &$o) {
        $o['bal'] = $o['open'] + $o['sale'] - $o['pay'];
    }
    unset($o);
    uasort($out, fn($a, $b) => $b['bal'] <=> $a['bal']);
    return array_values($out);
}
