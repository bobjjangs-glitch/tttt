<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'need_login' => true, 'message' => '로그인이 필요합니다.']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$productId = (int)($_POST['product_id'] ?? 0);

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => '상품 정보가 올바르지 않습니다.']);
    exit;
}

$pdo = Database::connection();

$stmt = $pdo->prepare("SELECT id FROM tt_wishlists WHERE user_id = :uid AND product_id = :pid LIMIT 1");
$stmt->execute([':uid' => $userId, ':pid' => $productId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

try {
    if ($existing) {
        $del = $pdo->prepare("DELETE FROM tt_wishlists WHERE id = :id");
        $del->execute([':id' => $existing['id']]);
        echo json_encode(['ok' => true, 'active' => false, 'message' => '찜 목록에서 제거했습니다.']);
    } else {
        $ins = $pdo->prepare("INSERT INTO tt_wishlists (user_id, product_id, created_at) VALUES (:uid, :pid, NOW())");
        $ins->execute([':uid' => $userId, ':pid' => $productId]);
        echo json_encode(['ok' => true, 'active' => true, 'message' => '찜 목록에 담았습니다.']);
    }
} catch (Throwable $e) {
    error_log('[wishlist-toggle] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '처리 중 오류가 발생했습니다.']);
}
