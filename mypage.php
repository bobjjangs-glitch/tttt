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

/* 구매확정이 가능한 주문 상태 / 리뷰 작성 가능 기간(일) */
if (!defined('ORDER_CONFIRM_ELIGIBLE_STATUSES')) {
    define('ORDER_CONFIRM_ELIGIBLE_STATUSES', ['shipped', 'done']);
}
if (!defined('REVIEW_WRITE_WINDOW_DAYS')) {
    define('REVIEW_WRITE_WINDOW_DAYS', 7);
}

/* [NEW-COUPON] 쿠폰 테이블/컬럼 자동 생성 보장 (core/functions.php 에 정의됨) */
ensure_coupon_tables();

/* =====================================================================
   [FIX-3] 비밀번호 변경 시도 쓰로틀링 (세션 기반, DB 스키마 변경 없음)
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

/**
 * 주문 한 건에 대해 "구매확정" 버튼 또는 리뷰 작성 가능 D-day 배지를 HTML로 반환한다.
 */
function render_order_confirm_cell(array $o): string
{
    $status      = $o['status'] ?? '';
    $confirmedAt = $o['confirmed_at'] ?? null;

    if ($confirmedAt === null && in_array($status, ORDER_CONFIRM_ELIGIBLE_STATUSES, true)) {
        $orderId = (int)$o['id'];
        return "
            <form method=\"post\" action=\"" . BASE_URL . "/mypage.php#orders\" style=\"display:inline;\">
                <input type=\"hidden\" name=\"form_type\" value=\"confirm_order\">
                <input type=\"hidden\" name=\"order_id\" value=\"{$orderId}\">
                " . Csrf::field() . "
                <button type=\"submit\" class=\"btn-confirm-order\"
                        onclick=\"return confirm('구매를 확정하시겠습니까? 확정 후 7일간 리뷰를 작성할 수 있습니다.');\">
                    구매확정
                </button>
            </form>";
    }

    if ($confirmedAt !== null) {
        $deadline = (new DateTime($confirmedAt))->modify('+' . REVIEW_WRITE_WINDOW_DAYS . ' days');
        $now = new DateTime();
        if ($now <= $deadline) {
            $diff = $now->diff($deadline);
            $daysLeft = max(1, (int)$diff->days + (($diff->h > 0 || $diff->i > 0) ? 1 : 0));
            return "<span class=\"badge-confirmed\">확정완료 (리뷰 D-{$daysLeft})</span>";
        }
        return '<span class="badge-confirmed expired">확정완료 (리뷰 기간 종료)</span>';
    }

    return '<span class="badge-muted">-</span>';
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

    if ($lockedMsg = pwchange_check_locked()) {
        flash('errors', json_encode(['current_password' => $lockedMsg], JSON_UNESCAPED_UNICODE));
        redirect('/mypage.php#password');
    }

    $curPw     = $_POST['current_password'] ?? '';
    $newPw     = $_POST['new_password'] ?? '';
    $newPwConf = $_POST['new_password_confirm'] ?? '';

    $v = new Validator(['new_password' => $newPw]);
    $v->require('new_password', '새 비밀번호')->passwordStrength('new_password');

    $stmt = $pdo->prepare('SELECT password_hash FROM tt_users WHERE id = :uid');
    $stmt->execute(['uid' => $uid]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($curPw, $row['password_hash'])) {
        pwchange_record_fail();
        flash('errors', json_encode(['current_password' => '현재 비밀번호가 일치하지 않습니다.'], JSON_UNESCAPED_UNICODE));
        redirect('/mypage.php#password');
    }
    if ($v->fails()) {
        flash('errors', json_encode($v->errors(), JSON_UNESCAPED_UNICODE));
        redirect('/mypage.php#password');
    }
    if ($newPw !== $newPwConf) {
        flash('errors', json_encode(['new_password_confirm' => '새 비밀번호가 일치하지 않습니다.'], JSON_UNESCAPED_UNICODE));
        redirect('/mypage.php#password');
    }

    pwchange_reset_fail();

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

// ---------- 구매확정 처리 (기존 그대로: 확정 후 상품 상세페이지로 이동해 모달 자동 오픈) ----------
if (is_post() && ($_POST['form_type'] ?? '') === 'confirm_order') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error', '유효하지 않은 요청입니다.');
        redirect('/mypage.php#orders');
    }
    $orderId = (int)($_POST['order_id'] ?? 0);

    $ordStmt = $pdo->prepare('SELECT id, user_id, status, confirmed_at FROM tt_orders WHERE id = :id LIMIT 1');
    $ordStmt->execute(['id' => $orderId]);
    $targetOrder = $ordStmt->fetch();

    if (!$targetOrder || (int)$targetOrder['user_id'] !== (int)$uid) {
        flash('error', '해당 주문을 찾을 수 없습니다.');
        redirect('/mypage.php#orders');
    }
    if ($targetOrder['confirmed_at'] !== null) {
        flash('error', '이미 구매확정된 주문입니다.');
        redirect('/mypage.php#orders');
    }
    if (!in_array($targetOrder['status'], ORDER_CONFIRM_ELIGIBLE_STATUSES, true)) {
        flash('error', '배송중 또는 배송완료 상태의 주문만 구매확정할 수 있습니다.');
        redirect('/mypage.php#orders');
    }

    $pdo->prepare('UPDATE tt_orders SET confirmed_at = NOW() WHERE id = :id AND user_id = :uid')
        ->execute(['id' => $orderId, 'uid' => $uid]);

    $itemStmt = $pdo->prepare('SELECT product_id FROM tt_order_items WHERE order_id = :oid');
    $itemStmt->execute(['oid' => $orderId]);
    $productIds = $itemStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($productIds)) {
        flash('success', '구매확정이 완료되었습니다. 오늘부터 7일간 리뷰를 작성할 수 있습니다.');
        redirect('/mypage.php#orders');
    }

    $firstProductId = (int)$productIds[0];
    if (count($productIds) > 1) {
        flash('success', '구매확정이 완료되었습니다. 나머지 상품은 마이페이지의 "구매 후기 작성"에서 리뷰를 작성해 주세요.');
    }

    redirect('/product-detail.php?id=' . $firstProductId . '&write_review=1#review');
}

// ---------- 데이터 조회 ----------
$stmt = $pdo->prepare('SELECT id, email, name, phone, address1, address2, zipcode FROM tt_users WHERE id = :uid');
$stmt->execute(['uid' => $uid]);
$user = $stmt->fetch();

if (!$user) {
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

$orderStmt = $pdo->prepare("SELECT * FROM tt_orders WHERE user_id = :uid ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$orderStmt->execute(['uid' => $uid]);
$orders = $orderStmt->fetchAll();

/* [FIX-5] 더블쿼트로 SQL을 감싸서 내부 홑따옴표(status='active')로 인한 파싱 에러 재발을 방지 */
$wishStmt = $pdo->prepare("
    SELECT w.id AS wish_id, p.id AS product_id, p.name, p.model, p.thumbnail_url,
           p.price_sale, p.price_original, p.rating_avg, p.review_count,
           b.name AS brand_name
    FROM tt_wishlists w
    JOIN tt_products p ON p.id = w.product_id AND p.status = 'active'
    JOIN tt_brands b ON b.id = p.brand_id
    WHERE w.user_id = :uid
    ORDER BY w.id DESC
");
$wishStmt->execute(['uid' => $uid]);
$wishlist = $wishStmt->fetchAll();

/* ===== [NEW] 마이페이지 안에서 바로 리뷰를 쓸 수 있는 대상: 구매확정 후 7일 이내 & 미작성 상품 ===== */
$reviewableStmt = $pdo->prepare("
    SELECT oi.product_id, MAX(o.confirmed_at) AS confirmed_at,
           p.name AS product_name, p.thumbnail_url
    FROM tt_order_items oi
    JOIN tt_orders o ON o.id = oi.order_id
    JOIN tt_products p ON p.id = oi.product_id
    WHERE o.user_id = :uid
      AND o.confirmed_at IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM tt_reviews r
          WHERE r.product_id = oi.product_id AND r.user_id = o.user_id
      )
    GROUP BY oi.product_id, p.name, p.thumbnail_url
    ORDER BY confirmed_at DESC
");
$reviewableStmt->execute(['uid' => $uid]);
$reviewableRows = $reviewableStmt->fetchAll(PDO::FETCH_ASSOC);

$reviewableProducts = [];
foreach ($reviewableRows as $row) {
    $confirmedAt = new DateTime($row['confirmed_at']);
    $deadline = (clone $confirmedAt)->modify('+' . REVIEW_WRITE_WINDOW_DAYS . ' days');
    $now = new DateTime();
    if ($now <= $deadline) {
        $diff = $now->diff($deadline);
        $row['days_left'] = max(1, (int)$diff->days + (($diff->h > 0 || $diff->i > 0) ? 1 : 0));
        $reviewableProducts[] = $row;
    }
}

/* ===== [NEW] 내가 작성한 리뷰 목록 (삭제 버튼 노출용) ===== */
$myReviewsStmt = $pdo->prepare("
    SELECT r.id, r.product_id, r.rating, r.content, r.created_at,
           p.name AS product_name, p.thumbnail_url
    FROM tt_reviews r
    JOIN tt_products p ON p.id = r.product_id
    WHERE r.user_id = :uid
    ORDER BY r.created_at DESC
");
$myReviewsStmt->execute(['uid' => $uid]);
$myReviews = $myReviewsStmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================================
   [NEW-COUPON] 마이페이지 쿠폰함 데이터 조회
   - 미사용 쿠폰 중 유효기간이 지난 것은 조회 시점에 즉시 expired로 갱신한다.
   - 정렬: 사용가능 쿠폰을 맨 위로, 그 안에서는 최근 발급순.
   ===================================================================== */
$couponStmt = $pdo->prepare("
    SELECT uc.id AS user_coupon_id, uc.status, uc.issued_at, uc.used_at,
           c.name, c.description, c.image_url, c.discount_type, c.discount_value,
           c.max_discount_amount, c.min_order_amount, c.valid_until
    FROM tt_user_coupons uc
    JOIN tt_coupons c ON c.id = uc.coupon_id
    WHERE uc.user_id = :uid
    ORDER BY (uc.status = 'unused') DESC, uc.issued_at DESC
");
$couponStmt->execute(['uid' => $uid]);
$myCoupons = $couponStmt->fetchAll(PDO::FETCH_ASSOC);

$nowForCoupon = new DateTime();
foreach ($myCoupons as &$mc) {
    if ($mc['status'] === 'unused' && $mc['valid_until'] && $nowForCoupon > new DateTime($mc['valid_until'])) {
        $mc['status'] = 'expired';
        $pdo->prepare("UPDATE tt_user_coupons SET status = 'expired' WHERE id = :id")
            ->execute(['id' => $mc['user_coupon_id']]);
    }
}
unset($mc);

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

<style>
.btn-confirm-order {
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  color: #fff;
  border: none;
  padding: 6px 16px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 10px rgba(99, 102, 241, .3);
  transition: transform .12s ease, box-shadow .12s ease;
}
.btn-confirm-order:hover { transform: translateY(-1px); box-shadow: 0 6px 14px rgba(99,102,241,.4); }
.badge-confirmed {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 999px;
  background: #eef2ff;
  color: #4f46e5;
  font-size: 12px;
  font-weight: 700;
}
.badge-confirmed.expired { background: #f1f5f9; color: #94a3b8; }
.badge-muted { color: #cbd5e1; font-size: 12px; }

/* ===== [NEW] 구매 후기 작성 카드 ===== */
.review-write-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
.review-write-card {
  border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px;
  display: flex; flex-direction: column; gap: 10px; background: #fff;
}
.rw-thumb { width: 100%; height: 120px; border-radius: 10px; overflow: hidden; background: #f1f5f9; display: flex; align-items: center; justify-content: center; }
.rw-thumb img { width: 100%; height: 100%; object-fit: cover; }
.rw-thumb .ph { font-size: 32px; }
.rw-name { font-weight: 700; font-size: 14px; }
.rw-ddays { font-size: 12px; color: #64748b; }
.btn-review-write {
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  color: #fff; border: none; padding: 10px 16px; border-radius: 999px;
  font-weight: 700; font-size: 13px; cursor: pointer;
  box-shadow: 0 6px 16px rgba(99, 102, 241, .3);
  transition: transform .12s ease;
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
}
.btn-review-write:hover { transform: translateY(-1px); }

/* ===== [NEW] 내가 쓴 리뷰 ===== */
.my-review-list { display: flex; flex-direction: column; gap: 14px; }
.my-review-item {
  display: grid; grid-template-columns: 72px 1fr auto; gap: 14px; align-items: start;
  border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px; background: #fff;
}
.mr-thumb { width: 72px; height: 72px; border-radius: 10px; overflow: hidden; background: #f1f5f9; display: flex; align-items: center; justify-content: center; }
.mr-thumb img { width: 100%; height: 100%; object-fit: cover; }
.mr-name { font-weight: 700; font-size: 14px; margin-bottom: 2px; }
.mr-stars { color: #fbbf24; font-size: 13px; margin-bottom: 6px; }
.mr-content { font-size: 13px; color: #334155; line-height: 1.5; margin: 0 0 6px; }
.mr-date { font-size: 12px; color: #94a3b8; }
.btn-review-delete {
  background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;
  padding: 8px 14px; border-radius: 999px; font-weight: 600; font-size: 12px; cursor: pointer;
  height: fit-content;
}
.btn-review-delete:hover { background: #fee2e2; }

/* ===== [NEW-COUPON] 마이페이지 쿠폰함 ===== */
.coupon-box-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
.my-coupon-card{
  display:flex; background:#fff; border:1px solid #e2e8f0; border-radius:14px;
  overflow:hidden; position:relative;
}
.my-coupon-card::after{
  content:''; position:absolute; left:88px; top:0; bottom:0; width:0;
  border-left:2px dashed #e2e8f0;
}
.my-coupon-card.status-used, .my-coupon-card.status-expired{ opacity:.5; }
.mc-left{
  width:88px; flex-shrink:0; display:flex; align-items:center; justify-content:center;
  background:linear-gradient(135deg,#6366f1,#8b5cf6); padding:10px;
}
.mc-img{width:100%;height:64px;object-fit:cover;border-radius:8px}
.mc-img-ph{display:flex;align-items:center;justify-content:center;font-size:26px;background:rgba(255,255,255,.15);color:#fff}
.mc-right{flex:1;padding:14px 16px;position:relative}
.mc-name{font-size:13.5px;font-weight:800;color:#1e293b}
.mc-discount{font-size:19px;font-weight:900;color:#6366f1;margin:4px 0}
.mc-cond{font-size:12px;color:#64748b}
.mc-expire{font-size:11.5px;color:#94a3b8;margin-top:4px}
.mc-status-badge{
  position:absolute; top:14px; right:14px; font-size:11px; font-weight:800;
  padding:3px 10px; border-radius:999px; background:#eef2ff; color:#4f46e5;
}
.status-used .mc-status-badge, .status-expired .mc-status-badge{background:#f1f5f9;color:#94a3b8}

/* ===== [NEW] 리뷰 작성 모달 (마이페이지 전용, product-detail.php와 동일 스타일) ===== */
.review-modal-overlay {
  position: fixed; inset: 0;
  background: rgba(15, 23, 42, .55);
  display: flex; align-items: center; justify-content: center;
  opacity: 0; visibility: hidden;
  transition: opacity .2s ease;
  z-index: 999;
}
.review-modal-overlay.active { opacity: 1; visibility: visible; }
.review-modal-box {
  background: #fff; border-radius: 20px; padding: 32px;
  width: 92%; max-width: 440px; position: relative;
  transform: translateY(16px) scale(.97);
  transition: transform .2s ease;
  box-shadow: 0 24px 60px rgba(0,0,0,.25);
}
.review-modal-overlay.active .review-modal-box { transform: translateY(0) scale(1); }
.review-modal-close {
  position: absolute; top: 16px; right: 16px;
  background: none; border: none; font-size: 22px; color: #94a3b8; cursor: pointer;
}
.review-modal-title { font-size: 19px; font-weight: 800; margin-bottom: 4px; }
.review-modal-sub { font-size: 13px; color: #64748b; margin-bottom: 18px; }
.star-rating { display: flex; flex-direction: row-reverse; gap: 4px; margin-bottom: 16px; }
.star-rating input { display: none; }
.star-rating label { font-size: 30px; color: #e2e8f0; cursor: pointer; transition: color .12s, transform .12s; }
.star-rating input:checked ~ label,
.star-rating label:hover,
.star-rating label:hover ~ label { color: #fbbf24; }
.review-modal-box textarea {
  width: 100%; border: 1px solid #e2e8f0; border-radius: 12px;
  padding: 12px 14px; font-size: 14px; resize: vertical; margin-bottom: 18px; box-sizing: border-box;
}
.review-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
.btn-modal-cancel {
  background: #f1f5f9; color: #475569; border: none;
  padding: 10px 20px; border-radius: 999px; font-weight: 600; cursor: pointer;
}
.btn-modal-submit {
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  color: #fff; border: none; padding: 10px 24px; border-radius: 999px; font-weight: 700; cursor: pointer;
  box-shadow: 0 6px 16px rgba(99, 102, 241, .35);
  transition: transform .12s ease;
}
.btn-modal-submit:hover { transform: translateY(-1px); }
</style>

<div class="mypage-wrap">

  <aside class="mypage-side">
    <div class="mypage-profile-card">
      <div class="mp-name"><?= h($user['name']) ?>님</div>
      <div class="mp-email"><?= h($user['email']) ?></div>
    </div>
    <nav class="mypage-tabs">
      <a href="#orders">주문내역</a>
      <a href="#write-review">구매 후기 작성</a>
      <a href="#myreviews">내가 쓴 리뷰</a>
      <a href="#coupons">쿠폰함</a>
      <a href="#wish">찜한 상품</a>
      <a href="#profile">회원정보 수정</a>
      <a href="#password">비밀번호 변경</a>
    </nav>
  </aside>

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
              <th>주문번호</th><th>주문일</th><th>상태</th><th>금액</th><th>구매확정 / 리뷰</th>
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
                <td><?= render_order_confirm_cell($o) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

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

    <!-- [NEW] 구매 후기 작성 -->
    <section class="mypage-section" id="write-review">
      <h2>구매 후기 작성</h2>
      <?php if ($reviewableProducts): ?>
        <div class="review-write-grid">
          <?php foreach ($reviewableProducts as $rp): ?>
            <div class="review-write-card">
              <div class="rw-thumb">
                <?php if (!empty($rp['thumbnail_url'])): ?>
                  <img src="<?= h($rp['thumbnail_url']) ?>" alt="<?= h($rp['product_name']) ?>">
                <?php else: ?>
                  <span class="ph">🛞</span>
                <?php endif; ?>
              </div>
              <div class="rw-body">
                <div class="rw-name"><?= h($rp['product_name']) ?></div>
                <div class="rw-ddays">리뷰 작성 가능: D-<?= (int)$rp['days_left'] ?></div>
              </div>
              <button type="button" class="btn-review-write mp-open-review-modal"
                      data-product-id="<?= (int)$rp['product_id'] ?>"
                      data-product-name="<?= h($rp['product_name']) ?>">
                <span>✎</span> 리뷰 작성
              </button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="empty-msg">현재 작성 가능한 리뷰가 없습니다. 배송중/배송완료 주문을 구매확정하면 7일간 리뷰를 작성할 수 있습니다.</p>
      <?php endif; ?>
    </section>

    <!-- [NEW] 내가 쓴 리뷰 -->
    <section class="mypage-section" id="myreviews">
      <h2>내가 쓴 리뷰</h2>
      <?php if ($myReviews): ?>
        <div class="my-review-list">
          <?php foreach ($myReviews as $mr): ?>
            <div class="my-review-item">
              <div class="mr-thumb">
                <?php if (!empty($mr['thumbnail_url'])): ?>
                  <img src="<?= h($mr['thumbnail_url']) ?>" alt="<?= h($mr['product_name']) ?>">
                <?php else: ?>
                  <span class="ph">🛞</span>
                <?php endif; ?>
              </div>
              <div>
                <div class="mr-name"><?= h($mr['product_name']) ?></div>
                <div class="mr-stars"><?= str_repeat('★', (int)$mr['rating']) . str_repeat('☆', 5 - (int)$mr['rating']) ?></div>
                <p class="mr-content"><?= nl2br(h($mr['content'])) ?></p>
                <div class="mr-date"><?= h(date('Y.m.d', strtotime($mr['created_at']))) ?></div>
              </div>
              <form method="post" action="<?= BASE_URL ?>/review-delete.php#myreviews"
                    onsubmit="return confirm('이 리뷰를 삭제하시겠습니까? 삭제 후에는 되돌릴 수 없습니다.');">
                <input type="hidden" name="review_id" value="<?= (int)$mr['id'] ?>">
                <input type="hidden" name="return_to" value="mypage">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-review-delete">삭제</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="empty-msg">작성한 리뷰가 없습니다.</p>
      <?php endif; ?>
    </section>

    <!-- [NEW-COUPON] 쿠폰함 -->
    <section class="mypage-section" id="coupons">
      <h2>쿠폰함</h2>
      <?php if ($myCoupons): ?>
        <div class="coupon-box-grid">
          <?php foreach ($myCoupons as $mc): ?>
            <?php
              $discountLabel = $mc['discount_type'] === 'percent'
                  ? (int)$mc['discount_value'] . '% 할인'
                  : number_format((int)$mc['discount_value']) . '원 할인';
            ?>
            <div class="my-coupon-card status-<?= h($mc['status']) ?>">
              <div class="mc-left">
                <?php if ($mc['image_url']): ?>
                  <img src="<?= h($mc['image_url']) ?>" class="mc-img" alt="">
                <?php else: ?>
                  <div class="mc-img mc-img-ph">🎟️</div>
                <?php endif; ?>
              </div>
              <div class="mc-right">
                <div class="mc-name"><?= h($mc['name']) ?></div>
                <div class="mc-discount"><?= $discountLabel ?></div>
                <div class="mc-cond">
                  <?= number_format((int)$mc['min_order_amount']) ?>원 이상 구매 시
                  <?php if ($mc['discount_type'] === 'percent' && $mc['max_discount_amount']): ?>
                    (최대 <?= number_format((int)$mc['max_discount_amount']) ?>원)
                  <?php endif; ?>
                </div>
                <div class="mc-expire">
                  <?= $mc['valid_until'] ? h(date('Y.m.d', strtotime($mc['valid_until']))) . '까지' : '기간 제한 없음' ?>
                </div>
                <span class="mc-status-badge"><?= coupon_status_label($mc['status']) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="empty-msg">보유한 쿠폰이 없습니다.</p>
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
        <div class="mp-form-row">
          <label>새 비밀번호 확인</label>
          <input type="password" name="new_password_confirm" required>
        </div>
        <button type="submit" class="btn-primary">비밀번호 변경하기</button>
      </form>
    </section>

  </div>
</div>

<!-- [NEW] 마이페이지 전용 리뷰 작성 모달: 여러 상품 공용, JS로 product_id/제목만 교체 -->
<div class="review-modal-overlay" id="mpReviewModalOverlay">
  <div class="review-modal-box">
    <button type="button" class="review-modal-close" id="mpReviewModalClose" aria-label="닫기">&times;</button>
    <h3 class="review-modal-title" id="mpReviewModalTitle">리뷰 작성하기</h3>
    <p class="review-modal-sub">솔직한 사용 후기를 남겨주시면 다른 고객에게 큰 도움이 됩니다.</p>
    <form method="post" action="<?= BASE_URL ?>/review-submit.php" class="mp-review-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="mypage">
        <input type="hidden" name="product_id" id="mpReviewProductId" value="">
        <div class="star-rating">
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <input type="radio" id="mpStar<?= $i ?>" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>>
                <label for="mpStar<?= $i ?>">★</label>
            <?php endfor; ?>
        </div>
        <textarea name="content" rows="4" maxlength="1000" placeholder="사용해 보신 솔직한 후기를 남겨주세요." required></textarea>
        <div class="review-modal-actions">
            <button type="button" class="btn-modal-cancel" id="mpReviewModalCancel">취소</button>
            <button type="submit" class="btn-modal-submit">등록하기</button>
        </div>
    </form>
  </div>
</div>

<script>
(function(){
  const overlay        = document.getElementById('mpReviewModalOverlay');
  const closeBtn        = document.getElementById('mpReviewModalClose');
  const cancelBtn        = document.getElementById('mpReviewModalCancel');
  const titleEl          = document.getElementById('mpReviewModalTitle');
  const productIdInput  = document.getElementById('mpReviewProductId');

  if (!overlay) return;

  function openModal(productId, productName) {
    productIdInput.value = productId;
    titleEl.textContent = productName ? (productName + ' 리뷰 작성하기') : '리뷰 작성하기';
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    overlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  document.querySelectorAll('.mp-open-review-modal').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openModal(btn.dataset.productId, btn.dataset.productName);
    });
  });

  closeBtn?.addEventListener('click', closeModal);
  cancelBtn?.addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.classList.contains('active')) closeModal();
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
