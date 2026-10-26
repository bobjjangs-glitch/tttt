<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
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

/* 그날 예약 전체 + 작업일지 연동 여부 */
$reservations = [];
try {
    $stmt = $pdo->prepare(
        "SELECT r.*, w.id AS work_log_id, w.status AS work_status
         FROM tt_reservations r
         LEFT JOIN tt_work_logs w ON w.reservation_id = r.id
         WHERE r.visit_date = :d
           AND r.status IN ('pending','confirmed','visited')
         ORDER BY FIELD(r.status,'confirmed','pending','visited'), r.visit_time ASC, r.id ASC");
    $stmt->execute(['d'=>$date]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* 시간대별 그룹 */
$slots = [];
foreach ($reservations as $r) {
    $slots[$r['visit_time'] ?? '기타'][] = $r;
}
ksort($slots);

$workers = get_workers(true);

$stats = ['pending'=>0, 'confirmed'=>0, 'visited'=>0];
foreach ($reservations as $r) $stats[$r['status']] = ($stats[$r['status']] ?? 0) + 1;
$sumAmount = 0;
foreach ($reservations as $r) $sumAmount += (int)($r['final_amount'] ?: ((int)$r['unit_price'] * (int)$r['qty']));

$fmt = fn(int $n): string => number_format($n);

$stateDate = $date;
$stateHash = tablet_state_data($date)['hash'];

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
    <div class="tablet-sum-item"><div class="k">전체 예약</div><div class="v"><?= count($reservations) ?>건</div></div>
    <div class="tablet-sum-item"><div class="k">확정 대기</div><div class="v" style="color:#d97706;"><?= (int)$stats['pending'] ?>건</div></div>
    <div class="tablet-sum-item"><div class="k">확정</div><div class="v blue"><?= (int)$stats['confirmed'] ?>건</div></div>
    <div class="tablet-sum-item"><div class="k">작업 연동 완료</div><div class="v green"><?= (int)$stats['visited'] ?>건</div></div>
    <div class="tablet-sum-item"><div class="k">예약 금액 합계</div><div class="v"><?= $fmt($sumAmount) ?>원</div></div>
  </div>
</div>

<?php if (empty($reservations)): ?>
  <div class="empty-state">이 날짜에 예약이 없습니다.<br><small style="font-weight:500;">사이트에서 타이어 예약이 접수되면 자동으로 이 판에 실시간 표시됩니다.</small></div>
<?php else: ?>
  <?php foreach ($slots as $slot => $rows): ?>
    <div class="time-group">
      <div class="time-group-label">⏰ <?= h((string)$slot) ?></div>
      <div class="resv-grid">
        <?php foreach ($rows as $r):
            $linked = !empty($r['work_log_id']);
            $amount = (int)($r['final_amount'] ?: ((int)$r['unit_price'] * (int)$r['qty']));
        ?>
        <div class="resv-card <?= $r['status'] === 'pending' ? 'is-pending' : ($r['status'] === 'confirmed' ? 'is-confirmed' : 'is-done') ?>">
          <div class="resv-head">
            <div class="resv-time"><?= h(substr((string)$r['visit_time'], 0, 5)) ?></div>
            <?php $vt = $r['visit_type'] ?? 'tire'; $vtLabels = resv_visit_type_labels(); if (!array_key_exists($vt, $vtLabels)) $vt = 'tire'; ?>
            <span class="badge badge-<?= $vt === 'repair' ? 'repair' : ($vt === 'etc' ? 'etc' : 'tire') ?>"><?= h($vtLabels[$vt]) ?></span>
            <?php if ($r['status'] === 'pending'): ?><span class="badge badge-working">확정 대기</span><?php endif; ?>
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
