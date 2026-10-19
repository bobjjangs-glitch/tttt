<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// 프론트는 JSON body로 보낸다. $_POST는 항상 비어있으므로 php://input을 직접 파싱한다.
$raw   = file_get_contents('php://input');
$json  = json_decode($raw, true);
$input = is_array($json) ? $json : $_POST;

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if (!Csrf::verify($input['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '유효하지 않은 요청입니다.']);
    exit;
}

$productId = (int)($input['product_id'] ?? 0);
if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '상품 정보가 올바르지 않습니다.']);
    exit;
}

$uid = Auth::currentUserId();
$pdo = Database::connection();

// 삭제/판매종료된 상품에 대한 찜 오작동 방지
$chk = $pdo->prepare('SELECT id FROM tt_products WHERE id = :id LIMIT 1');
$chk->execute(['id' => $productId]);
if (!$chk->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => '존재하지 않는 상품입니다.']);
    exit;
}

try {
    // 실제 스키마의 테이블명은 tt_wishlists (wishlists 아님) — product-detail.php의 조회 쿼리와 일치시킴
    $stmt = $pdo->prepare('SELECT id FROM tt_wishlists WHERE user_id = :uid AND product_id = :pid LIMIT 1');
    $stmt->execute(['uid' => $uid, 'pid' => $productId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare('DELETE FROM tt_wishlists WHERE id = :id')->execute(['id' => $existing['id']]);
        echo json_encode(['success' => true, 'data' => ['wished' => false]]);
    } else {
        $pdo->prepare('INSERT INTO tt_wishlists (user_id, product_id) VALUES (:uid, :pid)')
            ->execute(['uid' => $uid, 'pid' => $productId]);
        echo json_encode(['success' => true, 'data' => ['wished' => true]]);
    }
} catch (Throwable $e) {
    error_log('[wish-toggle] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '찜 처리 중 오류가 발생했습니다.']);
}
