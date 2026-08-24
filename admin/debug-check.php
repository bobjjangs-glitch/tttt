<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');
echo "=== PHP 버전 ===\n";
echo PHP_VERSION . "\n\n";

echo "=== stock-requests.php 실행 시도 ===\n";
try {
    require __DIR__ . '/stock-requests.php';
} catch (\Throwable $e) {
    echo "\n\n=== 진짜 오류 발견 ===\n";
    echo "타입 : " . get_class($e) . "\n";
    echo "메시지 : " . $e->getMessage() . "\n";
    echo "파일 : " . $e->getFile() . "\n";
    echo "라인 : " . $e->getLine() . "\n\n";
    echo "=== 스택트레이스 ===\n";
    echo $e->getTraceAsString();
}
