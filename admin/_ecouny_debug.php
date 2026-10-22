<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requireLogin();
if (!AdminAuth::isSuper()) {
    http_response_code(403);
    exit('최고관리자만 접근할 수 있습니다.');
}

header('Content-Type: text/html; charset=utf-8');
$pdo = Database::connection();

echo "<h2>이카운트 연동 진단</h2>";
echo "<p><b>1) DB에 저장된 설정값 (admin/api-settings.php에서 입력한 값)</b></p>";
echo "<pre>";
echo "ecount_com_code = " . htmlspecialchars(get_setting('ecount_com_code', '(미설정)')) . "\n";
echo "ecount_user_id  = " . htmlspecialchars(get_setting('ecount_user_id', '(미설정)')) . "\n";
echo "ecount_api_key  = " . (get_setting('ecount_api_key', '') !== '' ? '(저장됨, 앞 4자: ' . substr(get_setting('ecount_api_key',''),0,4) . '••••)' : '(미설정)') . "\n";
echo "ecount_domain   = " . htmlspecialchars(get_setting('ecount_domain', '(미설정)')) . "\n";
echo "</pre>";

echo "<p><b>2) 지금 실행 중인 PHP 코드가 실제로 사용하는 값</b></p>";
echo "<pre>";
try {
    $client = new EcountClient($pdo);
    if (method_exists($client, 'domainBase')) {
        echo "코드가 계산한 domainBase = " . htmlspecialchars($client->domainBase()) . "\n";
        echo "→ 이 값이 위 1번의 ecount_domain과 다르면, 코드가 예전 버전(OPcache 캐시 또는 업로드 누락)입니다.\n";
    } else {
        echo "⚠️ domainBase() 메서드가 없습니다. core/EcountClient.php가 구버전입니다. 수정본 재업로드가 필요합니다.\n";
    }
} catch (Throwable $e) {
    echo "EcountClient 생성 실패: " . htmlspecialchars($e->getMessage()) . "\n";
}
echo "</pre>";

echo "<p><b>3) 파일 반영 시각 확인</b></p>";
echo "<pre>";
$f = __DIR__ . '/../core/EcountClient.php';
echo "core/EcountClient.php 최종 수정 시각 = " . (is_file($f) ? date('Y-m-d H:i:s', filemtime($f)) : '파일 없음') . "\n";
echo "(방금 재업로드하셨다면, 이 시각이 방금 전이어야 정상입니다. 오래된 시각이면 업로드가 실패한 것입니다.)\n";
echo "</pre>";

echo "<p style='color:red;font-weight:bold;'>⚠️ 확인이 끝나면 이 파일(admin/_ecount_debug.php)은 반드시 삭제하세요. 민감정보는 마스킹했지만 진단용 임시파일을 운영서버에 남겨두면 안 됩니다.</p>";
