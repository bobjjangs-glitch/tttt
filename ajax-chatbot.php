<?php
// ajax-chatbot.php — AI 챗봇 응답 API (키워드 매칭)
require_once __DIR__ . '/core/bootstrap.php';
ensure_chatbot_table();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) {
    echo json_encode(['ok' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$message = trim($_POST['message'] ?? '');
if ($message === '' || mb_strlen($message, 'UTF-8') > 500) {
    echo json_encode(['ok' => false, 'message' => '메시지를 입력해주세요. (최대 500자)']);
    exit;
}

$pdo = Database::connection();

// 키워드 매칭
$matched = chatbot_match_answer($pdo, $message);

if ($matched) {
    echo json_encode([
        'ok' => true,
        'type' => 'matched',
        'question' => $matched['question'],
        'answer' => $matched['answer'],
        'suggestions' => chatbot_quick_suggestions($pdo, (int)$matched['id']),
    ]);
    exit;
}

// 매칭 실패 → 추천 질문과 함께 안내
echo json_encode([
    'ok' => true,
    'type' => 'fallback',
    'answer' => "죄송합니다, 정확한 답변을 찾지 못했습니다.\n아래 추천 질문을 눌러주시거나,\n고객센터(1588-0000)로 문의해주세요.",
    'suggestions' => chatbot_quick_suggestions($pdo, 0),
]);

function chatbot_quick_suggestions(PDO $pdo, int $excludeId): array
{
    try {
        $stmt = $pdo->prepare('SELECT question FROM tt_chatbot_entries WHERE is_active = 1 AND id != :ex ORDER BY sort_order ASC, id ASC LIMIT 3');
        $stmt->execute(['ex' => $excludeId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'question');
    } catch (Throwable $e) {
        return [];
    }
}
