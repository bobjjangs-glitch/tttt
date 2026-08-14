<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h2>convert_price_list.php 정밀 진단</h2>";

$target = __DIR__ . '/convert_price_list.php';

echo "대상 파일: " . htmlspecialchars($target) . "<br>";

if (!file_exists($target)) {
    echo "❌ 파일이 존재하지 않습니다.<br>";
    exit;
}

echo "파일 크기: " . filesize($target) . " bytes<br>";
echo "파일 권한: " . substr(sprintf('%o', fileperms($target)), -4) . "<br>";
echo "<hr>";

// 1) 문법 검사 (파싱만, 실행 안 함)
$code = file_get_contents($target);
$tokens = @token_get_all($code);
if ($tokens === false) {
    echo "❌ 토큰화 자체가 실패했습니다. (심각한 인코딩/문법 문제)<br>";
} else {
    echo "✅ 토큰화는 통과했습니다. (단, 이건 완전한 문법검사가 아닙니다)<br>";
}
echo "<hr>";

// 2) 실제 include 시도 (ParseError까지 잡아냄)
echo "<b>실제 include 시도:</b><br>";
try {
    ob_start();
    include $target;
    $output = ob_get_clean();
    echo "✅ include 성공! (아래는 실제 출력 내용)<br>";
    echo "<div style='border:1px solid #ccc;padding:10px;'>" . $output . "</div>";
} catch (\ParseError $e) {
    ob_end_clean();
    echo "❌ ParseError 발견!<br>";
    echo "메시지: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "파일: " . htmlspecialchars($e->getFile()) . "<br>";
    echo "라인: " . $e->getLine() . "<br>";
} catch (\Throwable $e) {
    ob_end_clean();
    echo "❌ 런타임 오류 발견!<br>";
    echo "종류: " . get_class($e) . "<br>";
    echo "메시지: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "파일: " . htmlspecialchars($e->getFile()) . "<br>";
    echo "라인: " . $e->getLine() . "<br>";
}
