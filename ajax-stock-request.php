<?php
declare(strict_types=1);
// /ajax-stock-request.php — 재고 요청 (로그인 회원 전용, 회원가입 정보를 그대로 사용)
require_once __DIR__ . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_post()) {
    json_response(['success' => false, 'message' => '잘못된 요청입니다.'], 400);
}

// 재고 요청은 화면에서 이름/연락처를 따로 입력받지 않고 회원가입 정보를 그대로 쓰므로 로그인이 필요하다.
if (!Auth::isLoggedIn()) {
    json_response(['success' => false, 'message' => '로그인이 필요합니다.'], 401);
}

// 화면은 FormData(multipart)로 전송하므로 $_POST를 그대로 사용한다.
$csrfToken = $_POST['csrf_token'] ?? '';
if (!Csrf::verify($csrfToken)) {
    json_response(['success' => false, 'message' => '유효하지 않은 요청입니다. 새로고침 후 다시 시도해 주세요.'], 403);
}

$productId = (int)($_POST['product_id'] ?? 0);
$qty       = max(1, (int)($_POST['qty'] ?? 1));

if ($productId <= 0) {
    json_response(['success' => false, 'message' => '상품 정보가 없습니다.'], 400);
}

$pdo = Database::connection();

// 상품 존재/판매중 확인
$productStmt = $pdo->prepare(
    "SELECT p.id, p.name, p.spec, b.name AS brand_name
     FROM tt_products p
     LEFT JOIN tt_brands b ON b.id = p.brand_id
     WHERE p.id = :id AND p.status = 'active'
     LIMIT 1"
);
$productStmt->execute([':id' => $productId]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);
if (!$product) {
    json_response(['success' => false, 'message' => '존재하지 않는 상품입니다.'], 404);
}

// 회원가입 시 입력한 정보를 그대로 가져온다 (별도 입력창 없이 tt_users 값을 사용)
$userStmt = $pdo->prepare('SELECT name, phone, email FROM tt_users WHERE id = :id LIMIT 1');
$userStmt->execute([':id' => Auth::currentUserId()]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    json_response(['success' => false, 'message' => '회원 정보를 확인할 수 없습니다. 다시 로그인해 주세요.'], 401);
}

/**
 * tt_stock_requests 테이블 스키마는 admin/stock-requests.php와 반드시 동일해야 한다.
 * 컬럼이 다르면 INSERT가 조용히 실패하고, 그 실패를 숨기면 사용자에겐 성공으로 보이는데
 * 관리자 화면엔 아무것도 안 쌓이는 문제가 재발한다. (실제로 있었던 버그)
 */
function ensure_stock_requests_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tt_stock_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT NULL COMMENT '연결된 상품ID (없으면 NULL)',
            brand_text VARCHAR(100) NULL COMMENT '요청 브랜드(자유입력)',
            size_text VARCHAR(60) NOT NULL COMMENT '요청 사이즈',
            requested_qty INT NOT NULL DEFAULT 1 COMMENT '요청 수량',
            customer_name VARCHAR(50) NOT NULL COMMENT '주문자명',
            customer_phone VARCHAR(20) NOT NULL COMMENT '주문자 연락처',
            customer_email VARCHAR(120) NULL COMMENT '주문자 이메일',
            memo TEXT NULL COMMENT '고객 요청 메모',
            status ENUM('pending','processing','done','cancelled') NOT NULL DEFAULT 'pending' COMMENT '처리 상태',
            admin_memo TEXT NULL COMMENT '관리자 처리 메모',
            processed_by INT NULL COMMENT '처리한 관리자 ID',
            processed_at DATETIME NULL COMMENT '처리 완료 시각',
            ip_address VARCHAR(45) NULL COMMENT '요청자 IP',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
ensure_stock_requests_table($pdo);

try {
    $stmt = $pdo->prepare("
        INSERT INTO tt_stock_requests
            (product_id, brand_text, size_text, requested_qty,
             customer_name, customer_phone, customer_email,
             status, ip_address, created_at)
        VALUES
            (:product_id, :brand_text, :size_text, :qty,
             :name, :phone, :email,
             'pending', :ip, NOW())
    ");
    $stmt->execute([
        'product_id' => $productId,
        'brand_text' => $product['brand_name'] ?: null,
        'size_text'  => $product['spec'] ?: ($product['name'] ?: '사이즈 정보 없음'),
        'qty'        => $qty,
        'name'       => $user['name'],
        'phone'      => $user['phone'],
        'email'      => $user['email'] ?: null,
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_response([
        'success' => true,
        'message' => '재고 요청이 접수되었습니다. 입고 시 안내드릴게요.',
        'data' => ['request_id' => (int)$pdo->lastInsertId()]
    ]);
} catch (Throwable $e) {
    error_log('[ajax-stock-request] ' . $e->getMessage());
    json_response(['success' => false, 'message' => '요청 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.'], 500);
}
