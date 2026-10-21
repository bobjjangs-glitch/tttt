<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$worker = tablet_require_worker();

$pageTitle = '오늘의 작업일지';
$pageKey = 'today';

/* 날짜 선택 (기본 오늘) */
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$today = date('Y-m-d');
$isToday = $date === $today;
$weekKor = ['일','월','화','수','목','금','토'];
$dateKor = date('n월 j일', strtotime($date)) . ' (' . $weekKor[(int)date('w', strtotime($date))] . ')';

$calc = compute_day_settlement($date);
$settlement = get_settlement($date);
$isClosed = $settlement && $settlement['reopened_at'] === null;

/* 미연동 예약(작업 대기) — 예약이 들어오면 별도 확인 없이 이 곳에 자동 표시
 * 예약판과 동일한 조건: 취소(canceled)/노쇼(noshow)만 제외한 미연동 예약 전부.
 * visited(방문확정) 상태인데 작업일지가 없는 예약도 놓치지 않고 표시한다. */
$pdo = Database::connection();
$waitReservations = [];
try {
    $stmt = $pdo->prepare(
        "SELECT r.* FROM tt_reservations r
         WHERE r.visit_date = :d AND r.status NOT IN ('canceled','noshow')
           AND NOT EXISTS (SELECT 1 FROM tt_work_logs w WHERE w.reservation_id = r.id)
         ORDER BY FIELD(r.status,'pending','confirmed','visited'), r.visit_time ASC, r.id ASC");
    $stmt->execute(['d'=>$date]);
    $waitReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$waitCount = count($waitReservations);
$workers = get_workers(true);

/* 첨부 사진 (작업일지별 맵) */
$photosMap = get_work_log_photos_by_logs(array_map(fn($l) => (int)$l['id'], $calc['logs']));

$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = ($date < $today) ? date('Y-m-d', strtotime($date . ' +1 day')) : null;

$labels = work_job_type_labels();

/* 시간별 그룹핑 (예약/일반 분리) */
$groups = ['reservation'=>[], 'walkin'=>[]];
foreach ($calc['logs'] as $log) {
    $src = $log['source'] === 'reservation' ? 'reservation' : 'walkin';
    $hour = substr((string)$log['work_time'], 0, 2);
    if ($hour === '' || !ctype_digit($hour)) $hour = '00';
    $groups[$src][$hour][] = $log;
}
ksort($groups['reservation']);
ksort($groups['walkin']);

$fmt = fn(int $n): string => number_format($n);

/* 폴링용 상태 해시 — api-state.php와 같은 계산(tablet_state_data) */
$stateDate = $date;
$stateHash = tablet_state_data($date)['hash'];

$okMsg = flash('tablet_ok');
$errMsg = flash('tablet_error');

require __DIR__ . '/includes/header.php';
?>

<?php if ($okMsg): ?><div class="notice notice-ok">✅ <?= h($okMsg) ?></div><?php endif; ?>
<?php if ($errMsg): ?><div class="notice notice-err">⚠ <?= h($errMsg) ?></div><?php endif; ?>
<?php if ($isClosed): ?>
<div class="notice notice-warn">🔒 <?= h($dateKor) ?> 작업일지는 일정산 마감이 완료되었습니다 (마감자: <?= h($settlement['closed_by'] ?? '') ?>) — 수정이 잠겨 있습니다.</div>
<?php endif; ?>

<?php /* 날짜 내비게이션 */ ?>
<div class="tablet-card" style="margin-bottom:16px;">
  <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <a class="tablet-btn tablet-btn-ghost tablet-btn-sm" href="?date=<?= h($prevDate) ?>">◀ 전날</a>
    <div style="font-size:22px;font-weight:800;"><?= h($dateKor) ?><?= $isToday ? ' · 오늘' : '' ?></div>
    <?php if ($nextDate): ?><a class="tablet-btn tablet-btn-ghost tablet-btn-sm" href="?date=<?= h($nextDate) ?>">다음날 ▶</a><?php endif; ?>
    <?php if (!$isToday): ?><a class="tablet-btn tablet-btn-sm" href="?date=">오늘로</a><?php endif; ?>
    <form method="get" style="display:flex;gap:8px;align-items:center;margin-left:auto;">
      <input type="date" name="date" value="<?= h($date) ?>" style="font-size:16px;padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:10px;min-height:44px;">
      <button class="tablet-btn tablet-btn-sm" type="submit">이동</button>
    </form>
    <a class="tablet-btn <?= $isClosed ? 'tablet-btn-ghost' : '' ?>" href="<?= BASE_URL ?>/tablet/log-add.php?date=<?= h($date) ?>">➕ <?= $isClosed ? '작업 등록 (마감됨)' : '작업 등록' ?></a>
  </div>
</div>

<?php /* 하루 요약 */ ?>
<div class="tablet-summary">
  <div class="tablet-sum-item"><div class="k">총 작업 (<?= (int)$calc['work_count'] ?>건<?= $calc['working_count'] ? ' · 진행중 ' . (int)$calc['working_count'] : '' ?>)</div><div class="v"><?= $fmt($calc['site_sales']) ?><span style="font-size:13px;color:#6b7280;font-weight:700;">원</span></div></div>
  <div class="tablet-sum-item"><div class="k">💳 카드결제</div><div class="v"><?= $fmt((int)$calc['card_sales']) ?>원</div></div>
  <div class="tablet-sum-item"><div class="k">💵 현금결제<?= (int)$calc['transfer_sales'] > 0 ? '·계좌' : '' ?></div><div class="v green"><?= $fmt((int)$calc['cash_sales'] + (int)$calc['transfer_sales']) ?>원</div></div>
  <div class="tablet-sum-item"><div class="k">⏳ 미수결제<?= (int)$calc['unpaid_count'] > 0 ? ' (' . (int)$calc['unpaid_count'] . '건)' : '' ?></div><div class="v" style="color:#c2410c;"><?= $fmt((int)$calc['unpaid_sales']) ?>원</div></div>
  <div class="tablet-sum-item"><div class="k">예약 / 일반</div><div class="v" style="font-size:19px;"><?= (int)$calc['reservation_count'] ?>건 · <?= (int)$calc['walkin_count'] ?>건</div></div>
  <div class="tablet-sum-item"><div class="k">온라인 주문(참고)</div><div class="v" style="font-size:19px;"><?= $fmt($calc['online_sales']) ?>원<?= (int)$calc['online_biz_sales'] > 0 ? ' (도매 ' . $fmt((int)$calc['online_biz_sales']) . ')' : '' ?></div></div>
</div>

<?php /* 예약작업 / 일반 로드 2단 */ ?>
<div class="tablet-cols">

  <!-- 예약작업 -->
  <section class="tablet-card">
    <div class="tablet-card-title">🗓️ 예약작업 <span class="count-chip"><?= (int)$calc['reservation_count'] ?>건<?= $waitCount ? ' · 대기 ' . (int)$waitCount . '건' : '' ?> · <?= $fmt($calc['reservation_sales']) ?>원</span></div>

    <?php /* 새로 들어온 예약 — 자동 표시 (연동 전) */ ?>
    <?php if ($waitCount > 0): ?>
      <div class="time-group-label" style="color:var(--tbl-blue-dark);">🔔 자동 표시된 예약 (<?= (int)$waitCount ?>건) — 예약이 접수되면 별도 확인 없이 이 곳에 바로 뜹니다</div>
      <div class="resv-grid wait-grid">
        <?php foreach ($waitReservations as $r): ?>
        <div class="resv-card is-wait">
          <div class="resv-head">
            <div class="resv-time"><?= h(substr((string)$r['visit_time'], 0, 5)) ?></div>
            <?php $vt = $r['visit_type'] ?? 'tire'; $vtLabels = resv_visit_type_labels(); if (!array_key_exists($vt, $vtLabels)) $vt = 'tire'; ?>
            <span class="badge badge-<?= $vt === 'repair' ? 'repair' : ($vt === 'etc' ? 'etc' : 'tire') ?>"><?= h($vtLabels[$vt]) ?></span>
            <?php if ($r['status'] === 'pending'): ?><span class="badge badge-working">확정 대기</span><?php endif; ?>
            <?php if ($r['status'] === 'confirmed'): ?><span class="badge badge-resv">예약 확정</span><?php endif; ?>
            <?php if ($r['status'] === 'visited'): ?><span class="badge badge-done">방문 확정 · 연동 대기</span><?php endif; ?>
            <span class="resv-no" style="margin-left:auto;"><?= h($r['res_no']) ?></span>
          </div>
          <div class="resv-body">
            <b><?= h($r['visitor_name']) ?></b> · 📞 <?= h($r['visitor_phone']) ?>
            <?php if ($r['car_info']): ?> · 🚗 <?= h($r['car_info']) ?><?php endif; ?><br>
            🛞 <b><?= h($r['product_name_snap']) ?></b><?= $r['size_snap'] ? ' (' . h($r['size_snap']) . ')' : '' ?> × <?= (int)$r['qty'] ?>본<br>
            예약가 <?= $fmt((int)$r['unit_price']) ?>원<?= $r['final_amount'] ? ' → 결제 ' . $fmt((int)$r['final_amount']) . '원' : '' ?>
            (<?= $r['payment_status'] === 'paid' ? '결제완료' : '미결제' ?>)
          </div>
          <?php if ($r['memo']): ?><div class="resv-memo">📝 <?= h($r['memo']) ?></div><?php endif; ?>
          <?php if ($isClosed): ?>
          <div class="resv-actions">
            <button type="button" class="tablet-btn tablet-btn-ghost" disabled style="flex:1;">🔒 일정산 마감 — 이 날짜는 연동 불가</button>
          </div>
          <?php else: ?>
          <form method="post" action="<?= BASE_URL ?>/tablet/action.php" class="resv-actions">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="action" value="start_reservation">
            <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="return_to" value="/tablet/index.php?date=<?= h($date) ?>">
            <select name="worker_id" style="flex:1;font-size:16px;font-weight:700;padding:10px 12px;border:1.5px solid #c7d7f5;border-radius:11px;min-height:52px;background:#fff;">
              <?php foreach ($workers as $w): ?>
              <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === (int)($worker['id'] ?? 0) ? 'selected' : '' ?>><?= h($w['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="tablet-btn" style="flex:1;">🔧 작업 시작 → 연동</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (empty($groups['reservation']) && $waitCount === 0): ?>
      <div class="empty-state">예약이 접수되면 자동으로 이 곳에 표시됩니다<br><small style="font-weight:500;">사이트에서 예약이 들어오면 별도 확인 없이 30초 내 자동 갱신 — "작업 시작"을 누르면 작업일지로 연동됩니다</small></div>
    <?php elseif (!empty($groups['reservation'])): ?>
      <?php if ($waitCount > 0): ?><div class="time-group-label" style="margin-top:18px;">연동 완료된 예약 작업</div><?php endif; ?>
      <?php foreach ($groups['reservation'] as $hour => $logs): ?>
        <div class="time-group">
          <div class="time-group-label"><?= h(sprintf('%02d', (int)$hour)) ?>시</div>
          <?php foreach ($logs as $log): ?>
            <?php include __DIR__ . '/includes/log-entry.php'; ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <div style="margin-top:12px;">
      <a class="tablet-btn tablet-btn-ghost tablet-btn-full" href="<?= BASE_URL ?>/tablet/reservation-board.php?date=<?= h($date) ?>">🗓️ 예약판 열기 — 오늘 예약 실시간 확인</a>
    </div>
  </section>

  <!-- 일반 로드 -->
  <section class="tablet-card">
    <div class="tablet-card-title">🚶 일반 로드 (픽업 손님) <span class="count-chip"><?= (int)$calc['walkin_count'] ?>건 · <?= $fmt($calc['walkin_sales']) ?>원</span></div>
    <?php if (empty($groups['walkin'])): ?>
      <div class="empty-state">등록된 일반 작업이 없습니다<br><small style="font-weight:500;">➕ 작업 등록 버튼으로 바로 기록하세요</small></div>
    <?php else: ?>
      <?php foreach ($groups['walkin'] as $hour => $logs): ?>
        <div class="time-group">
          <div class="time-group-label"><?= h(sprintf('%02d', (int)$hour)) ?>시</div>
          <?php foreach ($logs as $log): ?>
            <?php include __DIR__ . '/includes/log-entry.php'; ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <div style="margin-top:12px;">
      <a class="tablet-btn tablet-btn-full" href="<?= BASE_URL ?>/tablet/log-add.php?date=<?= h($date) ?>">➕ 일반 로드 작업 등록</a>
    </div>
  </section>

</div>

<?php /* [v40] 현장 결제등록 모달 — 작업일지 카드의 "💰 결제등록" 버튼으로 열림 */ ?>
<div class="pay-modal-backdrop" id="payModalBackdrop">
  <form method="post" action="<?= BASE_URL ?>/tablet/action.php" class="pay-modal" id="payModalForm">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="action" value="pay_register">
    <input type="hidden" name="id" id="payModalId" value="0">
    <input type="hidden" name="return_to" value="/tablet/index.php?date=<?= h($date) ?>">
    <h3>💰 현장 결제등록</h3>
    <div class="pay-modal-sub" id="payModalInfo">작업 완료 후 결제받은 금액과 결제수단을 등록하세요 — 정산마감에 카드/현금으로 반영됩니다.</div>
    <div class="seg">
      <input type="radio" name="pay_method" id="pm-card" value="card">
      <label for="pm-card">💳 카드</label>
      <input type="radio" name="pay_method" id="pm-cash" value="cash">
      <label for="pm-cash">💵 현금</label>
      <input type="radio" name="pay_method" id="pm-transfer" value="transfer">
      <label for="pm-transfer">🏦 계좌이체</label>
      <input type="radio" name="pay_method" id="pm-none" value="none">
      <label for="pm-none">⏳ 미수금으로 되돌림</label>
    </div>
    <input type="number" name="pay_amount" id="payModalAmount" min="0" step="1000" placeholder="결제금액 (비워두면 작업금액 그대로)">
    <?php /* [v42] 현금결제 시 현금영수증 발행 체크 */ ?>
    <label class="pay-receipt-row" id="payReceiptRow" style="display:none;">
      <input type="checkbox" name="cash_receipt" id="payReceiptChk" value="1">
      <span>🧾 현금영수증 발행 (현금결제 고객이 요청한 경우 체크)</span>
    </label>
    <div class="pay-modal-actions">
      <button type="button" class="tablet-btn tablet-btn-ghost" id="payModalCancel">취소</button>
      <button type="submit" class="tablet-btn tablet-btn-pay">💰 결제등록 저장</button>
    </div>
  </form>
</div>

<script>
/* 결제등록 모달 열기/닫기 */
(function(){
  var backdrop = document.getElementById('payModalBackdrop');
  if (!backdrop) return;
  var form = document.getElementById('payModalForm');
  var idInput = document.getElementById('payModalId');
  var amountInput = document.getElementById('payModalAmount');
  var info = document.getElementById('payModalInfo');
  var receiptRow = document.getElementById('payReceiptRow');
  var receiptChk = document.getElementById('payReceiptChk');
  function syncReceiptRow(){
    if (!receiptRow) return;
    var checked = document.querySelector('#payModalForm input[name="pay_method"]:checked');
    var isCash = checked && checked.value === 'cash';
    receiptRow.style.display = isCash ? 'flex' : 'none';
    if (!isCash && receiptChk) receiptChk.checked = false;
  }
  document.querySelectorAll('#payModalForm input[name="pay_method"]').forEach(function(r){
    r.addEventListener('change', syncReceiptRow);
  });
  function close(){ backdrop.classList.remove('is-open'); }
  document.querySelectorAll('.pay-open').forEach(function(btn){
    btn.addEventListener('click', function(){
      idInput.value = btn.getAttribute('data-id');
      var method = btn.getAttribute('data-method') || '';
      var pamount = parseInt(btn.getAttribute('data-pamount') || '0', 10);
      var amount = parseInt(btn.getAttribute('data-amount') || '0', 10);
      amountInput.value = pamount > 0 ? pamount : '';
      amountInput.placeholder = '결제금액 (작업금액 ' + amount.toLocaleString() + '원)';
      document.querySelectorAll('#payModalForm input[name="pay_method"]').forEach(function(r){
        r.checked = (r.value === (method || 'card'));
      });
      if (receiptChk) receiptChk.checked = (btn.getAttribute('data-receipt') === '1');
      syncReceiptRow();
      if (info) {
        info.textContent = method
          ? '이미 등록된 결제가 있습니다 — 수단/금액을 고쳐 저장하면 갱신되고, "미수금으로 되돌림"을 고르면 취소됩니다.'
          : (btn.getAttribute('data-resv') === '1'
              ? '예약 작업입니다 — 카드/현금/계좌이체 중 결제받은 수단을 선택해 주세요.'
              : '작업 완료 후 결제받은 금액과 결제수단을 등록하세요 — 정산마감에 카드/현금으로 반영됩니다.');
      }
      backdrop.classList.add('is-open');
    });
  });
  var cancel = document.getElementById('payModalCancel');
  if (cancel) cancel.addEventListener('click', close);
  backdrop.addEventListener('click', function(e){ if (e.target === backdrop) close(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
