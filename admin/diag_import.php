<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== 진단 시작 ===<br>";
echo "PHP 버전: " . phpversion() . "<br><hr>";

$targets = [
    'bootstrap.php'    => __DIR__ . '/../core/bootstrap.php',
    'SimpleXLSX.php'   => __DIR__ . '/libs/SimpleXLSX.php',
    'products_import.php (문법검사만)' => __DIR__ . '/products_import.php',
];

foreach ($targets as $label => $path) {
    echo "<b>[$label]</b><br>";
    echo "경로: " . htmlspecialchars($path) . "<br>";

    if (!file_exists($path)) {
        echo "❌ 파일이 존재하지 않습니다.<br><hr>";
        continue;
    }
    echo "✅ 파일 존재 (크기: " . filesize($path) . " bytes)<br>";

    // 문법 오류까지 잡아내기 위해 try-catch로 include 시도
    try {
        if ($label === 'products_import.php (문법검사만)') {
            // 실제 실행은 하지 않고 문법만 검사하기 위해 토큰화만 수행
            $code = file_get_contents($path);
            $tokens = @token_get_all($code);
            if ($tokens === false) {
                echo "❌ 토큰화 실패 (문법 오류 가능성 높음)<br>";
            } else {
                echo "✅ 문법 토큰화는 통과했습니다. (완전한 실행 검증은 아님)<br>";
            }
        } else {
            include $path;
            echo "✅ include 성공, 오류 없음<br>";
        }
    } catch (\Throwable $e) {
        echo "❌ 오류 발생: " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "&nbsp;&nbsp;파일: " . htmlspecialchars($e->getFile()) . " / 라인: " . $e->getLine() . "<br>";
    }
    echo "<hr>";
}

echo "=== 진단 종료 ===<br>";
