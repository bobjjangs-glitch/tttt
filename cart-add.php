<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// 프론트(product-detail.php)는 Content-Type: application/json으로 body를 보낸다.
// $_POST는 form-urlencoded/multipart일 때만 채워지므로, php://input을 직접 읽어 JSON을 디코드한다.
$raw   = file_get_contents('php://input');
$json  = json_decode($raw, true);
$input = is_array($json) ? $json : $_POST;

// 로그인 체크 — 반드시 Auth 클래스를 통해서만 판단한다 (세션 키: Auth::SESSION_KEY = 'tt_user_id')
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

// CSRF 체크 — core/Csrf.php의 Csrf::verify()를 그대로 사용한다
if (!Csrf::verify($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$userId      = Auth::currentUserId();
$productId   = (int)($input['product_id'] ?? 0);
$optionIdRaw = $input['option_id'] ?? null;
// 프론트는 옵션이 없을 때 null을 명시적으로 보낸다.
$optionId    = ($optionIdRaw !== null && (int)$optionIdRaw > 0) ? (int)$optionIdRaw : null;
$qty         = max(1, min(99, (int)($input['qty'] ?? 1)));

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '상품 정보가 올바르지 않습니다.']);
    exit;
}

$pdo = Database::connection();

$stmt = $pdo->prepare("SELECT id, status FROM tt_products WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product || $product['status'] !== 'active') {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => '판매 중지되었거나 존재하지 않는 상품입니다.']);
    exit;
}

// DOT 옵션 재고 확인
if ($optionId !== null) {
    $stmt = $pdo->prepare("SELECT id, stock_qty, is_active FROM tt_product_options WHERE id = :id AND product_id = :pid LIMIT 1");
    $stmt->execute([':id' => $optionId, ':pid' => $productId]);
    $option = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$option || (int)$option['is_active'] !== 1) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '선택한 DOT 옵션이 존재하지 않습니다.']);
        exit;
    }
    if ((int)$option['stock_qty'] < $qty) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => '재고가 부족합니다. (현재 재고: ' . (int)$option['stock_qty'] . '개)']);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // option_id가 NULL인 경우까지 안전하게 비교 (<=>)
    $stmt = $pdo->prepare(
        "SELECT id, qty FROM tt_carts
         WHERE user_id = :uid AND product_id = :pid AND option_id <=> :oid
         LIMIT 1"
    );
    $stmt->execute([':uid' => $userId, ':pid' => $productId, ':oid' => $optionId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $newQty = (int)$existing['qty'] + $qty;
        $upd = $pdo->prepare("UPDATE tt_carts SET qty = :qty WHERE id = :id");
        $upd->execute([':qty' => $newQty, ':id' => $existing['id']]);
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO tt_carts (user_id, product_id, option_id, qty, created_at)
             VALUES (:uid, :pid, :oid, :qty, NOW())"
        );
        $ins->execute([':uid' => $userId, ':pid' => $productId, ':oid' => $optionId, ':qty' => $qty]);
    }

    $countStmt = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM tt_carts WHERE user_id = :uid");
    $countStmt->execute([':uid' => $userId]);
    $cartCount = (int)$countStmt->fetchColumn();

    $pdo->commit();

    // 프론트가 읽는 구조: data.success / data.data.cart_count / data.data.message
    echo json_encode([
        'success' => true,
        'data' => [
            'cart_count' => $cartCount,
            'message'    => '장바구니에 담았습니다.',
        ],
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[cart-add] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '장바구니 처리 중 오류가 발생했습니다.']);
}
