<?php
declare(strict_types=1);

/* =====================================================================
   [FIX] 출력 버퍼링을 파일 최상단에서 즉시 시작한다.
   원인: 어딘가의 PHP 파일에 BOM 문자, ?> 태그 뒤 공백/줄바꿈, 혹은 남겨진
   echo/디버그 출력이 단 한 글자라도 섞여 있으면 header('Location: ...')가
   "headers already sent" 에러로 조용히 실패한다. 운영모드(APP_ENV=production)는
   display_errors=0 이라 화면에 아무 경고도 안 뜨고, 그냥 buy-now.php처럼
   리다이렉트 직전 페이지에 빈 화면(또는 파편적인 출력)만 남는 결과가 된다.
   ob_start()로 모든 출력을 버퍼에 가둬두면, 실제로 header()를 호출하는
   시점까지 브라우저로 아무것도 전송되지 않으므로 리다이렉트가 항상
   정상적으로 동작한다.
   ===================================================================== */
if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AdminAuth.php';
require_once __DIR__ . '/../Sms.php';   // Sms 클래스 호출 시 Fatal Error(500) 방지용 로딩

/* =====================================================================
   운영/개발 환경에 따라 에러 노출 방식을 분리한다.
   운영에서는 화면에 절대 노출하지 않고 로그에만 남긴다.
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
   처리되지 않은 예외(Exception) 전역 핸들러.
   운영에서는 친절한 안내 화면만 보여주고, 상세 내용은 로그에만 남긴다.
   ===================================================================== */
set_exception_handler(function (Throwable $e): void {
    error_log('[UNCAUGHT] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());

    // [FIX] 예외 발생 시 이미 버퍼에 쌓인 파편적인 출력이 있다면 깨끗이 비우고
    // 새로 에러 화면만 그린다. 화면에 이상한 조각 문자가 섞여 나오는 것을 방지.
    if (ob_get_level()) {
        ob_clean();
    }

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
    exit;
});

/* =====================================================================
   치명적 오류(Fatal Error) 캐치용 셧다운 핸들러.
   PHP의 Parse Error, Fatal Error 등은 예외가 아니라서 set_exception_handler로
   못 잡기 때문에 register_shutdown_function으로 별도 포착한다.
   ===================================================================== */
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) return;

    error_log('[FATAL] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);

    // [FIX] 여기서도 동일하게 버퍼를 비워 파편 출력 없이 안내 화면만 나가도록 한다.
    if (ob_get_level()) {
        ob_clean();
    }

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
