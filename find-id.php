<?php
require_once __DIR__ . '/core/bootstrap.php';

$foundEmail = null;
$errorMsg = null;

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('find_error', '유효하지 않은 요청입니다.');
        redirect(BASE_URL . '/find-id.php');
    }

    $name  = trim($_POST['name'] ?? '');
    $phone = preg_replace('/[^0-9]/', '', trim($_POST['phone'] ?? ''));

    $pdo = Database::connection();

    // 휴대폰 인증 확인 (최근 30분 이내 인증 완료된 번호만 허용)
    $pv = $pdo->prepare(
        'SELECT id FROM tt_phone_verifications
         WHERE phone = :phone AND is_verified = 1 AND created_at > (NOW() - INTERVAL 30 MINUTE)
         ORDER BY id DESC LIMIT 1'
    );
    $pv->execute(['phone' => $phone]);

    if (!$pv->fetch()) {
        flash('find_error', '휴대폰 인증을 먼저 완료해주세요.');
        redirect(BASE_URL . '/find-id.php');
    }

    $stmt = $pdo->prepare('SELECT email FROM tt_users WHERE name = :name AND phone = :phone');
    $stmt->execute(['name' => $name, 'phone' => $phone]);
    $user = $stmt->fetch();

    if (!$user) {
        flash('find_error', '일치하는 회원 정보를 찾을 수 없습니다.');
        redirect(BASE_URL . '/find-id.php');
    }

    // 이메일 마스킹 (앞 3자리만 노출)
    $email = $user['email'];
    [$local, $domain] = explode('@', $email);
    $maskedLocal = mb_substr($local, 0, 3) . str_repeat('*', max(mb_strlen($local) - 3, 0));
    flash('found_email', $maskedLocal . '@' . $domain);
    redirect(BASE_URL . '/find-id.php');
}

$pageTitle = '아이디 찾기';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-page">
  <div class="auth-card">
    <h1 class="auth-card-title">아이디 찾기</h1>

    <?php if ($err = flash('find_error')): ?><p class="error-msg"><?= h($err) ?></p><?php endif; ?>
    <?php if ($found = flash('found_email')): ?>
      <p class="success-msg">회원님의 이메일은 <strong><?= h($found) ?></strong> 입니다.</p>
    <?php endif; ?>

    <form method="post" action="<?= BASE_URL ?>/find-id.php" id="findIdForm">
      <?= Csrf::field() ?>
      <input type="text" name="name" class="auth-field" placeholder="이름" required>

      <div class="phone-verify-row">
        <input type="text" name="phone" id="findPhone" class="auth-field" placeholder="휴대폰 번호" required>
        <button type="button" id="sendCodeBtn" class="btn-outline">인증번호 발송</button>
      </div>
      <div class="phone-verify-row" id="codeInputRow" style="display:none">
        <input type="text" id="findCode" class="auth-field" placeholder="인증번호 6자리" maxlength="6">
        <button type="button" id="verifyCodeBtn" class="btn-outline">확인</button>
      </div>
      <p id="verifyMsg" class="verify-msg"></p>

      <button type="submit" class="btn-auth-primary">아이디 찾기</button>
    </form>

    <div class="auth-links-row">
      <a href="<?= BASE_URL ?>/login.php">로그인으로 돌아가기</a>
    </div>
  </div>
</div>

<script>
(function(){
  const phoneInput = document.getElementById('findPhone');
  const sendBtn     = document.getElementById('sendCodeBtn');
  const codeRow     = document.getElementById('codeInputRow');
  const codeInput   = document.getElementById('findCode');
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
