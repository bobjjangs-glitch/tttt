<?php
// test-db.php — 진단용 임시 파일. 확인 끝나면 반드시 삭제하세요.
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
echo "<pre>";

echo "STEP 1. Database::connection() 호출 → ";
try {
    $pdo = Database::connection();
    echo "✅ 연결 성공\n";
} catch (Throwable $e) {
    echo "❌ 예외: " . htmlspecialchars($e->getMessage()) . " (line " . $e->getLine() . ")\n";
    echo "</pre>";
    exit;
}

// buy-now.php와 완전히 동일한 쿼리, 동일한 파라미터(referer에서 확인된 id=11051, opt=1)로 테스트
$productId = 11051;
$optionId  = 1;

echo "\nSTEP 2. tt_products 조회 (id={$productId}) → ";
try {
    $stmt = $pdo->prepare('SELECT id, stock, status FROM tt_products WHERE id = :id');
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();
    if ($product) {
        echo "✅ 조회 성공\n";
        echo htmlspecialchars(print_r($product, true)) . "\n";
    } else {
        echo "⚠️ 결과 없음 (해당 id의 상품이 존재하지 않거나 다른 문제)\n";
    }
} catch (Throwable $e) {
    echo "❌ 예외: " . htmlspecialchars($e->getMessage()) . " (line " . $e->getLine() . ")\n";
}

echo "\nSTEP 3. tt_product_options 조회 (id={$optionId}, pid={$productId}) → ";
try {
    $optStmt = $pdo->prepare('
        SELECT id, stock_qty, status
        FROM tt_product_options
        WHERE id = :id AND product_id = :pid
    ');
    $optStmt->execute(['id' => $optionId, 'pid' => $productId]);
    $optionRow = $optStmt->fetch();
    if ($optionRow) {
        echo "✅ 조회 성공\n";
        echo htmlspecialchars(print_r($optionRow, true)) . "\n";
    } else {
        echo "⚠️ 결과 없음 (해당 옵션이 존재하지 않거나 컬럼명 불일치 가능성)\n";
    }
} catch (Throwable $e) {
    echo "❌ 예외: " . htmlspecialchars($e->getMessage()) . " (line " . $e->getLine() . ")\n";
}

echo "\n=== 진단 종료 ===\n";
echo "</pre>";
