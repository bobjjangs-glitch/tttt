<?php
declare(strict_types=1);
/**
 * [v51] 이카운트 자동동기화 엔트리 (cron).
 *
 * 호스팅 cron(dothome 예약작업 등)에서 5분 간격으로 이 URL을 호출하면:
 *  ① 결제완료 건의 ERP 전송 실패분 재시도 (10초 제한 준수)
 *  ② ERP 창고별 재고를 10분 주기로 가져와 쇼핑몰 재고에 자동 반영
 *
 * 인증: 관리자 세션 또는 ?token=자동동기화토큰 (연동 대시보드에서 확인·재발급)
 */
require_once __DIR__ . '/../core/bootstrap.php';
ensure_ecount_tables();

header('Content-Type: application/json; charset=utf-8');

$token = (string)($_GET['token'] ?? '');
$validToken = get_setting('ecount_cron_token', '');
$isAdmin = false;
try {
    $isAdmin = AdminAuth::currentAdminId() > 0;
} catch (Throwable $e) {
    $isAdmin = false;
}

if (!$isAdmin && ($token === '' || $validToken === '' || !hash_equals($validToken, $token))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '접근이 거부되었습니다 — 연동 대시보드의 자동동기화 URL을 사용하세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    session_write_close(); // [v61] ERP 호출(수 초) 전 세션 잠금 해제 — 다른 관리자 페이지 로드를 붙잡지 않게
    $result = ecount_cron_run();
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[admin/ecount-cron] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => '동기화 중 오류가 발생했습니다.'], JSON_UNESCAPED_UNICODE);
}
