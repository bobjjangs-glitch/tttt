<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
ensure_review_extra_columns();

if (!defined('REVIEW_WRITE_WINDOW_DAYS')) {
    define('REVIEW_WRITE_WINDOW_DAYS', 7);
}

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

// [NEW] 부가 옵션(태그) 화이트리스트 검증 — 어드민에서 관리하는 tt_review_option_tags 기준
$optionTagsInput = (array)($_POST['option_tags'] ?? []);
$optionTags      = array_values(array_intersect($optionTagsInput, review_option_tag_options()));
$optionTagsStr   = implode(',', $optionTags);

$pdo = Database::connection();

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

$confirmedAt = new DateTime($orderItem['confirmed_at']);
$deadline    = (clone $confirmedAt)->modify('+' . REVIEW_WRITE_WINDOW_DAYS . ' days');
if (new DateTime() > $deadline) {
    flash('error', '리뷰 작성 가능 기간(구매확정 후 7일)이 지났습니다.');
    review_submit_redirect($productId, $returnTo);
}

$dupStmt = $pdo->prepare('SELECT id FROM tt_reviews WHERE user_id = :uid AND product_id = :pid LIMIT 1');
$dupStmt->execute(['uid' => $userId, 'pid' => $productId]);
if ($dupStmt->fetch()) {
    flash('error', '이미 이 상품에 리뷰를 작성하셨습니다.');
    review_submit_redirect($productId, $returnTo);
}

// [기존] 리뷰 사진 업로드 처리 (최대 3장, jpg/png/webp, 장당 5MB 이하)
$uploadedPhotoUrls = [];
if (!empty($_FILES['photos']['tmp_name']) && is_array($_FILES['photos']['tmp_name'])) {
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $maxBytes   = 5 * 1024 * 1024;
    $maxCount   = 3;
    $count      = 0;

    foreach ($_FILES['photos']['tmp_name'] as $idx => $tmpPath) {
        if ($count >= $maxCount) break;
        if (($_FILES['photos']['error'][$idx] ?? 1) !== UPLOAD_ERR_OK) continue;
        if (($_FILES['photos']['size'][$idx] ?? 0) > $maxBytes) continue;

        $origName = (string)($_FILES['photos']['name'][$idx] ?? '');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) continue;
        if (@getimagesize($tmpPath) === false) continue;

        $subDir = 'uploads/reviews/' . date('Ym');
        $destDir = __DIR__ . '/' . $subDir;
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $fileName = bin2hex(random_bytes(8)) . '.' . $ext;
        if (move_uploaded_file($tmpPath, $destDir . '/' . $fileName)) {
            $uploadedPhotoUrls[] = '/' . $subDir . '/' . $fileName;
        }
        $count++;
    }
}

try {
    $pdo->beginTransaction();

    $pdo->prepare('
        INSERT INTO tt_reviews (product_id, user_id, order_item_id, rating, content, option_tags, created_at)
        VALUES (:pid, :uid, :oid, :rating, :content, :option_tags, NOW())
    ')->execute([
        'pid'          => $productId,
        'uid'          => $userId,
        'oid'          => $orderItem['id'],
        'rating'       => $rating,
        'content'      => $content,
        'option_tags'  => $optionTagsStr !== '' ? $optionTagsStr : null,
    ]);

    $reviewId = (int)$pdo->lastInsertId();

    if (!empty($uploadedPhotoUrls)) {
        $photoStmt = $pdo->prepare('INSERT INTO tt_review_photos (review_id, image_url, sort_order) VALUES (:rid, :url, :sort)');
        foreach ($uploadedPhotoUrls as $i => $url) {
            $photoStmt->execute(['rid' => $reviewId, 'url' => $url, 'sort' => $i]);
        }
    }

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

review_submit_redirect($productId, $returnTo);
