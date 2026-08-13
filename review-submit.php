<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

if (!defined('REVIEW_WRITE_WINDOW_DAYS')) {
    define('REVIEW_WRITE_WINDOW_DAYS', 7);
}

/**
 * 처리 결과에 따라 되돌아갈 위치를 결정한다.
 * return_to=mypage 이면 마이페이지의 '내가 쓴 리뷰' 섹션으로,
 * 그 외(기본값)에는 기존처럼 상품 상세페이지 리뷰 탭으로 돌려보낸다.
 */
function review_submit_redirect(int $productId, string $returnTo): void
{
    if ($returnTo === 'mypage') {
        redirect('/mypage.php#myreviews');
    } else {
        redirect('/product-detail.php?id=' . $productId . '#review');
    }
    exit;
}

if (!Auth::isLoggedIn()) {
    flash('error', '로그인이 필요합니다.');
    redirect('/login.php');
    exit;
}
if (!is_post()) {
    redirect('/');
    exit;
}

$returnTo = ($_POST['return_to'] ?? '') === 'mypage' ? 'mypage' : 'product';

if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    flash('error', '잘못된 요청입니다.');
    review_submit_redirect((int)($_POST['product_id'] ?? 0), $returnTo);
}

$userId    = (int)Auth::currentUserId();
$productId = (int)($_POST['product_id'] ?? 0);
$rating    = (int)($_POST['rating'] ?? 0);
$content   = trim($_POST['content'] ?? '');

if ($productId <= 0 || $rating < 1 || $rating > 5 || $content === '') {
    flash('error', '별점과 리뷰 내용을 모두 입력해 주세요.');
    review_submit_redirect($productId, $returnTo);
}
if (mb_strlen($content) > 1000) {
    flash('error', '리뷰는 1000자 이내로 작성해 주세요.');
    review_submit_redirect($productId, $returnTo);
}

$pdo = Database::connection();

// 구매확정(confirmed_at) 이력 확인
$buyStmt = $pdo->prepare("
    SELECT oi.id, o.confirmed_at
    FROM tt_order_items oi
    JOIN tt_orders o ON o.id = oi.order_id
    WHERE o.user_id = :uid AND oi.product_id = :pid AND o.confirmed_at IS NOT NULL
    ORDER BY o.confirmed_at DESC
    LIMIT 1
");
$buyStmt->execute(['uid' => $userId, 'pid' => $productId]);
$orderItem = $buyStmt->fetch(PDO::FETCH_ASSOC);

if (!$orderItem || empty($orderItem['id'])) {
    flash('error', '구매확정된 상품에만 리뷰를 작성할 수 있습니다.');
    review_submit_redirect($productId, $returnTo);
}

// 구매확정일로부터 7일 초과 시 서버에서 강제 차단 (화면 숨김과 별개인 이중 방어)
$confirmedAt = new DateTime($orderItem['confirmed_at']);
$deadline    = (clone $confirmedAt)->modify('+' . REVIEW_WRITE_WINDOW_DAYS . ' days');
if (new DateTime() > $deadline) {
    flash('error', '리뷰 작성 가능 기간(구매확정 후 7일)이 지났습니다.');
    review_submit_redirect($productId, $returnTo);
}

// 중복 작성 방지
$dupStmt = $pdo->prepare('SELECT id FROM tt_reviews WHERE user_id = :uid AND product_id = :pid LIMIT 1');
$dupStmt->execute(['uid' => $userId, 'pid' => $productId]);
if ($dupStmt->fetch()) {
    flash('error', '이미 이 상품에 리뷰를 작성하셨습니다.');
    review_submit_redirect($productId, $returnTo);
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

    $pdo->prepare('
        UPDATE tt_products p
        SET review_count = (SELECT COUNT(*) FROM tt_reviews WHERE product_id = p.id),
            rating_avg   = (SELECT COALESCE(AVG(rating),0) FROM tt_reviews WHERE product_id = p.id)
        WHERE p.id = :pid
    ')->execute(['pid' => $productId]);

    $pdo->commit();
    flash('success', '리뷰가 등록되었습니다.');

} catch (PDOException $e) {
    $pdo->rollBack();

    $sqlState  = $e->errorInfo[0] ?? $e->getCode();
    $driverMsg = $e->errorInfo[2] ?? $e->getMessage();

    error_log(sprintf(
        '[review-submit] sqlstate=%s uid=%d pid=%d oid=%s msg=%s',
        (string)$sqlState,
        $userId,
        $productId,
        (string)($orderItem['id'] ?? 'null'),
        $driverMsg
    ));

    flash('error', '리뷰 등록 중 오류가 발생했습니다.');

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[review-submit] ' . $e->getMessage());
    flash('error', '리뷰 등록 중 오류가 발생했습니다.');
}

review_submit_redirect($productId, $returnTo);
