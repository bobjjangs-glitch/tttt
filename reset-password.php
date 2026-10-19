<?php
require_once __DIR__ . '/core/bootstrap.php';

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('reset_error', '유효하지 않은 요청입니다.');
        redirect(BASE_URL . '/reset-password.php');
    }

    $email       = trim($_POST['email'] ?? '');
    $phone       = preg_replace('/[^0-9]/', '', trim($_POST['phone'] ?? ''));
    $newPassword = $_POST['new_password'] ?? '';

    $v = new Validator(['password' => $newPassword]);
    $v->require('password', '새 비밀번호')->passwordStrength('password');
    if ($v->fails()) {
        flash('reset_error', '비밀번호는 8자 이상, 영문과 숫자를 포함해야 합니다.');
        redirect(BASE_URL . '/reset-password.php');
    }

    $pdo = Database::connection();

    $pv = $pdo->prepare(
        'SELECT id FROM tt_phone_verifications
         WHERE phone = :phone AND is_verified = 1 AND created_at > (NOW() - INTERVAL 30 MINUTE)
         ORDER BY id DESC LIMIT 1'
    );
    $pv->execute(['phone' => $phone]);
    if (!$pv->fetch()) {
        flash('reset_error', '휴대폰 인증을 먼저 완료해주세요.');
        redirect(BASE_URL . '/reset-password.php');
    }

    $stmt = $pdo->prepare('SELECT id FROM tt_users WHERE email = :email AND phone = :phone');
    $stmt->execute(['email' => $email, 'phone' => $phone]);
    $user = $stmt->fetch();

    if (!$user) {
        flash('reset_error', '일치하는 회원 정보를 찾을 수 없습니다.');
        redirect(BASE_URL . '/reset-password.php');
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE tt_users SET password_hash = :hash WHERE id = :id')
        ->execute(['hash' => $hash, 'id' => $user['id']]);

    flash('success', '비밀번호가 재설정되었습니다. 새 비밀번호로 로그인해주세요.');
    redirect(BASE_URL . '/login.php');
}

$pageTitle = '비밀번호 재설정';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-page">
  <div class="auth-card">
    <h1 class="auth-card-title">비밀번호 재설정</h1>
    <?php if ($err = flash('reset_error')): ?><p class="error-msg"><?= h($err) ?></p><?php endif; ?>

    <form method="post" action="<?= BASE_URL ?>/reset-password.php" id="resetForm">
      <?= Csrf::field() ?>
      <input type="email" name="email" class="auth-field" placeholder="이메일" required>

      <div class="phone-verify-row">
        <input type="text" name="phone" id="resetPhone" class="auth-field" placeholder="휴대폰 번호" required>
        <button type="button" id="sendCodeBtn" class="btn-outline">인증번호 발송</button>
      </div>
      <div class="phone-verify-row" id="codeInputRow" style="display:none">
        <input type="text" id="resetCode" class="auth-field" placeholder="인증번호 6자리" maxlength="6">
        <button type="button" id="verifyCodeBtn" class="btn-outline">확인</button>
      </div>
      <p id="verifyMsg" class="verify-msg"></p>

      <input type="password" name="new_password" class="auth-field" placeholder="새 비밀번호 (영문+숫자 8자 이상)" required>
      <button type="submit" class="btn-auth-primary" id="resetSubmitBtn">비밀번호 변경</button>
    </form>

    <div class="auth-links-row">
      <a href="<?= BASE_URL ?>/login.php">로그인으로 돌아가기</a>
    </div>
  </div>
</div>

<script>
(function(){
  const phoneInput = document.getElementById('resetPhone');
  const sendBtn     = document.getElementById('sendCodeBtn');
  const codeRow     = document.getElementById('codeInputRow');
  const codeInput   = document.getElementById('resetCode');
  const verifyBtn   = document.getElementById('verifyCodeBtn');
  const msgEl       = document.getElementById('verifyMsg');

  sendBtn.addEventListener('click', function () {
    const phone = phoneInput.value.trim();
    if (!phone) { alert('휴대폰 번호를 입력해주세요.'); return; }
    fetch('<?= BASE_URL ?>/ajax-send-phone-code.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'phone=' + encodeURIComponent(phone)
    })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        codeRow.style.display = 'flex';
        msgEl.textContent = data.msg;
        msgEl.className = 'verify-msg';
        if (data.dev_code) console.log('[테스트모드] 인증번호:', data.dev_code);
      } else {
        msgEl.textContent = data.msg;
        msgEl.className = 'verify-msg error';
      }
    });
  });

  verifyBtn.addEventListener('click', function () {
    const phone = phoneInput.value.trim();
    const code  = codeInput.value.trim();
    fetch('<?= BASE_URL ?>/ajax-verify-phone-code.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'phone=' + encodeURIComponent(phone) + '&code=' + encodeURIComponent(code)
    })
    .then(r => r.json())
    .then(data => {
      msgEl.textContent = data.msg;
      msgEl.className = data.ok ? 'verify-msg success' : 'verify-msg error';
    });
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
