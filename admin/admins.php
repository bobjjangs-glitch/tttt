<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requireSuper();

$pdo  = Database::connection();
$myId = (int)AdminAuth::currentAdminId();

$roleLabels = [
    'super'   => '최고관리자',
    'product' => '상품/배너 담당',
    'order'   => '주문/재고 담당',
    'cs'      => '고객응대 담당',
];
$statusLabels = [
    'active'   => '활성',
    'disabled' => '비활성',
];

$errors = [];

function admin_active_super_count(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM tt_admins WHERE role = 'super' AND status = 'active'")->fetchColumn();
}

/* ---------- 계정 생성/수정 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'save_admin') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/admins.php');
    }

    $adminId         = (int)($_POST['admin_id'] ?? 0);
    $username        = trim($_POST['username'] ?? '');
    $name            = trim($_POST['name'] ?? '');

    $validRoles = ['super', 'product', 'order', 'cs'];
    $role = in_array($_POST['role'] ?? '', $validRoles, true) ? $_POST['role'] : 'cs';

    $password        = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $errors[] = '아이디는 영문/숫자/언더스코어 3~30자로 입력해 주세요.';
    }
    if ($name === '') {
        $errors[] = '이름을 입력해 주세요.';
    }
    if ($adminId === 0 && $password === '') {
        $errors[] = '신규 계정은 비밀번호를 반드시 입력해야 합니다.';
    }

    if ($password !== '') {
        if (strlen($password) < 8) {
            $errors[] = '비밀번호는 8자 이상이어야 합니다.';
        }
        if ($passwordConfirm === '') {
            $errors[] = '비밀번호 확인을 입력해 주세요.';
        } elseif ($password !== $passwordConfirm) {
            $errors[] = '비밀번호와 비밀번호 확인이 일치하지 않습니다.';
        }
    }

    if (empty($errors)) {
        $dup = $pdo->prepare('SELECT id FROM tt_admins WHERE username = :u AND id != :ex LIMIT 1');
        $dup->execute(['u' => $username, 'ex' => $adminId]);
        if ($dup->fetch()) {
            $errors[] = '이미 사용 중인 아이디입니다. (' . $username . ')';
        }
    }

    if (empty($errors) && $adminId > 0 && $role !== 'super') {
        $cur = $pdo->prepare('SELECT role, status FROM tt_admins WHERE id = :id');
        $cur->execute(['id' => $adminId]);
        $curRow = $cur->fetch();
        if ($curRow && $curRow['role'] === 'super' && $curRow['status'] === 'active'
            && admin_active_super_count($pdo) <= 1) {
            $errors[] = '마지막 남은 최고관리자의 권한은 낮출 수 없습니다.';
        }
    }

    if (empty($errors)) {
        try {
            if ($adminId > 0) {
                if ($password !== '') {
                    $pdo->prepare('UPDATE tt_admins SET username=:u, name=:n, role=:r, password_hash=:p WHERE id=:id')
                        ->execute([
                            'u' => $username, 'n' => $name, 'r' => $role,
                            'p' => password_hash($password, PASSWORD_DEFAULT), 'id' => $adminId,
                        ]);
                    AdminAuth::log($myId, 'admin_password_reset', "관리자#{$adminId} 비밀번호 변경");
                } else {
                    $pdo->prepare('UPDATE tt_admins SET username=:u, name=:n, role=:r WHERE id=:id')
                        ->execute(['u' => $username, 'n' => $name, 'r' => $role, 'id' => $adminId]);
                }
                AdminAuth::log($myId, 'admin_update', "관리자#{$adminId} 정보 수정 ({$name})");
                flash('admin_success', '관리자 정보가 수정되었습니다.');
            } else {
                $pdo->prepare('INSERT INTO tt_admins (username, password_hash, name, role, status) VALUES (:u, :p, :n, :r, "active")')
                    ->execute([
                        'u' => $username, 'p' => password_hash($password, PASSWORD_DEFAULT),
                        'n' => $name, 'r' => $role,
                    ]);
                $newId = (int)$pdo->lastInsertId();
                AdminAuth::log($myId, 'admin_create', "관리자#{$newId} 생성 ({$username})");
                flash('admin_success', "'{$name}' 관리자 계정이 생성되었습니다.");
            }
        } catch (Throwable $e) {
            error_log('[admin/admins save] ' . $e->getMessage());
            $errors[] = '저장 중 오류가 발생했습니다.';
        }
    }

    if (empty($errors)) {
        redirect('/admin/admins.php');
    }
}

/* ---------- 상태 전환 (활성/비활성) ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect('/admin/admins.php'); }
    $targetId = (int)($_POST['admin_id'] ?? 0);

    if ($targetId === $myId) {
        flash('admin_error', '본인 계정은 스스로 비활성화할 수 없습니다.');
        redirect('/admin/admins.php');
    }

    $stmt = $pdo->prepare('SELECT role, status FROM tt_admins WHERE id = :id');
    $stmt->execute(['id' => $targetId]);
    $target = $stmt->fetch();

    if (!$target) { flash('admin_error', '존재하지 않는 계정입니다.'); redirect('/admin/admins.php'); }

    if ($target['role'] === 'super' && $target['status'] === 'active' && admin_active_super_count($pdo) <= 1) {
        flash('admin_error', '마지막 남은 최고관리자는 비활성화할 수 없습니다.');
        redirect('/admin/admins.php');
    }

    $newStatus = $target['status'] === 'active' ? 'disabled' : 'active';
    $pdo->prepare('UPDATE tt_admins SET status = :s WHERE id = :id')->execute(['s' => $newStatus, 'id' => $targetId]);
    AdminAuth::log($myId, 'admin_status_toggle', "관리자#{$targetId} 상태를 {$newStatus}로 변경");
    flash('admin_success', '계정 상태가 변경되었습니다.');
    redirect('/admin/admins.php');
}

/* ---------- 삭제 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'delete_admin') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect('/admin/admins.php'); }
    $targetId = (int)($_POST['admin_id'] ?? 0);

    if ($targetId === $myId) {
        flash('admin_error', '본인 계정은 스스로 삭제할 수 없습니다.');
        redirect('/admin/admins.php');
    }

    $stmt = $pdo->prepare('SELECT role, status, username FROM tt_admins WHERE id = :id');
    $stmt->execute(['id' => $targetId]);
    $target = $stmt->fetch();

    if (!$target) { flash('admin_error', '존재하지 않는 계정입니다.'); redirect('/admin/admins.php'); }

    if ($target['role'] === 'super' && $target['status'] === 'active' && admin_active_super_count($pdo) <= 1) {
        flash('admin_error', '마지막 남은 최고관리자는 삭제할 수 없습니다.');
        redirect('/admin/admins.php');
    }

    $pdo->prepare('DELETE FROM tt_admins WHERE id = :id')->execute(['id' => $targetId]);
    AdminAuth::log($myId, 'admin_delete', "관리자#{$targetId}({$target['username']}) 삭제");
    flash('admin_success', '관리자 계정이 삭제되었습니다.');
    redirect('/admin/admins.php');
}

/* ---------- 목록 조회 ---------- */
$admins = $pdo->query(
    "SELECT id, username, name, role, status, last_login_at, created_at
     FROM tt_admins ORDER BY (role='super') DESC, id ASC"
)->fetchAll();

$totalCount = count($admins);
$superCount = count(array_filter($admins, fn($a) => $a['role'] === 'super' && $a['status'] === 'active'));

$pageTitle = '관리자 계정 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-card-head-row">
    <h2 class="admin-page-title">관리자 계정 등록</h2>
    <span class="admin-mini-hint">최고관리자만 이 페이지에 접근할 수 있습니다.</span>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="admin-alert admin-alert-error">
      <ul><?php foreach ($errors as $err) echo '<li>' . h($err) . '</li>'; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" class="admin-product-form" id="adminForm" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="form_type" value="save_admin">
    <input type="hidden" name="admin_id" value="0" id="adminIdInput">

    <div class="admin-form-grid">
      <div class="admin-form-row">
        <label>아이디 <span class="req">*</span></label>
        <input type="text" name="username" id="adminUsernameInput" placeholder="예: staff01" autocomplete="off">
        <p class="admin-form-hint">영문/숫자/언더스코어 3~30자</p>
      </div>
      <div class="admin-form-row">
        <label>이름 <span class="req">*</span></label>
        <input type="text" name="name" id="adminNameInput" placeholder="예: 홍길동">
      </div>
      <div class="admin-form-row">
        <label>권한 <span class="req">*</span></label>
        <select name="role" id="adminRoleInput">
          <option value="cs">고객응대 담당</option>
          <option value="order">주문/재고 담당</option>
          <option value="product">상품/배너 담당</option>
          <option value="super">최고관리자</option>
        </select>
      </div>

      <div class="admin-form-row">
        <label id="pwLabel">비밀번호 <span class="req">*</span></label>
        <input type="password" name="password" id="adminPasswordInput" placeholder="8자 이상" autocomplete="new-password">
        <p class="admin-form-hint" id="pwHint">신규 등록 시 필수 (8자 이상)</p>
      </div>
      <div class="admin-form-row">
        <label id="pwConfirmLabel">비밀번호 확인 <span class="req">*</span></label>
        <input type="password" name="password_confirm" id="adminPasswordConfirmInput" placeholder="비밀번호 다시 입력" autocomplete="new-password">
        <p class="admin-form-hint" id="pwConfirmHint">위와 동일하게 입력해 주세요.</p>
      </div>
    </div>

    <div class="admin-form-actions">
      <button type="button" class="btn-admin-secondary" id="adminFormCancelBtn" style="display:none">취소</button>
      <button type="submit" class="btn-admin-primary" id="adminFormSubmitBtn">계정 등록</button>
    </div>
  </form>
</div>

<div class="admin-card">
  <div class="admin-card-head-row">
    <h2>관리자 목록 <span class="admin-count-pill"><?= $totalCount ?>명</span></h2>
    <span class="admin-mini-hint">활성 최고관리자 <strong><?= $superCount ?></strong>명 · 마지막 1명은 보호됩니다</span>
  </div>

  <table class="admin-table-trendy">
    <thead>
      <tr>
        <th>아이디</th><th>이름</th><th>권한</th><th>상태</th><th>최근 로그인</th><th>등록일</th><th style="width:220px"></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($admins)): ?>
      <tr><td colspan="7" class="admin-empty-row">🔑 등록된 관리자가 없습니다.</td></tr>
    <?php else: foreach ($admins as $a): ?>
      <tr>
        <td class="mono">
          <?= h($a['username']) ?>
          <?php if ((int)$a['id'] === $myId): ?><span class="admin-mini-badge">나</span><?php endif; ?>
        </td>
        <td><strong><?= h($a['name']) ?></strong></td>
        <td>
          <span class="admin-role-badge admin-role-<?= h($a['role']) ?>">
            <?= h($roleLabels[$a['role']] ?? $a['role']) ?>
          </span>
        </td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="toggle_status">
            <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
            <button type="submit"
                    class="status-toggle-btn status-badge status-<?= $a['status'] === 'active' ? 'done' : 'cancelled' ?>"
                    <?= (int)$a['id'] === $myId ? 'disabled title="본인 계정은 변경 불가"' : '' ?>>
              <?= h($statusLabels[$a['status']] ?? $a['status']) ?>
            </button>
          </form>
        </td>
        <td class="admin-text-sub"><?= $a['last_login_at'] ? h(date('Y-m-d H:i', strtotime($a['last_login_at']))) : '-' ?></td>
        <td class="admin-text-sub"><?= h(date('Y-m-d', strtotime($a['created_at']))) ?></td>
        <td>
          <button type="button" class="admin-link-btn btn-edit-admin"
                  data-id="<?= (int)$a['id'] ?>" data-username="<?= h($a['username']) ?>"
                  data-name="<?= h($a['name']) ?>" data-role="<?= h($a['role']) ?>">수정</button>
          <form method="post" style="display:inline" onsubmit="return confirm('&quot;<?= h($a['name']) ?>&quot; 계정을 삭제하시겠습니까?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="delete_admin">
            <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
            <button type="submit" class="btn-admin-danger" style="padding:4px 10px;font-size:12px;"
                    <?= (int)$a['id'] === $myId ? 'disabled title="본인 계정은 삭제 불가"' : '' ?>>삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
const pwInput        = document.getElementById('adminPasswordInput');
const pwConfirmInput = document.getElementById('adminPasswordConfirmInput');
const pwLabel         = document.getElementById('pwLabel');
const pwConfirmLabel  = document.getElementById('pwConfirmLabel');
const pwHint          = document.getElementById('pwHint');
const pwConfirmHint   = document.getElementById('pwConfirmHint');

function setPasswordMode(isEdit) {
  if (isEdit) {
    pwLabel.innerHTML = '비밀번호 <span class="admin-form-hint" style="display:inline">(선택)</span>';
    pwConfirmLabel.innerHTML = '비밀번호 확인 <span class="admin-form-hint" style="display:inline">(선택)</span>';
    pwHint.textContent = '비워두면 기존 비밀번호가 유지됩니다.';
    pwConfirmHint.textContent = '비밀번호를 바꿀 때만 동일하게 입력해 주세요.';
  } else {
    pwLabel.innerHTML = '비밀번호 <span class="req">*</span>';
    pwConfirmLabel.innerHTML = '비밀번호 확인 <span class="req">*</span>';
    pwHint.textContent = '신규 등록 시 필수 (8자 이상)';
    pwConfirmHint.textContent = '위와 동일하게 입력해 주세요.';
  }
}

document.querySelectorAll('.btn-edit-admin').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('adminIdInput').value = this.dataset.id;
    document.getElementById('adminUsernameInput').value = this.dataset.username;
    document.getElementById('adminNameInput').value = this.dataset.name;
    document.getElementById('adminRoleInput').value = this.dataset.role;
    pwInput.value = '';
    pwConfirmInput.value = '';
    setPasswordMode(true);
    document.getElementById('adminFormSubmitBtn').textContent = '계정 수정 저장';
    document.getElementById('adminFormCancelBtn').style.display = 'inline-block';
    document.getElementById('adminForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

document.getElementById('adminFormCancelBtn').addEventListener('click', function () {
  document.getElementById('adminIdInput').value = '0';
  document.getElementById('adminUsernameInput').value = '';
  document.getElementById('adminNameInput').value = '';
  document.getElementById('adminRoleInput').value = 'cs';
  pwInput.value = '';
  pwConfirmInput.value = '';
  setPasswordMode(false);
  document.getElementById('adminFormSubmitBtn').textContent = '계정 등록';
  this.style.display = 'none';
});

document.getElementById('adminForm').addEventListener('submit', function (e) {
  const isEdit = document.getElementById('adminIdInput').value !== '0';
  const username = document.getElementById('adminUsernameInput');
  const name = document.getElementById('adminNameInput');
  let ok = true;
  let msg = [];

  [username, name].forEach(el => el.classList.remove('input-error'));

  if (!username.value.trim()) { ok = false; username.classList.add('input-error'); msg.push('아이디를 입력해 주세요.'); }
  if (!name.value.trim())     { ok = false; name.classList.add('input-error'); msg.push('이름을 입력해 주세요.'); }

  if (!isEdit && pwInput.value === '') {
    ok = false; msg.push('신규 등록 시 비밀번호는 필수입니다.');
  }
  if (pwInput.value !== '') {
    if (pwInput.value.length < 8) { ok = false; msg.push('비밀번호는 8자 이상이어야 합니다.'); }
    if (pwInput.value !== pwConfirmInput.value) { ok = false; msg.push('비밀번호와 비밀번호 확인이 일치하지 않습니다.'); }
  }

  if (!ok) { e.preventDefault(); alert(msg.join('\n')); }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
