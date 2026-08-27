<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AdminAuth.php';
require_once __DIR__ . '/../Sms.php';   // [FIX] 이 줄이 누락되어 있어 Sms 클래스 호출 시 Fatal Error(500) 발생 → "발송 중 오류가 발생했습니다"로 이어졌음


/* =====================================================================
   [FIX] 운영/개발 환경에 따라 에러 노출 방식을 분리한다.
   과거에는 error_reporting(E_ALL) + display_errors=1 이 배포 후에도
   그대로 남아 있어, 치명적 오류 발생 시 서버 내부 경로/쿼리/스택트레이스가
   사용자 화면에 그대로 노출되는 문제가 있었다.
   ===================================================================== */
error_reporting(E_ALL);          // 로그에는 항상 모든 레벨을 남긴다 (운영/개발 공통)
ini_set('log_errors', '1');

if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0755, true);
}
ini_set('error_log', LOG_DIR . 'php-error.log');

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');   // 화면에는 절대 노출하지 않음
    ini_set('display_startup_errors', '0');
} else {
    ini_set('display_errors', '1');   // 개발 중에는 즉시 확인 가능하게 노출
    ini_set('display_startup_errors', '1');
}

/* =====================================================================
   [NEW] 처리되지 않은 예외(Exception) 전역 핸들러.
   운영에서는 친절한 안내 화면만 보여주고, 상세 내용은 로그에만 남긴다.
   ===================================================================== */
set_exception_handler(function (Throwable $e): void {
    error_log('[UNCAUGHT] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (APP_ENV === 'production') {
        echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><title>일시적인 오류</title></head><body style="font-family:sans-serif;text-align:center;padding:80px 20px;">'
           . '<h2>일시적인 오류가 발생했습니다</h2><p>잠시 후 다시 시도해 주세요. 문제가 계속되면 고객센터로 문의해 주세요.</p>'
           . '<a href="' . BASE_URL . '/" style="color:#2563eb;">홈으로 돌아가기</a></body></html>';
    } else {
        echo '<pre style="color:red;white-space:pre-wrap;">' . htmlspecialchars((string)$e) . '</pre>';
    }
});

/* =====================================================================
   [NEW] 치명적 오류(Fatal Error) 캐치용 셧다운 핸들러.
   PHP의 Parse Error, Fatal Error 등은 예외가 아니라서 set_exception_handler로
   못 잡기 때문에 register_shutdown_function으로 별도 포착한다.
   ===================================================================== */
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) return;

    error_log('[FATAL] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);

    if (APP_ENV === 'production' && !headers_sent()) {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><title>일시적인 오류</title></head><body style="font-family:sans-serif;text-align:center;padding:80px 20px;">'
           . '<h2>일시적인 오류가 발생했습니다</h2><p>잠시 후 다시 시도해 주세요. 문제가 계속되면 고객센터로 문의해 주세요.</p>'
           . '<a href="' . BASE_URL . '/" style="color:#2563eb;">홈으로 돌아가기</a></body></html>';
    }
});

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AdminAuth.php';
