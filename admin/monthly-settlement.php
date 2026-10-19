<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('monthly-settlement');
ensure_work_log_tables();
ensure_monthly_settlement_table();

$pdo = Database::connection();
$pageTitle = '월간 정산 · 인센티브';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');

/* ===== 마감/지급 확정/인센티브율 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/monthly-settlement.php');
    }
    $month = $_POST['month'] ?? '';
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        flash('admin_error', '정산 월이 올바르지 않습니다.');
        redirect('/admin/monthly-settlement.php');
    }
    $action = $_POST['action'] ?? '';
    $adminId = (int)AdminAuth::currentAdminId();
    $adminName = AdminAuth::currentAdminName();

    if ($action === 'close') {
        $result = close_month_settlement($month, $adminId, $adminName);
        if ($result['ok']) {
            $profitRate = $result['calc']['total_sales'] > 0 ? round($result['calc']['margin_total'] / $result['calc']['total_sales'] * 100, 1) : 0;
            flash('admin_success', "{$month} 월간 정산 마감 완료 — 종합매출 " . number_format($result['calc']['total_sales'])
                . '원 · 전체 수익(마진) ' . number_format($result['calc']['margin_total']) . "원 ({$profitRate}%)"
                . ' · 인센티브 총액 ' . number_format($result['calc']['incentive_total']) . '원');
        } else {
            flash('admin_error', $result['msg'] ?? '마감 처리에 실패했습니다.');
        }
    } elseif ($action === 'reopen') {
        $result = set_month_settlement_paid($month, false, $adminId, $adminName);
        flash($result['ok'] ? 'admin_success' : 'admin_error',
            $result['ok'] ? "{$month} 지급 확정을 해제했습니다. 재마감으로 스냅샷을 갱신할 수 있습니다." : ($result['msg'] ?? '해제에 실패했습니다.'));
    } elseif ($action === 'mark_paid') {
        $result = set_month_settlement_paid($month, true, $adminId, $adminName);
        flash($result['ok'] ? 'admin_success' : 'admin_error',
            $result['ok'] ? "{$month} 인센티브 지급을 확정했습니다." : ($result['msg'] ?? '지급 확정에 실패했습니다.'));
    } elseif ($action === 'save_rate') {
        $rate = (float)str_replace(',', '.', (string)($_POST['incentive_rate'] ?? '5'));
        if ($rate >= 0 && $rate <= 50) {
            set_setting('incentive_rate', (string)$rate);
            AdminAuth::log($adminId, 'incentive_rate', "인센티브율 {$rate}%로 설정");
            flash('admin_success', "기본 인센티브율을 {$rate}%로 저장했습니다. 마감 시 이 율이 스냅샷에 반영됩니다.");
        } else {
            flash('admin_error', '인센티브율은 0~50% 사이로 입력해 주세요.');
        }
    }
    redirect('/admin/monthly-settlement.php?month=' . $month);
}

/* ===== 조회 월 ===== */
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$thisMonth = date('Y-m');
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

$calc = compute_month_settlement($month);
$settlement = get_month_settlement($month);
$isClosed = $settlement && $settlement['reopened_at'] === null;
$isPaid = $settlement && $settlement['paid_status'] === 'paid';
$fmt = fn(int $n): string => number_format($n);
$cats = $calc['cats'];

/* 마감 스냅샷이 있으면 스냅샷 우선 (마감 확정값) */
if ($settlement && $isClosed) {
    $calc['tire_sales'] = (int)$settlement['tire_sales'];
    $calc['repair_sales'] = (int)$settlement['repair_sales'];
    $calc['etc_sales'] = (int)$settlement['etc_sales'];
    $calc['site_sales'] = (int)$settlement['site_sales'];
    $calc['online_sales'] = (int)$settlement['online_sales'];
    $calc['total_sales'] = (int)$settlement['total_sales'];
    $calc['cost_factory_total'] = (int)$settlement['cost_factory_total'];
    $calc['cost_supply_total'] = (int)$settlement['cost_supply_total'];
    $calc['margin_total'] = (int)$settlement['margin_total'];
    $calc['card_sales'] = (int)($settlement['card_sales'] ?? 0);
    $calc['cash_sales'] = (int)($settlement['cash_sales'] ?? 0);
    $calc['transfer_sales'] = (int)($settlement['transfer_sales'] ?? 0);
    $calc['unpaid_sales'] = (int)($settlement['unpaid_sales'] ?? 0);
    $calc['online_retail_sales'] = (int)($settlement['online_retail_sales'] ?? 0);
    $calc['online_biz_sales'] = (int)($settlement['online_biz_sales'] ?? 0);
    $calc['retail_sales'] = (int)($settlement['retail_sales'] ?? $settlement['site_sales']);
    $calc['resv_sales'] = (int)($settlement['resv_sales'] ?? 0);
    $calc['wholesale_sales'] = (int)($settlement['wholesale_sales'] ?? $settlement['online_biz_sales']);
    $calc['local_wholesale_sales'] = (int)($settlement['local_wholesale_sales'] ?? 0);
    $calc['local_wholesale_count'] = (int)($settlement['local_wholesale_count'] ?? 0);
    $calc['wholesale_card_sales'] = (int)($settlement['wholesale_card_sales'] ?? 0);
    $calc['wholesale_cash_sales'] = 0;  /* 구 스냅샷 상세 미보관 — 근사 불가, 0 처리 */
    $calc['wholesale_unpaid_sales'] = 0;
    $calc['online_channel_sales'] = (int)($settlement['online_channel_sales'] ?? 0)
        ?: ((int)$calc['resv_sales'] + (int)$calc['online_retail_sales']);
    $calc['retail_margin'] = (int)($settlement['retail_margin'] ?? 0);
    $calc['online_margin'] = (int)($settlement['online_margin'] ?? 0);
    $calc['wholesale_margin'] = (int)($settlement['wholesale_margin'] ?? 0);
    $calc['card_total_sales'] = (int)($settlement['card_total_sales'] ?? 0);
    $calc['card_fee_rate'] = (float)($settlement['card_fee_rate'] ?? 0);
    $calc['card_fee_amount'] = (int)($settlement['card_fee_amount'] ?? 0);
    $calc['card_net_sales'] = (int)($settlement['card_net_sales'] ?? $calc['card_total_sales']);
    /* [v42] 현장/온라인 요율 스냅샷 — 요율×스냅샷 카드매출로 수수료 재구성 */
    $calc['card_fee_rate_site'] = (float)($settlement['card_fee_rate_site'] ?? 0) ?: (float)$calc['card_fee_rate'];
    $calc['card_fee_rate_online'] = (float)($settlement['card_fee_rate_online'] ?? 0) ?: (float)$calc['card_fee_rate'];
    $calc['card_fee_site_amount'] = (int)round($calc['card_sales'] * $calc['card_fee_rate_site'] / 100);
    $calc['card_fee_online_amount'] = (int)round(max(0, $calc['card_total_sales'] - $calc['card_sales']) * $calc['card_fee_rate_online'] / 100);
    $calc['local_wholesale_margin'] = (int)($settlement['local_wholesale_margin'] ?? 0);
    $calc['wholesale_group_sales'] = (int)($settlement['wholesale_group_sales'] ?? 0)
        ?: ($calc['wholesale_sales'] + $calc['local_wholesale_sales']);
    $calc['wholesale_margin_total'] = (int)($settlement['wholesale_margin_total'] ?? 0)
        ?: ($calc['wholesale_margin'] + $calc['local_wholesale_margin']);
    $calc['online_group_sales'] = $calc['resv_sales'] + $calc['online_retail_sales'];
    $calc['online_margin_group'] = $calc['online_margin'];
    $calc['reservation_count'] = (int)$settlement['reservation_count'];
    $calc['walkin_count'] = (int)$settlement['walkin_count'];
    $calc['work_count'] = (int)$settlement['work_count'];
    $calc['incentive_rate'] = (float)$settlement['incentive_rate'];
    $calc['incentive_total'] = (int)$settlement['incentive_total'];
    $calc['workers'] = json_decode((string)$settlement['worker_stats'], true) ?: [];
    uasort($calc['workers'], fn($a, $b) => $b['amount'] <=> $a['amount']);
    /* 매출 구분 세부는 스냅샷 대분류 값으로 재구성 (v41 컬럼이 없는 구 스냅샷은 근사) */
    $cats['retail_total'] = ['count' => 0, 'card' => 0, 'cash' => 0, 'transfer' => 0, 'unpaid' => 0,
                             'receipt' => 0, 'taxinv' => 0, 'total' => $calc['retail_sales']];
    $cats['tire'] = ['total' => $calc['tire_sales']] + $cats['tire'];
    $cats['repair'] = ['total' => $calc['repair_sales']] + $cats['repair'];
    $cats['etc'] = ['total' => $calc['etc_sales']] + $cats['etc'];
    $cats['online_resv'] = ['total' => $calc['resv_sales']] + $cats['online_resv'];
    $cats['online_retail'] = ['total' => $calc['online_retail_sales']] + $cats['online_retail'];
    $cats['online_biz'] = ['total' => $calc['online_biz_sales']] + $cats['online_biz'];
    $cats['online_total'] = ['total' => $calc['online_group_sales']] + $cats['online_total'];
    $cats['wholesale_local'] = ['total' => $calc['local_wholesale_sales']] + $cats['wholesale_local'];
    $cats['wholesale_total'] = ['total' => $calc['wholesale_group_sales']] + $cats['wholesale_total'];
}

/* 월별 이력 (최근 12개월) */
$history = [];
try {
    $stmt = $pdo->prepare(
        "SELECT settle_month, site_sales, total_sales, margin_total, incentive_rate, incentive_total, paid_status, paid_at, paid_by, closed_by, closed_at, reopened_at
         FROM tt_monthly_settlements
         ORDER BY settle_month DESC LIMIT 12");
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">📅 월간 정산 · 인센티브</h2>
      <p class="admin-list-sub">한 달 매출을 <b>소매(현장 로드손님)</b> / <b>온라인(소매 예약장착 · 소매 택배)</b> / <b>도매(온라인 도매주문 · 일반 도매=로컬 거래처)</b> 대분류로 정리하고, 전체 수익(마진) × 인센티브율을 작업자 기여도로 배분합니다.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php if ($settlement): ?>
      <a class="btn btn-primary" href="<?= BASE_URL ?>/admin/monthly-settlement-print.php?month=<?= h($month) ?>" target="_blank">🖨️ 월 정산표 인쇄</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <!-- 월 선택 -->
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:18px;">
    <a href="?month=<?= h($prevMonth) ?>" class="btn" style="padding:8px 14px;font-size:13px;">◀ 이전 달</a>
    <form method="get" style="display:flex;gap:8px;align-items:center;">
      <input type="month" name="month" value="<?= h($month) ?>" style="padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-weight:700;">
      <button type="submit" class="btn btn-primary" style="padding:9px 16px;font-size:13px;">조회</button>
    </form>
    <?php if ($nextMonth <= $thisMonth): ?><a href="?month=<?= h($nextMonth) ?>" class="btn" style="padding:8px 14px;font-size:13px;">다음 달 ▶</a><?php endif; ?>
    <?php if ($month !== $thisMonth): ?><a href="?month=<?= h($thisMonth) ?>" class="btn" style="padding:8px 14px;font-size:13px;">이번 달</a><?php endif; ?>
    <div style="margin-left:auto;font-size:13px;font-weight:700;">
      <?php if ($isPaid): ?><span style="color:#16a34a;">💰 지급 확정 (<?= h(date('Y.m.d', strtotime($settlement['paid_at']))) ?> · <?= h((string)$settlement['paid_by']) ?>)</span>
      <?php elseif ($isClosed): ?><span style="color:#2563eb;">🔒 마감 완료 (<?= h((string)$settlement['closed_by']) ?>)</span>
      <?php else: ?><span style="color:#f59e0b;">⏳ 라이브 집계 (마감 전)</span><?php endif; ?>
    </div>
  </div>

  <!-- ===== 대분류 카드 [v43 — 소매/온라인/도매/종합/수익] ===== -->
  <div class="ms-stats">
    <div class="ms-stat"><div class="k">🛞 소매매출 (현장·로드손님)</div><div class="v"><?= $fmt($calc['retail_sales']) ?><span>원</span></div><div class="s">타이어 <?= $fmt($calc['tire_sales']) ?> · 정비 <?= $fmt($calc['repair_sales']) ?> · 기타 <?= $fmt($calc['etc_sales']) ?> · <?= number_format((int)$calc['walkin_count']) ?>건</div></div>
    <div class="ms-stat on"><div class="k">🛒 온라인매출 (예약장착+소매택배)</div><div class="v"><?= $fmt($calc['online_group_sales']) ?><span>원</span></div><div class="s">소매 예약장착 <?= $fmt($calc['resv_sales']) ?> · 소매 택배 <?= $fmt($calc['online_retail_sales']) ?></div></div>
    <div class="ms-stat wh"><div class="k">📦 도매매출 (온라인 도매+일반 도매)</div><div class="v"><?= $fmt($calc['wholesale_group_sales']) ?><span>원</span></div><div class="s">온라인 도매주문 <?= $fmt($calc['wholesale_sales']) ?> · 일반 도매(로컬 거래처) <?= $fmt($calc['local_wholesale_sales']) ?></div></div>
    <div class="ms-stat hero"><div class="k">📊 종합매출 (전체)</div><div class="v"><?= $fmt($calc['total_sales']) ?><span>원</span></div><div class="s"><?= number_format($calc['work_count'] + (int)$calc['local_wholesale_count']) ?>건 작업·도매 · <?= number_format($calc['days']) ?>일 정산</div></div>
    <div class="ms-stat profit"><div class="k">💰 전체 수익 (마진)</div><div class="v"><?= $fmt($calc['margin_total']) ?><span>원</span></div><div class="s">수익률 <b><?= $calc['total_sales'] > 0 ? round($calc['margin_total'] / $calc['total_sales'] * 100, 1) : 0 ?>%</b> · 현금영수증 <?= number_format((int)$calc['receipt_count']) ?>건 · 세금계산서 <?= number_format((int)$calc['taxinv_count']) ?>건</div></div>
  </div>

  <!-- ===== 매출 구분 [v42 — 건수·금액·현금영수증·세금계산서] ===== -->
  <h3 class="admin-list-title" style="margin:22px 0 10px;">🧾 매출 구분</h3>
  <div class="admin-table-wrap" style="overflow-x:auto;margin-bottom:16px;">
  <table class="admin-table ms-cat-table">
    <thead><tr><th>구분</th><th>건수</th><th>💳 카드</th><th>💵 현금·계좌</th><th>⏳ 외상</th><th>🧾 현금영수증</th><th>📄 세금계산서</th><th>합계</th></tr></thead>
    <tbody>
      <tr><td>🛞 현장 타이어판매 (로드손님)</td><td><?= (int)$cats['tire']['count'] ?>건</td><td><?= $fmt((int)$cats['tire']['card']) ?>원</td><td><?= $fmt((int)$cats['tire']['cash'] + (int)$cats['tire']['transfer']) ?>원</td><td><?= $fmt((int)$cats['tire']['unpaid']) ?>원</td><td><?= (int)$cats['tire']['receipt'] ?>건</td><td><?= (int)$cats['tire']['taxinv'] ?>건</td><td><b><?= $fmt((int)$cats['tire']['total']) ?></b>원</td></tr>
      <tr><td>🔧 정비매출</td><td><?= (int)$cats['repair']['count'] ?>건</td><td><?= $fmt((int)$cats['repair']['card']) ?>원</td><td><?= $fmt((int)$cats['repair']['cash'] + (int)$cats['repair']['transfer']) ?>원</td><td><?= $fmt((int)$cats['repair']['unpaid']) ?>원</td><td><?= (int)$cats['repair']['receipt'] ?>건</td><td><?= (int)$cats['repair']['taxinv'] ?>건</td><td><b><?= $fmt((int)$cats['repair']['total']) ?></b>원</td></tr>
      <tr><td>📄 기타작업 매출</td><td><?= (int)$cats['etc']['count'] ?>건</td><td><?= $fmt((int)$cats['etc']['card']) ?>원</td><td><?= $fmt((int)$cats['etc']['cash'] + (int)$cats['etc']['transfer']) ?>원</td><td><?= $fmt((int)$cats['etc']['unpaid']) ?>원</td><td><?= (int)$cats['etc']['receipt'] ?>건</td><td><?= (int)$cats['etc']['taxinv'] ?>건</td><td><b><?= $fmt((int)$cats['etc']['total']) ?></b>원</td></tr>
      <tr class="ms-sub-total"><td>소매매출 합계 (현장 로드손님)</td><td><?= (int)$cats['retail_total']['count'] ?>건</td><td><b><?= $fmt((int)$cats['retail_total']['card']) ?></b>원</td><td><b><?= $fmt((int)$cats['retail_total']['cash'] + (int)$cats['retail_total']['transfer']) ?></b>원</td><td><b><?= $fmt((int)$cats['retail_total']['unpaid']) ?></b>원</td><td><b><?= (int)$cats['retail_total']['receipt'] ?>건</b></td><td><b><?= (int)$cats['retail_total']['taxinv'] ?>건</b></td><td><b style="color:#2563eb;"><?= $fmt($calc['retail_sales']) ?></b>원</td></tr>
      <tr><td>🛒 소매 예약장착 (주문→예약 방문)</td><td><?= number_format($calc['reservation_count']) ?>건</td><td><?= $fmt((int)$cats['online_resv']['card']) ?>원</td><td><?= $fmt((int)$cats['online_resv']['cash'] + (int)$cats['online_resv']['transfer']) ?>원</td><td><?= $fmt((int)$cats['online_resv']['unpaid']) ?>원</td><td><?= (int)$cats['online_resv']['receipt'] ?>건</td><td><?= (int)$cats['online_resv']['taxinv'] ?>건</td><td><b><?= $fmt($calc['resv_sales']) ?></b>원</td></tr>
      <tr><td>📦 소매 택배 (온라인 소매주문)</td><td><?= (int)$cats['online_retail']['count'] ?>건</td><td><?= $fmt((int)$cats['online_retail']['card']) ?>원</td><td class="text-muted"><?= $fmt((int)$cats['online_retail']['transfer']) ?>원 (무통장)</td><td>-</td><td><?= (int)$cats['online_retail']['receipt'] ?>건</td><td><?= (int)$cats['online_retail']['taxinv'] ?>건</td><td><b><?= $fmt($calc['online_retail_sales']) ?></b>원</td></tr>
      <tr class="ms-sub-total"><td>온라인매출 합계 (예약장착+소매택배)</td><td><?= (int)$cats['online_total']['count'] ?>건</td><td><b><?= $fmt((int)$cats['online_total']['card']) ?></b>원</td><td><b><?= $fmt((int)$cats['online_total']['cash'] + (int)$cats['online_total']['transfer']) ?></b>원</td><td><b><?= $fmt((int)$cats['online_total']['unpaid']) ?></b>원</td><td><b><?= (int)$cats['online_total']['receipt'] ?>건</b></td><td><b><?= (int)$cats['online_total']['taxinv'] ?>건</b></td><td><b style="color:#7c3aed;"><?= $fmt($calc['online_group_sales']) ?></b>원</td></tr>
      <tr><td>🏢 사업자 택배 (온라인 도매주문)</td><td><?= (int)$cats['online_biz']['count'] ?>건</td><td><?= $fmt((int)$cats['online_biz']['card']) ?>원</td><td class="text-muted"><?= $fmt((int)$cats['online_biz']['transfer']) ?>원 (무통장)</td><td>-</td><td><?= (int)$cats['online_biz']['receipt'] ?>건</td><td><?= (int)$cats['online_biz']['taxinv'] ?>건</td><td><b><?= $fmt($calc['online_biz_sales']) ?></b>원</td></tr>
      <tr><td>🏪 일반 도매 (로컬 거래처 · 전화주문)</td><td><?= (int)$cats['wholesale_local']['count'] ?>건</td><td><?= $fmt((int)$cats['wholesale_local']['card']) ?>원</td><td><?= $fmt((int)$cats['wholesale_local']['cash'] + (int)$cats['wholesale_local']['transfer']) ?>원</td><td><?= $fmt((int)$cats['wholesale_local']['unpaid']) ?>원</td><td>-</td><td><?= (int)$cats['wholesale_local']['taxinv'] ?>건</td><td><b><?= $fmt((int)$cats['wholesale_local']['total']) ?></b>원</td></tr>
      <tr class="ms-sub-total"><td>도매매출 합계 (온라인 도매+일반 도매)</td><td><?= (int)$cats['wholesale_total']['count'] ?>건</td><td><b><?= $fmt((int)$cats['wholesale_total']['card']) ?></b>원</td><td><b><?= $fmt((int)$cats['wholesale_total']['cash'] + (int)$cats['wholesale_total']['transfer']) ?></b>원</td><td><b><?= $fmt((int)$cats['wholesale_total']['unpaid']) ?></b>원</td><td><b>-</b></td><td><b><?= (int)$cats['wholesale_total']['taxinv'] ?>건</b></td><td><b style="color:#c2410c;"><?= $fmt($calc['wholesale_group_sales']) ?></b>원</td></tr>
      <tr class="ms-grand-total"><td>종합매출 (전체)</td><td><?= number_format((int)$calc['work_count'] + (int)$calc['online_count'] + (int)$calc['local_wholesale_count']) ?>건</td><td><b><?= $fmt($calc['card_total_sales']) ?></b>원</td><td><b><?= $fmt($calc['cash_sales'] + $calc['transfer_sales'] + (int)$calc['online_bank_sales'] + (int)$calc['wholesale_cash_sales']) ?></b>원</td><td><b><?= $fmt($calc['unpaid_sales'] + (int)$calc['wholesale_unpaid_sales']) ?></b>원</td><td><b><?= (int)$calc['receipt_count'] ?>건</b></td><td><b><?= (int)$calc['taxinv_count'] ?>건</b></td><td><b style="color:#1d4ed8;"><?= $fmt($calc['total_sales']) ?></b>원</td></tr>
    </tbody>
  </table>
  </div>

  <!-- ===== 카드 수수료 + 마진 [v42] ===== -->
  <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:14px;margin-bottom:18px;">
    <div class="ms-fee-box">
      <div class="ms-fee-head">💳 카드 수수료 <a class="btn btn-sm" href="<?= BASE_URL ?>/admin/card-fees.php" style="float:right;margin-top:-4px;">⚙️ 수수료율 설정</a></div>
      <div class="ms-fee-rows">
        <div class="row"><span>현장·일반도매 카드 결제매출 (<?= number_format($calc['card_fee_rate_site'], 1) ?>%)</span><b><?= $fmt($calc['card_sales'] + (int)$calc['wholesale_card_sales']) ?>원 → 수수료 -<?= $fmt((int)$calc['card_fee_site_amount']) ?>원</b></div>
        <div class="row"><span>온라인 카드 결제매출 (<?= number_format($calc['card_fee_rate_online'], 1) ?>%)</span><b><?= $fmt((int)$calc['online_card_sales']) ?>원 → 수수료 -<?= $fmt((int)$calc['card_fee_online_amount']) ?>원</b></div>
        <div class="row"><span>카드 결제매출 합계</span><b><?= $fmt($calc['card_total_sales']) ?>원</b></div>
        <div class="row"><span>카드 수수료 합계</span><b style="color:#c2410c;">-<?= $fmt($calc['card_fee_amount']) ?>원</b></div>
        <div class="row total"><span>카드 순매출 (수수료 제외)</span><b style="color:#16a34a;"><?= $fmt($calc['card_net_sales']) ?>원</b></div>
      </div>
    </div>
    <div class="ms-margin-box">
      <div class="ms-fee-head" style="color:#166534;">💰 마진 (매출 − 매입단가)</div>
      <div class="ms-fee-rows">
        <div class="row"><span>소매마진 (현장)</span><b><?= $fmt($calc['retail_margin']) ?>원<?= $calc['retail_sales'] > 0 ? ' · ' . round($calc['retail_margin'] / $calc['retail_sales'] * 100, 1) . '%' : '' ?></b></div>
        <div class="row"><span>온라인마진 (예약장착+소매택배)</span><b style="color:#7c3aed;"><?= $fmt($calc['online_margin_group']) ?>원<?= $calc['online_group_sales'] > 0 ? ' · ' . round($calc['online_margin_group'] / $calc['online_group_sales'] * 100, 1) . '%' : '' ?></b></div>
        <div class="row"><span>도매마진 (온라인 도매+일반 도매)</span><b style="color:#c2410c;"><?= $fmt($calc['wholesale_margin_total']) ?>원<?= $calc['wholesale_group_sales'] > 0 ? ' · ' . round($calc['wholesale_margin_total'] / $calc['wholesale_group_sales'] * 100, 1) . '%' : '' ?></b></div>
        <div class="row total"><span>토탈 마진 (전체 수익)</span><b style="color:#16a34a;"><?= $fmt($calc['margin_total']) ?>원 · <?= $calc['total_sales'] > 0 ? round($calc['margin_total'] / $calc['total_sales'] * 100, 1) : 0 ?>%</b></div>
      </div>
    </div>
  </div>

  <!-- ===== 작업자별 인센티브 ===== -->
  <h3 class="admin-list-title" style="margin:4px 0 10px;">👷 작업자별 인센티브 (전체 수익 <?= $fmt($calc['margin_total']) ?>원 × <?= number_format($calc['incentive_rate'], 1) ?>% = <?= $fmt($calc['incentive_total']) ?>원)</h3>
  <div class="admin-table-wrap" style="overflow-x:auto;margin-bottom:16px;">
    <table class="admin-table">
      <thead>
        <tr><th>작업자</th><th>작업 건수</th><th>작업 매출</th><th>기여도</th><th>카드 / 현금·계좌 / 외상</th><th>인센티브</th></tr>
      </thead>
      <tbody>
        <?php if (empty($calc['workers'])): ?>
        <tr><td colspan="6" class="empty">이 달에 기록된 작업일지가 없습니다.</td></tr>
        <?php else: $wi = 0; foreach ($calc['workers'] as $w): $wi++; ?>
        <tr <?= $wi <= 3 ? 'style="background:#faf5ff;"' : '' ?>>
          <td><b><?= h($w['name']) ?></b><?= $wi === 1 ? ' <span style="font-size:10px;background:#ede9fe;color:#6d28d9;padding:1px 6px;border-radius:4px;font-weight:800;">TOP</span>' : '' ?></td>
          <td><?= number_format($w['count']) ?>건</td>
          <td><b><?= $fmt($w['amount']) ?></b>원</td>
          <td><b><?= number_format((float)$w['share'], 1) ?></b>%</td>
          <td style="font-size:12px;"><?= $fmt((int)$w['card']) ?> / <?= $fmt((int)$w['cash'] + (int)$w['transfer']) ?> / <?= $fmt((int)$w['unpaid']) ?></td>
          <td><b style="color:#9333ea;"><?= $fmt($w['incentive']) ?></b>원</td>
        </tr>
        <?php endforeach; ?>
        <tr class="ms-grand-total">
          <td>합계</td>
          <td><?= number_format($calc['work_count']) ?>건</td>
          <td><?= $fmt($calc['site_sales']) ?>원</td>
          <td>100%</td>
          <td style="font-size:12px;"><?= $fmt((int)$calc['card_sales']) ?> / <?= $fmt((int)$calc['cash_sales'] + (int)$calc['transfer_sales']) ?> / <?= $fmt((int)$calc['unpaid_sales']) ?></td>
          <td style="color:#9333ea;"><?= $fmt($calc['incentive_total']) ?>원</td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- 마감/지급 버튼 + 인센티브율 -->
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
    <?php if (!$isClosed): ?>
    <form method="post" onsubmit="return confirm('<?= h($month) ?> 월간 정산을 마감할까요?\n(작업일지는 일정산 마감으로 이미 잠겨 있으며, 월 스냅샷이 저장됩니다)');">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="month" value="<?= h($month) ?>">
      <input type="hidden" name="action" value="close">
      <button type="submit" class="btn btn-primary" style="padding:11px 20px;font-size:14px;">🔒 월간 정산 마감</button>
    </form>
    <?php elseif (!$isPaid): ?>
    <form method="post" onsubmit="return confirm('<?= h($month) ?> 인센티브 <?= number_format($calc['incentive_total']) ?>원 지급을 확정할까요?\n확정 후에는 스냅샷을 바꿀 수 없습니다 (해제 가능).');">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="month" value="<?= h($month) ?>">
      <input type="hidden" name="action" value="mark_paid">
      <button type="submit" class="btn btn-primary" style="padding:11px 20px;font-size:14px;background:#16a34a;">💰 지급 확정</button>
    </form>
    <form method="post" onsubmit="return confirm('이 달을 다시 집계해서 마감 스냅샷을 갱신할까요?');">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="month" value="<?= h($month) ?>">
      <input type="hidden" name="action" value="close">
      <button type="submit" class="btn" style="padding:11px 20px;font-size:14px;">🔄 재마감 (스냅샷 갱신)</button>
    </form>
    <?php else: ?>
    <form method="post" onsubmit="return confirm('지급 확정을 해제할까요?');">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="month" value="<?= h($month) ?>">
      <input type="hidden" name="action" value="reopen">
      <button type="submit" class="btn" style="padding:11px 20px;font-size:14px;">↩ 지급 확정 해제</button>
    </form>
    <?php endif; ?>

    <form method="post" style="display:flex;gap:8px;align-items:flex-end;margin-left:auto;background:#faf5ff;border-radius:12px;padding:10px 14px;">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="month" value="<?= h($month) ?>">
      <input type="hidden" name="action" value="save_rate">
      <div>
        <label style="font-size:11px;font-weight:700;color:#6d28d9;display:block;margin-bottom:3px">기본 인센티브율 (%)</label>
        <input type="number" name="incentive_rate" min="0" max="50" step="0.5" value="<?= h((string)incentive_rate()) ?>"
               style="padding:8px 10px;border:1.5px solid #e9d5ff;border-radius:8px;font-size:13px;width:90px">
      </div>
      <button type="submit" class="btn btn-primary" style="padding:9px 14px;font-size:12px;">저장</button>
    </form>
  </div>
  <p style="font-size:12px;color:#64748b;margin:0 0 20px;">인센티브 = 전체 수익(토탈마진) × 인센티브율, 작업 매출 기여도로 작업자별 배분. 마감 시점의 율과 집계가 스냅샷으로 고정됩니다.</p>

  <!-- 월간 이력 -->
  <h3 class="admin-list-title" style="margin:6px 0 10px;">📚 월간 정산 이력 (최근 12개월)</h3>
  <?php if (empty($history)): ?>
  <p style="color:#94a3b8;font-size:13px;padding:16px 0;">마감 이력이 없습니다.</p>
  <?php else: ?>
  <div class="admin-table-wrap" style="overflow-x:auto">
    <table class="admin-table">
      <thead><tr><th>정산 월</th><th>종합 매출</th><th>토탈 마진</th><th>인센티브율</th><th>인센티브 총액</th><th>지급</th><th>마감자/시각</th></tr></thead>
      <tbody>
        <?php foreach ($history as $h): ?>
        <tr <?= $h['settle_month'] === $month ? 'style="background:#eff6ff;font-weight:700;"' : '' ?>>
          <td class="mono"><b><?= h($h['settle_month']) ?></b></td>
          <td><?= number_format((int)$h['total_sales']) ?>원</td>
          <td><?= number_format((int)$h['margin_total']) ?>원</td>
          <td><?= number_format((float)$h['incentive_rate'], 1) ?>%</td>
          <td><b style="color:#9333ea;"><?= number_format((int)$h['incentive_total']) ?></b>원</td>
          <td><?= $h['paid_status'] === 'paid' ? '<span style="color:#16a34a;font-weight:800;">✅ 지급완료</span>' : '<span style="color:#64748b;">미지급</span>' ?></td>
          <td style="font-size:11px;color:#64748b;"><?= h((string)$h['closed_by']) ?> · <?= h(substr((string)$h['closed_at'], 0, 10)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<style>
/* ===== 월간 정산 — 카드 ===== */
.ms-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px;}
.ms-stat{background:#fff;border:1px solid #e5e7eb;border-left:5px solid #2563eb;border-radius:14px;padding:16px 18px 14px;transition:transform .15s,box-shadow .15s;}
.ms-stat:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(15,23,42,.09);}
.ms-stat .k{font-size:13px;font-weight:700;color:#64748b;}
.ms-stat .v{font-size:24px;font-weight:800;color:#0f172e;margin:5px 0 4px;letter-spacing:-.5px;}
.ms-stat .v span{font-size:13px;font-weight:700;color:#94a3b8;margin-left:2px;}
.ms-stat .s{font-size:11.5px;font-weight:600;color:#94a3b8;}
.ms-stat.on{border-left-color:#7c3aed;}
.ms-stat.on .v{color:#7c3aed;}
.ms-stat.wh{border-left-color:#ea580c;}
.ms-stat.wh .v{color:#c2410c;}
.ms-stat.profit{border-left-color:#16a34a;background:linear-gradient(135deg,#f0fdf4 0%,#ecfdf5 100%);}
.ms-stat.profit .v{color:#15803d;}
.ms-stat.hero{border-left-color:#0f172e;background:linear-gradient(135deg,#f8fafc 0%,#eef2ff 100%);}
/* ===== 매출 구분 표 ===== */
.ms-cat-table td{white-space:nowrap;}
.ms-cat-table .ms-sub-total td{background:#f8fafc;font-weight:700;}
.ms-cat-table .ms-grand-total td{background:#eff6ff;font-weight:800;}
/* ===== 카드수수료 / 마진 박스 ===== */
.ms-fee-box{border:1.5px solid #fde68a;background:linear-gradient(135deg,#fffbeb 0%,#fefce8 100%);border-radius:14px;padding:16px 18px;}
.ms-margin-box{border:1.5px solid #bbf7d0;background:linear-gradient(135deg,#f0fdf4 0%,#f7fee7 100%);border-radius:14px;padding:16px 18px;}
.ms-fee-head{font-size:14.5px;font-weight:800;color:#b45309;margin-bottom:10px;}
.ms-fee-rows .row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px dashed rgba(148,163,184,.35);font-size:13px;color:#475569;}
.ms-fee-rows .row:last-child{border-bottom:none;}
.ms-fee-rows .row b{font-weight:800;color:#0f172e;}
.ms-fee-rows .row.total{padding-top:10px;margin-top:4px;border-top:1.5px solid rgba(148,163,184,.4);border-bottom:none;}
@media(max-width:1100px){.ms-stats{grid-template-columns:repeat(2,1fr);}}
@media(max-width:768px){.ms-stats{grid-template-columns:1fr;}.admin-table-wrap table{min-width:880px}}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
