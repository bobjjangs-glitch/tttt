<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('daily-settlement');
ensure_work_log_tables();

$pdo = Database::connection();
$pageTitle = '일정산 마감';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');

/* ===== 마감/해제/카드수수료율 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/daily-settlement.php');
    }
    $date = $_POST['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        flash('admin_error', '날짜가 올바르지 않습니다.');
        redirect('/admin/daily-settlement.php');
    }
    $action = $_POST['action'] ?? '';
    $adminId = (int)AdminAuth::currentAdminId();
    $adminName = AdminAuth::currentAdminName();

    if ($action === 'close') {
        $result = close_day_settlement($date, $adminId, $adminName);
        if ($result['ok']) {
            $profitRate = $result['calc']['total_sales'] > 0 ? round($result['calc']['margin_total'] / $result['calc']['total_sales'] * 100, 1) : 0;
            flash('admin_success', "{$date} 일정산 마감 완료 — 종합매출 " . number_format($result['calc']['total_sales']) . '원 · 전체 수익(마진) ' . number_format($result['calc']['margin_total']) . "원 ({$profitRate}%)");
        } else {
            flash('admin_error', $result['msg'] ?? '마감 처리에 실패했습니다.');
        }
    } elseif ($action === 'reopen') {
        $result = reopen_day_settlement($date, $adminId);
        flash($result['ok'] ? 'admin_success' : 'admin_error',
            $result['ok'] ? "{$date} 마감을 해제했습니다. 작업일지를 다시 수정할 수 있습니다." : ($result['msg'] ?? '해제에 실패했습니다.'));
    }
    redirect('/admin/daily-settlement.php?date=' . $date);
}

/* ===== 조회 날짜 ===== */
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$calc = compute_day_settlement($date);
$settlement = get_settlement($date);
$isClosed = $settlement && $settlement['reopened_at'] === null;
$jobLabels = work_job_type_labels();
$fmt = fn(int $n): string => number_format($n);
$weekdayKo = ['일','월','화','수','목','금','토'][(int)date('w', strtotime($date))] ?? '';
$cats = $calc['cats'];

/* 마감 이력 (최근 30일) */
$history = [];
try {
    $stmt = $pdo->prepare(
        "SELECT * FROM tt_daily_settlements
         WHERE settle_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         ORDER BY settle_date DESC LIMIT 30");
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">💰 일정산 마감</h2>
      <p class="admin-list-sub">하루 매출을 <b>소매(현장 로드손님)</b> / <b>온라인(소매 예약장착 · 소매 택배)</b> / <b>도매(온라인 도매주문 · 일반 도매=로컬 거래처)</b> 대분류로 정리하고, 마감 시 스냅샷으로 고정 + 작업일지·도매매출을 잠급니다.</p>
    </div>
    <div style="display:flex;gap:8px;">
      <a class="btn" href="?date=<?= h($yesterday) ?>">◀ 어제</a>
      <a class="btn" href="?date=">오늘</a>
      <a class="btn" target="_blank" href="daily-settlement-print.php?date=<?= h($date) ?>">🖨️ 정산표 인쇄</a>
    </div>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <!-- 날짜 선택 + 마감/해제 -->
  <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
    <form method="get" style="display:flex;gap:10px;align-items:center;">
      <label style="font-weight:700;">정산 날짜</label>
      <input type="date" name="date" value="<?= h($date) ?>" style="padding:8px 10px;border:1px solid #ddd;border-radius:8px;">
      <button class="btn btn-primary" type="submit">조회</button>
    </form>
    <div style="margin-left:auto;display:flex;gap:10px;align-items:center;">
      <?php if ($isClosed): ?>
        <span class="badge badge-success">🔒 <?= h($date) ?> 마감 완료 — <?= h((string)($settlement['closed_by'] ?? '')) ?> · <?= h(substr((string)($settlement['closed_at'] ?? ''), 0, 16)) ?></span>
        <form method="post" onsubmit="return confirm('마감을 해제하면 이 날짜의 작업일지를 다시 수정할 수 있습니다. 해제할까요?');">
          <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
          <input type="hidden" name="action" value="reopen">
          <input type="hidden" name="date" value="<?= h($date) ?>">
          <button type="submit" class="btn btn-danger">🔓 마감 해제</button>
        </form>
      <?php else: ?>
        <form method="post" onsubmit="return confirm('<?= h($date) ?> 일정산을 마감할까요? 마감 후 이 날짜의 작업일지는 수정/삭제할 수 없습니다.');">
          <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
          <input type="hidden" name="action" value="close">
          <input type="hidden" name="date" value="<?= h($date) ?>">
          <button type="submit" class="btn btn-primary" style="font-size:16px;padding:12px 22px;">🔒 일정산 마감 실행</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$isClosed && $calc['working_count'] > 0): ?>
  <div class="alert alert-warning">🔧 아직 <b><?= (int)$calc['working_count'] ?>건</b>이 '작업중' 상태입니다. 마감하면 그대로 집계되니 확인 후 진행하세요.</div>
  <?php endif; ?>

  <!-- ===== 대분류 카드: 소매 / 온라인 / 도매 / 종합 / 전체 수익 [v43] ===== -->
  <div class="ds-stats">
    <div class="ds-stat">
      <div class="ds-ico">🛞</div>
      <div class="ds-body">
        <div class="ds-k">소매매출 <span class="ds-tag">현장 · 로드손님 전용</span></div>
        <div class="ds-v"><?= $fmt($calc['retail_sales']) ?><span class="ds-won">원</span></div>
        <div class="ds-sub">타이어 <?= $fmt($calc['tire_sales']) ?> · 정비 <?= $fmt($calc['repair_sales']) ?> · 기타 <?= $fmt($calc['etc_sales']) ?> / <?= (int)$cats['retail_total']['count'] ?>건</div>
      </div>
    </div>
    <div class="ds-stat ds-violet">
      <div class="ds-ico">🛒</div>
      <div class="ds-body">
        <div class="ds-k">온라인매출 <span class="ds-tag">예약장착 + 소매 택배</span></div>
        <div class="ds-v"><?= $fmt($calc['online_group_sales']) ?><span class="ds-won">원</span></div>
        <div class="ds-sub">소매 예약장착 <?= $fmt($calc['resv_sales']) ?> · 소매 택배 <?= $fmt($calc['online_retail_sales']) ?> / <?= (int)$cats['online_total']['count'] ?>건</div>
      </div>
    </div>
    <div class="ds-stat ds-wholesale">
      <div class="ds-ico">📦</div>
      <div class="ds-body">
        <div class="ds-k">도매매출 <span class="ds-tag">온라인 도매 + 일반 도매</span></div>
        <div class="ds-v"><?= $fmt($calc['wholesale_group_sales']) ?><span class="ds-won">원</span></div>
        <div class="ds-sub">온라인 도매주문 <?= $fmt($calc['wholesale_sales']) ?> · 일반 도매(로컬 거래처) <?= $fmt($calc['local_wholesale_sales']) ?> / <?= (int)$cats['wholesale_total']['count'] ?>건</div>
      </div>
    </div>
    <div class="ds-stat ds-hero">
      <div class="ds-ico">📊</div>
      <div class="ds-body">
        <div class="ds-k">종합매출 (전체)</div>
        <div class="ds-v"><?= $fmt($calc['total_sales']) ?><span class="ds-won">원</span></div>
        <div class="ds-sub"><?= h($date) ?><?= $weekdayKo ? ' (' . $weekdayKo . ')' : '' ?> · 소매 + 온라인 + 도매</div>
      </div>
    </div>
    <div class="ds-stat ds-profit">
      <div class="ds-ico">💰</div>
      <div class="ds-body">
        <div class="ds-k">전체 수익 (마진)</div>
        <div class="ds-v"><?= $fmt($calc['margin_total']) ?><span class="ds-won">원</span></div>
        <div class="ds-sub">수익률 <b><?= $calc['total_sales'] > 0 ? round($calc['margin_total'] / $calc['total_sales'] * 100, 1) : 0 ?>%</b> (종합매출 대비)</div>
      </div>
    </div>
  </div>

  <!-- ===== 매출 구분 상세표 [v42 — 건수·금액·현금영수증·세금계산서] ===== -->
  <h3 class="admin-list-title" style="margin:22px 0 10px;">🧾 매출 구분</h3>
  <div class="admin-table-wrap" style="overflow-x:auto;">
  <table class="admin-table ds-cat-table">
    <thead><tr><th>구분</th><th>건수</th><th>💳 카드</th><th>💵 현금·계좌</th><th>⏳ 외상</th><th>🧾 현금영수증</th><th>📄 세금계산서</th><th>합계</th></tr></thead>
    <tbody>
      <tr><td>🛞 현장 타이어판매 (로드손님)</td><td><?= (int)$cats['tire']['count'] ?>건</td><td><?= $fmt((int)$cats['tire']['card']) ?>원</td><td><?= $fmt((int)$cats['tire']['cash'] + (int)$cats['tire']['transfer']) ?>원</td><td><?= $fmt((int)$cats['tire']['unpaid']) ?>원</td><td><?= (int)$cats['tire']['receipt'] ?>건</td><td><?= (int)$cats['tire']['taxinv'] ?>건</td><td><b><?= $fmt((int)$cats['tire']['total']) ?></b>원</td></tr>
      <tr><td>🔧 정비매출</td><td><?= (int)$cats['repair']['count'] ?>건</td><td><?= $fmt((int)$cats['repair']['card']) ?>원</td><td><?= $fmt((int)$cats['repair']['cash'] + (int)$cats['repair']['transfer']) ?>원</td><td><?= $fmt((int)$cats['repair']['unpaid']) ?>원</td><td><?= (int)$cats['repair']['receipt'] ?>건</td><td><?= (int)$cats['repair']['taxinv'] ?>건</td><td><b><?= $fmt((int)$cats['repair']['total']) ?></b>원</td></tr>
      <tr><td>📄 기타작업 매출</td><td><?= (int)$cats['etc']['count'] ?>건</td><td><?= $fmt((int)$cats['etc']['card']) ?>원</td><td><?= $fmt((int)$cats['etc']['cash'] + (int)$cats['etc']['transfer']) ?>원</td><td><?= $fmt((int)$cats['etc']['unpaid']) ?>원</td><td><?= (int)$cats['etc']['receipt'] ?>건</td><td><?= (int)$cats['etc']['taxinv'] ?>건</td><td><b><?= $fmt((int)$cats['etc']['total']) ?></b>원</td></tr>
      <tr class="ds-sub-total"><td>소매매출 합계 (현장 로드손님)</td><td><?= (int)$cats['retail_total']['count'] ?>건</td><td><b><?= $fmt((int)$cats['retail_total']['card']) ?></b>원</td><td><b><?= $fmt((int)$cats['retail_total']['cash'] + (int)$cats['retail_total']['transfer']) ?></b>원</td><td><b><?= $fmt((int)$cats['retail_total']['unpaid']) ?></b>원</td><td><b><?= (int)$cats['retail_total']['receipt'] ?>건</b></td><td><b><?= (int)$cats['retail_total']['taxinv'] ?>건</b></td><td><b style="color:#2563eb;"><?= $fmt((int)$cats['retail_total']['total']) ?></b>원</td></tr>
      <tr><td>🛒 소매 예약장착 (주문→예약 방문)</td><td><?= (int)$cats['online_resv']['count'] ?>건</td><td><?= $fmt((int)$cats['online_resv']['card']) ?>원</td><td><?= $fmt((int)$cats['online_resv']['cash'] + (int)$cats['online_resv']['transfer']) ?>원</td><td><?= $fmt((int)$cats['online_resv']['unpaid']) ?>원</td><td><?= (int)$cats['online_resv']['receipt'] ?>건</td><td><?= (int)$cats['online_resv']['taxinv'] ?>건</td><td><b><?= $fmt((int)$cats['online_resv']['total']) ?></b>원</td></tr>
      <tr><td>📦 소매 택배 (온라인 소매주문)</td><td><?= (int)$cats['online_retail']['count'] ?>건</td><td><?= $fmt((int)$cats['online_retail']['card']) ?>원</td><td class="text-muted"><?= $fmt((int)$cats['online_retail']['transfer']) ?>원 (무통장)</td><td>-</td><td><?= (int)$cats['online_retail']['receipt'] ?>건</td><td><?= (int)$cats['online_retail']['taxinv'] ?>건</td><td><b><?= $fmt((int)$cats['online_retail']['total']) ?></b>원</td></tr>
      <tr class="ds-sub-total"><td>온라인매출 합계 (예약장착+소매택배)</td><td><?= (int)$cats['online_total']['count'] ?>건</td><td><b><?= $fmt((int)$cats['online_total']['card']) ?></b>원</td><td><b><?= $fmt((int)$cats['online_total']['cash'] + (int)$cats['online_total']['transfer']) ?></b>원</td><td><b><?= $fmt((int)$cats['online_total']['unpaid']) ?></b>원</td><td><b><?= (int)$cats['online_total']['receipt'] ?>건</b></td><td><b><?= (int)$cats['online_total']['taxinv'] ?>건</b></td><td><b style="color:#7c3aed;"><?= $fmt($calc['online_group_sales']) ?></b>원</td></tr>
      <tr><td>🏢 사업자 택배 (온라인 도매주문)</td><td><?= (int)$cats['online_biz']['count'] ?>건</td><td><?= $fmt((int)$cats['online_biz']['card']) ?>원</td><td class="text-muted"><?= $fmt((int)$cats['online_biz']['transfer']) ?>원 (무통장)</td><td>-</td><td><?= (int)$cats['online_biz']['receipt'] ?>건</td><td><?= (int)$cats['online_biz']['taxinv'] ?>건</td><td><b><?= $fmt((int)$cats['online_biz']['total']) ?></b>원</td></tr>
      <tr><td>🏪 일반 도매 (로컬 거래처 · 전화주문)</td><td><?= (int)$cats['wholesale_local']['count'] ?>건</td><td><?= $fmt((int)$cats['wholesale_local']['card']) ?>원</td><td><?= $fmt((int)$cats['wholesale_local']['cash'] + (int)$cats['wholesale_local']['transfer']) ?>원</td><td><?= $fmt((int)$cats['wholesale_local']['unpaid']) ?>원</td><td>-</td><td><?= (int)$cats['wholesale_local']['taxinv'] ?>건</td><td><b><?= $fmt((int)$cats['wholesale_local']['total']) ?></b>원</td></tr>
      <tr class="ds-sub-total"><td>도매매출 합계 (온라인 도매+일반 도매)</td><td><?= (int)$cats['wholesale_total']['count'] ?>건</td><td><b><?= $fmt((int)$cats['wholesale_total']['card']) ?></b>원</td><td><b><?= $fmt((int)$cats['wholesale_total']['cash'] + (int)$cats['wholesale_total']['transfer']) ?></b>원</td><td><b><?= $fmt((int)$cats['wholesale_total']['unpaid']) ?></b>원</td><td><b>-</b></td><td><b><?= (int)$cats['wholesale_total']['taxinv'] ?>건</b></td><td><b style="color:#c2410c;"><?= $fmt($calc['wholesale_group_sales']) ?></b>원</td></tr>
      <tr class="ds-grand-total"><td>종합매출 (전체)</td><td><?= (int)$calc['work_count'] + (int)$calc['online_count'] + (int)$calc['local_wholesale_count'] ?>건</td><td><b><?= $fmt($calc['card_total_sales']) ?></b>원</td><td><b><?= $fmt($calc['cash_sales'] + $calc['transfer_sales'] + (int)$calc['online_bank_sales'] + (int)$calc['wholesale_cash_sales']) ?></b>원</td><td><b><?= $fmt($calc['unpaid_sales'] + (int)$calc['wholesale_unpaid_sales']) ?></b>원</td><td><b><?= (int)$calc['receipt_count'] ?>건</b></td><td><b><?= (int)$calc['taxinv_count'] ?>건</b></td><td><b style="color:#1d4ed8;"><?= $fmt($calc['total_sales']) ?></b>원</td></tr>
    </tbody>
  </table>
  </div>
  <p style="font-size:12.5px;color:#64748b;margin:8px 0 0;">현장 결제는 태블릿 작업일지 카드의 "💰 결제등록"(카드/현금/계좌이체)으로 등록합니다 — 등록 전 작업은 외상으로 표시됩니다. 현금영수증은 현금결제 시 발행 처리된 건, 세금계산서는 발행 완료된 건의 수입니다.</p>

  <!-- ===== 카드 수수료 [v42 — 현장/온라인 요율 분리, 설정은 카드수수료 설정 페이지] ===== -->
  <div class="ds-fee-box">
    <div class="ds-fee-head">💳 카드 수수료 <a class="btn btn-sm" href="<?= BASE_URL ?>/admin/card-fees.php" style="float:right;margin-top:-4px;">⚙️ 수수료율 설정</a></div>
    <div class="ds-fee-grid">
      <div class="ds-fee-cell">
        <div class="fc-k">현장·일반도매 카드 결제매출</div>
        <div class="fc-v"><?= $fmt($calc['card_sales'] + (int)$calc['wholesale_card_sales']) ?>원</div>
        <div class="fc-sub">수수료율 <?= number_format($calc['card_fee_rate_site'], 1) ?>% → -<?= $fmt((int)$calc['card_fee_site_amount']) ?>원</div>
      </div>
      <div class="ds-fee-cell">
        <div class="fc-k">온라인 카드 결제매출</div>
        <div class="fc-v"><?= $fmt((int)$calc['online_card_sales']) ?>원</div>
        <div class="fc-sub">수수료율 <?= number_format($calc['card_fee_rate_online'], 1) ?>% → -<?= $fmt((int)$calc['card_fee_online_amount']) ?>원</div>
      </div>
      <div class="ds-fee-cell">
        <div class="fc-k">카드 수수료 합계</div>
        <div class="fc-v" style="color:#c2410c;">-<?= $fmt($calc['card_fee_amount']) ?>원</div>
        <div class="fc-sub">현장 <?= number_format($calc['card_fee_rate_site'], 1) ?>% + 온라인 <?= number_format($calc['card_fee_rate_online'], 1) ?>%</div>
      </div>
      <div class="ds-fee-cell" style="background:#f0fdf4;">
        <div class="fc-k">카드 순매출 (수수료 제외)</div>
        <div class="fc-v" style="color:#16a34a;"><?= $fmt($calc['card_net_sales']) ?>원</div>
        <div class="fc-sub">수수료 차감 후 실제 입금 예정액</div>
      </div>
    </div>
  </div>

  <!-- ===== 마진 [v43 — 소매/온라인/도매/토탈 + 수익률] ===== -->
  <h3 class="admin-list-title" style="margin:22px 0 10px;">💰 마진 (매출 − 매입단가)</h3>
  <div class="ds-margin-grid">
    <div class="ds-mg-card"><div class="k">소매마진</div><div class="v"><?= $fmt($calc['retail_margin']) ?><span>원</span></div><div class="s">소매매출 <?= $fmt($calc['retail_sales']) ?> − 매입 <?= $fmt($calc['retail_sales'] - $calc['retail_margin']) ?> · 마진율 <b><?= $calc['retail_sales'] > 0 ? round($calc['retail_margin'] / $calc['retail_sales'] * 100, 1) : 0 ?>%</b></div></div>
    <div class="ds-mg-card mg-on"><div class="k">온라인마진 (예약장착+소매택배)</div><div class="v"><?= $fmt($calc['online_margin_group']) ?><span>원</span></div><div class="s">온라인매출 <?= $fmt($calc['online_group_sales']) ?> − 원가 <?= $fmt($calc['online_group_sales'] - $calc['online_margin_group']) ?> · 마진율 <b><?= $calc['online_group_sales'] > 0 ? round($calc['online_margin_group'] / $calc['online_group_sales'] * 100, 1) : 0 ?>%</b></div></div>
    <div class="ds-mg-card mg-wh"><div class="k">도매마진 (온라인 도매+일반 도매)</div><div class="v"><?= $fmt($calc['wholesale_margin_total']) ?><span>원</span></div><div class="s">도매매출 <?= $fmt($calc['wholesale_group_sales']) ?> − 원가 <?= $fmt($calc['wholesale_group_sales'] - $calc['wholesale_margin_total']) ?> · 마진율 <b><?= $calc['wholesale_group_sales'] > 0 ? round($calc['wholesale_margin_total'] / $calc['wholesale_group_sales'] * 100, 1) : 0 ?>%</b></div></div>
    <div class="ds-mg-card mg-total"><div class="k">토탈 마진 (전체 수익)</div><div class="v"><?= $fmt($calc['margin_total']) ?><span>원</span></div><div class="s">종합매출 대비 수익률 <b><?= $calc['total_sales'] > 0 ? round($calc['margin_total'] / $calc['total_sales'] * 100, 1) : 0 ?>%</b></div></div>
  </div>

  <!-- ===== 작업자별 집계 ===== -->
  <h3 class="admin-list-title" style="margin:22px 0 10px;">👷 작업자별 집계 (인센티브 기준)</h3>
  <div class="admin-table-wrap" style="overflow-x:auto;">
  <table class="admin-table">
    <thead><tr><th>작업자</th><th>건수</th><th>작업 매출</th><th>💳 카드</th><th>💵 현금·계좌</th><th>⏳ 외상</th><th>마진</th><th>비중</th></tr></thead>
    <tbody>
      <?php if (empty($calc['workers'])): ?>
      <tr><td colspan="8" class="empty">이 날짜에 작업일지가 없습니다.</td></tr>
      <?php else: foreach ($calc['workers'] as $wrow): ?>
      <tr>
        <td><b><?= h((string)$wrow['name']) ?></b></td>
        <td><?= (int)$wrow['count'] ?>건</td>
        <td><b><?= $fmt((int)$wrow['amount']) ?></b>원</td>
        <td><?= $fmt((int)$wrow['card']) ?>원</td>
        <td><?= $fmt((int)$wrow['cash'] + (int)$wrow['transfer']) ?>원</td>
        <td><?= $fmt((int)$wrow['unpaid']) ?>원</td>
        <td><b style="color:#16a34a;"><?= $fmt((int)$wrow['margin']) ?></b>원</td>
        <td><?= $calc['site_sales'] > 0 ? round($wrow['amount'] / $calc['site_sales'] * 100, 1) : 0 ?>%</td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>

  <!-- ===== 작업일지 원본 ===== -->
  <h3 class="admin-list-title" style="margin:22px 0 10px;">📋 <?= h($date) ?> 작업일지 원본 (<?= count($calc['logs']) ?>건)</h3>
  <div class="admin-table-wrap" style="overflow-x:auto;">
  <table class="admin-table">
    <thead><tr><th>시각</th><th>구분</th><th>작업내용</th><th>작업자</th><th>금액</th><th>결제수단</th><th>공장도/매입×수량</th><th>상태</th></tr></thead>
    <tbody>
      <?php $payLabels = work_pay_method_labels(); ?>
      <?php if (empty($calc['logs'])): ?>
      <tr><td colspan="8" class="empty">작업일지가 없습니다.</td></tr>
      <?php else: foreach ($calc['logs'] as $log): ?>
      <?php $logPay = (string)($log['pay_method'] ?? '') ?: ''; ?>
      <tr>
        <td><?= h(substr((string)$log['work_time'], 0, 5)) ?></td>
        <td><?= $log['source'] === 'reservation' ? '<span class="badge badge-info">🛒 온라인 예약</span>' : '<span class="badge">🚶 현장 로드</span>' ?><br><small class="text-muted"><?= h($jobLabels[$log['job_type']] ?? '') ?></small></td>
        <td style="max-width:360px;"><?= h(mb_strimwidth((string)$log['work_content'], 0, 60, '…')) ?><?php if ($log['res_no_snap']): ?><br><small class="text-muted"><?= h($log['res_no_snap']) ?></small><?php endif; ?></td>
        <td><?= h(worker_name((int)$log['worker_id']) ?: '미지정') ?></td>
        <td><b><?= $fmt((int)$log['work_amount']) ?></b>원</td>
        <td><?php if ($logPay !== '' && isset($payLabels[$logPay])): ?><span class="badge badge-info"><?= $payLabels[$logPay] ?> <?= $fmt((int)($log['pay_amount'] ?? 0) ?: (int)$log['work_amount']) ?>원</span><?php else: ?><span class="badge" style="background:#fff7ed;color:#c2410c;">⏳ 외상</span><?php endif; ?>
          <?php if ((int)($log['cash_receipt_issued'] ?? 0) === 1): ?><br><span class="badge" style="background:#ecfeff;color:#0e7490;">🧾 현금영수증 발행</span><?php endif; ?>
          <?php if (!empty($log['tax_invoice_issued'])): ?><br><span class="badge" style="background:#fef2f2;color:#b91c1c;">📄 세금계산서 발행</span><?php endif; ?>
        </td>
        <td><?= $log['job_type'] === 'tire' && ((int)$log['cost_factory'] || (int)$log['cost_supply']) ? $fmt((int)$log['cost_factory']) . ' / ' . $fmt((int)$log['cost_supply']) . ' ×' . (int)$log['qty'] : '-' ?></td>
        <td><?= $log['settlement_id'] ? '<span class="badge badge-success">🔒 마감</span>' : ($log['status'] === 'working' ? '<span class="badge badge-warning">🔧 작업중</span>' : '✅ 완료') ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- 마감 이력 -->
<div class="admin-card" style="margin-top:16px;">
  <h2 class="admin-list-title">📚 마감 이력 (최근 30일)</h2>
  <div class="admin-table-wrap" style="overflow-x:auto;">
  <table class="admin-table">
    <thead><tr><th>정산일</th><th>소매매출 (현장)</th><th>온라인매출 (예약장착+소매택배)</th><th>도매매출 (온라인 도매+일반)</th><th>종합매출</th><th>전체 수익 (마진)</th><th>카드수수료</th><th>건수</th><th>마감자</th><th>상태</th><th>상세</th></tr></thead>
    <tbody>
      <?php if (empty($history)): ?>
      <tr><td colspan="11" class="empty">마감 이력이 없습니다.</td></tr>
      <?php else: foreach ($history as $hrow): ?>
      <?php
        /* v43 스냅샷 우선 — v42 이하 구버전 스냅샷은 근사 표기 */
        $hRetail = (int)($hrow['retail_sales'] ?? 0) ?: (int)$hrow['site_sales'];
        $hOn = (int)($hrow['resv_sales'] ?? 0) + (int)($hrow['online_retail_sales'] ?? 0);
        $hWh = (int)($hrow['wholesale_group_sales'] ?? 0) ?: (((int)($hrow['wholesale_sales'] ?? 0) ?: (int)($hrow['online_biz_sales'] ?? 0)) + (int)($hrow['local_wholesale_sales'] ?? 0));
        $hProfit = (int)$hrow['margin_total'];
      ?>
      <tr>
        <td><b><?= h((string)$hrow['settle_date']) ?></b></td>
        <td><?= $fmt($hRetail) ?>원</td>
        <td><?= $fmt($hOn) ?>원</td>
        <td><b style="color:#c2410c;"><?= $fmt($hWh) ?></b>원</td>
        <td><b style="color:#2563eb;"><?= $fmt((int)$hrow['total_sales']) ?></b>원</td>
        <td><b style="color:#16a34a;"><?= $fmt($hProfit) ?></b>원<?= (int)$hrow['total_sales'] > 0 ? ' <small class="text-muted">(' . round($hProfit / (int)$hrow['total_sales'] * 100, 1) . '%)</small>' : '' ?></td>
        <td><?= (int)($hrow['card_fee_amount'] ?? 0) > 0 ? '-' . $fmt((int)$hrow['card_fee_amount']) . '원' : '-' ?></td>
        <td><?= (int)$hrow['work_count'] + (int)($hrow['local_wholesale_count'] ?? 0) + (int)($hrow['online_count'] ?? 0) ?>건</td>
        <td><?= h((string)$hrow['closed_by']) ?></td>
        <td><?= $hrow['reopened_at'] === null ? '<span class="badge badge-success">마감</span>' : '<span class="badge badge-warning">해제됨</span>' ?></td>
        <td><a class="btn btn-sm" href="?date=<?= h((string)$hrow['settle_date']) ?>">보기</a> <a class="btn btn-sm" target="_blank" href="daily-settlement-print.php?date=<?= h((string)$hrow['settle_date']) ?>">🖨️</a></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<style>
/* ===== 일정산 마감 — 대분류 카드 ===== */
.ds-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px;}
.ds-stat{
  display:flex;align-items:flex-start;gap:14px;
  background:#fff;border:1px solid #e5e7eb;border-left:5px solid #2563eb;
  border-radius:14px;padding:18px 18px 16px;
  transition:transform .15s, box-shadow .15s;
}
.ds-stat:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(15,23,42,.09);}
.ds-ico{
  flex:none;width:46px;height:46px;border-radius:12px;
  display:flex;align-items:center;justify-content:center;font-size:22px;
  background:#eff6ff;
}
.ds-violet{border-left-color:#7c3aed;}
.ds-violet .ds-ico{background:#f3e8ff;}
.ds-wholesale{border-left-color:#ea580c;}
.ds-wholesale .ds-ico{background:#ffedd5;}
.ds-wholesale .ds-tag{color:#c2410c;background:#ffedd5;}
.ds-wholesale .ds-v{color:#c2410c;}
.ds-hero{border-left-color:#0f172e;background:linear-gradient(135deg,#f8fafc 0%,#eef2ff 100%);}
.ds-hero .ds-ico{background:#0f172e;}
.ds-profit{border-left-color:#16a34a;background:linear-gradient(135deg,#f0fdf4 0%,#ecfdf5 100%);}
.ds-profit .ds-ico{background:#dcfce7;}
.ds-profit .ds-v{color:#15803d;}
.ds-body{min-width:0;flex:1;}
.ds-k{font-size:13.5px;font-weight:700;color:#64748b;letter-spacing:-.2px;}
.ds-v{font-size:25px;font-weight:800;color:#0f172e;letter-spacing:-.5px;margin:4px 0 3px;line-height:1.15;}
.ds-won{font-size:14px;font-weight:700;color:#94a3b8;margin-left:3px;}
.ds-sub{font-size:12px;font-weight:600;color:#94a3b8;}
.ds-sub b{color:#16a34a;font-weight:800;}
.ds-tag{
  display:inline-block;font-size:10.5px;font-weight:800;color:#7c3aed;
  background:#f3e8ff;border-radius:99px;padding:1px 8px;margin-left:5px;vertical-align:1px;
}
/* ===== 매출 구분 표 ===== */
.ds-cat-table td{white-space:nowrap;}
.ds-cat-table .ds-sub-total td{background:#f8fafc;font-weight:700;}
.ds-cat-table .ds-grand-total td{background:#eff6ff;font-weight:800;}
/* ===== 카드 수수료 박스 ===== */
.ds-fee-box{margin-top:18px;border:1.5px solid #fde68a;background:linear-gradient(135deg,#fffbeb 0%,#fefce8 100%);border-radius:14px;padding:16px 18px;}
.ds-fee-head{font-size:14.5px;font-weight:800;color:#b45309;margin-bottom:12px;}
.ds-fee-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.ds-fee-cell{background:#fff;border:1px solid #fde68a;border-radius:12px;padding:14px 16px;}
.ds-fee-cell .fc-k{font-size:12px;font-weight:700;color:#92400e;}
.ds-fee-cell .fc-v{font-size:21px;font-weight:800;color:#0f172e;margin:4px 0 3px;letter-spacing:-.4px;}
.ds-fee-cell .fc-sub{font-size:11.5px;font-weight:600;color:#a16207;line-height:1.5;}
.ds-fee-cell.ds-fee-rate{display:flex;flex-direction:column;justify-content:center;}
/* ===== 마진 카드 [v42 — 3종] ===== */
.ds-margin-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.ds-mg-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 18px;}
.ds-mg-card .k{font-size:12.5px;font-weight:700;color:#64748b;}
.ds-mg-card .v{font-size:23px;font-weight:800;color:#0f172e;margin:5px 0 4px;letter-spacing:-.4px;}
.ds-mg-card .v span{font-size:13px;font-weight:700;color:#94a3b8;margin-left:2px;}
.ds-mg-card .s{font-size:11.5px;font-weight:600;color:#94a3b8;}
.ds-mg-card .s b{color:#16a34a;}
.ds-mg-card.mg-on{border-color:#ddd6fe;background:#faf5ff;}
.ds-mg-card.mg-on .v{color:#7c3aed;}
.ds-mg-card.mg-wh{border-color:#a5f3fc;background:#ecfeff;}
.ds-mg-card.mg-wh .v{color:#0891b2;}
.ds-mg-card.mg-total{border-color:#bbf7d0;background:#f0fdf4;}
.ds-mg-card.mg-total .v{color:#16a34a;}
@media(max-width:1280px){.ds-stats{grid-template-columns:repeat(3,1fr);}}
@media(max-width:1100px){.ds-stats{grid-template-columns:repeat(2,1fr);}.ds-fee-grid,.ds-margin-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:560px){.ds-stats,.ds-fee-grid,.ds-margin-grid{grid-template-columns:1fr;}.ds-v{font-size:22px;}}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
