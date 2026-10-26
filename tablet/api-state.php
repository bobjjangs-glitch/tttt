<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

/* 보강된 해시(예약확정 감지)를 쓰기 위해 board-lib 로드 — 실패해도 기존 동작으로 폴백 */
$boardLibPath = __DIR__ . '/includes/board-lib.php';
if (is_file($boardLibPath)) {
    try { require_once $boardLibPath; } catch (Throwable $e) { error_log('[board-lib] ' . $e->getMessage()); }
}

/* 실시간 상태 API (폴링용) — 로그인 필요, JSON 반환
 * 페이지에 박힌 data-state-hash와 반드시 같은 계산이어야 변화 없을 때 새로고침이 일어나지 않음 */
if (!tablet_current_worker() && !AdminAuth::isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'msg'=>'login required']);
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$state = tablet_state_data($date);
$hash  = function_exists('tablet_page_hash') ? tablet_page_hash($date) : $state['hash'];

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'date' => $date,
    'log_count' => $state['log_count'],
    'reservation_count' => $state['reservation_count'],
    'state_hash' => $hash,
]);
