<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
ensure_review_extra_columns();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$csrfToken = (string)($input['csrf_token'] ?? '');
$reviewId  = (int)($input['review_id'] ?? 0);

if (!Csrf::verify($csrfToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($reviewId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '잘못된 리뷰입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)Auth::currentUserId();
$pdo = Database::connection();

try {
    $chkStmt = $pdo->prepare('SELECT id FROM tt_reviews WHERE id = :rid LIMIT 1');
    $chkStmt->execute(['rid' => $reviewId]);
    if (!$chkStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '존재하지 않는 리뷰입니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    $existStmt = $pdo->prepare('SELECT id FROM tt_review_helpful WHERE review_id = :rid AND user_id = :uid LIMIT 1');
    $existStmt->execute(['rid' => $reviewId, 'uid' => $userId]);
    $existing = $existStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->prepare('DELETE FROM tt_review_helpful WHERE id = :id')->execute(['id' => $existing['id']]);
        $pdo->prepare('UPDATE tt_reviews SET helpful_count = GREATEST(0, helpful_count - 1) WHERE id = :id')->execute(['id' => $reviewId]);
        $helpfulNow = false;
    } else {
        $pdo->prepare('INSERT INTO tt_review_helpful (review_id, user_id) VALUES (:rid, :uid)')->execute(['rid' => $reviewId, 'uid' => $userId]);
        $pdo->prepare('UPDATE tt_reviews SET helpful_count = helpful_count + 1 WHERE id = :id')->execute(['id' => $reviewId]);
        $helpfulNow = true;
    }

    $countStmt = $pdo->prepare('SELECT helpful_count FROM tt_reviews WHERE id = :id');
    $countStmt->execute(['id' => $reviewId]);
    $newCount = (int)$countStmt->fetchColumn();

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'data' => [
            'helpful'       => $helpfulNow,
            'helpful_count' => $newCount,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[review-helpful] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '처리 중 오류가 발생했습니다.'], JSON_UNESCAPED_UNICODE);
}
