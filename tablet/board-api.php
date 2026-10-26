<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/board-lib.php';

function board_json(array $data, int $code = 200): never
{
    if (ob_get_level()) ob_clean();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {                       // 인코딩 실패를 빈 응답으로 흘리지 않는다
        $code = 500;
        $json = '{"ok":false,"msg":"encode error"}';
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo $json;
    exit;
}

$ok = board_authorized();      // 세션을 읽은 뒤
session_write_close();         // 폴링 요청끼리 세션 락으로 직렬화되지 않게 즉시 해제
if (!$ok) board_json(['ok' => false, 'msg' => 'unauthorized'], 401);

try {
    board_json(['ok' => true] + board_fetch(date('Y-m-d')));
} catch (Throwable $e) {
    error_log('[board-api] ' . $e->getMessage());
    sg_maybe_invalidate($e);
    board_json(['ok' => false, 'msg' => 'server error'], 500);
}
