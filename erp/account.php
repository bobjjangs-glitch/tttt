<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_once __DIR__ . '/inc/users_lib.php';

header('Cache-Control: no-store');

$u = current_user();
if (!$u) {
    redirect('login.php');
    exit;
}
$uid = (int)$u['id'];
$errors = [];

if (is_post()) {
    csrf_check();
    $cur  = (string)($_POST['current_password'] ?? '');
    $new  = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');
    try {
        $wait = ul_throttle_wait();
        if ($wait > 0) {
            throw new UserLibError('현재 비밀번호를 여러 번 틀려 잠시 막았습니다. ' . (int)ceil($wait / 60) . '분 뒤에 다시 시도하세요.');
        }
        if ($cur === '' || $new === '' || $new2 === '') {
            throw new UserLibError('모든 칸을 입력하세요.');
        }
        if ($new !== $new2) {
            throw new UserLibError('새 비밀번호와 확인 칸이 서로 다릅니다.');
        }
        if ($new === $cur) {
            throw new UserLibError('새 비밀번호가 현재 비밀번호와 같습니다.');
        }
        $pwErr = ul_validate_password($new, (string)$u['login_id']);
        if ($pwErr) {
            $errors = $pwErr;
        } else {
            ul_change_password($uid, $cur, $new);
            ul_throttle_reset();
            session_regenerate_id(true);
            audit('password_change', 'user', $uid, '');
            flash('비밀번호를 변경했습니다.');
            redirect('account.php');
            exit;
        }
    } catch (UserLibError $e) {
        if ($e->getCode() === 1) {
            ul_throttle_fail();
        }
        $errors[] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[ERP] account: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $errors[] = cfg('app.debug', false) ? $e->getMessage() : '처리 중 오류가 발생했습니다.';
    }
}

page_header('내 정보', 'me');
?>
<h1>내 정보</h1>

<div class="card">
  <div class="kv">
    <div><span class="sub">이름</span><br><strong><?= h($u['name']) ?></strong></div>
    <div><span class="sub">아이디</span><br><strong><?= h($u['login_id']) ?></strong></div>
    <div><span class="sub">권한</span><br><strong><?= h(ul_role_label((string)$u['role'])) ?></strong></div>
  </div>
  <p class="hint" style="margin:10px 0 0">이름·권한 변경은 관리자에게 요청하세요.</p>
</div>

<div class="card" id="pw" style="max-width:520px">
  <h2 style="font-size:16px;margin:0 0 12px">비밀번호 변경</h2>
  <?php foreach ($errors as $m): ?>
    <div class="flash err"><?= h($m) ?></div>
  <?php endforeach; ?>
  <form method="post" class="form-stack" autocomplete="off">
    <?= csrf_field() ?>
    <label>현재 비밀번호
      <input type="password" name="current_password" required autocomplete="current-password" <?= (($_GET['tab'] ?? '') === 'password') ? 'autofocus' : '' ?>>
    </label>
    <label>새 비밀번호
      <input type="password" name="new_password" required minlength="<?= PW_MIN ?>" autocomplete="new-password">
    </label>
    <label>새 비밀번호 확인
      <input type="password" name="new_password2" required minlength="<?= PW_MIN ?>" autocomplete="new-password">
    </label>
    <p class="hint" style="margin:0"><?= PW_MIN ?>자 이상, 영문·숫자·기호 중 2가지 이상. 아이디를 포함할 수 없습니다.</p>
    <div><button class="btn btn-primary" type="submit">비밀번호 변경</button></div>
  </form>
</div>
<?php page_footer();
