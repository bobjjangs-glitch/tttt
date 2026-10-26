<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_once __DIR__ . '/inc/users_lib.php';

header('Cache-Control: no-store');

$me = current_user();
if (!$me || ($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    page_header('사용자 관리', 'config');
    echo '<div class="card"><p>관리자만 사용할 수 있는 화면입니다.</p></div>';
    page_footer();
    exit;
}
$meId = (int)$me['id'];

// 구조 확인 (비밀번호 컬럼, 권한 목록)
try {
    $roles = ul_roles();
    ul_pw_col();
} catch (Throwable $e) {
    page_header('사용자 관리', 'config');
    echo '<div class="flash err">' . h($e->getMessage()) . '</div>';
    page_footer();
    exit;
}

$errors = [];
$issued = null; // [로그인 ID, 임시 비밀번호] — 이 응답에서만 한 번 표시
$form = ['login_id' => '', 'name' => '', 'role' => in_array('office', $roles, true) ? 'office' : $roles[0]];

if (is_post()) {
    csrf_check();
    $act = in_str($_POST, 'act', 10);
    try {
        if ($act === 'create') {
            $login = in_str($_POST, 'login_id', 30);
            $name  = in_str($_POST, 'name', 50);
            $role  = in_str($_POST, 'role', 20);
            $pw    = (string)($_POST['password'] ?? '');
            $form  = ['login_id' => $login, 'name' => $name, 'role' => $role];

            if (!ul_valid_login_id($login)) {
                throw new UserLibError('아이디는 영문·숫자·._- 3~30자로 입력하세요.');
            }
            if ($name === '') {
                throw new UserLibError('이름을 입력하세요.');
            }
            if (!in_array($role, $roles, true)) {
                throw new UserLibError('권한 값이 올바르지 않습니다.');
            }
            if ($pw === '') {
                $pw = ul_gen_password();
            } else {
                $pwErr = ul_validate_password($pw, $login);
                if ($pwErr) {
                    $errors = $pwErr;
                    throw new UserLibError('');
                }
            }
            $newId = ul_insert_user($login, $name, $role, $pw);
            audit('user_create', 'user', $newId, $login . ' / ' . $role);
            $issued = [$login, $pw];
            $form = ['login_id' => '', 'name' => '', 'role' => $form['role']];
        } elseif ($act === 'update') {
            $id     = in_int($_POST, 'id');
            $name   = in_str($_POST, 'name', 50);
            $role   = in_str($_POST, 'role', 20);
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') {
                throw new UserLibError('이름을 입력하세요.');
            }
            if (!in_array($role, $roles, true)) {
                throw new UserLibError('권한 값이 올바르지 않습니다.');
            }
            ul_update_user($meId, $id, $name, $role, $active);
            audit('user_update', 'user', $id, $role . ' / active=' . $active);
            flash('사용자 정보를 저장했습니다.');
            redirect('users.php');
            exit;
        } elseif ($act === 'reset') {
            $id = in_int($_POST, 'id');
            $pw = ul_gen_password();
            $login = ul_reset_password($id, $pw);
            audit('user_pw_reset', 'user', $id, $login);
            $issued = [$login, $pw];
        } else {
            throw new UserLibError('요청을 처리할 수 없습니다.');
        }
    } catch (UserLibError $e) {
        if ($e->getMessage() !== '') {
            $errors[] = $e->getMessage();
        }
    } catch (Throwable $e) {
        error_log('[ERP] users: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $errors[] = cfg('app.debug', false) ? $e->getMessage() : '처리 중 오류가 발생했습니다.';
    }
}

$users = ul_fetch_users();
$adminCnt = ul_active_admin_count();

page_header('사용자 관리', 'config');
?>
<style>
.pw-issued{background:#fff7e0;border:1px solid #f0cf83;border-radius:12px;padding:14px 16px;margin-bottom:14px;color:#6b3410}
.pw-issued code{display:inline-block;margin:6px 0;padding:6px 12px;border-radius:8px;background:#fff;border:1px solid #f0cf83;font-size:18px;font-weight:800;letter-spacing:.06em;color:#151b26;user-select:all}
</style>
<h1>사용자 관리 <small>활성 관리자 <?= (int)$adminCnt ?>명</small></h1>

<?php foreach ($errors as $m): ?>
  <div class="flash err"><?= h($m) ?></div>
<?php endforeach; ?>

<?php if ($issued): ?>
  <div class="pw-issued">
    <strong>아이디 <?= h($issued[0]) ?> 의 임시 비밀번호입니다.</strong><br>
    <code><?= h($issued[1]) ?></code><br>
    이 화면을 벗어나면 다시 볼 수 없습니다. 지금 사용자에게 전달하고, 첫 로그인 후 <b>내 정보 → 비밀번호 변경</b>에서 바꾸도록 안내하세요.
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="font-size:16px;margin:0 0 12px">사용자 추가</h2>
  <form method="post" class="grid-form" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="create">
    <label>아이디<input name="login_id" required maxlength="30" value="<?= h($form['login_id']) ?>" autocomplete="off"></label>
    <label>이름<input name="name" required maxlength="50" value="<?= h($form['name']) ?>"></label>
    <label>권한
      <select name="role">
        <?php foreach ($roles as $r): ?>
          <option value="<?= h($r) ?>" <?= $form['role'] === $r ? 'selected' : '' ?>><?= h(ul_role_label($r)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>초기 비밀번호 (비워 두면 자동 생성)
      <input type="text" name="password" maxlength="72" autocomplete="off">
    </label>
    <div class="wide"><button class="btn btn-primary" type="submit">사용자 추가</button></div>
  </form>
</div>

<table class="table">
  <thead>
    <tr><th>아이디</th><th>이름</th><th>권한</th><th>사용</th><th>등록일</th><th>관리</th></tr>
  </thead>
  <tbody>
  <?php foreach ($users as $r):
      $rid  = (int)$r['id'];
      $self = ($rid === $meId);
      $fid  = 'uf' . $rid;
      $on   = ((int)$r['is_active'] === 1); ?>
    <tr class="<?= $on ? '' : 'muted' ?>">
      <td>
        <form id="<?= h($fid) ?>" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="id" value="<?= $rid ?>">
        </form>
        <strong><?= h($r['login_id']) ?></strong><?= $self ? ' <small class="sub">(나)</small>' : '' ?>
      </td>
      <td><input name="name" form="<?= h($fid) ?>" value="<?= h($r['name']) ?>" maxlength="50" required></td>
      <td>
        <?php if ($self): ?>
          <input type="hidden" name="role" form="<?= h($fid) ?>" value="<?= h($r['role']) ?>">
          <select disabled><option><?= h(ul_role_label((string)$r['role'])) ?></option></select>
        <?php else: ?>
          <select name="role" form="<?= h($fid) ?>">
            <?php foreach ($roles as $ro): ?>
              <option value="<?= h($ro) ?>" <?= $r['role'] === $ro ? 'selected' : '' ?>><?= h(ul_role_label($ro)) ?></option>
            <?php endforeach; ?>
            <?php if (!in_array($r['role'], $roles, true)): ?>
              <option value="<?= h($r['role']) ?>" selected><?= h($r['role']) ?> (목록에 없음)</option>
            <?php endif; ?>
          </select>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($self): ?>
          <input type="hidden" name="is_active" form="<?= h($fid) ?>" value="1">
          <input type="checkbox" checked disabled>
        <?php else: ?>
          <input type="checkbox" name="is_active" value="1" form="<?= h($fid) ?>" <?= $on ? 'checked' : '' ?>>
        <?php endif; ?>
      </td>
      <td><?= h(isset($r['created_at']) ? substr((string)$r['created_at'], 0, 10) : '') ?></td>
      <td style="white-space:nowrap">
        <button class="btn btn-sm btn-primary" type="submit" form="<?= h($fid) ?>">저장</button>
        <?php if (!$self): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= h($r['login_id']) ?> 의 비밀번호를 임시 비밀번호로 바꿀까요? 기존 비밀번호는 즉시 사용할 수 없습니다.')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="reset">
            <input type="hidden" name="id" value="<?= $rid ?>">
            <button class="btn btn-sm btn-danger" type="submit">비밀번호 재발급</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?><tr><td colspan="6" class="empty">사용자가 없습니다.</td></tr><?php endif; ?>
  </tbody>
</table>
<p class="hint">사용자는 삭제하지 않고 &quot;사용&quot; 체크를 해제해 비활성화합니다. 전표와 감사 로그가 사용자 기록을 참조하기 때문입니다. 본인 계정은 관리자 권한과 사용 상태를 바꿀 수 없습니다.</p>
<?php page_footer();
