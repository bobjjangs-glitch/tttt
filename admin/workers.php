<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('workers');
ensure_work_log_tables();
ensure_worker_pin_lock_columns();

$pdo = Database::connection();
$pageTitle = '작업자 관리';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');

/* ===== POST 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/workers.php');
    }
    $action = $_POST['action'] ?? '';
    $wid = (int)($_POST['worker_id'] ?? 0);

    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $role = trim((string)($_POST['role'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $pin = trim((string)($_POST['pin'] ?? ''));
        $isActive = (int)(($_POST['is_active'] ?? 1) === 1);
        if ($name === '') {
            flash('admin_error', '작업자 이름을 입력해 주세요.');
            redirect('/admin/workers.php');
        }
        if ($pin !== '' && !preg_match('/^\d{3,8}$/', $pin)) {
            flash('admin_error', 'PIN은 숫자 3~8자리로 입력해 주세요.');
            redirect('/admin/workers.php');
        }
        try {
            if ($wid > 0) {
                $sql = 'UPDATE tt_workers SET name=:name, role=:role, phone=:phone, is_active=:active';
                $params = ['name'=>$name, 'role'=>$role ?: null, 'phone'=>$phone ?: null, 'active'=>$isActive, 'id'=>$wid];
                if ($pin !== '') {
                    $sql .= ', pin_hash=:pin';
                    $params['pin'] = password_hash($pin, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id=:id';
                $pdo->prepare($sql)->execute($params);
                AdminAuth::log((int)AdminAuth::currentAdminId(), 'worker_update', "작업자 '{$name}' 정보 수정");
                flash('admin_success', "작업자 '{$name}' 정보를 수정했습니다.");
            } else {
                $pdo->prepare('INSERT INTO tt_workers (name, role, phone, pin_hash, is_active) VALUES (:name, :role, :phone, :pin, :active)')
                    ->execute([
                        'name'=>$name, 'role'=>$role ?: null, 'phone'=>$phone ?: null,
                        'pin'=>$pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null,
                        'active'=>$isActive,
                    ]);
                AdminAuth::log((int)AdminAuth::currentAdminId(), 'worker_create', "작업자 '{$name}' 등록");
                flash('admin_success', "작업자 '{$name}'을(를) 등록했습니다." . ($pin === '' ? ' PIN을 설정해 태블릿 로그인을 활성화하세요.' : ''));
            }
        } catch (Throwable $e) {
            flash('admin_error', '저장 중 오류가 발생했습니다.');
        }
    } elseif ($action === 'unlock' && $wid > 0) {
        /* PIN 잠금 해제 (연속 실패 카운터/잠금 초기화) */
        try {
            $pdo->prepare('UPDATE tt_workers SET pin_failed_attempts = 0, pin_locked_until = NULL WHERE id = :id')
                ->execute(['id'=>$wid]);
            $stmt = $pdo->prepare('SELECT name FROM tt_workers WHERE id = :id');
            $stmt->execute(['id'=>$wid]);
            $name = (string)$stmt->fetchColumn();
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'worker_pin_unlock', "작업자 '{$name}' PIN 잠금 해제");
            flash('admin_success', "'{$name}' 님의 PIN 잠금을 해제했습니다.");
        } catch (Throwable $e) {
            flash('admin_error', '잠금 해제 중 오류가 발생했습니다.');
        }
    } elseif ($action === 'delete' && $wid > 0) {        try {
            /* 작업일지가 있으면 비활성화만 */
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM tt_work_logs WHERE worker_id = {$wid}")->fetchColumn();
            $stmt = $pdo->prepare('SELECT name FROM tt_workers WHERE id = :id');
            $stmt->execute(['id'=>$wid]);
            $name = (string)$stmt->fetchColumn();
            if ($cnt > 0) {
                $pdo->prepare('UPDATE tt_workers SET is_active = 0 WHERE id = :id')->execute(['id'=>$wid]);
                flash('admin_success', "'{$name}' 님은 작업일지가 있어 비활성화 처리했습니다.");
            } else {
                $pdo->prepare('DELETE FROM tt_workers WHERE id = :id')->execute(['id'=>$wid]);
                flash('admin_success', "'{$name}' 님을 삭제했습니다.");
            }
        } catch (Throwable $e) {
            flash('admin_error', '삭제 중 오류가 발생했습니다.');
        }
    }
    redirect('/admin/workers.php');
}

/* ===== 목록 ===== */
$workers = get_workers(false);

/* 각 작업자 작업 통계 */
$statsById = [];
try {
    foreach ($pdo->query(
        'SELECT worker_id, COUNT(*) AS cnt, COALESCE(SUM(work_amount),0) AS amt
         FROM tt_work_logs GROUP BY worker_id') as $row) {
        $statsById[(int)$row['worker_id']] = $row;
    }
} catch (Throwable $e) {}

require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">👷 작업자 관리 (태블릿 로그인)</h2>
      <p class="admin-list-sub">태블릿 작업일지 사이트에 로그인하는 작업자(기사)를 관리합니다. PIN을 설정하면 태블릿에서 이름 선택 후 PIN 입력으로 로그인합니다.</p>
    </div>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <div style="display:grid;grid-template-columns:340px 1fr;gap:18px;align-items:start;">
    <!-- 등록/수정 폼 -->
    <form method="post" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="worker_id" id="fWorkerId" value="0">
      <h3 class="admin-form-section-title" id="fTitle">➕ 작업자 등록</h3>
      <div class="admin-form-row">
        <label>이름 *</label>
        <input type="text" name="name" id="fName" required placeholder="예: 김기술">
      </div>
      <div class="admin-form-row">
        <label>직책</label>
        <input type="text" name="role" id="fRole" placeholder="예: 책임 기사 / 기사">
      </div>
      <div class="admin-form-row">
        <label>연락처</label>
        <input type="text" name="phone" id="fPhone" placeholder="예: 010-1234-5678">
      </div>
      <div class="admin-form-row">
        <label>PIN (숫자 3~8자리)</label>
        <input type="text" name="pin" inputmode="numeric" pattern="\d{3,8}" placeholder="태블릿 로그인용 PIN">
        <p class="admin-form-hint">비워 두면 기존 PIN을 유지합니다(신규 등록 시 로그인 불가).</p>
      </div>
      <div class="admin-form-row">
        <label>상태</label>
        <select name="is_active">
          <option value="1">활성</option>
          <option value="0">비활성</option>
        </select>
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary">💾 저장</button>
        <button type="button" class="btn" id="fReset">새로 입력</button>
      </div>
    </form>

    <!-- 목록 -->
    <div>
      <table class="admin-table">
        <thead>
          <tr>
            <th>이름</th><th>직책</th><th>연락처</th><th>PIN</th>
            <th>작업 통계</th><th>상태</th><th>관리</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($workers)): ?>
          <tr><td colspan="7" class="empty">등록된 작업자가 없습니다. 왼쪽에서 등록해 주세요.</td></tr>
          <?php else: foreach ($workers as $w):
            $st = $statsById[(int)$w['id']] ?? null;
            $pinLock = worker_pin_lock_state($w);
            $pinAttempts = (int)($w['pin_failed_attempts'] ?? 0);
          ?>
          <tr>
            <td><b><?= h($w['name']) ?></b></td>
            <td><?= h($w['role'] ?: '-') ?></td>
            <td><?= h($w['phone'] ?: '-') ?></td>
            <td>
              <?php if ($pinLock['locked']): ?>
                <span style="color:#dc2626;font-weight:800;">🔒 잠김 (<?= (int)$pinLock['minutes'] ?>분 남음)</span>
              <?php elseif (!empty($w['pin_hash'])): ?>
                🔒 설정됨<?= $pinAttempts > 0 ? ' <small class="text-muted">(실패 ' . $pinAttempts . '회)</small>' : '' ?>
              <?php else: ?><span class="text-muted">미설정</span><?php endif; ?>
            </td>
            <td><?= $st ? number_format((int)$st['cnt']) . '건 · ' . number_format((int)$st['amt']) . '원' : '-' ?></td>
            <td><?= (int)$w['is_active'] === 1 ? '<span class="badge badge-success">활성</span>' : '<span class="badge">비활성</span>' ?></td>
            <td>
              <?php if ($pinLock['locked'] || $pinAttempts > 0): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('PIN 잠금/실패 카운터를 해제할까요?');">
                <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                <input type="hidden" name="action" value="unlock">
                <input type="hidden" name="worker_id" value="<?= (int)$w['id'] ?>">
                <button type="submit" class="btn btn-sm" style="padding:4px 10px;font-size:12px;">🔓 잠금 해제</button>
              </form>
              <?php endif; ?>
              <button type="button" class="btn btn-sm btn-edit"
                      data-id="<?= (int)$w['id'] ?>" data-name="<?= h($w['name']) ?>"
                      data-role="<?= h($w['role'] ?: '') ?>" data-phone="<?= h($w['phone'] ?: '') ?>"
                      data-active="<?= (int)$w['is_active'] ?>">✏️ 수정</button>
              <form method="post" style="display:inline" onsubmit="return confirm('이 작업자를 삭제/비활성화할까요?');">
                <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="worker_id" value="<?= (int)$w['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">🗑 삭제</button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
/* 수정 버튼 → 폼 채우기 */
document.querySelectorAll('.btn-edit').forEach(function(b){
  b.addEventListener('click', function(){
    document.getElementById('fWorkerId').value = b.getAttribute('data-id');
    document.getElementById('fName').value = b.getAttribute('data-name');
    document.getElementById('fRole').value = b.getAttribute('data-role');
    document.getElementById('fPhone').value = b.getAttribute('data-phone');
    document.querySelector('select[name=is_active]').value = b.getAttribute('data-active');
    document.getElementById('fTitle').textContent = '✏️ 작업자 수정 — ' + b.getAttribute('data-name');
    window.scrollTo({top:0, behavior:'smooth'});
  });
});
document.getElementById('fReset').addEventListener('click', function(){
  document.getElementById('fWorkerId').value = '0';
  document.getElementById('fName').value = '';
  document.getElementById('fRole').value = '';
  document.getElementById('fPhone').value = '';
  document.getElementById('fTitle').textContent = '➕ 작업자 등록';
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
