<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = '태블릿 로그인';
$step = $_GET['step'] ?? 'worker';          // worker → pin
$selectedWorkerId = (int)($_GET['worker'] ?? ($_SESSION['tbl_login_worker'] ?? 0));
$loginError = '';
$loginOk = false;

/* 이미 로그인되어 있으면 메인으로 */
if (tablet_current_worker()) {
    redirect('/tablet/index.php');
}

$workers = get_workers(true);
$selectedWorker = null;
foreach ($workers as $w) {
    if ((int)$w['id'] === $selectedWorkerId) $selectedWorker = $w;
}

/* ===== 로그인 처리 (연속 실패 5회 → 5분 잠금) ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $loginError = '유효하지 않은 요청입니다. 다시 시도해 주세요.';
    } else {
        $wid = (int)($_POST['worker_id'] ?? 0);
        $pin = trim((string)($_POST['pin'] ?? ''));
        $stmt = Database::connection()->prepare('SELECT * FROM tt_workers WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $wid]);
        $w = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$w) {
            $loginError = '작업자를 다시 선택해 주세요.';
        } else {
            /* PIN 잠금 상태면 먼저 차단 */
            $lock = worker_pin_lock_state($w);
            if ($lock['locked']) {
                $loginError = 'PIN을 ' . MAX_PIN_ATTEMPTS . '회 잘못 입력해 잠겨 있습니다. ' . $lock['minutes'] . '분 후 다시 시도해 주세요.';
                $_SESSION['tbl_login_worker'] = $wid;
                $step = 'pin';
                $selectedWorkerId = $wid;
                foreach ($workers as $wk) { if ((int)$wk['id'] === $wid) $selectedWorker = $wk; }
            } elseif (empty($w['pin_hash']) || !password_verify($pin, $w['pin_hash'])) {
                /* 실패 누적 → 5회 도달 시 잠금 */
                $attempts = (int)($w['pin_failed_attempts'] ?? 0) + 1;
                if ($attempts >= MAX_PIN_ATTEMPTS) {
                    Database::connection()->prepare('UPDATE tt_workers SET pin_failed_attempts = 0, pin_locked_until = :lu WHERE id = :id')
                        ->execute(['lu' => date('Y-m-d H:i:s', time() + PIN_LOCK_SECONDS), 'id' => $wid]);
                    $loginError = 'PIN을 ' . MAX_PIN_ATTEMPTS . '회 잘못 입력해 ' . (int)(PIN_LOCK_SECONDS / 60) . '분간 잠깁니다. 잠시 후 다시 시도해 주세요.';
                } else {
                    Database::connection()->prepare('UPDATE tt_workers SET pin_failed_attempts = :fa WHERE id = :id')
                        ->execute(['fa' => $attempts, 'id' => $wid]);
                    $left = MAX_PIN_ATTEMPTS - $attempts;
                    $loginError = 'PIN이 올바르지 않습니다. (남은 시도 ' . $left . '회)';
                }
                $_SESSION['tbl_login_worker'] = $wid;
                $step = 'pin';
                $selectedWorkerId = $wid;
                foreach ($workers as $wk) { if ((int)$wk['id'] === $wid) $selectedWorker = $wk; }
            } else {
                /* 성공 → 실패 카운터/잠금 초기화 */
                try {
                    Database::connection()->prepare('UPDATE tt_workers SET pin_failed_attempts = 0, pin_locked_until = NULL WHERE id = :id')
                        ->execute(['id' => $wid]);
                } catch (Throwable $e) {}
                session_regenerate_id(true);
                $_SESSION['tt_worker_id'] = (int)$w['id'];
                $_SESSION['tt_worker_name'] = $w['name'];
                unset($_SESSION['tbl_login_worker']);
                $loginOk = true;
            }
        }
    }
}
if ($loginOk) redirect('/tablet/index.php');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>태블릿 로그인 — 작업일지</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/tablet/assets/css/tablet.css">
</head>
<body class="tablet-body">
<div class="login-wrap">
  <div class="login-logo">🔧 <?= h(defined('SITE_NAME') ? SITE_NAME : 'TIRETOP') ?></div>
  <div class="login-sub">태블릿 작업일지 — 작업자 로그인</div>

  <?php if ($loginError): ?><div class="notice notice-err"><?= h($loginError) ?></div><?php endif; ?>

  <?php if (empty($workers)): ?>
    <div class="tablet-card">
      <div class="empty-state">
        등록된 작업자가 없습니다.<br>
        관리자 페이지(작업자 관리)에서 작업자를 등록해 주세요.<br>
        <small style="font-weight:500;color:#9ca3af;">관리자 세션으로 로그인한 상태면 상단 없이 바로 열람할 수 있습니다.</small>
      </div>
    </div>
  <?php elseif ($step !== 'pin' || !$selectedWorker): ?>

    <!-- 1단계: 작업자 선택 -->
    <div class="tablet-card">
      <div class="tablet-card-title">작업자 선택 <span class="count-chip"><?= count($workers) ?>명</span></div>
      <div class="worker-select-list">
        <?php foreach ($workers as $w): ?>
        <a href="?step=pin&worker=<?= (int)$w['id'] ?>" style="text-decoration:none;color:inherit;display:block;">
          <button type="button" style="width:100%;">
            <span>👤 <?= h($w['name']) ?></span>
            <span class="wrole"><?= h($w['role'] ?: '기사') ?></span>
          </button>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

  <?php else: ?>

    <!-- 2단계: PIN 입력 -->
    <div class="tablet-card">
      <div class="tablet-card-title">
        <?= h($selectedWorker['name']) ?> 님
        <a href="?step=worker" class="tablet-btn tablet-btn-ghost tablet-btn-sm">↩ 다시 선택</a>
      </div>
      <?php $pinLock = worker_pin_lock_state($selectedWorker); ?>
      <?php if ($pinLock['locked']): ?>
      <div class="notice notice-warn">🔒 PIN을 <?= MAX_PIN_ATTEMPTS ?>회 잘못 입력해 잠겨 있습니다 — <?= (int)$pinLock['minutes'] ?>분 후 다시 시도할 수 있어요.</div>
      <?php endif; ?>
      <form method="post" id="pinForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
        <input type="hidden" name="worker_id" value="<?= (int)$selectedWorker['id'] ?>">
        <input type="hidden" name="pin" id="pinInput" maxlength="8" <?= $pinLock['locked'] ? 'disabled' : '' ?>>
        <div class="pin-display" id="pinDisplay" data-maxlen="8"></div>
        <div class="keypad">
          <?php foreach (['1','2','3','4','5','6','7','8','9'] as $k): ?>
          <button type="button" class="knum" data-key="<?= $k ?>"><?= $k ?></button>
          <?php endforeach; ?>
          <button type="button" class="kfn" data-key="back">⌫ 지우기</button>
          <button type="button" class="knum" data-key="0">0</button>
          <button type="button" class="kfn" data-key="submit" id="pinSubmit">✓ 로그인</button>
        </div>
      </form>
    </div>

  <?php endif; ?>
</div>
<script>
(function(){
  var input = document.getElementById('pinInput');
  if (!input) return;
  var disp = document.getElementById('pinDisplay');
  var max = parseInt(disp.getAttribute('data-maxlen') || '8', 10);
  function render(){
    var len = input.value.length;
    disp.innerHTML = '';
    for (var i = 0; i < 4; i++) {
      var d = document.createElement('div');
      d.className = 'pin-dot' + (i < len ? ' filled' : '');
      d.textContent = i < len ? '•' : '';
      disp.appendChild(d);
    }
  }
  function press(k){
    if (k === 'back') { input.value = input.value.slice(0, -1); }
    else if (k === 'submit') { if (input.value.length >= 3) document.getElementById('pinForm').submit(); return; }
    else { if (input.value.length < max) input.value += k; }
    render();
  }
  Array.prototype.forEach.call(document.querySelectorAll('.knum,.kfn'), function(b){
    b.addEventListener('click', function(){ press(b.getAttribute('data-key')); });
  });
  render();
})();
</script>
</body>
</html>
