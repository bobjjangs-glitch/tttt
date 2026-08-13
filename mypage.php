<?php
// /mypage.php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

if (!Auth::isLoggedIn()) {
    flash('error', '로그인이 필요합니다.');
    redirect('/login.php');
}

$pdo = Database::connection();
$uid = Auth::currentUserId();

/* =====================================================================
   [FIX-3] 비밀번호 변경 시도 쓰로틀링 (세션 기반, DB 스키마 변경 없음)
   로그인 폼(Auth::attemptLogin)과 동일한 취지로, 세션을 탈취한 공격자가
   "현재 비밀번호"를 무제한으로 추측하지 못하도록 5회 실패 시 15분 잠근다.
   ===================================================================== */
function pwchange_check_locked(): ?string
{
    $info = $_SESSION['_pw_change_fail'] ?? ['count' => 0, 'locked_until' => 0];
    if (($info['locked_until'] ?? 0) > time()) {
        $remainMin = (int)ceil(($info['locked_until'] - time()) / 60);
        return "비밀번호 변경 시도가 너무 많습니다. {$remainMin}분 후 다시 시도해 주세요.";
    }
    return null;
}
function pwchange_record_fail(): void
{
    $info = $_SESSION['_pw_change_fail'] ?? ['count' => 0, 'locked_until' => 0];
    $info['count'] = ($info['count'] ?? 0) + 1;
    if ($info['count'] >= 5) {
        $info['locked_until'] = time() + 15 * 60;
        $info['count'] = 0;
    }
    $_SESSION['_pw_change_fail'] = $info;
}
function pwchange_reset_fail(): void
{
    unset($_SESSION['_pw_change_fail']);
}

// ---------- 프로필 수정 처리 ----------
if (is_post() && ($_POST['form_type'] ?? '') === 'profile') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error', '유효하지 않은 요청입니다.');
        redirect('/mypage.php#profile');
    }
    $v = new Validator([
        'name'     => trim($_POST['name'] ?? ''),
        'phone'    => trim($_POST['phone'] ?? ''),
        'address1' => trim($_POST['address1'] ?? ''),
        'address2' => trim($_POST['address2'] ?? ''),
        'zipcode'  => trim($_POST['zipcode'] ?? ''),
    ]);
    $v->require('name', '이름')
      ->require('phone', '휴대폰 번호')->phone('phone');

    /* [FIX-5 관련 아님, 별도] 우편번호 형식 검증 — Validator 클래스를 전역
       수정하면 다른 페이지(회원가입 등)에도 영향을 주므로, 여기서는
       마이페이지 전용으로 간단히 인라인 검증한다. */
    $zipcode = trim($_POST['zipcode'] ?? '');
    $zipErrors = [];
    if ($zipcode !== '' && !preg_match('/^\d{5}$/', $zipcode)) {
        $zipErrors['zipcode'] = '우편번호는 숫자 5자리로 입력해 주세요.';
    }

    if (!$v->fails() && empty($zipErrors)) {
        $stmt = $pdo->prepare('
            UPDATE tt_users
            SET name = :name, phone = :phone, address1 = :address1, address2 = :address2, zipcode = :zipcode
            WHERE id = :uid
        ');
        $stmt->execute([
            'name'     => trim($_POST['name']),
            'phone'    => trim($_POST['phone']),
            'address1' => trim($_POST['address1'] ?? ''),
            'address2' => trim($_POST['address2'] ?? ''),
            'zipcode'  => $zipcode,
            'uid'      => $uid,
        ]);
        flash('success', '정보가 수정되었습니다.');
        redirect('/mypage.php#profile');
    }
    flash('errors', json_encode(array_merge($v->errors(), $zipErrors), JSON_UNESCAPED_UNICODE));
    redirect('/mypage.php#profile');
}

// ---------- 비밀번호 변경 처리 ----------
if (is_post() && ($_POST['form_type'] ?? '') === 'password') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error', '유효하지 않은 요청입니다.');
        redirect('/mypage.php#password');
    }

    /* [FIX-3] 잠금 상태면 password_verify()조차 시도하지 않고 즉시 차단 */
    if ($lockedMsg = pwchange_check_locked()) {
        flash('errors', json_encode(['current_password' => $lockedMsg], JSON_UNESCAPED_UNICODE));
        redirect('/mypage.php#password');
    }

    $curPw     = $_POST['current_password'] ?? '';
    $newPw     = $_POST['new_password'] ?? '';
    $newPwConf = $_POST['new_password_confirm'] ?? ''; // [FIX-2] 확인 입력값

    $v = new Validator(['new_password' => $newPw]);
    $v->require('new_password', '새 비밀번호')->passwordStrength('new_password');

    $stmt = $pdo->prepare('SELECT password_hash FROM tt_users WHERE id = :uid');
    $stmt->execute(['uid' => $uid]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($curPw, $row['password_hash'])) {
        pwchange_record_fail(); // [FIX-3] 실패 카운트 증가
        flash('errors', json_encode(['current_password' => '현재 비밀번호가 일치하지 않습니다.'], JSON_UNESCAPED_UNICODE));
        redirect('/mypage.php#password');
    }
    if ($v->fails()) {
        flash('errors', json_encode($v->errors(), JSON_UNESCAPED_UNICODE));
        redirect('/mypage.php#password');
    }
    /* [FIX-2] 새 비밀번호와 확인값이 다르면 저장하지 않음 */
    if ($newPw !== $newPwConf) {
        flash('errors', json_encode(['new_password_confirm' => '새 비밀번호가 일치하지 않습니다.'], JSON_UNESCAPED_UNICODE));
        redirect('/mypage.php#password');
    }

    pwchange_reset_fail(); // [FIX-3] 성공했으니 실패 카운트 초기화

    $newHash = password_hash($newPw, PASSWORD_DEFAULT);
    $upd = $pdo->prepare('UPDATE tt_users SET password_hash = :hash WHERE id = :uid');
    $upd->execute(['hash' => $newHash, 'uid' => $uid]);
    flash('success', '비밀번호가 변경되었습니다.');
    redirect('/mypage.php#password');
}

// ---------- 찜 삭제 처리 ----------
if (is_post() && ($_POST['form_type'] ?? '') === 'wish_remove') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error', '유효하지 않은 요청입니다.');
        redirect('/mypage.php#wish');
    }
    $wishId = (int)($_POST['wish_id'] ?? 0);
    $del = $pdo->prepare('DELETE FROM tt_wishlists WHERE id = :id AND user_id = :uid');
    $del->execute(['id' => $wishId, 'uid' => $uid]);
    flash('success', '찜 목록에서 삭제되었습니다.');
    redirect('/mypage.php#wish');
}

// ---------- 데이터 조회 ----------
/* [FIX-1] SELECT * 대신 필요한 컬럼만 명시적으로 조회 — password_hash를
   불필요하게 PHP 메모리/배열에 올리지 않는다. */
$stmt = $pdo->prepare('SELECT id, email, name, phone, address1, address2, zipcode FROM tt_users WHERE id = :uid');
$stmt->execute(['uid' => $uid]);
$user = $stmt->fetch();

if (!$user) {
    // 세션은 있는데 실제 유저 레코드가 없는 비정상 상태 → 강제 로그아웃
    Auth::logout();
    flash('error', '사용자 정보를 확인할 수 없습니다. 다시 로그인해주세요.');
    redirect('/login.php');
}

/* [FIX-4] 주문내역 페이지네이션 (10건씩) */
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM tt_orders WHERE user_id = :uid');
$countStmt->execute(['uid' => $uid]);
$totalOrders = (int)$countStmt->fetchColumn();
$totalPages  = max(1, (int)ceil($totalOrders / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// LIMIT/OFFSET은 서버에서 (int) 캐스팅한 값만 사용하므로 SQL 인젝션 위험 없음
$orderStmt = $pdo->prepare("SELECT * FROM tt_orders WHERE user_id = :uid ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$orderStmt->execute(['uid' => $uid]);
$orders = $orderStmt->fetchAll();

/* [FIX-5] 판매중지/숨김 상품은 찜 목록에서 제외 (product-list.php와 동일 기준) */
$wishStmt = $pdo->prepare('
    SELECT w.id AS wish_id, p.id AS product_id, p.name, p.model, p.thumbnail_url,
           p.price_sale, p.price_original, p.rating_avg, p.review_count,
           b.name AS brand_name
    FROM tt_wishlists w
    JOIN tt_products p ON p.id = w.product_id AND p.status = 'active'
    JOIN tt_brands b ON b.id = p.brand_id
    WHERE w.user_id = :uid
    ORDER BY w.id DESC
');
$wishStmt->execute(['uid' => $uid]);
$wishlist = $wishStmt->fetchAll();

$statusLabel = [
    'pending'   => '주문접수',
    'paid'      => '결제완료',
    'preparing' => '상품준비중',
    'shipped'   => '배송중',
    'done'      => '배송완료',
    'cancelled' => '주문취소',
];
$errors = json_decode(flash('errors') ?? '{}', true) ?: [];
$successMsg = flash('success');
$errorMsg = flash('error');

$pageTitle = '마이페이지';
require __DIR__ . '/includes/header.php';
?>

<div class="mypage-wrap">

  <!-- 좌측 사이드바 -->
  <aside class="mypage-side">
    <div class="mypage-profile-card">
      <div class="mp-name"><?= h($user['name']) ?>님</div>
      <div class="mp-email"><?= h($user['email']) ?></div>
    </div>
    <nav class="mypage-tabs">
      <a href="#orders">주문내역</a>
      <a href="#wish">찜한 상품</a>
      <a href="#profile">회원정보 수정</a>
      <a href="#password">비밀번호 변경</a>
    </nav>
  </aside>

  <!-- 우측 메인 -->
  <div class="mypage-main">

    <?php if ($successMsg): ?>
      <p class="error-msg" style="background:#f0fdf4;color:#15803d"><?= h($successMsg) ?></p>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
      <p class="error-msg"><?= h($errorMsg) ?></p>
    <?php endif; ?>

    <!-- 주문내역 -->
    <section class="mypage-section" id="orders">
      <h2>주문내역</h2>
      <?php if ($orders): ?>
        <table class="order-table">
          <thead>
            <tr>
              <th>주문번호</th><th>주문일</th><th>상태</th><th>금액</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o): ?>
              <tr>
                <td>#<?= h((string)$o['id']) ?></td>
                <td><?= h(date('Y-m-d', strtotime($o['created_at']))) ?></td>
                <td>
                  <span class="order-status status-<?= h($o['status']) ?>">
                    <?= h($statusLabel[$o['status']] ?? $o['status']) ?>
                  </span>
                </td>
                <td><?= format_price((int)$o['total_amount']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <!-- [FIX-4] 주문내역 페이지네이션 -->
        <?php if ($totalPages > 1): ?>
          <div class="order-pagination" style="display:flex;gap:8px;justify-content:center;margin-top:16px">
            <?php if ($page > 1): ?>
              <a href="?page=<?= $page - 1 ?>#orders" class="btn-admin-secondary" style="padding:6px 14px">이전</a>
            <?php endif; ?>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
              <a href="?page=<?= $p ?>#orders"
                 class="<?= $p === $page ? 'btn-admin-primary' : 'btn-admin-secondary' ?>"
                 style="padding:6px 14px"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
              <a href="?page=<?= $page + 1 ?>#orders" class="btn-admin-secondary" style="padding:6px 14px">다음</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      <?php else: ?>
        <p class="empty-msg">주문 내역이 없습니다.</p>
      <?php endif; ?>
    </section>

    <!-- 찜한 상품 -->
    <section class="mypage-section" id="wish">
      <h2>찜한 상품</h2>
      <?php if ($wishlist): ?>
        <div class="wish-grid">
          <?php foreach ($wishlist as $w): ?>
            <div class="prod-card wish-card">
              <form method="post" action="<?= BASE_URL ?>/mypage.php#wish" class="wish-remove-form">
                <input type="hidden" name="form_type" value="wish_remove">
                <input type="hidden" name="wish_id" value="<?= (int)$w['wish_id'] ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="wish-remove-btn" title="삭제">✕</button>
              </form>
              <a href="<?= BASE_URL ?>/product-detail.php?id=<?= (int)$w['product_id'] ?>">
                <div class="prod-img-wrap">
                  <?php if ($w['thumbnail_url']): ?>
                    <img src="<?= h($w['thumbnail_url']) ?>" alt="<?= h($w['name']) ?>" loading="lazy">
                  <?php else: ?>
                    <div class="prod-img-placeholder">🛞</div>
                  <?php endif; ?>
                  <span class="prod-brand-badge"><?= h($w['brand_name']) ?></span>
                </div>
                <div class="prod-body">
                  <div class="prod-brand-name"><?= h($w['brand_name']) ?></div>
                  <div class="prod-title"><?= h($w['name']) ?></div>
                  <div class="prod-rating">
                    <span class="star">★</span> <?= number_format((float)$w['rating_avg'], 1) ?>
                    <span>(<?= (int)$w['review_count'] ?>)</span>
                  </div>
                  <div class="prod-price-area">
                    <span class="prod-price-now"><?= format_price((int)$w['price_sale']) ?></span>
                  </div>
                </div>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="empty-msg">찜한 상품이 없습니다.</p>
      <?php endif; ?>
    </section>

    <!-- 회원정보 수정 -->
    <section class="mypage-section" id="profile">
      <h2>회원정보 수정</h2>
      <?php if ($errors): ?>
        <div class="error-msg">
          <?php foreach ($errors as $msg): ?><div><?= h($msg) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <form method="post" action="<?= BASE_URL ?>/mypage.php#profile">
        <input type="hidden" name="form_type" value="profile">
        <?= Csrf::field() ?>
        <div class="mp-form-row">
          <label>이메일</label>
          <input type="email" value="<?= h($user['email']) ?>" readonly>
        </div>
        <div class="mp-form-row">
          <label>이름</label>
          <input type="text" name="name" value="<?= h($user['name']) ?>" required>
        </div>
        <div class="mp-form-row">
          <label>휴대폰 번호</label>
          <input type="text" name="phone" value="<?= h($user['phone']) ?>" placeholder="010-1234-5678" required>
        </div>
        <div class="mp-form-row">
          <label>우편번호</label>
          <input type="text" name="zipcode" value="<?= h($user['zipcode'] ?? '') ?>" maxlength="5" placeholder="숫자 5자리">
        </div>
        <div class="mp-form-row">
          <label>주소</label>
          <input type="text" name="address1" value="<?= h($user['address1'] ?? '') ?>" placeholder="기본 주소">
        </div>
        <div class="mp-form-row">
          <label>상세주소</label>
          <input type="text" name="address2" value="<?= h($user['address2'] ?? '') ?>" placeholder="상세 주소">
        </div>
        <button type="submit" class="btn-primary">정보 수정하기</button>
      </form>
    </section>

    <!-- 비밀번호 변경 -->
    <section class="mypage-section" id="password">
      <h2>비밀번호 변경</h2>
      <form method="post" action="<?= BASE_URL ?>/mypage.php#password">
        <input type="hidden" name="form_type" value="password">
        <?= Csrf::field() ?>
        <div class="mp-form-row">
          <label>현재 비밀번호</label>
          <input type="password" name="current_password" required>
        </div>
        <div class="mp-form-row">
          <label>새 비밀번호</label>
          <input type="password" name="new_password" required>
        </div>
        <!-- [FIX-2] 새 비밀번호 확인 필드 추가 -->
        <div class="mp-form-row">
          <label>새 비밀번호 확인</label>
          <input type="password" name="new_password_confirm" required>
        </div>
        <button type="submit" class="btn-primary">비밀번호 변경하기</button>
      </form>
    </section>

  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
