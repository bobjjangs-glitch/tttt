<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

/* 실시간 상태 API (폴링용) — 로그인 필요, JSON 반환
 * 해시 계산은 core/functions.php tablet_state_data() 하나로 통일
 * (페이지에 박힌 data-state-hash와 같은 값이어야 변화 없을 때 새로고침이 일어나지 않음) */
if (!tablet_current_worker() && !AdminAuth::isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'msg'=>'login required']);
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$state = tablet_state_data($date);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'date' => $date,
    'log_count' => $state['log_count'],
    'reservation_count' => $state['reservation_count'],
    'state_hash' => $state['hash'],
]);
