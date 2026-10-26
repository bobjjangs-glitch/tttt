<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

/* 보강된 상태 해시(예약확정 감지) — 파일이 없거나 오류여도 기존 화면은 그대로 */
$boardLibPath = __DIR__ . '/includes/board-lib.php';
if (is_file($boardLibPath)) {
    try { require_once $boardLibPath; } catch (Throwable $e) { error_log('[board-lib] ' . $e->getMessage()); }
}

$worker = tablet_require_worker();

$pageTitle = '실시간 예약판';
$pageKey = 'reserve';

/* 날짜 선택 (기본 오늘) */
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$today = date('Y-m-d');
$isToday = $date === $today;
$weekKor = ['일','월','화','수','목','금','토'];
$dateKor = date('n월 j일', strtotime($date)) . ' (' . $weekKor[(int)date('w', strtotime($date))] . ')';
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = ($date < $today) ? date('Y-m-d', strtotime($date . ' +1 day')) : null;

$pdo = Database::connection();

/* 그날 "예약확정" 이후 예약 + 작업일지 연동 여부 (접수 상태 pending은 표시하지 않음) */
$reservations = [];
try {
    $stmt = $pdo->prepare(
        "SELECT r.*, w.id AS work_log_id, w.status AS work_status
         FROM tt_reservations r
         LEFT JOIN tt_work_logs w ON w.reservation_id = r.id
         WHERE r.visit_date = :d
           AND r.status IN ('confirmed','visited')
         ORDER BY FIELD(r.status,'confirmed','visited'), r.visit_time ASC, r.id ASC");
    $stmt->execute(['d'=>$date]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* 확정 대기 건수 — 표시는 안 하고 안내만 (관리자가 확정을 놓치지 않도록) */
$pendingCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tt_reservations WHERE visit_date = :d AND status = 'pending'");
    $stmt->execute(['d'=>$date]);
    $pendingCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

/* 시간대별 그룹 */
$slots = [];
foreach ($reservations as $r) {
    $slots[$r['visit_time'] ?? '기타'][] = $r;
}
ksort($slots);

$workers = get_workers(true);

$stats = ['confirmed'=>0, 'visited'=>0];
foreach ($reservations as $r) $stats[$r['status']] = ($stats[$r['status']] ?? 0) + 1;
$sumAmount = 0;
foreach ($reservations as $r) $sumAmount += (int)($r['final_amount'] ?: ((int)$r['unit_price'] * (int)$r['qty']));

$fmt = fn(int $n): string => number_format($n);

$stateDate = $date;
$stateHash = function_exists('tablet_page_hash') ? tablet_page_hash($date) : tablet_state_data($date)['hash'];

$okMsg = flash('tablet_ok');
$errMsg = flash('tablet_error');

require __DIR__ . '/includes/header.php';
?>

<?php if ($okMsg): ?><div class="notice notice-ok">✅ <?= h($okMsg) ?></div><?php endif; ?>
<?php if ($errMsg): ?><div class="notice notice-err">⚠ <?= h($errMsg) ?></div><?php endif; ?>

<div class="tablet-card" style="margin-bottom:16px;">
  <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <a class="tablet-btn tablet-btn-ghost tablet-btn-sm" href="?date=<?= h($prevDate) ?>">◀ 전날</a>
    <div style="font-size:22px;font-weight:800;"><?= h($dateKor) ?> 예약판<?= $isToday ? ' · 실시간 (30초 자동 갱신)' : '' ?></div>
    <?php if ($nextDate): ?><a class="tablet-btn tablet-btn-ghost tablet-btn-sm" href="?date=<?= h($nextDate) ?>">다음날 ▶</a><?php endif; ?>
    <?php if (!$isToday): ?><a class="tablet-btn tablet-btn-sm" href="?date=">오늘로</a><?php endif; ?>
    <form method="get" style="display:flex;gap:8px;align-items:center;margin-left:auto;">
      <input type="date" name="date" value="<?= h($date) ?>" style="font-size:16px;padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:10px;min-height:44px;">
      <button class="tablet-btn tablet-btn-sm" type="submit">이동</button>
    </form>
  </div>
  <div class="tablet-summary" style="margin:14px 0 0;">
    <div class="tablet-sum-item"><div class="k">확정 예약</div><div class="v"><?= count($reservations) ?>건</div></div>
    <div class="tablet-sum-item"><div class="k">작업 전(확정)</div><div class="v blue"><?= (int)$stats['confirmed'] ?>건</div></div>
    <div class="tablet-sum-item"><div class="k">작업 연동 완료</div><div class="v green"><?= (int)$stats['visited'] ?>건</div></div>
    <div class="tablet-sum-item"><div class="k">예약 금액 합계</div><div class="v"><?= $fmt($sumAmount) ?>원</div></div>
  </div>
  <?php if ($pendingCount > 0): ?>
  <div style="margin-top:12px;font-size:14px;font-weight:700;color:#92400e;">⏳ 예약확정 대기 <?= (int)$pendingCount ?>건 — 관리자가 '예약확정' 처리하면 이 판에 표시됩니다</div>
  <?php endif; ?>
</div>

<?php if (empty($reservations)): ?>
  <div class="empty-state">이 날짜에 확정된 예약이 없습니다.<br><small style="font-weight:500;">예약이 접수된 뒤 관리자가 '예약확정' 처리하면 자동으로 이 판에 표시됩니다.</small></div>
<?php else: ?>
  <?php foreach ($slots as $slot => $rows): ?>
    <div class="time-group">
      <div class="time-group-label">⏰ <?= h((string)$slot) ?></div>
      <div class="resv-grid">
        <?php foreach ($rows as $r):
            $linked = !empty($r['work_log_id']);
        ?>
        <div class="resv-card <?= $r['status'] === 'confirmed' ? 'is-confirmed' : 'is-done' ?>">
          <div class="resv-head">
            <div class="resv-time"><?= h(substr((string)$r['visit_time'], 0, 5)) ?></div>
            <?php $vt = $r['visit_type'] ?? 'tire'; $vtLabels = resv_visit_type_labels(); if (!array_key_exists($vt, $vtLabels)) $vt = 'tire'; ?>
            <span class="badge badge-<?= $vt === 'repair' ? 'repair' : ($vt === 'etc' ? 'etc' : 'tire') ?>"><?= h($vtLabels[$vt]) ?></span>
            <?php if ($r['status'] === 'confirmed'): ?><span class="badge badge-resv">예약 확정</span><?php endif; ?>
            <?php if ($r['status'] === 'visited'): ?><span class="badge badge-done">작업 연동 <?= $linked ? '완료' : '대기' ?></span><?php endif; ?>
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
          <?php if ($linked): ?>
          <div class="resv-actions">
            <a class="tablet-btn tablet-btn-green" href="<?= BASE_URL ?>/tablet/index.php?date=<?= h($date) ?>" style="flex:1;">✅ 작업일지에서 보기 — 연동 완료</a>
          </div>
          <?php else: ?>
          <?php $checkedIn = !empty($r['checked_in_at']); ?>
          <form method="post" action="<?= BASE_URL ?>/tablet/checkin.php" class="resv-actions">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="undo" value="<?= $checkedIn ? '1' : '0' ?>">
            <input type="hidden" name="return_to" value="/tablet/reservation-board.php?date=<?= h($date) ?>">
            <button type="submit" class="tablet-btn <?= $checkedIn ? 'tablet-btn-ghost' : '' ?>" style="flex:1;">
              <?= $checkedIn ? '↩ 입고 취소' : '🚗 입고 처리' ?>
            </button>
          </form>
          <form method="post" action="<?= BASE_URL ?>/tablet/action.php" class="resv-actions">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="action" value="start_reservation">
            <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="return_to" value="/tablet/reservation-board.php?date=<?= h($date) ?>">
            <select name="worker_id" style="flex:1;font-size:16px;font-weight:700;padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:11px;min-height:52px;background:#fff;">
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
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
