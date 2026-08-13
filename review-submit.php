<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

if (!Auth::isLoggedIn()) {
    flash('error', '로그인이 필요합니다.');
    redirect('/login.php');
}
if (!is_post()) {
    redirect('/');
}
if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    flash('error', '잘못된 요청입니다.');
    redirect('/product-detail.php?id=' . (int)($_POST['product_id'] ?? 0));
}

$userId    = (int)Auth::currentUserId();
$productId = (int)($_POST['product_id'] ?? 0);
$rating    = (int)($_POST['rating'] ?? 0);
$content   = trim($_POST['content'] ?? '');

if ($productId <= 0 || $rating < 1 || $rating > 5 || $content === '') {
    flash('error', '별점과 리뷰 내용을 모두 입력해 주세요.');
    redirect('/product-detail.php?id=' . $productId);
}
if (mb_strlen($content) > 1000) {
    flash('error', '리뷰는 1000자 이내로 작성해 주세요.');
    redirect('/product-detail.php?id=' . $productId);
}

$pdo = Database::connection();

// 구매 확정 이력 확인 (order_item_id를 함께 찾아 리뷰에 연결)
$buyStmt = $pdo->prepare("
    SELECT oi.id FROM tt_order_items oi
    JOIN tt_orders o ON o.id = oi.order_id
    WHERE o.user_id = :uid AND oi.product_id = :pid AND o.status = 'completed'
    LIMIT 1
");
$buyStmt->execute(['uid' => $userId, 'pid' => $productId]);
$orderItem = $buyStmt->fetch();

if (!$orderItem) {
    flash('error', '구매가 완료된 상품에만 리뷰를 작성할 수 있습니다.');
    redirect('/product-detail.php?id=' . $productId);
}

// 중복 작성 방지 (같은 상품에 이미 리뷰를 남겼는지)
$dupStmt = $pdo->prepare('SELECT id FROM tt_reviews WHERE user_id = :uid AND product_id = :pid LIMIT 1');
$dupStmt->execute(['uid' => $userId, 'pid' => $productId]);
if ($dupStmt->fetch()) {
    flash('error', '이미 이 상품에 리뷰를 작성하셨습니다.');
    redirect('/product-detail.php?id=' . $productId);
}

try {
    $pdo->beginTransaction();

    $pdo->prepare('
        INSERT INTO tt_reviews (product_id, user_id, order_item_id, rating, content, created_at)
        VALUES (:pid, :uid, :oid, :rating, :content, NOW())
    ')->execute([
        'pid'     => $productId,
        'uid'     => $userId,
        'oid'     => $orderItem['id'],
        'rating'  => $rating,
        'content' => $content,
    ]);

    // 상품 테이블의 평균 별점/리뷰 수 캐시 갱신
    $pdo->prepare('
        UPDATE tt_products p
        SET review_count = (SELECT COUNT(*) FROM tt_reviews WHERE product_id = p.id),
            rating_avg   = (SELECT COALESCE(AVG(rating),0) FROM tt_reviews WHERE product_id = p.id)
        WHERE p.id = :pid
    ')->execute(['pid' => $productId]);

    $pdo->commit();
    flash('success', '리뷰가 등록되었습니다.');
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[review-submit] ' . $e->getMessage());
    flash('error', '리뷰 등록 중 오류가 발생했습니다.');
}

redirect('/product-detail.php?id=' . $productId . '#review');
