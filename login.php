<?php
// /login.php — 일반회원 + 사업자회원 통합 로그인/가입 페이지
require_once __DIR__ . '/core/bootstrap.php';
ensure_coupon_tables(); // 신규 가입 쿠폰팩 지급용 (is_signup_pack 컬럼 자동 보강)
ensure_user_extra_columns(Database::connection());
ensure_price_tier_columns();

$formType = $_POST['form_type'] ?? '';
$memberType = $_POST['member_type'] ?? 'personal'; // personal | business

/* ===== 로그인 처리 (공통) ===== */
if (is_post() && $formType === 'login') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error_login', '유효하지 않은 요청입니다.');
        redirect(BASE_URL . '/login.php');
    }
    $result = Auth::attemptLogin(trim($_POST['email'] ?? ''), $_POST['password'] ?? '');
    if ($result['ok']) {
        // 로그인 성공 시 user_type에 따라 리다이렉트
        if (Auth::isBusiness()) {
            redirect(BASE_URL . '/index.php');
        }
        redirect(BASE_URL . '/index.php');
    }
    flash('error_login', $result['msg']);
    redirect(BASE_URL . '/login.php');
}

/* ===== 일반회원 가입 처리 ===== */
if (is_post() && $formType === 'signup_personal') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error_signup_personal', '유효하지 않은 요청입니다. 다시 시도해주세요.');
        redirect(BASE_URL . '/login.php#signup-personal');
    }

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $name     = trim($_POST['name'] ?? '');
    $phoneRaw = trim($_POST['phone'] ?? '');
    $phone    = preg_replace('/[^0-9]/', '', $phoneRaw);

    $termsAgree   = isset($_POST['terms_agree']);
    $privacyAgree = isset($_POST['privacy_agree']);

    $v = new Validator([
        'email' => $email, 'password' => $password, 'name' => $name, 'phone' => $phoneRaw,
    ]);
    $v->require('email', '이메일')->email('email')
      ->require('password', '비밀번호')->passwordStrength('password')
      ->require('name', '이름')
      ->require('phone', '휴대폰 번호')->phone('phone');

    $pdo = Database::connection();

    $dupEmail = false;
    if (!$v->fails()) {
        $dup = $pdo->prepare('SELECT id FROM tt_users WHERE email = :email');
        $dup->execute(['email' => $email]);
        if ($dup->fetch()) $dupEmail = true;
    }

    $phoneVerified = false;
    if (!$v->fails() && !$dupEmail) {
        $pv = $pdo->prepare(
            'SELECT id FROM tt_phone_verifications
             WHERE phone = :phone AND is_verified = 1 AND created_at > (NOW() - INTERVAL 30 MINUTE)
             ORDER BY id DESC LIMIT 1'
        );
        $pv->execute(['phone' => $phone]);
        $phoneVerified = (bool)$pv->fetch();
    }

    if (!$v->fails() && !$dupEmail && $phoneVerified && $termsAgree && $privacyAgree) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO tt_users
                (email, password_hash, name, phone, user_type, marketing_agree,
                 phone_verified, phone_verified_at,
                 terms_agreed_at, privacy_agreed_at)
             VALUES
                (:email, :hash, :name, :phone, "personal", :agree,
                 1, NOW(),
                 NOW(), NOW())'
        );
        $stmt->execute([
            'email' => $email, 'hash' => $hash, 'name' => $name, 'phone' => $phone,
            'agree' => isset($_POST['marketing_agree']) ? 1 : 0,
        ]);
        /* ===== 신규 가입 쿠폰팩 자동 지급 (관리자가 가입팩으로 설정한 쿠폰) ===== */
        $packIssued = issue_signup_coupon_pack((int)$pdo->lastInsertId());
        flash('success', '일반 회원가입이 완료되었습니다. 로그인해주세요.' . ($packIssued > 0
            ? " 🎉 신규 가입 축하 쿠폰팩 {$packIssued}장이 쿠폰함에 지급되었습니다 — 로그인 후 마이페이지 쿠폰함에서 확인해주세요."
            : ''));
        redirect(BASE_URL . '/login.php');
    }

    $errors = $v->errors();
    if ($dupEmail) $errors['email'] = '이미 가입된 이메일입니다.';
    if (!$phoneVerified && !$v->fails()) $errors['phone'] = '휴대폰 인증을 완료해주세요.';
    if (!$termsAgree || !$privacyAgree) $errors['agree'] = '이용약관과 개인정보 수집·이용에 모두 동의해주셔야 가입할 수 있습니다.';

    $_SESSION['_old'] = [
        'email' => $email, 'name' => $name, 'phone' => $phoneRaw,
    ];
    flash('errors_signup_personal', json_encode($errors, JSON_UNESCAPED_UNICODE));
    redirect(BASE_URL . '/login.php#signup-personal');
}

/* ===== 사업자 회원 가입 처리 ===== */
if (is_post() && $formType === 'signup_business') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error_signup_biz', '유효하지 않은 요청입니다. 다시 시도해주세요.');
        redirect(BASE_URL . '/login.php#signup-business');
    }

    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $name      = trim($_POST['name'] ?? '');
    $phoneRaw  = trim($_POST['phone'] ?? '');
    $phone     = preg_replace('/[^0-9]/', '', $phoneRaw);
    $bizNumber = trim($_POST['biz_number'] ?? '');
    $bizName   = trim($_POST['biz_name'] ?? '');
    $bizOwner  = trim($_POST['biz_owner_name'] ?? '');
    $bizAddr   = trim($_POST['biz_address'] ?? '');

    $termsAgree   = isset($_POST['terms_agree']);
    $privacyAgree = isset($_POST['privacy_agree']);

    $v = new Validator([
        'email' => $email, 'password' => $password, 'name' => $name,
        'phone' => $phoneRaw, 'biz_number' => $bizNumber,
        'biz_name' => $bizName, 'biz_owner_name' => $bizOwner,
        'biz_address' => $bizAddr,
    ]);
    $v->require('email', '이메일')->email('email')
      ->require('password', '비밀번호')->passwordStrength('password')
      ->require('name', '이름')
      ->require('phone', '휴대폰 번호')->phone('phone')
      ->require('biz_number', '사업자등록번호')->bizNumber('biz_number')
      ->require('biz_name', '상호명')
      ->require('biz_owner_name', '대표자명')
      ->require('biz_address', '사업자 주소');

    $pdo = Database::connection();

    $dupEmail = false;
    if (!$v->fails()) {
        $dup = $pdo->prepare('SELECT id FROM tt_users WHERE email = :email');
        $dup->execute(['email' => $email]);
        if ($dup->fetch()) $dupEmail = true;
    }

    $phoneVerified = false;
    if (!$v->fails() && !$dupEmail) {
        $pv = $pdo->prepare(
            'SELECT id FROM tt_phone_verifications
             WHERE phone = :phone AND is_verified = 1 AND created_at > (NOW() - INTERVAL 30 MINUTE)
             ORDER BY id DESC LIMIT 1'
        );
        $pv->execute(['phone' => $phone]);
        $phoneVerified = (bool)$pv->fetch();
    }

    if (!$v->fails() && !$dupEmail && $phoneVerified && $termsAgree && $privacyAgree) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO tt_users
                (email, password_hash, name, phone, user_type, marketing_agree,
                 biz_number, biz_name, biz_owner_name, biz_address,
                 biz_status,
                 phone_verified, phone_verified_at,
                 terms_agreed_at, privacy_agreed_at)
             VALUES
                (:email, :hash, :name, :phone, "business", :agree,
                 :biz_number, :biz_name, :biz_owner_name, :biz_address,
                 "pending",
                 1, NOW(),
                 NOW(), NOW())'
        );
        $stmt->execute([
            'email' => $email, 'hash' => $hash, 'name' => $name, 'phone' => $phone,
            'agree' => isset($_POST['marketing_agree']) ? 1 : 0,
            'biz_number' => $bizNumber, 'biz_name' => $bizName,
            'biz_owner_name' => $bizOwner, 'biz_address' => $bizAddr,
        ]);
        /* [v46] 사업자 회원은 쿠폰 대상이 아니므로 가입 쿠폰팩을 지급하지 않음 */
        flash('success', '사업자 회원가입이 접수되었습니다. 관리자 승인 후 로그인 가능합니다. 승인까지 1~2영업일이 소요됩니다.');
        redirect(BASE_URL . '/login.php');
    }

    $errors = $v->errors();
    if ($dupEmail) $errors['email'] = '이미 가입된 이메일입니다.';
    if (!$phoneVerified && !$v->fails()) $errors['phone'] = '휴대폰 인증을 완료해주세요.';
    if (!$termsAgree || !$privacyAgree) $errors['agree'] = '이용약관과 개인정보 수집·이용에 모두 동의해주셔야 가입할 수 있습니다.';

    $_SESSION['_old'] = [
        'email' => $email, 'name' => $name, 'phone' => $phoneRaw,
        'biz_number' => $bizNumber, 'biz_name' => $bizName, 'biz_owner_name' => $bizOwner,
        'biz_address' => $bizAddr,
    ];
    flash('errors_signup_biz', json_encode($errors, JSON_UNESCAPED_UNICODE));
    redirect(BASE_URL . '/login.php#signup-business');
}

$pageTitle = '로그인';
require __DIR__ . '/includes/header.php';
$errorsSignupPersonal = json_decode(flash('errors_signup_personal') ?? '{}', true);
$errorsSignupBiz = json_decode(flash('errors_signup_biz') ?? '{}', true);
?>

<div class="auth-page">
  <div class="auth-card">

    <!-- ===== 회원 유형 탭 ===== -->
    <div class="auth-member-tabs">
      <button type="button" class="auth-member-tab active" data-tab="login">로그인</button>
      <button type="button" class="auth-member-tab" data-tab="signup-personal">일반 회원가입</button>
      <button type="button" class="auth-member-tab" data-tab="signup-business">사업자 회원가입</button>
    </div>

    <!-- ===== 로그인 뷰 ===== -->
    <div class="auth-view active" id="viewLogin">
      <h1 class="auth-card-title">회원 로그인</h1>
      <?php if ($err = flash('error_login')): ?><p class="error-msg"><?= h($err) ?></p><?php endif; ?>
      <?php if ($ok = flash('success')): ?><p class="success-msg"><?= h($ok) ?></p><?php endif; ?>

      <form method="post" action="<?= BASE_URL ?>/login.php">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_type" value="login">
        <input type="email" name="email" class="auth-field" placeholder="이메일" required>
        <input type="password" name="password" class="auth-field" placeholder="비밀번호" required>
        <button type="submit" class="btn-auth-primary">로그인</button>
      </form>

      <div class="auth-links-row">
        <a href="<?= BASE_URL ?>/find-id.php">아이디 찾기</a>
        <span class="auth-links-divider">|</span>
        <a href="<?= BASE_URL ?>/reset-password.php">비밀번호 재설정</a>
      </div>
    </div>

    <!-- ===== 일반 회원가입 뷰 ===== -->
    <div class="auth-view" id="viewSignupPersonal">
      <h1 class="auth-card-title">일반 회원가입</h1>
      <?php if ($errorsSignupPersonal): ?>
        <ul class="error-list">
          <?php foreach ($errorsSignupPersonal as $msg): ?><li><?= h($msg) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <form method="post" action="<?= BASE_URL ?>/login.php#signup-personal" id="signupPersonalForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_type" value="signup_personal">
        <input type="hidden" name="member_type" value="personal">
        <input type="hidden" name="phone_verified_flag" id="personalPhoneVerifiedFlag" value="0">

        <input type="email" name="email" class="auth-field" value="<?= old('email') ?>" placeholder="이메일" required>
        <input type="password" name="password" class="auth-field" placeholder="비밀번호 (영문+숫자 8자 이상)" required>
        <input type="text" name="name" class="auth-field" value="<?= old('name') ?>" placeholder="이름" required>

        <div class="phone-verify-row inline-verify-row">
          <input type="text" name="phone" id="personalPhone" class="auth-field" value="<?= old('phone') ?>" placeholder="휴대폰 번호 (010-1234-5678)" required>
          <button type="button" id="personalSendCodeBtn" class="btn-verify">인증번호 발송</button>
        </div>
        <div class="phone-verify-row inline-verify-row" id="personalCodeInputRow" style="display:none">
          <input type="text" id="personalCode" class="auth-field" placeholder="인증번호 6자리" maxlength="6">
          <button type="button" id="personalVerifyCodeBtn" class="btn-verify">확인</button>
          <span id="personalCodeTimer" class="code-timer"></span>
        </div>
        <p id="personalPhoneVerifyMsg" class="verify-msg"></p>

        <div class="terms-agree-box">
          <label class="checkbox-line"><input type="checkbox" id="personalAgreeAll"><strong>전체 동의</strong></label>
          <hr class="agree-divider">
          <label class="checkbox-line">
            <input type="checkbox" name="terms_agree" class="agree-required" required>
            <a href="<?= BASE_URL ?>/terms.php" target="_blank">이용약관</a> 동의 (필수)
          </label>
          <label class="checkbox-line">
            <input type="checkbox" name="privacy_agree" class="agree-required" required>
            <a href="<?= BASE_URL ?>/privacy.php" target="_blank">개인정보 수집·이용</a> 동의 (필수)
          </label>
          <label class="checkbox-line">
            <input type="checkbox" name="marketing_agree"> 마케팅 정보 수신 동의 (선택)
          </label>
        </div>

        <button type="submit" class="btn-auth-primary" id="personalSignupSubmitBtn">가입하기</button>
      </form>

      <div class="auth-links-row">
        <span>사업자이신가요?</span>
        <a href="#" data-tab="signup-business" class="auth-switch-link">사업자 회원가입</a>
      </div>
    </div>

    <!-- ===== 사업자 회원가입 뷰 ===== -->
    <div class="auth-view" id="viewSignupBusiness">
      <h1 class="auth-card-title">사업자 회원가입</h1>
      <div class="biz-approval-notice">
        <strong>📋 사업자 승인제 안내</strong>
        <p>사업자 회원가입 후 관리자 승인(1~2영업일)이 완료되어야 로그인 가능합니다.</p>
      </div>
      <?php if ($errorsSignupBiz): ?>
        <ul class="error-list">
          <?php foreach ($errorsSignupBiz as $msg): ?><li><?= h($msg) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <form method="post" action="<?= BASE_URL ?>/login.php#signup-business" id="signupBizForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_type" value="signup_business">
        <input type="hidden" name="member_type" value="business">
        <input type="hidden" name="phone_verified_flag" id="bizPhoneVerifiedFlag" value="0">

        <input type="email" name="email" class="auth-field" value="<?= old('email') ?>" placeholder="이메일" required>
        <input type="password" name="password" class="auth-field" placeholder="비밀번호 (영문+숫자 8자 이상)" required>
        <input type="text" name="name" class="auth-field" value="<?= old('name') ?>" placeholder="이름(담당자)" required>

        <!-- 휴대폰 인증 -->
        <div class="phone-verify-row inline-verify-row">
          <input type="text" name="phone" id="bizPhone" class="auth-field" value="<?= old('phone') ?>" placeholder="휴대폰 번호 (010-1234-5678)" required>
          <button type="button" id="bizSendCodeBtn" class="btn-verify">인증번호 발송</button>
        </div>
        <div class="phone-verify-row inline-verify-row" id="bizCodeInputRow" style="display:none">
          <input type="text" id="bizCode" class="auth-field" placeholder="인증번호 6자리" maxlength="6">
          <button type="button" id="bizVerifyCodeBtn" class="btn-verify">확인</button>
          <span id="bizCodeTimer" class="code-timer"></span>
        </div>
        <p id="bizPhoneVerifyMsg" class="verify-msg"></p>

        <hr class="auth-divider">
        <p class="biz-title">사업자 정보</p>

        <input type="text" name="biz_name" class="auth-field" value="<?= old('biz_name') ?>" placeholder="상호명" required>
        <input type="text" name="biz_owner_name" class="auth-field" value="<?= old('biz_owner_name') ?>" placeholder="대표자명" required>

        <div class="inline-verify-row">
          <input type="text" name="biz_number" id="bizNumberInput" class="auth-field" value="<?= old('biz_number') ?>" placeholder="사업자등록번호 (000-00-00000)" maxlength="12" required>
          <button type="button" id="bizNumberCheckBtn" class="btn-verify">번호 확인</button>
        </div>
        <p id="bizNumberMsg" class="verify-msg"></p>

        <div class="inline-verify-row">
          <input type="text" id="bizAddressBase" class="auth-field" placeholder="주소 찾기를 눌러 주소를 입력해주세요" readonly>
          <button type="button" id="addrSearchBtn" class="btn-verify">주소 찾기</button>
        </div>
        <input type="text" id="bizAddressDetail" class="auth-field" placeholder="상세주소를 입력해주세요">
        <input type="hidden" name="biz_address" id="bizAddressFull">

        <div class="terms-agree-box">
          <label class="checkbox-line"><input type="checkbox" id="bizAgreeAll"><strong>전체 동의</strong></label>
          <hr class="agree-divider">
          <label class="checkbox-line">
            <input type="checkbox" name="terms_agree" class="agree-required" required>
            <a href="<?= BASE_URL ?>/terms.php" target="_blank">이용약관</a> 동의 (필수)
          </label>
          <label class="checkbox-line">
            <input type="checkbox" name="privacy_agree" class="agree-required" required>
            <a href="<?= BASE_URL ?>/privacy.php" target="_blank">개인정보 수집·이용</a> 동의 (필수)
          </label>
          <label class="checkbox-line">
            <input type="checkbox" name="marketing_agree"> 마케팅 정보 수신 동의 (선택)
          </label>
        </div>

        <button type="submit" class="btn-auth-primary" id="bizSignupSubmitBtn">사업자 가입하기</button>
      </form>

      <div class="auth-links-row">
        <span>일반 회원이신가요?</span>
        <a href="#" data-tab="signup-personal" class="auth-switch-link">일반 회원가입</a>
      </div>
    </div>

  </div>
</div>

<script>
(function(){
  /* ===== 회원 유형 탭 전환 ===== */
  const views = {
    'login': document.getElementById('viewLogin'),
    'signup-personal': document.getElementById('viewSignupPersonal'),
    'signup-business': document.getElementById('viewSignupBusiness'),
  };
  const tabs = document.querySelectorAll('.auth-member-tab');

  function showView(name) {
    Object.keys(views).forEach(function (key) {
      views[key].classList.toggle('active', key === name);
    });
    tabs.forEach(function(t) { t.classList.toggle('active', t.dataset.tab === name); });
    history.replaceState(null, '', name === 'login' ? window.location.pathname : '#' + name);
  }

  tabs.forEach(function(tab) {
    tab.addEventListener('click', function() { showView(this.dataset.tab); });
  });
  document.querySelectorAll('.auth-switch-link').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      showView(this.dataset.tab);
    });
  });

  if (window.location.hash === '#signup-personal') showView('signup-personal');
  else if (window.location.hash === '#signup-business') showView('signup-business');

  /* ===== 공통 휴대폰 인증 함수 ===== */
  function setupPhoneVerify(prefix) {
    const phoneInput = document.getElementById(prefix + 'Phone');
    const sendBtn    = document.getElementById(prefix + 'SendCodeBtn');
    const codeRow    = document.getElementById(prefix + 'CodeInputRow');
    const codeInput  = document.getElementById(prefix + 'Code');
    const verifyBtn  = document.getElementById(prefix + 'VerifyCodeBtn');
    const msgEl      = document.getElementById(prefix + 'PhoneVerifyMsg');
    const timerEl    = document.getElementById(prefix + 'CodeTimer');
    const flagEl     = document.getElementById(prefix + 'PhoneVerifiedFlag');

    if (!phoneInput || !sendBtn) return;
    let timerInterval = null, remainSec = 0;

    function startTimer() {
      remainSec = 180;
      clearInterval(timerInterval);
      timerInterval = setInterval(function () {
        remainSec--;
        const m = Math.floor(remainSec / 60), s = String(remainSec % 60).padStart(2, '0');
        if (timerEl) timerEl.textContent = '남은시간 ' + m + ':' + s;
        if (remainSec <= 0) { clearInterval(timerInterval); if (timerEl) timerEl.textContent = '인증시간 만료. 다시 발송해주세요.'; }
      }, 1000);
    }

    sendBtn.addEventListener('click', function () {
      const phone = phoneInput.value.trim();
      if (!phone) { alert('휴대폰 번호를 입력해주세요.'); return; }
      sendBtn.disabled = true;
      sendBtn.textContent = '발송 중...';
      fetch('<?= BASE_URL ?>/ajax-send-phone-code.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'phone=' + encodeURIComponent(phone)
      }).then(r => r.json()).then(data => {
        sendBtn.disabled = false;
        sendBtn.textContent = '인증번호 발송';
        if (data.ok) {
          codeRow.style.display = 'flex';
          startTimer();
          if (data.dev_code) {
            // 무료 모드: 화면에 인증번호 크게 표시
            msgEl.innerHTML = '<strong style="font-size:16px;color:#16a34a;">인증번호: ' + data.dev_code + '</strong><br><span style="font-size:12px;color:#64748b;">위 번호를 입력하고 확인을 눌러주세요</span>';
            msgEl.className = 'verify-msg success';
            // 인증번호 입력란에 자동으로 채우기
            if (codeInput) codeInput.value = data.dev_code;
          } else {
            msgEl.textContent = data.msg;
            msgEl.className = 'verify-msg';
          }
        }
        else { msgEl.textContent = data.msg; msgEl.className = 'verify-msg error'; }
      }).catch((err) => { sendBtn.disabled = false; sendBtn.textContent = '인증번호 발송'; msgEl.textContent = '발송 중 오류: ' + err; msgEl.className = 'verify-msg error'; });
    });

    verifyBtn.addEventListener('click', function () {
      const phone = phoneInput.value.trim(), code = codeInput.value.trim();
      if (!code) { alert('인증번호를 입력해주세요.'); return; }
      fetch('<?= BASE_URL ?>/ajax-verify-phone-code.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'phone=' + encodeURIComponent(phone) + '&code=' + encodeURIComponent(code)
      }).then(r => r.json()).then(data => {
        msgEl.textContent = data.msg;
        if (data.ok) { msgEl.className = 'verify-msg success'; flagEl.value = '1'; clearInterval(timerInterval); if (timerEl) timerEl.textContent = ''; codeInput.disabled = true; verifyBtn.disabled = true; verifyBtn.classList.add('confirmed'); sendBtn.disabled = true; }
        else { msgEl.className = 'verify-msg error'; }
      });
    });
  }

  setupPhoneVerify('personal');
  setupPhoneVerify('biz');

  /* ===== 사업자등록번호 확인 ===== */
  const bizNumberInput = document.getElementById('bizNumberInput');
  const bizNumberBtn   = document.getElementById('bizNumberCheckBtn');
  const bizNumberMsg   = document.getElementById('bizNumberMsg');

  function isValidBizNumber(raw) {
    const v = raw.replace(/[^0-9]/g, '');
    if (v.length !== 10) return false;
    const weights = [1, 3, 7, 1, 3, 7, 1, 3, 5];
    let sum = 0;
    for (let i = 0; i < 9; i++) sum += Number(v[i]) * weights[i];
    sum += Math.floor((Number(v[8]) * 5) / 10);
    return ((10 - (sum % 10)) % 10) === Number(v[9]);
  }

  bizNumberBtn?.addEventListener('click', function () {
    const raw = bizNumberInput.value.trim();
    if (!raw) { alert('사업자등록번호를 입력해주세요.'); return; }
    if (isValidBizNumber(raw)) { bizNumberMsg.textContent = '올바른 형식의 사업자등록번호입니다.'; bizNumberMsg.className = 'verify-msg success'; bizNumberBtn.classList.add('confirmed'); }
    else { bizNumberMsg.textContent = '사업자등록번호 형식이 올바르지 않습니다.'; bizNumberMsg.className = 'verify-msg error'; bizNumberBtn.classList.remove('confirmed'); }
  });

  /* ===== 주소 찾기 ===== */
  document.getElementById('addrSearchBtn')?.addEventListener('click', function () {
    if (typeof daum === 'undefined' || !daum.Postcode) { alert('주소 검색 서비스를 불러오지 못했습니다.'); return; }
    new daum.Postcode({ oncomplete: function (data) {
      document.getElementById('bizAddressBase').value = data.roadAddress || data.jibunAddress;
      document.getElementById('bizAddressDetail').focus();
    }}).open();
  });

  /* ===== 전체 동의 토글 ===== */
  function setupAgreeAll(prefix) {
    const agreeAll = document.getElementById(prefix + 'AgreeAll');
    if (!agreeAll) return;
    const form = agreeAll.closest('form');
    const required = form.querySelectorAll('.agree-required');
    const marketing = form.querySelector('input[name="marketing_agree"]');
    agreeAll.addEventListener('change', function () {
      required.forEach(function(c) { c.checked = agreeAll.checked; });
      if (marketing) marketing.checked = agreeAll.checked;
    });
  }
  setupAgreeAll('personal');
  setupAgreeAll('biz');

  /* ===== 제출 시 검증 ===== */
  document.getElementById('signupPersonalForm')?.addEventListener('submit', function (e) {
    if (document.getElementById('personalPhoneVerifiedFlag').value !== '1') { e.preventDefault(); alert('휴대폰 인증을 먼저 완료해주세요.'); return; }
    const required = this.querySelectorAll('.agree-required');
    if (Array.prototype.some.call(required, function(c) { return !c.checked; })) { e.preventDefault(); alert('필수 약관에 모두 동의해주세요.'); }
  });

  document.getElementById('signupBizForm')?.addEventListener('submit', function (e) {
    const base = document.getElementById('bizAddressBase').value.trim();
    const detail = document.getElementById('bizAddressDetail').value.trim();
    document.getElementById('bizAddressFull').value = base && detail ? (base + ' ' + detail) : base;
    if (!base) { e.preventDefault(); alert('주소 찾기를 눌러 사업자 주소를 입력해주세요.'); return; }
    if (document.getElementById('bizPhoneVerifiedFlag').value !== '1') { e.preventDefault(); alert('휴대폰 인증을 먼저 완료해주세요.'); return; }
    const required = this.querySelectorAll('.agree-required');
    if (Array.prototype.some.call(required, function(c) { return !c.checked; })) { e.preventDefault(); alert('필수 약관에 모두 동의해주세요.'); }
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
