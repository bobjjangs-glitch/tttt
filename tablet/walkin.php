<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/board-lib.php';
$worker = tablet_require_worker();
$pdo    = Database::connection();
ensure_board_columns();
$today = date('Y-m-d');

/* ===== POST: 입고 등록 / 입고 취소 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('tablet_error', '유효하지 않은 요청입니다. 다시 시도해 주세요.');
        redirect('/tablet/walkin.php');
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $raw = trim((string)preg_replace('/\s+/u', ' ', (string)($_POST['plate'] ?? '')));
            if ($raw === '') {
                flash('tablet_error', '차량번호를 입력해 주세요.');
            } elseif (mb_strlen($raw) > 20) {
                flash('tablet_error', '차량번호가 너무 깁니다 (20자 이내).');
            } else {
                $plate = function_exists('extract_plate_no') ? trim((string)extract_plate_no($raw)) : '';
                if ($plate === '') $plate = $raw;

                /* 같은 번호가 이미 입고중이면 중복 등록 차단 (더블탭 방어 겸용) */
                $dup = $pdo->prepare(
                    'SELECT 1 FROM tt_board_walkins i
                       LEFT JOIN tt_work_logs w ON w.id = i.work_log_id
                      WHERE i.visit_date = :d AND i.plate_no = :p AND w.id IS NULL LIMIT 1'
                );
                $dup->execute(['d' => $today, 'p' => $plate]);
                if ($dup->fetchColumn()) {
                    flash('tablet_error', $plate . ' — 이미 입고중으로 등록된 차량입니다.');
                } else {
                    $pdo->prepare(
                        'INSERT INTO tt_board_walkins (visit_date, plate_no, created_by, created_at)
                         VALUES (:d, :p, :by, :at)'
                    )->execute([
                        'd'  => $today,
                        'p'  => $plate,
                        'by' => mb_substr((string)($worker['name'] ?? ''), 0, 50),
                        'at' => date('Y-m-d H:i:s'),
                    ]);
                    flash('tablet_ok', $plate . ' 입고 등록 — 현황판에 "입고중"으로 표시됩니다.');
                }
            }
        } elseif ($action === 'cancel') {
            $id = (int)($_POST['id'] ?? 0);
            /* [FIX] 화면 목록과 같은 기준으로 삭제한다.
               목록은 "작업일지 미연결 건 + 연결된 작업일지가 삭제돼 사라진 건"을 모두 보여주는데,
               예전 삭제문은 work_log_id IS NULL만 허용해서 후자는 화면에 보이는데도 지워지지 않았다.
               (살아 있는 작업일지에 연결된 건만 취소 불가) */
            $stmt = $pdo->prepare(
                'DELETE i FROM tt_board_walkins i
                   LEFT JOIN tt_work_logs w ON w.id = i.work_log_id
                  WHERE i.id = :id AND w.id IS NULL'
            );
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() > 0) {
                flash('tablet_ok', '입고를 취소했습니다.');
            } else {
                /* 실패 사유를 구분해서 알려준다 (지워진 건인지, 작업이 진행 중인 건인지) */
                $chk = $pdo->prepare('SELECT 1 FROM tt_board_walkins WHERE id = :id');
                $chk->execute(['id' => $id]);
                flash('tablet_error', $chk->fetchColumn()
                    ? '작업이 이미 시작된 건이라 취소할 수 없습니다. (작업일지를 삭제하면 다시 취소할 수 있습니다)'
                    : '이미 취소됐거나 존재하지 않는 입고 건입니다.');
            }
        }
    } catch (Throwable $e) {
        error_log('[walkin] ' . $e->getMessage());
        sg_maybe_invalidate($e);
        flash('tablet_error', '처리 중 오류가 발생했습니다.');
    }
    redirect('/tablet/walkin.php');
}

/* ===== 화면: 오늘 입고중(작업일지 미연결) 목록 ===== */
$list = [];
try {
    $stmt = $pdo->prepare(
        'SELECT i.id, i.plate_no, i.created_at
           FROM tt_board_walkins i
           LEFT JOIN tt_work_logs w ON w.id = i.work_log_id
          WHERE i.visit_date = :d AND w.id IS NULL
          ORDER BY i.id ASC'
    );
    $stmt->execute(['d' => $today]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[walkin:list] ' . $e->getMessage());
    sg_maybe_invalidate($e);
}

$pageTitle = '현장 입고';
$pageKey   = 'walkin';
$okMsg     = flash('tablet_ok');
$errMsg    = flash('tablet_error');
require __DIR__ . '/includes/header.php';
?>

<?php if ($okMsg): ?><div class="notice" style="background:#dcfce7;color:#166534;">✅ <?= h($okMsg) ?></div><?php endif; ?>
<?php if ($errMsg): ?><div class="notice notice-err">⚠ <?= h($errMsg) ?></div><?php endif; ?>

<form method="post" class="tablet-card" autocomplete="off">
  <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
  <input type="hidden" name="action" value="add">
  <div class="tablet-card-title">🚗 현장 입고 등록 <span class="count-chip">예약 없이 방문한 차량</span></div>
  <div class="tablet-form">
    <div class="form-item">
      <label>차량번호</label>
      <input type="text" name="plate" maxlength="20" placeholder="예: 12가3456" required>
    </div>
    <div class="action-bar">
      <button type="submit" class="tablet-btn tablet-btn-xl">입고 등록</button>
    </div>
  </div>
</form>

<div class="tablet-card">
  <div class="tablet-card-title">입고중 차량 <span class="count-chip"><?= count($list) ?>대</span></div>
  <?php if (!$list): ?>
    <div class="empty-state">입고중인 현장 차량이 없습니다</div>
  <?php else: foreach ($list as $e): ?>
    <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #e5e7eb;">
      <div style="flex:1;">
        <b style="font-size:20px;"><?= h((string)$e['plate_no']) ?></b>
        <span style="color:#6b7280;margin-left:8px;"><?= h(substr((string)$e['created_at'], 11, 5)) ?> 입고</span>
      </div>
      <a class="tablet-btn" href="<?= BASE_URL ?>/tablet/log-add.php?walkin_id=<?= (int)$e['id'] ?>">🔧 작업 시작</a>
      <form method="post" class="inline-form" onsubmit="return confirm('입고를 취소할까요?');">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
        <button type="submit" class="tablet-btn tablet-btn-ghost tablet-btn-sm">입고 취소</button>
      </form>
    </div>
  <?php endforeach; endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
