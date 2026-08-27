<?php
// /login.php
require_once __DIR__ . '/core/bootstrap.php';

$formType = $_POST['form_type'] ?? '';

/* ===== 로그인 처리 ===== */
if (is_post() && $formType === 'login') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error_login', '유효하지 않은 요청입니다.');
        redirect(BASE_URL . '/login.php');
    }
    $result = Auth::attemptLogin(trim($_POST['email'] ?? ''), $_POST['password'] ?? '');
    if ($result['ok']) {
        redirect(BASE_URL . '/index.php');
    }
    flash('error_login', $result['msg']);
    redirect(BASE_URL . '/login.php');
}

/* ===== 회원가입 처리 ===== */
if (is_post() && $formType === 'signup') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error_signup', '유효하지 않은 요청입니다. 다시 시도해주세요.');
        redirect(BASE_URL . '/login.php#signup');
    }

    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $name      = trim($_POST['name'] ?? '');
    $phoneRaw  = trim($_POST['phone'] ?? '');
    $phone     = preg_replace('/[^0-9]/', '', $phoneRaw);
    $bizNumber = trim($_POST['biz_number'] ?? '');
    $bizName   = trim($_POST['biz_name'] ?? '');
    $bizOwner  = trim($_POST['biz_owner_name'] ?? '');
    $bizAddr   = trim($_POST['biz_address'] ?? ''); // JS에서 base+detail 합쳐서 전송됨

    $termsAgree   = isset($_POST['terms_agree']);
    $privacyAgree = isset($_POST['privacy_agree']);

    $v = new Validator([
        'email' => $email, 'password' => $password, 'name' => $name,
        'phone' => $phoneRaw, 'biz_number' => $bizNumber,
        'biz_name' => $bizName, 'biz_owner_name' => $bizOwner,
        'biz_address' => $bizAddr, // ★ 추가: require 체크 대상에 포함
    ]);
    $v->require('email', '이메일')->email('email')
      ->require('password', '비밀번호')->passwordStrength('password')
      ->require('name', '이름')
      ->require('phone', '휴대폰 번호')->phone('phone')
      ->require('biz_number', '사업자등록번호')->bizNumber('biz_number')
      ->require('biz_name', '상호명')
      ->require('biz_owner_name', '대표자명')
      ->require('biz_address', '사업자 주소'); // ★ 추가

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
                (email, password_hash, name, phone, marketing_agree,
                 biz_number, biz_name, biz_owner_name, biz_address,
                 phone_verified, phone_verified_at,
                 terms_agreed_at, privacy_agreed_at)
             VALUES
                (:email, :hash, :name, :phone, :agree,
                 :biz_number, :biz_name, :biz_owner_name, :biz_address,
                 1, NOW(),
                 NOW(), NOW())'
        );
        $stmt->execute([
            'email' => $email, 'hash' => $hash, 'name' => $name, 'phone' => $phone,
            'agree' => isset($_POST['marketing_agree']) ? 1 : 0,
            'biz_number' => $bizNumber, 'biz_name' => $bizName,
            'biz_owner_name' => $bizOwner, 'biz_address' => $bizAddr,
        ]);
        flash('success', '회원가입이 완료되었습니다. 로그인해주세요.');
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
    flash('errors_signup', json_encode($errors, JSON_UNESCAPED_UNICODE));
    redirect(BASE_URL . '/login.php#signup');
}

$pageTitle = '로그인';
require __DIR__ . '/includes/header.php';
$errorsSignup = json_decode(flash('errors_signup') ?? '{}', true);
?>

<div class="auth-page">
  <div class="auth-card">

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
        <span class="auth-links-divider">|</span>
        <a href="#" data-view="signup" class="auth-switch-link">회원가입</a>
      </div>
    </div>

    <!-- ===== 회원가입 뷰 (사업자 회원 전용) ===== -->
    <div class="auth-view" id="viewSignup">
      <h1 class="auth-card-title">사업자 회원가입</h1>
      <?php if ($errorsSignup): ?>
        <ul class="error-list">
          <?php foreach ($errorsSignup as $msg): ?><li><?= h($msg) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <form method="post" action="<?= BASE_URL ?>/login.php#signup" id="signupForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_type" value="signup">
        <input type="hidden" name="phone_verified_flag" id="phoneVerifiedFlag" value="0">

        <input type="email" name="email" class="auth-field" value="<?= old('email') ?>" placeholder="이메일" required>
        <input type="password" name="password" class="auth-field" placeholder="비밀번호 (영문+숫자 8자 이상)" required>
        <input type="text" name="name" class="auth-field" value="<?= old('name') ?>" placeholder="이름" required>

        <!-- 휴대폰 인증 (기존 정상 동작 스타일 — 이 스타일을 기준으로 아래 두 항목을 통일함) -->
        <div class="phone-verify-row inline-verify-row">
          <input type="text" name="phone" id="signupPhone" class="auth-field" value="<?= old('phone') ?>" placeholder="휴대폰 번호 (010-1234-5678)" required>
          <button type="button" id="sendCodeBtn" class="btn-verify">인증번호 발송</button>
        </div>
        <div class="phone-verify-row inline-verify-row" id="codeInputRow" style="display:none">
          <input type="text" id="signupCode" class="auth-field" placeholder="인증번호 6자리" maxlength="6">
          <button type="button" id="verifyCodeBtn" class="btn-verify">확인</button>
          <span id="codeTimer" class="code-timer"></span>
        </div>
        <p id="phoneVerifyMsg" class="verify-msg"></p>

        <hr class="auth-divider">
        <p class="biz-title">사업자 정보</p>

        <input type="text" name="biz_name" class="auth-field" value="<?= old('biz_name') ?>" placeholder="상호명" required>
        <input type="text" name="biz_owner_name" class="auth-field" value="<?= old('biz_owner_name') ?>" placeholder="대표자명" required>

        <!-- 사업자등록번호 + 확인 버튼 (휴대폰 인증과 동일한 스타일로 통일) -->
        <div class="inline-verify-row">
          <input type="text" name="biz_number" id="bizNumberInput" class="auth-field" value="<?= old('biz_number') ?>" placeholder="사업자등록번호 (000-00-00000)" maxlength="12" required>
          <button type="button" id="bizNumberCheckBtn" class="btn-verify">번호 확인</button>
        </div>
        <p id="bizNumberMsg" class="verify-msg"></p>

        <!-- 주소 찾기 (다음 우편번호 서비스 — 우편번호 입력창은 제거, 도로명주소만 사용) -->
        <div class="inline-verify-row">
          <input type="text" id="bizAddressBase" class="auth-field" placeholder="주소 찾기를 눌러 주소를 입력해주세요" readonly>
          <button type="button" id="addrSearchBtn" class="btn-verify">주소 찾기</button>
        </div>
        <input type="text" id="bizAddressDetail" class="auth-field" placeholder="상세주소를 입력해주세요">
        <!-- 최종 전송용 hidden: base + detail을 합쳐서 서버로 전송 -->
        <input type="hidden" name="biz_address" id="bizAddressFull">

        <div class="terms-agree-box">
          <label class="checkbox-line"><input type="checkbox" id="agreeAll"><strong>전체 동의</strong></label>
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

        <button type="submit" class="btn-auth-primary" id="signupSubmitBtn">가입하기</button>
      </form>

      <div class="auth-links-row">
        <span>이미 계정이 있으신가요?</span>
        <a href="#" data-view="login" class="auth-switch-link">로그인</a>
      </div>
    </div>

  </div>
</div>

<script>
(function(){
  /* ===== 로그인/회원가입 카드 내 전환 ===== */
  const views = {
    login: document.getElementById('viewLogin'),
    signup: document.getElementById('viewSignup'),
  };

  function showView(name) {
    Object.keys(views).forEach(function (key) {
      views[key].classList.toggle('active', key === name);
    });
    history.replaceState(null, '', name === 'signup' ? '#signup' : window.location.pathname);
  }

  document.querySelectorAll('.auth-switch-link').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      showView(this.dataset.view);
    });
  });

  if (window.location.hash === '#signup') {
    showView('signup');
  }

  /* ===== 휴대폰 인증 ===== */
  const phoneInput   = document.getElementById('signupPhone');
  const sendBtn      = document.getElementById('sendCodeBtn');
  const codeRow      = document.getElementById('codeInputRow');
  const codeInput    = document.getElementById('signupCode');
  const verifyBtn    = document.getElementById('verifyCodeBtn');
  const msgEl        = document.getElementById('phoneVerifyMsg');
  const timerEl      = document.getElementById('codeTimer');
  const verifiedFlag = document.getElementById('phoneVerifiedFlag');

  let timerInterval = null;
  let remainSec = 0;

  function startTimer() {
    remainSec = 180;
    clearInterval(timerInterval);
    timerInterval = setInterval(function () {
      remainSec--;
      const m = Math.floor(remainSec / 60);
      const s = String(remainSec % 60).padStart(2, '0');
      timerEl.textContent = `남은시간 ${m}:${s}`;
      if (remainSec <= 0) {
        clearInterval(timerInterval);
        timerEl.textContent = '인증시간이 만료되었습니다. 다시 발송해주세요.';
      }
    }, 1000);
  }

  sendBtn.addEventListener('click', function () {
    const phone = phoneInput.value.trim();
    if (!phone) { alert('휴대폰 번호를 입력해주세요.'); return; }

    sendBtn.disabled = true;
    fetch('<?= BASE_URL ?>/ajax-send-phone-code.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'phone=' + encodeURIComponent(phone)
    })
    .then(r => r.json())
    .then(data => {
      sendBtn.disabled = false;
      if (data.ok) {
        codeRow.style.display = 'flex';
        msgEl.textContent = data.msg;
        msgEl.className = 'verify-msg';
        startTimer();
        if (data.dev_code) console.log('[테스트모드] 인증번호:', data.dev_code);
      } else {
        msgEl.textContent = data.msg;
        msgEl.className = 'verify-msg error';
      }
    })
    .catch(() => {
      sendBtn.disabled = false;
      msgEl.textContent = '발송 중 오류가 발생했습니다.';
      msgEl.className = 'verify-msg error';
    });
  });

  verifyBtn.addEventListener('click', function () {
    const phone = phoneInput.value.trim();
    const code  = codeInput.value.trim();
    if (!code) { alert('인증번호를 입력해주세요.'); return; }

    fetch('<?= BASE_URL ?>/ajax-verify-phone-code.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'phone=' + encodeURIComponent(phone) + '&code=' + encodeURIComponent(code)
    })
    .then(r => r.json())
    .then(data => {
      msgEl.textContent = data.msg;
      if (data.ok) {
        msgEl.className = 'verify-msg success';
        verifiedFlag.value = '1';
        clearInterval(timerInterval);
        timerEl.textContent = '';
        codeInput.disabled = true;
        verifyBtn.disabled = true;
        verifyBtn.classList.add('confirmed');
        sendBtn.disabled = true;
      } else {
        msgEl.className = 'verify-msg error';
      }
    });
  });

  /* ===== 사업자등록번호 형식+체크섬 확인 (클라이언트 측 즉시 확인용, 서버도 이중 검증함) ===== */
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
    const checkDigit = (10 - (sum % 10)) % 10;
    return checkDigit === Number(v[9]);
  }

  bizNumberBtn.addEventListener('click', function () {
    const raw = bizNumberInput.value.trim();
    if (!raw) { alert('사업자등록번호를 입력해주세요.'); return; }

    if (isValidBizNumber(raw)) {
      bizNumberMsg.textContent = '올바른 형식의 사업자등록번호입니다. (국세청 진위확인은 가입 후 관리자가 확인합니다)';
      bizNumberMsg.className = 'verify-msg success';
      bizNumberBtn.classList.add('confirmed');
    } else {
      bizNumberMsg.textContent = '사업자등록번호 형식이 올바르지 않습니다. 다시 확인해주세요.';
      bizNumberMsg.className = 'verify-msg error';
      bizNumberBtn.classList.remove('confirmed');
    }
  });

  /* ===== 주소 찾기 (다음 우편번호 서비스) ===== */
  const addrSearchBtn   = document.getElementById('addrSearchBtn');
  const bizAddressBase  = document.getElementById('bizAddressBase');
  const bizAddressDetail= document.getElementById('bizAddressDetail');

  addrSearchBtn.addEventListener('click', function () {
    if (typeof daum === 'undefined' || !daum.Postcode) {
      alert('주소 검색 서비스를 불러오지 못했습니다. 잠시 후 다시 시도해주세요.');
      return;
    }
    new daum.Postcode({
      oncomplete: function (data) {
        bizAddressBase.value = data.roadAddress || data.jibunAddress;
        bizAddressDetail.focus();
      }
    }).open();
  });

  /* ===== 전체 동의 토글 ===== */
  const agreeAll = document.getElementById('agreeAll');
  const agreeRequired = document.querySelectorAll('.agree-required');
  const marketingChk = document.querySelector('input[name="marketing_agree"]');
  if (agreeAll) {
    agreeAll.addEventListener('change', function () {
      agreeRequired.forEach(function (chk) { chk.checked = agreeAll.checked; });
      if (marketingChk) marketingChk.checked = agreeAll.checked;
    });
  }

  /* ===== 제출 시: 주소 합치기 + 각종 필수 확인 ===== */
  document.getElementById('signupForm').addEventListener('submit', function (e) {
    // base + detail 을 합쳐서 hidden 필드에 채운다 (기존에는 이 단계가 없어서 항상 빈 값이 전송됐음)
    const base = bizAddressBase.value.trim();
    const detail = bizAddressDetail.value.trim();
    document.getElementById('bizAddressFull').value = base && detail ? (base + ' ' + detail) : base;

    if (!base) {
      e.preventDefault();
      alert('주소 찾기를 눌러 사업자 주소를 입력해주세요.');
      return;
    }
    if (verifiedFlag.value !== '1') {
      e.preventDefault();
      alert('휴대폰 인증을 먼저 완료해주세요.');
      return;
    }
    const missing = Array.prototype.some.call(agreeRequired, function (chk) { return !chk.checked; });
    if (missing) {
      e.preventDefault();
      alert('이용약관과 개인정보 수집·이용에 모두 동의해주셔야 가입할 수 있습니다.');
    }
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
