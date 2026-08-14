<?php
// /admin/whoami.php — 진단 전용, 확인 후 삭제
declare(strict_types=1);

echo "<pre>";
echo "1) 이 파일의 실제 경로(__FILE__): " . __FILE__ . "\n";
echo "2) 이 파일이 있는 폴더(__DIR__): " . __DIR__ . "\n\n";

$loginPath = __DIR__ . '/login.php';
echo "3) admin/login.php 파일 정보\n";
echo "   - 존재 여부: " . (file_exists($loginPath) ? '있음' : '없음') . "\n";
if (file_exists($loginPath)) {
    echo "   - 최종 수정 시각: " . date('Y-m-d H:i:s', filemtime($loginPath)) . "\n";
    echo "   - 파일 크기: " . filesize($loginPath) . " bytes\n\n";
    echo "   - 실제 내용 (앞부분 10줄):\n";
    $lines = file($loginPath);
    for ($i = 0; $i < min(10, count($lines)); $i++) {
        echo "     [" . ($i + 1) . "] " . htmlspecialchars($lines[$i]);
    }
}

echo "\n4) bootstrap.php 후보 경로 확인\n";
$candidateA = __DIR__ . '/core/bootstrap.php';        // admin/core/bootstrap.php
$candidateB = __DIR__ . '/../core/bootstrap.php';     // core/bootstrap.php (루트)
echo "   - admin/core/bootstrap.php 존재 여부: " . (file_exists($candidateA) ? '있음' : '없음') . "\n";
echo "   - 프로젝트 루트 core/bootstrap.php 존재 여부: " . (file_exists($candidateB) ? '있음' : '없음') . "\n";
if (file_exists($candidateB)) {
    echo "     (실제 경로: " . realpath($candidateB) . ")\n";
}

echo "\n5) OPcache 상태\n";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    echo "   - OPcache 활성화 여부: " . ($status !== false ? '활성화됨' : '비활성화됨') . "\n";
    if ($status !== false && isset($status['scripts'][$loginPath])) {
        echo "   - ⚠️ login.php가 OPcache에 캐시되어 있음 (캐시된 버전과 실제 파일이 다를 수 있음)\n";
    }
} else {
    echo "   - OPcache 함수 없음 (비활성 환경)\n";
}
echo "</pre>";
