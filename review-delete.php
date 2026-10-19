<?php
// /review-delete.php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

if (!Auth::isLoggedIn()) {
    flash('error', '로그인이 필요합니다.');
    redirect('/login.php');
}
if (!is_post()) {
    redirect('/mypage.php#myreviews');
}

$returnTo = ($_POST['return_to'] ?? '') === 'product' ? 'product' : 'mypage';

if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    flash('error', '유효하지 않은 요청입니다.');
    redirect($returnTo === 'product' ? '/' : '/mypage.php#myreviews');
}

$userId   = (int)Auth::currentUserId();
$reviewId = (int)($_POST['review_id'] ?? 0);

$pdo = Database::connection();

// 반드시 user_id까지 함께 확인해 본인 리뷰만 삭제 가능하도록 한다 (타인 리뷰 삭제 방지)
$stmt = $pdo->prepare('SELECT id, product_id, user_id FROM tt_reviews WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $reviewId]);
$review = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$review || (int)$review['user_id'] !== $userId) {
    flash('error', '삭제할 리뷰를 찾을 수 없거나 권한이 없습니다.');
    redirect($returnTo === 'product' ? '/' : '/mypage.php#myreviews');
}

$productId = (int)$review['product_id'];

try {
    $pdo->beginTransaction();

    $pdo->prepare('DELETE FROM tt_reviews WHERE id = :id AND user_id = :uid')
        ->execute(['id' => $reviewId, 'uid' => $userId]);

    // 삭제 후 상품의 평균 별점/리뷰 수 캐시를 재계산한다
    $pdo->prepare('
        UPDATE tt_products p
        SET review_count = (SELECT COUNT(*) FROM tt_reviews WHERE product_id = p.id),
            rating_avg   = (SELECT COALESCE(AVG(rating),0) FROM tt_reviews WHERE product_id = p.id)
        WHERE p.id = :pid
    ')->execute(['pid' => $productId]);

    $pdo->commit();
    flash('success', '리뷰가 삭제되었습니다. 다시 작성하실 수 있습니다.');
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[review-delete] ' . $e->getMessage());
    flash('error', '리뷰 삭제 중 오류가 발생했습니다.');
}

if ($returnTo === 'product') {
    redirect('/product-detail.php?id=' . $productId . '#review');
} else {
    redirect('/mypage.php#myreviews');
}
