<?php
declare(strict_types=1);
// /ajax-stock-request.php — 재고 요청 (로그인 회원 전용, 회원가입 정보를 그대로 사용)
// [중요] 이 파일은 어떤 상황(DB 오류, 로그인 안 됨, 잘못된 값)에서도
// 절대 HTML을 응답하지 않고 반드시 JSON만 반환해야 한다.
// 그래야 클라이언트 fetch의 res.json()이 깨지면서 "네트워크 오류"로 오인되는 문제가 재발하지 않는다.
require_once __DIR__ . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (!is_post()) {
        json_response(['success' => false, 'message' => '잘못된 요청입니다.'], 400);
    }

    // 재고 요청은 화면에서 이름/연락처를 따로 입력받지 않고 회원가입 정보를 그대로 쓰므로 로그인이 필요하다.
    if (!Auth::isLoggedIn()) {
        json_response(['success' => false, 'message' => '로그인이 필요합니다.'], 401);
    }

    // product-list.php / product-detail.php 모두 FormData(multipart)로 전송하므로 $_POST를 사용한다.
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Csrf::verify($csrfToken)) {
        json_response(['success' => false, 'message' => '유효하지 않은 요청입니다. 새로고침 후 다시 시도해 주세요.'], 403);
    }

    $productId = (int)($_POST['product_id'] ?? 0);
    $qty       = max(1, (int)($_POST['qty'] ?? 1));
    $dotCode   = trim((string)($_POST['dot_code'] ?? '')); // product-list.php에서 DOT별로 넘어오는 값 (없을 수 있음)

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
     * 컬럼이 다르면 INSERT가 실패하고, 여기서 예외를 못 잡으면 HTML 에러 페이지가 나가서
     * 클라이언트에 "네트워크 오류"로 잘못 표시되는 문제가 재발한다. 그래서 전체를 최상위
     * try/catch로 한 번 더 감싸둔다.
     */
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

    // DOT 옵션 지정 요청이면 사이즈 정보에 DOT 코드를 같이 남겨서 관리자가 어떤 재고인지 구분할 수 있게 한다.
    $sizeText = $product['spec'] ?: ($product['name'] ?: '사이즈 정보 없음');
    if ($dotCode !== '') {
        $sizeText .= ' (DOT ' . $dotCode . ')';
    }

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
        'size_text'  => $sizeText,
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
    error_log('[ajax-stock-request] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    // json_response는 exit()을 호출하므로 여기서 나가면 전역 exception handler(HTML 출력)로 절대 넘어가지 않는다.
    json_response(['success' => false, 'message' => '요청 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.'], 500);
}
