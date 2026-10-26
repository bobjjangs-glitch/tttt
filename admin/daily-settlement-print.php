<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('daily-settlement');
ensure_work_log_tables();

/* 일정산표 인쇄 — 별도 A4 인쇄 레이아웃 */
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$calc = compute_day_settlement($date);
$settlement = get_settlement($date);
$jobLabels = work_job_type_labels();
$fmt = fn(int $n): string => number_format($n);
$weekKor = ['일','월','화','수','목','금','토'];
$dateKor = date('Y년 n월 j일', strtotime($date)) . ' (' . $weekKor[(int)date('w', strtotime($date))] . ')';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>일정산표 — <?= h($date) ?></title>
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

<h1>일 정 산 표</h1>
<div class="sub"><?= h(defined('SITE_NAME') ? SITE_NAME : 'TIRETOP') ?> · <?= h($dateKor) ?></div>

<table class="head-table">
  <tr>
    <td class="label">마감 상태</td>
    <td><?= $settlement ? ($settlement['reopened_at'] === null ? '마감 완료' : '해제됨(재집계)') : '마감 전 — 라이브 집계' ?></td>
    <td class="label">마감자</td>
    <td><?= h((string)($settlement['closed_by'] ?? '-')) ?></td>
    <td class="label">마감시각</td>
    <td><?= h(substr((string)($settlement['closed_at'] ?? '-'), 0, 16)) ?></td>
  </tr>
</table>

<table>
  <thead><tr><th>매출 구분</th><th>건수</th><th>카드</th><th>현금·계좌</th><th>외상</th><th>현금영수증</th><th>세금계산서</th><th>합계</th></tr></thead>
  <tbody>
    <?php $cats = $calc['cats']; ?>
    <tr><td>현장 타이어판매 (로드손님)</td><td class="num"><?= (int)$cats['tire']['count'] ?></td><td class="num"><?= $fmt((int)$cats['tire']['card']) ?></td><td class="num"><?= $fmt((int)$cats['tire']['cash'] + (int)$cats['tire']['transfer']) ?></td><td class="num"><?= $fmt((int)$cats['tire']['unpaid']) ?></td><td class="num"><?= (int)$cats['tire']['receipt'] ?>건</td><td class="num"><?= (int)$cats['tire']['taxinv'] ?>건</td><td class="num"><?= $fmt((int)$cats['tire']['total']) ?>원</td></tr>
    <tr><td>정비매출</td><td class="num"><?= (int)$cats['repair']['count'] ?></td><td class="num"><?= $fmt((int)$cats['repair']['card']) ?></td><td class="num"><?= $fmt((int)$cats['repair']['cash'] + (int)$cats['repair']['transfer']) ?></td><td class="num"><?= $fmt((int)$cats['repair']['unpaid']) ?></td><td class="num"><?= (int)$cats['repair']['receipt'] ?>건</td><td class="num"><?= (int)$cats['repair']['taxinv'] ?>건</td><td class="num"><?= $fmt((int)$cats['repair']['total']) ?>원</td></tr>
    <tr><td>기타작업 매출</td><td class="num"><?= (int)$cats['etc']['count'] ?></td><td class="num"><?= $fmt((int)$cats['etc']['card']) ?></td><td class="num"><?= $fmt((int)$cats['etc']['cash'] + (int)$cats['etc']['transfer']) ?></td><td class="num"><?= $fmt((int)$cats['etc']['unpaid']) ?></td><td class="num"><?= (int)$cats['etc']['receipt'] ?>건</td><td class="num"><?= (int)$cats['etc']['taxinv'] ?>건</td><td class="num"><?= $fmt((int)$cats['etc']['total']) ?>원</td></tr>
    <tr style="background:#f8fafc;"><td><b>소매매출 합계 (현장 로드손님)</b></td><td class="num"><?= (int)$cats['retail_total']['count'] ?></td><td class="num"><b><?= $fmt((int)$cats['retail_total']['card']) ?></b></td><td class="num"><b><?= $fmt((int)$cats['retail_total']['cash'] + (int)$cats['retail_total']['transfer']) ?></b></td><td class="num"><b><?= $fmt((int)$cats['retail_total']['unpaid']) ?></b></td><td class="num"><b><?= (int)$cats['retail_total']['receipt'] ?>건</b></td><td class="num"><b><?= (int)$cats['retail_total']['taxinv'] ?>건</b></td><td class="num"><b><?= $fmt((int)$cats['retail_total']['total']) ?>원</b></td></tr>
    <tr><td>소매 예약장착 (주문→예약 방문)</td><td class="num"><?= (int)$cats['online_resv']['count'] ?></td><td class="num"><?= $fmt((int)$cats['online_resv']['card']) ?></td><td class="num"><?= $fmt((int)$cats['online_resv']['cash'] + (int)$cats['online_resv']['transfer']) ?></td><td class="num"><?= $fmt((int)$cats['online_resv']['unpaid']) ?></td><td class="num"><?= (int)$cats['online_resv']['receipt'] ?>건</td><td class="num"><?= (int)$cats['online_resv']['taxinv'] ?>건</td><td class="num"><?= $fmt((int)$cats['online_resv']['total']) ?>원</td></tr>
    <tr><td>소매 택배 (온라인 소매주문)</td><td class="num"><?= (int)$cats['online_retail']['count'] ?></td><td class="num"><?= $fmt((int)$cats['online_retail']['card']) ?></td><td class="num"><?= $fmt((int)$cats['online_retail']['transfer']) ?> (무통장)</td><td class="num">-</td><td class="num"><?= (int)$cats['online_retail']['receipt'] ?>건</td><td class="num"><?= (int)$cats['online_retail']['taxinv'] ?>건</td><td class="num"><?= $fmt((int)$cats['online_retail']['total']) ?>원</td></tr>
    <tr style="background:#faf5ff;"><td><b>온라인매출 합계 (예약장착+소매택배)</b></td><td class="num"><?= (int)$cats['online_total']['count'] ?></td><td class="num"><b><?= $fmt((int)$cats['online_total']['card']) ?></b></td><td class="num"><b><?= $fmt((int)$cats['online_total']['cash'] + (int)$cats['online_total']['transfer']) ?></b></td><td class="num"><b><?= $fmt((int)$cats['online_total']['unpaid']) ?></b></td><td class="num"><b><?= (int)$cats['online_total']['receipt'] ?>건</b></td><td class="num"><b><?= (int)$cats['online_total']['taxinv'] ?>건</b></td><td class="num"><b><?= $fmt($calc['online_group_sales']) ?>원</b></td></tr>
    <tr><td>사업자 택배 (온라인 도매주문)</td><td class="num"><?= (int)$cats['online_biz']['count'] ?></td><td class="num"><?= $fmt((int)$cats['online_biz']['card']) ?></td><td class="num"><?= $fmt((int)$cats['online_biz']['transfer']) ?> (무통장)</td><td class="num">-</td><td class="num"><?= (int)$cats['online_biz']['receipt'] ?>건</td><td class="num"><?= (int)$cats['online_biz']['taxinv'] ?>건</td><td class="num"><?= $fmt((int)$cats['online_biz']['total']) ?>원</td></tr>
    <tr><td>일반 도매 (로컬 거래처 · 전화주문)</td><td class="num"><?= (int)$cats['wholesale_local']['count'] ?></td><td class="num"><?= $fmt((int)$cats['wholesale_local']['card']) ?></td><td class="num"><?= $fmt((int)$cats['wholesale_local']['cash'] + (int)$cats['wholesale_local']['transfer']) ?></td><td class="num"><?= $fmt((int)$cats['wholesale_local']['unpaid']) ?></td><td class="num">-</td><td class="num"><?= (int)$cats['wholesale_local']['taxinv'] ?>건</td><td class="num"><?= $fmt((int)$cats['wholesale_local']['total']) ?>원</td></tr>
    <tr style="background:#fff7ed;"><td><b>도매매출 합계 (온라인 도매+일반 도매)</b></td><td class="num"><?= (int)$cats['wholesale_total']['count'] ?></td><td class="num"><b><?= $fmt((int)$cats['wholesale_total']['card']) ?></b></td><td class="num"><b><?= $fmt((int)$cats['wholesale_total']['cash'] + (int)$cats['wholesale_total']['transfer']) ?></b></td><td class="num"><b><?= $fmt((int)$cats['wholesale_total']['unpaid']) ?></b></td><td class="num"><b>-</b></td><td class="num"><b><?= (int)$cats['wholesale_total']['taxinv'] ?>건</b></td><td class="num"><b><?= $fmt($calc['wholesale_group_sales']) ?>원</b></td></tr>
    <tr class="total"><td>종합매출 (전체)</td><td class="num"><?= (int)$calc['work_count'] + (int)$calc['online_count'] + (int)$calc['local_wholesale_count'] ?></td><td class="num"><?= $fmt($calc['card_total_sales']) ?></td><td class="num"><?= $fmt($calc['cash_sales'] + $calc['transfer_sales'] + (int)$calc['online_bank_sales'] + (int)$calc['wholesale_cash_sales']) ?></td><td class="num"><?= $fmt($calc['unpaid_sales'] + (int)$calc['wholesale_unpaid_sales']) ?></td><td class="num"><?= (int)$calc['receipt_count'] ?>건</td><td class="num"><?= (int)$calc['taxinv_count'] ?>건</td><td class="num"><?= $fmt($calc['total_sales']) ?>원</td></tr>
  </tbody>
</table>

<table>
  <thead><tr><th>카드 수수료 / 마진</th><th>금액</th></tr></thead>
  <tbody>
    <tr><td>현장·일반도매 카드 결제매출 (수수료율 <?= number_format($calc['card_fee_rate_site'], 1) ?>%)</td><td class="num"><?= $fmt($calc['card_sales'] + (int)$calc['wholesale_card_sales']) ?>원 → -<?= $fmt((int)$calc['card_fee_site_amount']) ?>원</td></tr>
    <tr><td>온라인 카드 결제매출 (수수료율 <?= number_format($calc['card_fee_rate_online'], 1) ?>%)</td><td class="num"><?= $fmt((int)$calc['online_card_sales']) ?>원 → -<?= $fmt((int)$calc['card_fee_online_amount']) ?>원</td></tr>
    <tr><td>카드 결제매출 (현장·일반도매+온라인)</td><td class="num"><?= $fmt($calc['card_total_sales']) ?>원</td></tr>
    <tr><td>카드 수수료 합계</td><td class="num">-<?= $fmt($calc['card_fee_amount']) ?>원</td></tr>
    <tr><td>카드 순매출 (수수료 제외)</td><td class="num"><?= $fmt($calc['card_net_sales']) ?>원</td></tr>
    <tr><td>공장도가 합계</td><td class="num"><?= $fmt($calc['cost_factory_total']) ?>원</td></tr>
    <tr><td>매입단가 합계 (현장 작업)</td><td class="num"><?= $fmt($calc['cost_supply_total']) ?>원</td></tr>
    <tr><td>온라인 주문 원가 (매입단가)</td><td class="num"><?= $fmt((int)$calc['online_cost_total']) ?>원</td></tr>
    <tr><td>외상 금액<?= (int)$calc['unpaid_count'] > 0 ? ' (' . (int)$calc['unpaid_count'] . '건)' : '' ?></td><td class="num"><?= $fmt($calc['unpaid_sales']) ?>원</td></tr>
    <tr><td>소매마진 (소매매출 − 매입단가)</td><td class="num"><?= $fmt($calc['retail_margin']) ?>원</td></tr>
    <tr><td>온라인마진 (예약장착+소매택배)</td><td class="num"><?= $fmt($calc['online_margin_group']) ?>원</td></tr>
    <tr><td>도매마진 (온라인 도매+일반 도매)</td><td class="num"><?= $fmt($calc['wholesale_margin_total']) ?>원</td></tr>
    <tr class="total"><td>전체 수익 (토탈 마진)</td><td class="num"><?= $fmt($calc['margin_total']) ?>원<?= $calc['total_sales'] > 0 ? ' (' . round($calc['margin_total'] / $calc['total_sales'] * 100, 1) . '%)' : '' ?></td></tr>
  </tbody>
</table>

<table>
  <thead><tr><th>작업자</th><th>건수</th><th>타이어</th><th>정비</th><th>기타</th><th>카드</th><th>현금·계좌</th><th>마진</th><th>작업금액</th></tr></thead>
  <tbody>
    <?php if (empty($calc['workers'])): ?>
    <tr><td colspan="9">작업일지 없음</td></tr>
    <?php else: foreach ($calc['workers'] as $wrow): ?>
    <tr>
      <td><?= h((string)$wrow['name']) ?></td>
      <td class="num"><?= (int)$wrow['count'] ?></td>
      <td class="num"><?= $fmt((int)$wrow['tire']) ?></td>
      <td class="num"><?= $fmt((int)$wrow['repair']) ?></td>
      <td class="num"><?= $fmt((int)$wrow['etc']) ?></td>
      <td class="num"><?= $fmt((int)$wrow['card']) ?></td>
      <td class="num"><?= $fmt((int)$wrow['cash'] + (int)$wrow['transfer']) ?></td>
      <td class="num"><?= $fmt((int)$wrow['margin']) ?></td>
      <td class="num"><?= $fmt((int)$wrow['amount']) ?>원</td>
    </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<table>
  <thead><tr><th>시각</th><th>구분</th><th>작업내용</th><th>작업자</th><th>고객/차량</th><th>금액</th><th>결제</th><th>공장도/매입×수량</th></tr></thead>
  <tbody>
    <?php $payLabels = work_pay_method_labels(); foreach ($calc['logs'] as $log): $logPay = (string)($log['pay_method'] ?? '') ?: ''; ?>
    <tr>
      <td><?= h(substr((string)$log['work_time'], 0, 5)) ?></td>
      <td><?= $log['source'] === 'reservation' ? '온라인예약' : '현장로드' ?>/<?= h($jobLabels[$log['job_type']] ?? '') ?></td>
      <td><?= h((string)$log['work_content']) ?><?= $log['res_no_snap'] ? ' (' . h($log['res_no_snap']) . ')' : '' ?></td>
      <td><?= h(worker_name((int)$log['worker_id']) ?: '미지정') ?></td>
      <td><?= h(($log['customer_name'] ?: '-') . ($log['car_info'] ? ' / ' . $log['car_info'] : '')) ?></td>
      <td class="num"><?= $fmt((int)$log['work_amount']) ?></td>
      <td><?= $logPay !== '' && isset($payLabels[$logPay]) ? str_replace('결제', '', $payLabels[$logPay]) . ' ' . $fmt((int)($log['pay_amount'] ?? 0) ?: (int)$log['work_amount']) : '외상' ?></td>
      <td class="num"><?= $log['job_type'] === 'tire' && ((int)$log['cost_factory'] || (int)$log['cost_supply']) ? $fmt((int)$log['cost_factory']) . '/' . $fmt((int)$log['cost_supply']) . '×' . (int)$log['qty'] : '-' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($calc['logs'])): ?><tr><td colspan="8">작업일지 없음</td></tr><?php endif; ?>
  </tbody>
</table>

</body>
</html>
