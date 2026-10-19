<?php
// test-checkout.php — 진단용 임시 파일. 확인 끝나면 반드시 삭제하세요.
require_once __DIR__ . '/core/bootstrap.php';
ensure_coupon_tables();
header('Content-Type: text/html; charset=utf-8');
echo "<pre>";

if (!Auth::isLoggedIn()) { echo "로그인 후 열어주세요.\n"; exit; }
$pdo = Database::connection();
$uid = Auth::currentUserId();

// buy-now.php에서 실제로 세팅하는 것과 동일한 조건 (product-detail.php?id=11051&opt=1 기준)
$productId = 11051;
$optionId  = 1;
$qty       = 1;

echo "STEP 1. tt_products / tt_product_options 컬럼 목록\n";
foreach (['tt_products', 'tt_product_options'] as $t) {
    $cols = $pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_COLUMN);
    echo "[$t] " . implode(', ', $cols) . "\n";
}

echo "\nSTEP 2. checkout.php와 동일한 JOIN 쿼리 실행\n";
$stmt = $pdo->prepare('
    SELECT :qty AS qty, p.id AS product_id, p.name, p.price_sale, p.stock AS product_stock,
           o.id AS option_id, o.size, o.extra_price, o.stock_qty AS option_stock
    FROM tt_products p
    LEFT JOIN tt_product_options o ON o.id = :option_id
    WHERE p.id = :pid AND p.status = "active"
');
try {
    $stmt->execute(['qty' => $qty, 'option_id' => $optionId, 'pid' => $productId]);
    $items = $stmt->fetchAll();
    echo "✅ 조회 성공: " . print_r($items, true) . "\n";
} catch (Throwable $e) {
    echo "❌ 예외: " . $e->getMessage() . "\n";
    exit;
}

echo "\nSTEP 3. 트랜잭션 시작 후 실제 로직 재현 (반드시 롤백)\n";
try {
    $pdo->beginTransaction();

    foreach ($items as $it) {
        echo "  - 재고 잠금 조회 → ";
        if ($it['option_id']) {
            $lock = $pdo->prepare('SELECT stock_qty FROM tt_product_options WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $it['option_id']]);
        } else {
            $lock = $pdo->prepare('SELECT stock FROM tt_products WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $it['product_id']]);
        }
        $row = $lock->fetch();
        echo "✅ " . print_r($row, true) . "\n";

        echo "  - 재고 차감 UPDATE → ";
        if ($it['option_id']) {
            $pdo->prepare('UPDATE tt_product_options SET stock_qty = stock_qty - :q WHERE id = :id')
                ->execute(['q' => $it['qty'], 'id' => $it['option_id']]);
        } else {
            $pdo->prepare('UPDATE tt_products SET stock = stock - :q WHERE id = :id')
                ->execute(['q' => $it['qty'], 'id' => $it['product_id']]);
        }
        echo "✅\n";

        echo "  - sales_count UPDATE → ";
        $pdo->prepare('UPDATE tt_products SET sales_count = sales_count + :q WHERE id = :id')
            ->execute(['q' => $it['qty'], 'id' => $it['product_id']]);
        echo "✅\n";
    }

    echo "\n  - tt_orders INSERT 시도 → ";
    $orderNo = 'TEST' . date('YmdHis');
    $pdo->prepare('
        INSERT INTO tt_orders (order_no, user_id, total_amount, shipping_fee, discount_amount, user_coupon_id,
                                recipient_name, recipient_phone, recipient_addr, memo, status)
        VALUES (:no, :uid, :total, :ship, :discount, :ucid, :rname, :rphone, :raddr, :memo, "pending")
    ')->execute([
        'no' => $orderNo, 'uid' => $uid, 'total' => 1000, 'ship' => 0, 'discount' => 0, 'ucid' => null,
        'rname' => '테스트', 'rphone' => '010-0000-0000', 'raddr' => '테스트 주소', 'memo' => '',
    ]);
    echo "✅ (orderId=" . $pdo->lastInsertId() . ")\n";

    $pdo->rollBack();
    echo "\n✅ 모든 단계 성공. 안전하게 롤백했습니다.\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n❌❌❌ 예외 발생: " . htmlspecialchars($e->getMessage()) . "\n";
    echo "   (line " . $e->getLine() . ", file " . $e->getFile() . ")\n";
}

echo "\n=== 진단 종료 ===\n";
echo "</pre>";
