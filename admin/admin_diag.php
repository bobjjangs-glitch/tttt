<?php
// /admin_diag.php
// ⚠️ 진단 전용 임시 파일입니다. 확인 후 반드시 삭제하세요.
declare(strict_types=1);

echo "<h3>1. bootstrap.php 로드</h3>";
require_once __DIR__ . '/core/bootstrap.php';
echo "bootstrap.php 로드 완료<br>";

echo "<h3>2. AdminAuth 클래스 존재 여부</h3>";
echo class_exists('AdminAuth') ? "✅ AdminAuth 클래스 존재함<br>" : "❌ AdminAuth 클래스가 존재하지 않음<br>";

echo "<h3>3. AdminAuth 클래스가 실제로 로드된 파일 경로</h3>";
if (class_exists('AdminAuth')) {
    $ref = new ReflectionClass('AdminAuth');
    $filePath = $ref->getFileName();
    echo "파일 경로: " . htmlspecialchars($filePath) . "<br>";
    echo "파일 수정 시각: " . date('Y-m-d H:i:s', filemtime($filePath)) . "<br>";
    echo "파일 크기: " . filesize($filePath) . " bytes<br>";
}

echo "<h3>4. AdminAuth 클래스에 정의된 메서드 목록</h3>";
if (class_exists('AdminAuth')) {
    foreach (get_class_methods('AdminAuth') as $m) {
        echo "- " . htmlspecialchars($m) . "<br>";
    }
}

echo "<h3>5. core/AdminAuth.php 파일 직접 확인</h3>";
$directPath = __DIR__ . '/core/AdminAuth.php';
echo "경로: {$directPath}<br>";
echo "존재 여부: " . (file_exists($directPath) ? '✅' : '❌') . "<br>";
if (file_exists($directPath)) {
    echo "수정 시각: " . date('Y-m-d H:i:s', filemtime($directPath)) . "<br>";
    echo "파일 크기: " . filesize($directPath) . " bytes<br>";
    echo "<strong>currentAdminName 문자열 포함 여부:</strong> ";
    $content = file_get_contents($directPath);
    echo (strpos($content, 'currentAdminName') !== false) ? '✅ 포함됨' : '❌ 포함 안 됨';
    echo "<br>";
}

echo "<h3>6. OPcache 상태</h3>";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    echo "OPcache 활성화 여부: " . (($status !== false) ? '✅ 활성화됨' : '❌ 비활성화됨') . "<br>";
} else {
    echo "OPcache 함수 없음 (비활성 환경으로 추정)<br>";
}
