<?php
// test-order.php — 진단용 임시 파일. 확인 끝나면 반드시 삭제하세요.
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
echo "<pre>";

if (!Auth::isLoggedIn()) {
    echo "로그인 후 이 페이지를 열어주세요.\n";
    exit;
}
$pdo = Database::connection();
$uid = Auth::currentUserId();

echo "=== STEP 1. 실제 테이블 컬럼 목록 확인 ===\n";
foreach (['tt_orders', 'tt_order_items', 'tt_order_status_logs'] as $table) {
    echo "\n[$table]\n";
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
        echo implode(', ', $cols) . "\n";
    } catch (Throwable $e) {
        echo "❌ 조회 실패: " . $e->getMessage() . "\n";
    }
}

echo "\n=== STEP 2. checkout.php와 완전히 동일한 INSERT 시도 (트랜잭션 → 무조건 롤백) ===\n";
try {
    $pdo->beginTransaction();

    $orderNo = 'TEST' . date('YmdHis');
    $pdo->prepare('
        INSERT INTO tt_orders (order_no, user_id, total_amount, shipping_fee, discount_amount, user_coupon_id,
                                recipient_name, recipient_phone, recipient_addr, memo, status)
        VALUES (:no, :uid, :total, :ship, :discount, :ucid, :rname, :rphone, :raddr, :memo, "pending")
    ')->execute([
        'no' => $orderNo, 'uid' => $uid, 'total' => 1000, 'ship' => 0,
        'discount' => 0, 'ucid' => null,
        'rname' => '테스트', 'rphone' => '010-0000-0000',
        'raddr' => '(00000) 테스트 주소', 'memo' => '',
    ]);
    $orderId = (int)$pdo->lastInsertId();
    echo "✅ tt_orders INSERT 성공 (orderId={$orderId})\n";

    $pdo->prepare('
        INSERT INTO tt_order_items (order_id, product_id, option_id, product_name, price, qty)
        VALUES (:oid, :pid, :optid, :name, :price, :qty)
    ')->execute([
        'oid' => $orderId, 'pid' => 11051, 'optid' => null,
        'name' => '테스트상품', 'price' => 1000, 'qty' => 1,
    ]);
    echo "✅ tt_order_items INSERT 성공\n";

    $pdo->prepare('INSERT INTO tt_order_status_logs (order_id, status, memo) VALUES (:oid, "pending", "테스트")')
        ->execute(['oid' => $orderId]);
    echo "✅ tt_order_status_logs INSERT 성공\n";

    $pdo->rollBack(); // 절대 commit 하지 않음 — 테스트 데이터 남기지 않기 위함
    echo "\n(모든 INSERT가 성공했으므로 안전하게 롤백했습니다. 실제 데이터는 남지 않았습니다.)\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "❌ 예외 발생: " . htmlspecialchars($e->getMessage()) . "\n";
    echo "   (line " . $e->getLine() . ")\n";
}

echo "\n=== 진단 종료 ===\n";
echo "</pre>";
