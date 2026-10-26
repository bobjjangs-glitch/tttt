<?php
require __DIR__ . '/inc/bootstrap.php';
if (current_user()) {
    redirect('index.php');
}

$err = '';
if (is_post()) {
    csrf_check();
    $id = in_str($_POST, 'login_id', 30);
    $pw = (string)($_POST['password'] ?? '');
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM users WHERE login_id = ? LIMIT 1');
    $st->execute([$id]);
    $u = $st->fetch();
    $now = date('Y-m-d H:i:s');
    $generic = '아이디 또는 비밀번호가 올바르지 않거나, 잠시 잠겨 있습니다.';

    if ($u && !empty($u['locked_until']) && $u['locked_until'] > $now) {
        $err = $generic;
    } elseif ($u && (int)$u['is_active'] === 1 && password_verify($pw, $u['password_hash'])) {
        $pdo->prepare('UPDATE users SET failed_count = 0, locked_until = NULL, last_login_at = ? WHERE id = ?')
            ->execute([$now, $u['id']]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        audit('login', 'user', (int)$u['id']);
        redirect('index.php');
    } else {
        if ($u) {
            $cnt = (int)$u['failed_count'] + 1;
            if ($cnt >= 5) { // 5회 실패 시 10분 잠금
                $until = date('Y-m-d H:i:s', time() + 600);
                $pdo->prepare('UPDATE users SET failed_count = 0, locked_until = ? WHERE id = ?')->execute([$until, $u['id']]);
            } else {
                $pdo->prepare('UPDATE users SET failed_count = ? WHERE id = ?')->execute([$cnt, $u['id']]);
            }
        }
        audit('login_fail', 'user', $u ? (int)$u['id'] : null, 'id=' . $id);
        $err = $generic;
    }
}
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>로그인</title>
<link rel="stylesheet" href="assets/style.css"></head>
<body><main class="container narrow">
<h1><?= h(cfg('app.name', 'ERP')) ?></h1>
<?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
<form method="post" class="card form-stack">
  <?= csrf_field() ?>
  <label>아이디<input name="login_id" required maxlength="30" autofocus autocomplete="username"></label>
  <label>비밀번호<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn btn-primary" type="submit">로그인</button>
</form>
</main></body></html>
