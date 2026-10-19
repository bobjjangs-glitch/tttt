<?php
// ajax-chatbot-suggestions.php — 챗봇 첫 화면 추천 질문 목록
require_once __DIR__ . '/core/bootstrap.php';
ensure_chatbot_table();
header('Content-Type: application/json; charset=utf-8');

try {
    $stmt = Database::connection()->query(
        'SELECT question FROM tt_chatbot_entries WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 3'
    );
    $questions = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'question');
    echo json_encode(['ok' => true, 'questions' => $questions]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'questions' => []]);
}
