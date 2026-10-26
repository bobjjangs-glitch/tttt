<?php
require __DIR__ . '/functions.php';
$GLOBALS['CFG'] = require __DIR__ . '/config.php';

mb_internal_encoding('UTF-8');
date_default_timezone_set((string)cfg('app.timezone', 'Asia/Seoul'));
error_reporting(E_ALL);
ini_set('display_errors', cfg('app.debug', false) ? '1' : '0');
ini_set('log_errors', '1');

set_exception_handler(function ($e) {
    error_log('[ERP] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (cfg('app.debug', false)) {
        // 디버그 중에는 상태코드를 바꾸지 않고(200) 본문을 그대로 보여준다.
        echo '<pre style="white-space:pre-wrap">' . h(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . '</pre>';
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!doctype html><meta charset="utf-8"><p>처리 중 오류가 발생했습니다. 관리자에게 문의하세요.</p>';
});

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('TIREERPSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require __DIR__ . '/trade_service.php';
require __DIR__ . '/layout.php';
