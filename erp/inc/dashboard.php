<?php
// inc/dashboard.php — 대시보드 집계(읽기 전용) + 공통 스타일
require_once __DIR__ . '/functions.php';

class DashConfigError extends RuntimeException
{
}

function dash_won(int $n): string
{
    return number_format($n);
}

/** 위젯 하나가 실패해도 화면 전체가 죽지 않게 감싼다. */
function dash_try(callable $fn): array
{
    try {
        return ['ok' => true, 'v' => $fn()];
    } catch (DashConfigError $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[ERP] dashboard: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return ['ok' => false, 'msg' => cfg('app.debug', false) ? $e->getMessage() : '집계에 실패했습니다.'];
    }
}

/** 전월 대비 증감 문자열 (정수 연산) */
function dash_delta(int $cur, int $prev): string
{
    if ($prev === 0) {
        return $cur === 0 ? '전월 대비 -' : '전월 대비 신규';
    }
    $pct = intdiv(($cur - $prev) * 100, $prev);
    return '전월 대비 ' . ($pct > 0 ? '+' : '') . $pct . '%';
}

/** erp_trades 에서 집계에 쓸 금액 컬럼 찾기: [컬럼명, 기준 설명] */
function dash_amount_col(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $fields = [];
    foreach (db()->query('SHOW COLUMNS FROM erp_trades')->fetchAll() as $r) {
        $fields[] = strtolower((string)$r['Field']);
    }
    foreach (['supply_amount', 'supply_total', 'total_supply', 'supply', 'subtotal', 'sub_total'] as $c) {
        if (in_array($c, $fields, true)) {
            return $cache = [$c, '공급가액 기준'];
        }
    }
    foreach (['total_amount', 'total', 'grand_total', 'total_price', 'amount_total', 'sum_total'] as $c) {
        if (in_array($c, $fields, true)) {
            return $cache = [$c, '합계(부가세 포함) 기준'];
        }
    }
    throw new DashConfigError(
        'erp_trades 에서 금액 컬럼을 찾지 못했습니다. 실제 컬럼: ' . implode(', ', $fields)
        . ' — 이 목록을 알려 주시면 맞춰 드립니다.'
    );
}

/** 기간 합계 (확정 전표만) */
function dash_sums(string $from, string $to): array
{
    [$col] = dash_amount_col();
    $st = db()->prepare(
        "SELECT trade_type, channel, COUNT(*) AS cnt,
                CAST(COALESCE(SUM(`$col`), 0) AS SIGNED) AS amt
           FROM erp_trades
          WHERE status = 'confirmed' AND trade_date BETWEEN ? AND ?
          GROUP BY trade_type, channel"
    );
    $st->execute([$from, $to]);
    $o = ['sale' => 0, 'retail' => 0, 'wholesale' => 0, 'purchase' => 0, 'sale_cnt' => 0, 'purchase_cnt' => 0];
    foreach ($st->fetchAll() as $r) {
        $amt = (int)$r['amt'];
        $cnt = (int)$r['cnt'];
        if ($r['trade_type'] === 'sale') {
            $o['sale'] += $amt;
            $o['sale_cnt'] += $cnt;
            if ($r['channel'] === 'retail') {
                $o['retail'] += $amt;
            } elseif ($r['channel'] === 'wholesale') {
                $o['wholesale'] += $amt;
            }
        } elseif ($r['trade_type'] === 'purchase') {
            $o['purchase'] += $amt;
            $o['purchase_cnt'] += $cnt;
        }
    }
    return $o;
}

/** 일별 판매: [일(int) => [건수, 금액]] */
function dash_daily(string $from, string $to): array
{
    [$col] = dash_amount_col();
    $st = db()->prepare(
        "SELECT trade_date, COUNT(*) AS cnt,
                CAST(COALESCE(SUM(`$col`), 0) AS SIGNED) AS amt
           FROM erp_trades
          WHERE status = 'confirmed' AND trade_type = 'sale' AND trade_date BETWEEN ? AND ?
          GROUP BY trade_date"
    );
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)substr((string)$r['trade_date'], 8, 2)] = [(int)$r['cnt'], (int)$r['amt']];
    }
    return $out;
}

/** 최근 N개월(오래된 달 → 최신 달): [['ym','sale','purchase'], ...] */
function dash_months(string $endYm, int $n = 6): array
{
    [$col] = dash_amount_col();
    $from = date('Y-m-01', strtotime($endYm . '-01 -' . ($n - 1) . ' month'));
    $to   = date('Y-m-t', strtotime($endYm . '-01'));
    $st = db()->prepare(
        "SELECT DATE_FORMAT(trade_date, '%Y-%m') AS ym, trade_type,
                CAST(COALESCE(SUM(`$col`), 0) AS SIGNED) AS amt
           FROM erp_trades
          WHERE status = 'confirmed' AND trade_date BETWEEN ? AND ?
          GROUP BY ym, trade_type"
    );
    $st->execute([$from, $to]);
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $map[$r['ym']][$r['trade_type']] = (int)$r['amt'];
    }
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime($endYm . '-01 -' . $i . ' month'));
        $out[] = ['ym' => $ym, 'sale' => $map[$ym]['sale'] ?? 0, 'purchase' => $map[$ym]['purchase'] ?? 0];
    }
    return $out;
}

/** 거래처별 판매 TOP5 (거래처가 지정된 전표만) */
function dash_top_partners(string $from, string $to): array
{
    [$col] = dash_amount_col();
    $st = db()->prepare(
        "SELECT p.id, p.name, COUNT(*) AS cnt,
                CAST(COALESCE(SUM(t.`$col`), 0) AS SIGNED) AS amt
           FROM erp_trades t
           JOIN erp_partners p ON p.id = t.partner_id
          WHERE t.status = 'confirmed' AND t.trade_type = 'sale' AND t.trade_date BETWEEN ? AND ?
          GROUP BY p.id, p.name
          ORDER BY amt DESC
          LIMIT 5"
    );
    $st->execute([$from, $to]);
    return $st->fetchAll();
}

/** 최근 전표 8건 (상태 무관) */
function dash_recent(): array
{
    [$col] = dash_amount_col();
    return db()->query(
        "SELECT t.id, t.trade_no, t.trade_type, t.channel, t.status, t.trade_date,
                COALESCE(NULLIF(p.name, ''), NULLIF(t.customer_name, ''), '') AS who,
                t.`$col` AS amt
           FROM erp_trades t
           LEFT JOIN erp_partners p ON p.id = t.partner_id
          ORDER BY t.id DESC
          LIMIT 8"
    )->fetchAll();
}

/** 주의 카운터: 임시 전표 수, 마이너스 재고 품목 수 */
function dash_counts(): array
{
    $pdo = db();
    $draft = (int)$pdo->query("SELECT COUNT(*) FROM erp_trades WHERE status = 'draft'")->fetchColumn();
    $minus = (int)$pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT i.id
              FROM erp_items i
              JOIN erp_stock_ledger l ON l.item_id = i.id
             WHERE i.is_stock_item = 1 AND i.is_active = 1
             GROUP BY i.id
            HAVING SUM(l.qty) < 0
         ) x"
    )->fetchColumn();
    return ['draft' => $draft, 'minus' => $minus];
}

function dash_css(): void
{
    echo <<<'CSS'
<style>
.dsh-alert{background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;border-radius:4px;margin-bottom:8px;font-size:13px}
.dsh-alert a{color:#92400e;font-weight:600}
.dsh-fail{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;padding:8px 12px;border-radius:4px;font-size:12px}
.dsh-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-bottom:12px}
.dsh-kpi{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:10px 12px}
.dsh-kpi b{display:block;font-size:20px;margin:4px 0}
.dsh-kpi small{color:#6b7280}
.dsh-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:12px;margin-bottom:12px}
.dsh-grid2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;margin-bottom:12px}
@media(max-width:900px){.dsh-grid,.dsh-grid2{grid-template-columns:1fr}}
.dsh-card{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px}
.dsh-card h2{font-size:15px;margin:0 0 8px}
.dsh-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:8px}
.dsh-head h2{margin:0}
.dsh-nav a{display:inline-block;padding:3px 10px;border:1px solid #d1d5db;border-radius:3px;color:#111827;text-decoration:none;font-size:12px;background:#fff}
.dsh-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:2px}
.dsh-dow{text-align:center;font-size:12px;color:#6b7280;padding:2px 0}
.dsh-cell{display:block;min-height:84px;border:1px solid #e5e7eb;border-radius:4px;padding:3px 4px;color:#111827;text-decoration:none;overflow:hidden;background:#fff}
.dsh-empty{border:0;background:transparent}
.dsh-cell.sun .dsh-dn{color:#dc2626}
.dsh-cell.sat .dsh-dn{color:#2563eb}
.dsh-cell.today{outline:2px solid #1f2937}
.dsh-cell.sel{background:#eef2ff}
.dsh-dn{font-size:12px;font-weight:600}
.dsh-sales{float:right;font-size:10px;color:#6b7280}
.dsh-chip{display:block;font-size:11px;margin-top:2px;padding:1px 3px;border-radius:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dsh-chip.done{text-decoration:line-through;opacity:.55}
.dsh-more{font-size:10px;color:#6b7280}
.dsh-ev{border:1px solid #e5e7eb;border-left-width:4px;border-radius:4px;padding:6px 8px;margin-bottom:6px;font-size:13px}
.dsh-ev.done .dsh-et{text-decoration:line-through;opacity:.6}
.dsh-ev small{color:#6b7280;display:block}
.dsh-acts{margin-top:4px}
.dsh-acts form{display:inline}
.dsh-acts .btn,.dsh-acts a{font-size:12px;margin-right:4px}
.dsh-form label{display:block;font-size:12px;color:#374151;margin-top:6px}
.dsh-form input,.dsh-form select,.dsh-form textarea{width:100%;box-sizing:border-box}
.dsh-row2{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.dsh-bars{display:flex;align-items:stretch;gap:2px;height:150px}
.dsh-bar{flex:1;display:flex;flex-direction:column;min-width:0}
.dsh-bi{flex:1;display:flex;align-items:flex-end}
.dsh-bi i{display:block;width:100%;background:#2563eb;border-radius:2px 2px 0 0}
.dsh-bar span{font-size:9px;color:#6b7280;text-align:center;margin-top:2px}
.dsh-mrow{display:grid;grid-template-columns:56px 1fr;gap:6px;align-items:center;margin:6px 0;font-size:12px}
.dsh-hb{height:10px;border-radius:2px;min-width:1px;margin:2px 0}
.dsh-tbl{width:100%;border-collapse:collapse;font-size:13px}
.dsh-tbl th,.dsh-tbl td{padding:5px 6px;border-bottom:1px solid #f3f4f6;text-align:left}
.dsh-tbl .num{text-align:right}
.dsh-foot{font-size:11px;color:#6b7280;margin-top:6px}
</style>
CSS;
}
