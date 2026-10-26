<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('reservations');
ensure_reservations_table();
ensure_reservation_reminder_table();

$pdo = Database::connection();
$pageTitle = '예약 리마인더';

$okMsg  = flash('admin_success');
$errMsg = flash('admin_error');

/* 발송 대상일: 기본 내일 */
$targetDate = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) $targetDate = date('Y-m-d', strtotime('+1 day'));

/* ===== 일괄 발송 ===== */
if (is_post() && ($_POST['action'] ?? '') === 'send_bulk') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/reservation-reminders.php?date=' . $targetDate);
    }
    $result = send_reservation_reminders($targetDate, (string)AdminAuth::currentAdminName());
    if ($result['sent'] > 0) {
        flash('admin_success', "리마인더 {$result['sent']}건 발송했습니다."
            . ($result['skipped'] ? " (이미 발송됨 {$result['skipped']}건 제외)" : '')
            . ($result['failed'] ? " / 실패 {$result['failed']}건" : ''));
    } else {
        flash('admin_error', '발송된 건이 없습니다. 대상 예약이 있는지, 이미 발송되지 않았는지 확인해 주세요.');
    }
    redirect('/admin/reservation-reminders.php?date=' . $targetDate);
}

/* 대상 예약 + 발송 여부 */
$stmt = $pdo->prepare(
    "SELECT r.*, (rr.id IS NOT NULL) AS reminded, rr.sent_at AS reminded_at, rr.result_ok
     FROM tt_reservations r
     LEFT JOIN tt_reservation_reminders rr ON rr.reservation_id = r.id AND rr.send_date = :today
     WHERE r.visit_date = :d AND r.status IN ('pending','confirmed')
     ORDER BY r.visit_time ASC");
$stmt->execute(['d' => $targetDate, 'today' => date('Y-m-d')]);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$remindable = array_filter($reservations, fn($r) => !$r['reminded']);
$already = count($reservations) - count($remindable);

/* 최근 발송 이력 */
$recent = [];
try {
    $recent = $pdo->query(
        "SELECT rr.*, r.res_no, r.visitor_name, r.visit_date
         FROM tt_reservation_reminders rr
         JOIN tt_reservations r ON r.id = rr.reservation_id
         ORDER BY rr.id DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">📩 예약 리마인더</h2>
      <p class="admin-list-sub">방문 전날 고객에게 예약 안내 문자를 보내 노쇼를 줄입니다. (SMS API 설정이 없으면 무료 테스트 모드로 이력만 기록)</p>
    </div>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <!-- 대상일 선택 -->
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px;">
    <div>
      <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">방문 예정일 (기본: 내일)</label>
      <input type="date" name="date" value="<?= h($targetDate) ?>" style="padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
    </div>
    <button type="submit" class="btn-admin-primary" style="padding:9px 18px;font-size:13px;">조회</button>
    <?php
    $dPrev = date('Y-m-d', strtotime($targetDate . ' -1 day'));
    $dNext = date('Y-m-d', strtotime($targetDate . ' +1 day'));
    ?>
    <a href="?date=<?= h($dPrev) ?>" class="btn-admin-secondary" style="padding:8px 14px;font-size:13px;text-decoration:none;">◀ 전날</a>
    <a href="?date=<?= h($dNext) ?>" class="btn-admin-secondary" style="padding:8px 14px;font-size:13px;text-decoration:none;">다음날 ▶</a>
  </form>

  <!-- 요약 -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px;">
    <div class="admin-stat-card"><div class="admin-stat-label">대상 예약</div><div class="admin-stat-value"><?= number_format(count($reservations)) ?></div></div>
    <div class="admin-stat-card"><div class="admin-stat-label">미발송</div><div class="admin-stat-value" style="color:#f59e0b"><?= number_format(count($remindable)) ?></div></div>
    <div class="admin-stat-card"><div class="admin-stat-label">오늘 발송 완료</div><div class="admin-stat-value" style="color:#16a34a"><?= number_format($already) ?></div></div>
  </div>

  <!-- 일괄 발송 -->
  <?php if (!empty($remindable)): ?>
  <form method="post" onsubmit="return confirm('미발송 <?= count($remindable) ?>건에 리마인더 문자를 발송할까요?');" style="margin-bottom:16px;">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="send_bulk">
    <button type="submit" class="btn-admin-primary" style="padding:11px 22px;font-size:14px;">📩 미발송 <?= count($remindable) ?>건 일괄 발송</button>
  </form>
  <?php endif; ?>

  <!-- 대상 목록 -->
  <div class="admin-table-wrap" style="overflow-x:auto">
    <table class="admin-table">
      <thead>
        <tr><th>예약번호</th><th>예약자</th><th>연락처</th><th>방문 시간</th><th>상품</th><th>상태</th><th>리마인더</th></tr>
      </thead>
      <tbody>
        <?php if (empty($reservations)): ?>
        <tr><td colspan="7" class="empty"><?= h(date('Y년 n월 j일', strtotime($targetDate))) ?> 방문 예정 예약(접수/확정)이 없습니다.</td></tr>
        <?php else: foreach ($reservations as $r): ?>
        <tr>
          <td class="mono"><b><?= h($r['res_no']) ?></b></td>
          <td><?= h($r['visitor_name']) ?></td>
          <td style="font-size:12px;"><?= h($r['visitor_phone']) ?></td>
          <td><b><?= h($r['visit_time']) ?></b></td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;" title="<?= h($r['product_name_snap']) ?>"><?= h($r['product_name_snap']) ?></td>
          <td><span class="resv-status-badge" style="color:<?= $r['status'] === 'confirmed' ? '#2563eb' : '#f59e0b' ?>;background:<?= $r['status'] === 'confirmed' ? '#2563eb' : '#f59e0b' ?>18;font-size:11px;font-weight:800;padding:3px 10px;border-radius:999px;"><?= $r['status'] === 'confirmed' ? '예약확정' : '예약접수' ?></span></td>
          <td style="font-size:12px;">
            <?php if ($r['reminded']): ?>
              <span style="color:<?= $r['result_ok'] ? '#16a34a' : '#dc2626' ?>;font-weight:700;"><?= $r['result_ok'] ? '✅ 발송 완료' : '❌ 발송 실패' ?></span>
              <small class="text-muted"><?= h(date('H:i', strtotime($r['reminded_at']))) ?></small>
            <?php else: ?>
              <span style="color:#f59e0b;font-weight:700;">미발송</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- 자동 발송(크론) 안내 -->
  <div style="background:#f0f9ff;border-radius:12px;padding:16px;margin-top:20px;">
    <h3 style="font-size:14px;font-weight:800;margin:0 0 8px;color:#0369a1;">⏰ 매일 자동 발송 (호스팅 크론 등록)</h3>
    <p style="font-size:13px;color:#0369a1;margin:0 0 8px;">호스팅 제어판의 크론(Cron)에 아래 명령을 하루 1회(예: 매일 저녁 9시) 등록하면 버튼 없이 자동 발송됩니다.<br>등록하지 않아도 위 화면에서 수동 일괄 발송이 언제나 가능합니다.</p>
    <code style="display:block;background:#e0f2fe;border-radius:8px;padding:10px 14px;font-size:12px;color:#0c4a6e;white-space:pre-wrap;">php <?= htmlspecialchars(dirname(__DIR__) . '/scripts/reservation-reminders.php') ?> &gt;&gt; <?= htmlspecialchars(dirname(__DIR__) . '/logs/reservation-reminders.log') ?> 2&gt;&amp;1</code>
  </div>

  <!-- 발송 이력 -->
  <div style="margin-top:22px;">
    <h3 style="font-size:15px;font-weight:800;margin:0 0 10px;">📜 최근 발송 이력</h3>
    <?php if (empty($recent)): ?>
    <p style="color:#94a3b8;font-size:13px;padding:16px 0;">발송 이력이 없습니다.</p>
    <?php else: ?>
    <table class="admin-table">
      <thead><tr><th>발송 시각</th><th>예약번호</th><th>고객</th><th>방문일</th><th>결과</th><th>발송자</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $r): ?>
        <tr>
          <td style="font-size:12px;"><?= h(date('Y-m-d H:i', strtotime($r['sent_at']))) ?></td>
          <td class="mono"><?= h($r['res_no']) ?></td>
          <td><?= h($r['visitor_name']) ?></td>
          <td style="font-size:12px;"><?= h($r['visit_date']) ?></td>
          <td><?= $r['result_ok'] ? '<span style="color:#16a34a;font-weight:700;">성공</span>' : '<span style="color:#dc2626;font-weight:700;">실패</span>' ?></td>
          <td style="font-size:12px;"><?= h($r['sent_by'] ?: '-') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<style>
@media(max-width:768px){ .admin-table-wrap table{min-width:900px} }
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
