<?php
// ajax-coupon-download.php — 할인 쿠폰 받기 (상품 상세 티켓 버튼)
// 로그인 회원 쿠폰함(tt_user_coupons)에 지급. 수량 소진/중복/기간 만료 가드는
// core/functions.php 의 issue_coupon_to_user() 가 담당한다.
require_once __DIR__ . '/core/bootstrap.php';
ensure_coupon_tables();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다. 로그인 후 쿠폰을 받아주세요.']);
    exit;
}

/* [v46] 사업자 회원은 쿠폰 대상이 아님 — 서버에서도 차단 */
if (Auth::isBusiness()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '사업자 회원은 쿠폰을 사용할 수 없습니다.']);
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '유효하지 않은 요청입니다. 페이지를 새로고침 후 다시 시도해주세요.']);
    exit;
}

$couponId = (int)($_POST['coupon_id'] ?? 0);
if ($couponId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '쿠폰 정보가 올바르지 않습니다.']);
    exit;
}

$result = issue_coupon_to_user($couponId, Auth::currentUserId());
http_response_code($result['ok'] ? 200 : 409);
echo json_encode([
    'success' => $result['ok'],
    'message' => $result['msg'],
    'already' => !empty($result['already']),
], JSON_UNESCAPED_UNICODE);
