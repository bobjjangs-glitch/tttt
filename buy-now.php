<?php
// /buy-now.php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

if (!Auth::isLoggedIn()) redirect('/login.php');

if (!is_post()) redirect('/product-list.php');

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    flash('error', '유효하지 않은 요청입니다. 다시 시도해주세요.');
    redirect('/product-list.php');
}

$pdo = Database::connection();

$productId = (int)($_POST['product_id'] ?? 0);
$optionId  = (isset($_POST['option_id']) && $_POST['option_id'] !== '') ? (int)$_POST['option_id'] : null;
$qty       = max(1, min(99, (int)($_POST['qty'] ?? 1))); // 비정상적인 대량 주문 방지

if ($productId <= 0) {
    flash('error', '잘못된 상품 요청입니다.');
    redirect('/product-list.php');
}

// 상품 존재 및 판매 상태 확인 (가격은 여기서 절대 신뢰하지 않고 checkout.php에서 다시 조회함)
$stmt = $pdo->prepare('SELECT id, stock, status FROM tt_products WHERE id = :id');
$stmt->execute(['id' => $productId]);
$product = $stmt->fetch();

if (!$product || $product['status'] !== 'active') {
    flash('error', '판매 중인 상품이 아닙니다.');
    redirect('/product-detail.php?id=' . $productId);
}

if ($optionId !== null) {
    // [FIX] tt_product_options 테이블에는 'status' 컬럼이 존재하지 않음.
    //       실제 스키마 기준 판매 상태 컬럼은 'is_active' (TINYINT, 1=활성/0=비활성).
    //       'status'로 SELECT 시 SQLSTATE[42S22] Unknown column 예외가 발생하여
    //       try/catch 없이 그대로 전역 핸들러까지 전파 → 500 에러의 원인이었음.
    $optStmt = $pdo->prepare('
        SELECT id, stock_qty, is_active
        FROM tt_product_options
        WHERE id = :id AND product_id = :pid
    ');
    $optStmt->execute(['id' => $optionId, 'pid' => $productId]);
    $optionRow = $optStmt->fetch();

    if (!$optionRow || (int)$optionRow['is_active'] !== 1) {
        flash('error', '선택하신 옵션은 판매 중이 아닙니다.');
        redirect('/product-detail.php?id=' . $productId);
    }
    if ((int)$optionRow['stock_qty'] < $qty) {
        flash('error', '선택하신 옵션의 재고가 부족합니다.');
        redirect('/product-detail.php?id=' . $productId);
    }
} else {
    if ((int)$product['stock'] < $qty) {
        flash('error', '상품 재고가 부족합니다.');
        redirect('/product-detail.php?id=' . $productId);
    }
}

// DB(tt_carts)에는 절대 기록하지 않는다 — 장바구니와 완전히 분리된 임시 구매 의도만 세션에 저장
$_SESSION['buy_now'] = [
    'product_id' => $productId,
    'option_id'  => $optionId,
    'qty'        => $qty,
];

redirect('/checkout.php?mode=buynow');
