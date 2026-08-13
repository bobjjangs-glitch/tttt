<?php
// /admin/login.php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

if (AdminAuth::isLoggedIn()) {
    redirect('/admin/index.php');
}

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/login.php');
    }
    $result = AdminAuth::attemptLogin(trim($_POST['username'] ?? ''), $_POST['password'] ?? '');
    if ($result['ok']) {
        redirect('/admin/index.php');
    }
    flash('admin_error', $result['msg'] ?? '아이디 또는 비밀번호가 올바르지 않습니다.');
    redirect('/admin/login.php');
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>관리자 로그인 - <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/admin/assets/css/admin.css">
</head>
<body>
<div class="admin-login-wrap">
  <h1><?= h(SITE_NAME) ?> 관리자</h1>
  <?php if ($err = flash('admin_error')): ?>
    <div class="admin-login-error"><?= h($err) ?></div>
  <?php endif; ?>
  <form method="post" action="<?= BASE_URL ?>/admin/login.php">
    <?= Csrf::field() ?>
    <input type="text" name="username" placeholder="관리자 아이디" required autofocus>
    <input type="password" name="password" placeholder="비밀번호" required>
    <button type="submit">로그인</button>
  </form>
</div>
</body>
</html>
