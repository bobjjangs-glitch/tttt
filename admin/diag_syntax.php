<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h2>products_import.php 문법 정밀 검사</h2>";

try {
    // 실제로 include를 시도하되, 이 파일 자체가 아닌 외부에서 감싸므로
    // 내부 파일의 ParseError를 여기서 정확히 catch할 수 있습니다.
    ob_start();
    include __DIR__ . '/products_import.php';
    ob_end_clean();
    echo "✅ 문법/실행 오류 없이 정상적으로 로드되었습니다.";
} catch (\ParseError $e) {
    echo "<b>❌ 문법(Parse) 오류 발견!</b><br>";
    echo "메시지: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "파일: " . htmlspecialchars($e->getFile()) . "<br>";
    echo "<b>정확한 라인 번호: " . $e->getLine() . "</b>";
} catch (\Throwable $e) {
    echo "<b>❌ 실행 중 오류 발견!</b><br>";
    echo "종류: " . get_class($e) . "<br>";
    echo "메시지: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "파일: " . htmlspecialchars($e->getFile()) . "<br>";
    echo "라인: " . $e->getLine() . "<br>";
}
