<?php
echo 'PHP 버전: ' . PHP_VERSION . '<br>';
echo '현재 시간: ' . date('Y-m-d H:i:s') . '<br>';

$logDir = __DIR__ . '/logs';
echo 'logs 폴더 존재 여부: ' . (is_dir($logDir) ? '있음' : '없음') . '<br>';

if (is_dir($logDir)) {
    $logFile = $logDir . '/php-error.log';
    if (is_file($logFile)) {
        echo '<hr><b>php-error.log 마지막 내용:</b><pre>';
        $lines = file($logFile);
        $lastLines = array_slice($lines, -30);
        foreach ($lastLines as $line) {
            echo htmlspecialchars($line);
        }
        echo '</pre>';
    } else {
        echo 'php-error.log 파일 없음<br>';
    }
}

echo '<hr>bootstrap 로딩 테스트: ';
try {
    require_once __DIR__ . '/core/bootstrap.php';
    echo '성공';
} catch (Throwable $e) {
    echo '실패 → ' . htmlspecialchars($e->getMessage()) . ' (파일: ' . htmlspecialchars($e->getFile()) . ', 줄: ' . $e->getLine() . ')';
}
