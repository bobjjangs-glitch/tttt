<?php
require_once __DIR__ . '/core/bootstrap.php';
redirect(BASE_URL . '/login.php#signup');


if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error', '유효하지 않은 요청입니다. 다시 시도해주세요.');
        redirect('/signup.php');
    }

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');

    // Validator는 생성자에 데이터 배열을 받고, 이후 필드명 기준으로 검증한다.
    $v = new Validator([
        'email'    => $email,
        'password' => $password,
        'name'     => $name,
        'phone'    => $phone,
    ]);
    $v->require('email', '이메일')->email('email')
      ->require('password', '비밀번호')->passwordStrength('password')
      ->require('name', '이름')
      ->require('phone', '휴대폰 번호')->phone('phone');

    $pdo = Database::connection();

    // 이메일 중복 체크는 Validator 상태와 별개로 플래그로만 처리한다.
    $dupEmail = false;
    if (!$v->fails()) {
        $dup = $pdo->prepare('SELECT id FROM tt_users WHERE email = :email');
        $dup->execute(['email' => $email]);
        if ($dup->fetch()) {
            $dupEmail = true;
        }
    }

    if (!$v->fails() && !$dupEmail) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO tt_users (email, password_hash, name, phone, marketing_agree)
             VALUES (:email, :hash, :name, :phone, :agree)'
        );
        $stmt->execute([
            'email' => $email, 'hash' => $hash, 'name' => $name, 'phone' => $phone,
            'agree' => isset($_POST['marketing_agree']) ? 1 : 0,
        ]);
        flash('success', '회원가입이 완료되었습니다. 로그인해주세요.');
        redirect('/login.php');
    }

    $errors = $v->errors();
    if ($dupEmail) {
        $errors['email'] = '이미 가입된 이메일입니다.';
    }

    $_SESSION['_old'] = ['email' => $email, 'name' => $name, 'phone' => $phone];
    flash('errors', json_encode($errors, JSON_UNESCAPED_UNICODE));
    redirect('/signup.php');
}

$pageTitle = '회원가입';
require __DIR__ . '/includes/header.php';
$errors = json_decode(flash('errors') ?? '{}', true);
?>
<div class="auth-wrap">
  <h1>회원가입</h1>
  <?php if ($errors): ?>
    <ul class="error-list">
      <?php foreach ($errors as $msg): ?><li><?= h($msg) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <form method="post" action="<?= BASE_URL ?>/signup.php">
    <?= Csrf::field() ?>
    <label>이메일 <input type="email" name="email" value="<?= old('email') ?>" required></label>
    <label>비밀번호 <input type="password" name="password" required></label>
    <label>이름 <input type="text" name="name" value="<?= old('name') ?>" required></label>
    <label>휴대폰 <input type="text" name="phone" value="<?= old('phone') ?>" placeholder="010-1234-5678" required></label>
    <label><input type="checkbox" name="marketing_agree"> 마케팅 정보 수신 동의</label>
    <button type="submit" class="btn-primary">가입하기</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
