<?php
// inc/trade_service.php — 전표 확정 / 취소 / 임시 삭제

// 확정 시 결제수단·계좌를 강제할지 (false 로 두면 예전 동작)
const TS_REQUIRE_PAYMENT = true;
// 외상 거래처의 판매를 현금/이체/카드로 확정하는 것을 허용할지.
// 미수금 원장이 거래처 단위라서, 허용하면 이중 집계됩니다. 원장을 전표 단위로 바꾼 뒤에만 true.
const TS_CREDIT_PARTNER_PAID_NOW = false;
// 결제수단 코드 (trade_form.php 와 같은 값)
const TS_PAY_METHODS = ['cash' => '현금', 'transfer' => '이체', 'card' => '카드', 'credit' => '외상'];

function trade_requires_partner(array $t): bool
{
    return $t['trade_type'] === 'purchase'
        || ($t['trade_type'] === 'sale' && $t['channel'] === 'wholesale');
}

/** 컬럼 존재 여부 (요청당 캐시). $table 은 실제 테이블명(erp_ 포함). */
function ts_col_exists(PDO $pdo, string $table, string $col): bool
{
    static $cache = [];
    $k = $table . '.' . $col;
    if (!isset($cache[$k])) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $col]);
        $cache[$k] = (int)$st->fetchColumn() > 0;
    }
    return $cache[$k];
}

/** 확정 전 결제수단·계좌 검사. 문제가 있으면 RuntimeException. */
function ts_check_payment(PDO $pdo, array $t): void
{
    if (!TS_REQUIRE_PAYMENT) {
        return;
    }
    $pm = (string)($t['pay_method'] ?? '');
    if ($pm === '' || !isset(TS_PAY_METHODS[$pm])) {
        throw new RuntimeException('결제수단이 지정되지 않았습니다. 전표 수정 화면에서 결제수단을 선택한 뒤 확정하세요.');
    }
    $isSale = $t['trade_type'] === 'sale';

    // 거래처의 외상 지정 여부
    $partnerCredit = false;
    if (!empty($t['partner_id']) && ts_col_exists($pdo, 'erp_partners', 'is_credit')) {
        $st = $pdo->prepare('SELECT is_credit FROM partners WHERE id = ?');
        $st->execute([(int)$t['partner_id']]);
        $partnerCredit = (int)$st->fetchColumn() === 1;
    }

    if ($isSale) {
        if ($pm === 'credit' && !$partnerCredit) {
            throw new RuntimeException('외상 판매는 외상 거래처로 지정된 거래처만 가능합니다. 지정하지 않으면 미수금에 잡히지 않습니다.');
        }
        if ($pm !== 'credit' && $partnerCredit && !TS_CREDIT_PARTNER_PAID_NOW) {
            throw new RuntimeException('외상 거래처의 판매는 결제수단을 "외상"으로 지정해야 합니다. 현금으로 받은 건 입금등록으로 처리하세요.');
        }
    }

    // 현금/이체/카드는 계좌 필요 (활성 계좌가 하나라도 있을 때)
    if ($pm === 'credit') {
        return;
    }
    try {
        $hasActiveCol = ts_col_exists($pdo, 'erp_fund_accounts', 'is_active');
        $activeSql    = $hasActiveCol ? ' WHERE is_active = 1' : '';
        $cnt = (int)$pdo->query('SELECT COUNT(*) FROM erp_fund_accounts' . $activeSql)->fetchColumn();
        if ($cnt === 0) {
            return; // 등록된 계좌가 없으면 자금일보에서 "계좌 미지정"으로 집계
        }
        $aid = (int)($t['account_id'] ?? 0);
        if ($aid <= 0) {
            throw new RuntimeException('입금/출금 계좌가 지정되지 않았습니다. 전표 수정 화면에서 계좌를 선택한 뒤 확정하세요.');
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM erp_fund_accounts WHERE id = ?' . ($hasActiveCol ? ' AND is_active = 1' : '')
        );
        $st->execute([$aid]);
        if ((int)$st->fetchColumn() === 0) {
            throw new RuntimeException('지정된 계좌가 없거나 사용 중지 상태입니다. 계좌를 다시 선택하세요.');
        }
    } catch (PDOException $e) {
        error_log('[ERP] trade_service payment check: ' . $e->getMessage());
        throw new RuntimeException('자금 계좌 정보를 확인하지 못해 확정을 막았습니다. 관리자에게 문의하세요.');
    }
}

/** 전표 확정: 재고 원장 기록. 멱등(이미 확정이면 그대로 반환). */
function confirm_trade(int $tradeId, int $userId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM trades WHERE id = ? FOR UPDATE');
        $st->execute([$tradeId]);
        $t = $st->fetch();
        if (!$t) {
            throw new RuntimeException('전표를 찾을 수 없습니다.');
        }
        if ($t['status'] === 'confirmed') {
            $pdo->commit();
            return;
        }
        if ($t['status'] !== 'draft') {
            throw new RuntimeException('확정할 수 없는 상태입니다.');
        }
        if (trade_requires_partner($t) && empty($t['partner_id'])) {
            throw new RuntimeException('도매·매입 전표는 거래처가 필요합니다.');
        }
        ts_check_payment($pdo, $t);

        $st = $pdo->prepare(
            'SELECT l.*, i.is_stock_item, i.last_cost
               FROM trade_lines l JOIN items i ON i.id = l.item_id
              WHERE l.trade_id = ? ORDER BY l.item_id, l.line_no'
        );
        $st->execute([$tradeId]);
        $lines = $st->fetchAll();
        if (!$lines) {
            throw new RuntimeException('품목이 없는 전표는 확정할 수 없습니다.');
        }

        $sum = 0;
        foreach ($lines as $l) {
            $sum += (int)$l['amount'];
        }
        if ($sum !== (int)$t['supply_amount']) {
            throw new RuntimeException('전표 합계가 라인 합계와 다릅니다. 수정 후 다시 저장하세요.');
        }

        $isSale = $t['trade_type'] === 'sale';
        $insLedger = $pdo->prepare(
            'INSERT INTO stock_ledger (item_id, move_date, qty, trade_line_id, entry_kind, memo, created_by, created_at)
             VALUES (?, ?, ?, ?, \'post\', ?, ?, ?)'
        );
        $setCost = $pdo->prepare('UPDATE trade_lines SET cost_price = ? WHERE id = ?');
        $setLast = $pdo->prepare('UPDATE items SET last_cost = ? WHERE id = ?');
        $now = date('Y-m-d H:i:s');

        foreach ($lines as $l) {
            if ((int)$l['is_stock_item'] === 1) {
                $qty = $isSale ? neg_dec((string)$l['qty']) : (string)$l['qty'];
                $insLedger->execute([$l['item_id'], $t['trade_date'], $qty, $l['id'], $t['trade_no'], $userId, $now]);
            }
            if ($isSale) {
                // 임시 원가: 최근 매입단가 스냅샷 (이동평균 원가는 2단계에서 교체)
                $setCost->execute([(int)$l['last_cost'], $l['id']]);
            } elseif ((int)$l['unit_price'] > 0) {
                $setLast->execute([(int)$l['unit_price'], $l['item_id']]);
            }
        }

        $up = $pdo->prepare('UPDATE trades SET status = \'confirmed\', confirmed_by = ?, confirmed_at = ?, updated_at = ? WHERE id = ?');
        $up->execute([$userId, $now, $now, $tradeId]);
        audit('trade_confirm', 'trade', $tradeId, $t['trade_no']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** 확정 전표 취소: 삭제하지 않고 반대 수량 원장을 추가한다. */
function cancel_trade(int $tradeId, int $userId, string $reason): void
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('취소 사유를 입력하세요.');
    }
    if (mb_strlen($reason) > 200) {
        throw new RuntimeException('취소 사유는 200자 이하로 입력하세요.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM trades WHERE id = ? FOR UPDATE');
        $st->execute([$tradeId]);
        $t = $st->fetch();
        if (!$t) {
            throw new RuntimeException('전표를 찾을 수 없습니다.');
        }
        if ($t['status'] !== 'confirmed') {
            throw new RuntimeException('확정된 전표만 취소할 수 있습니다.');
        }
        // TODO(2단계): 세금계산서/현금영수증이 발급된 전표는 여기서 취소를 막고 수정발행 흐름으로 보낸다.

        $st = $pdo->prepare(
            'SELECT id, item_id, qty, trade_line_id FROM stock_ledger
              WHERE entry_kind = \'post\'
                AND trade_line_id IN (SELECT id FROM trade_lines WHERE trade_id = ?)
              ORDER BY item_id, id'
        );
        $st->execute([$tradeId]);
        $rows = $st->fetchAll();

        $ins = $pdo->prepare(
            'INSERT INTO stock_ledger (item_id, move_date, qty, trade_line_id, entry_kind, memo, created_by, created_at)
             VALUES (?, ?, ?, ?, \'reverse\', ?, ?, ?)'
        );
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $ins->execute([$r['item_id'], date('Y-m-d'), neg_dec((string)$r['qty']), $r['trade_line_id'], $t['trade_no'] . ' 취소', $userId, $now]);
        }

        $up = $pdo->prepare('UPDATE trades SET status = \'canceled\', canceled_at = ?, cancel_reason = ?, updated_at = ? WHERE id = ?');
        $up->execute([$now, $reason, $now, $tradeId]);
        audit('trade_cancel', 'trade', $tradeId, $t['trade_no'] . ' / ' . $reason);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function delete_draft(int $tradeId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM trades WHERE id = ? FOR UPDATE');
        $st->execute([$tradeId]);
        $t = $st->fetch();
        if (!$t || $t['status'] !== 'draft') {
            throw new RuntimeException('임시 전표만 삭제할 수 있습니다.');
        }
        $pdo->prepare('DELETE FROM trade_lines WHERE trade_id = ?')->execute([$tradeId]);
        $pdo->prepare('DELETE FROM trades WHERE id = ?')->execute([$tradeId]);
        audit('trade_delete_draft', 'trade', $tradeId, $t['trade_no']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
