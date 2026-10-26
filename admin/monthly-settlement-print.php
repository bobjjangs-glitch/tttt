<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('monthly-settlement');
ensure_work_log_tables();
ensure_monthly_settlement_table();

/* 월 정산표(인센티브 지급대장) 인쇄 — 별도 A4 인쇄 레이아웃 */
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');

$calc = compute_month_settlement($month);
$settlement = get_month_settlement($month);
$isClosed = $settlement && $settlement['reopened_at'] === null;
$isPaid = $settlement && $settlement['paid_status'] === 'paid';

/* 마감 스냅샷 우선 */
if ($settlement && $isClosed) {
    $calc['site_sales'] = (int)$settlement['site_sales'];
    $calc['online_sales'] = (int)$settlement['online_sales'];
    $calc['total_sales'] = (int)$settlement['total_sales'];
    $calc['tire_sales'] = (int)$settlement['tire_sales'];
    $calc['repair_sales'] = (int)$settlement['repair_sales'];
    $calc['etc_sales'] = (int)$settlement['etc_sales'];
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
    $calc['online_channel_sales'] = (int)($settlement['online_channel_sales'] ?? 0)
        ?: ((int)$calc['resv_sales'] + (int)$calc['online_retail_sales']);
    $calc['retail_margin'] = (int)($settlement['retail_margin'] ?? 0);
    $calc['online_margin'] = (int)($settlement['online_margin'] ?? 0);
    $calc['wholesale_margin'] = (int)($settlement['wholesale_margin'] ?? 0);
    $calc['card_total_sales'] = (int)($settlement['card_total_sales'] ?? 0);
    $calc['card_fee_rate'] = (float)($settlement['card_fee_rate'] ?? 0);
    $calc['card_fee_amount'] = (int)($settlement['card_fee_amount'] ?? 0);
    $calc['card_net_sales'] = (int)($settlement['card_net_sales'] ?? $calc['card_total_sales']);
    /* [v42] 현장/온라인 요율 스냅샷 */
    $calc['card_fee_rate_site'] = (float)($settlement['card_fee_rate_site'] ?? 0) ?: (float)$calc['card_fee_rate'];
    $calc['card_fee_rate_online'] = (float)($settlement['card_fee_rate_online'] ?? 0) ?: (float)$calc['card_fee_rate'];
    $calc['card_fee_site_amount'] = (int)round($calc['card_sales'] * $calc['card_fee_rate_site'] / 100);
    $calc['card_fee_online_amount'] = (int)round(max(0, $calc['card_total_sales'] - $calc['card_sales']) * $calc['card_fee_rate_online'] / 100);
    $calc['online_cost_total'] = (int)($settlement['online_cost_total'] ?? 0);
    $calc['reservation_count'] = (int)$settlement['reservation_count'];
    $calc['walkin_count'] = (int)$settlement['walkin_count'];
    $calc['work_count'] = (int)$settlement['work_count'];
    $calc['incentive_rate'] = (float)$settlement['incentive_rate'];
    $calc['incentive_total'] = (int)$settlement['incentive_total'];
    $calc['workers'] = json_decode((string)$settlement['worker_stats'], true) ?: [];
    uasort($calc['workers'], fn($a, $b) => $b['amount'] <=> $a['amount']);
}

$fmt = fn(int $n): string => number_format($n);
$monthKor = date('Y년 n월', strtotime($month . '-01'));
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>월 정산표 · 인센티브 지급대장 — <?= h($month) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
<style>
  body { font-family:'Pretendard Variable', Pretendard, sans-serif; color:#111827; padding:28px; max-width:860px; margin:0 auto; }
  h1 { font-size:22px; text-align:center; margin-bottom:2px; }
  .sub { text-align:center; color:#6b7280; font-size:13px; margin-bottom:18px; }
  table { width:100%; border-collapse:collapse; margin-bottom:16px; }
  th, td { border:1px solid #d1d5db; padding:8px 10px; font-size:13px; }
  th { background:#f3f4f6; }
  td.num { text-align:right; font-weight:700; }
  .head-table td { border:none; padding:2px 4px; font-size:13px; }
  .head-table { margin-bottom:10px; }
  .head-table .label { color:#6b7280; }
  .total td { background:#eff6ff; font-weight:800; }
  .print-btn { display:block; margin:0 0 14px; }
  .noscreen { display:block; }
  @media print {
    .print-btn, .noscreen { display:none; }
    body { padding:0; }
  }
</style>
</head>
<body>
<button class="print-btn noscreen" onclick="window.print()">🖨️ 인쇄</button>

<h1>월 정산표 · 인센티브 지급대장</h1>
<div class="sub"><?= h(defined('SITE_NAME') ? SITE_NAME : 'TIRETOP') ?> · <?= h($monthKor) ?></div>

<table class="head-table">
  <tr>
    <td class="label">마감 상태</td>
    <td><?= $settlement ? ($settlement['reopened_at'] === null ? '마감 완료' : '해제됨') : '마감 전 — 라이브 집계' ?></td>
    <td class="label">지급 확정</td>
    <td><?= $isPaid ? '지급 완료 (' . h(date('Y.m.d', strtotime($settlement['paid_at']))) . ' · ' . h((string)$settlement['paid_by']) . ')' : '미확정' ?></td>
    <td class="label">마감자/시각</td>
    <td><?= h((string)($settlement['closed_by'] ?? '-')) ?> / <?= h(substr((string)($settlement['closed_at'] ?? '-'), 0, 16)) ?></td>
  </tr>
</table>

<table>
  <thead><tr><th>구분</th><th>금액</th><th>비고</th></tr></thead>
  <tbody>
    <tr><td>현장 타이어판매 (로드손님)</td><td class="num"><?= $fmt($calc['tire_sales']) ?>원</td><td>소매매출 — 현장 작업</td></tr>
    <tr><td>정비매출</td><td class="num"><?= $fmt($calc['repair_sales']) ?>원</td><td>소매매출 — 현장 작업</td></tr>
    <tr><td>기타작업 매출</td><td class="num"><?= $fmt($calc['etc_sales']) ?>원</td><td>소매매출 — 현장 작업</td></tr>
    <tr class="total"><td>소매매출 합계 (현장 로드손님)</td><td class="num"><?= $fmt($calc['retail_sales']) ?>원</td><td>작업 <?= number_format($calc['walkin_count']) ?>건</td></tr>
    <tr><td>소매 예약장착 (주문→예약 방문)</td><td class="num"><?= $fmt($calc['resv_sales']) ?>원</td><td>온라인매출 — 예약 <?= number_format($calc['reservation_count']) ?>건</td></tr>
    <tr><td>소매 택배 (온라인 소매주문)</td><td class="num"><?= $fmt($calc['online_retail_sales']) ?>원</td><td>온라인매출 — 일반회원 택배</td></tr>
    <tr class="total"><td>온라인매출 합계 (예약장착+소매택배)</td><td class="num"><?= $fmt((int)$calc['resv_sales'] + (int)$calc['online_retail_sales']) ?>원</td><td>현금영수증 <?= number_format((int)$calc['receipt_count']) ?>건 · 세금계산서 <?= number_format((int)$calc['taxinv_count']) ?>건</td></tr>
    <tr><td>사업자 택배 (온라인 도매주문)</td><td class="num"><?= $fmt($calc['wholesale_sales']) ?>원</td><td>도매매출 — 온라인 도매주문</td></tr>
    <tr><td>일반 도매 (로컬 거래처 · 전화주문)</td><td class="num"><?= $fmt((int)$calc['local_wholesale_sales']) ?>원</td><td>도매매출 — 일반 도매 <?= number_format((int)$calc['local_wholesale_count']) ?>건</td></tr>
    <tr class="total"><td>도매매출 합계 (온라인 도매+일반 도매)</td><td class="num"><?= $fmt($calc['wholesale_group_sales']) ?>원</td><td>일반 도매 관리에서 등록</td></tr>
    <tr class="total"><td>종합매출 (전체)</td><td class="num"><?= $fmt($calc['total_sales']) ?>원</td><td>소매 + 온라인 + 도매</td></tr>
    <tr><td>현장·일반도매 카드 결제매출 (<?= number_format($calc['card_fee_rate_site'], 1) ?>%)</td><td class="num"><?= $fmt($calc['card_sales'] + (int)$calc['wholesale_card_sales']) ?>원 → -<?= $fmt((int)$calc['card_fee_site_amount']) ?>원</td><td>현장 수수료율</td></tr>
    <tr><td>온라인 카드 결제매출 (<?= number_format($calc['card_fee_rate_online'], 1) ?>%)</td><td class="num"><?= $fmt((int)$calc['online_card_sales']) ?>원 → -<?= $fmt((int)$calc['card_fee_online_amount']) ?>원</td><td>온라인 수수료율</td></tr>
    <tr><td>카드 수수료 합계</td><td class="num">-<?= $fmt($calc['card_fee_amount']) ?>원</td><td>카드 순매출 <?= $fmt($calc['card_net_sales']) ?>원</td></tr>
    <tr><td>공장도가 합계</td><td class="num"><?= $fmt($calc['cost_factory_total']) ?>원</td><td>×수량 기준</td></tr>
    <tr><td>매입단가 합계 (현장 작업)</td><td class="num"><?= $fmt($calc['cost_supply_total']) ?>원</td><td>×수량 기준</td></tr>
    <tr><td>온라인 주문 원가</td><td class="num"><?= $fmt((int)$calc['online_cost_total']) ?>원</td><td>주문 상품 매입단가</td></tr>
    <tr><td>외상 금액</td><td class="num"><?= $fmt($calc['unpaid_sales']) ?>원</td><td><?= (int)$calc['unpaid_count'] ?>건</td></tr>
    <tr><td>소매마진</td><td class="num"><?= $fmt($calc['retail_margin']) ?>원</td><td>소매매출 − 매입단가</td></tr>
    <tr><td>온라인마진</td><td class="num"><?= $fmt((int)$calc['online_margin']) ?>원</td><td>예약장착+소매택배 마진</td></tr>
    <tr><td>도매마진</td><td class="num"><?= $fmt($calc['wholesale_margin_total']) ?>원</td><td>온라인 도매+일반 도매 마진</td></tr>
    <tr class="total"><td>전체 수익 (토탈 마진)</td><td class="num"><?= $fmt($calc['margin_total']) ?>원</td><td>수익률 <?= $calc['total_sales'] > 0 ? round($calc['margin_total'] / $calc['total_sales'] * 100, 1) : 0 ?>% · 인센티브 산정 기준</td></tr>
    <tr class="total"><td>인센티브 총액 (전체 수익 × <?= number_format($calc['incentive_rate'], 1) ?>%)</td><td class="num"><?= $fmt($calc['incentive_total']) ?>원</td><td>작업 매출 기여도로 배분</td></tr>
  </tbody>
</table>

<table>
  <thead>
    <tr><th>작업자</th><th>작업 건수</th><th>작업 매출</th><th>기여도</th><th>타이어 / 정비 / 기타</th><th>카드 / 현금·계좌 / 외상</th><th>인센티브</th></tr>
  </thead>
  <tbody>
    <?php if (empty($calc['workers'])): ?>
    <tr><td colspan="7" style="text-align:center;color:#9ca3af;">이 달에 기록된 작업일지가 없습니다.</td></tr>
    <?php else: foreach ($calc['workers'] as $w): ?>
    <tr>
      <td><?= h($w['name']) ?></td>
      <td class="num"><?= number_format($w['count']) ?>건</td>
      <td class="num"><?= $fmt($w['amount']) ?>원</td>
      <td class="num"><?= number_format((float)$w['share'], 1) ?>%</td>
      <td class="num" style="font-weight:400;"><?= $fmt($w['tire']) ?> / <?= $fmt($w['repair']) ?> / <?= $fmt($w['etc']) ?></td>
      <td class="num" style="font-weight:400;"><?= $fmt((int)$w['card']) ?> / <?= $fmt((int)$w['cash'] + (int)$w['transfer']) ?> / <?= $fmt((int)$w['unpaid']) ?></td>
      <td class="num"><?= $fmt($w['incentive']) ?>원</td>
    </tr>
    <?php endforeach; ?>
    <tr class="total">
      <td>합계</td>
      <td><?= number_format($calc['work_count']) ?>건</td>
      <td><?= $fmt($calc['site_sales']) ?>원</td>
      <td>100%</td>
      <td style="font-weight:400;"><?= $fmt($calc['tire_sales']) ?> / <?= $fmt($calc['repair_sales']) ?> / <?= $fmt($calc['etc_sales']) ?></td>
      <td style="font-weight:400;"><?= $fmt((int)$calc['card_sales']) ?> / <?= $fmt((int)$calc['cash_sales'] + (int)$calc['transfer_sales']) ?> / <?= $fmt((int)$calc['unpaid_sales']) ?></td>
      <td><?= $fmt($calc['incentive_total']) ?>원</td>
    </tr>
    <?php endif; ?>
  </tbody>
</table>

<div style="display:flex;justify-content:space-between;margin-top:48px;font-size:13px;">
  <div>작업자 서명: ______________</div>
  <div>대표자 서명: ______________</div>
</div>

</body>
</html>
