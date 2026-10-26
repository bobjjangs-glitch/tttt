<?php
// 진단 전용(읽기만 함). 확인 후 서버에서 반드시 삭제하세요.
require __DIR__ . '/inc/functions.php';
$GLOBALS['CFG'] = require __DIR__ . '/inc/config.php';
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$key = (string)cfg('app.install_key', '');
if (strlen($key) < 8 || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
    exit("접속 주소 끝에 ?key=(config.php 의 install_key) 를 붙이세요.\n");
}

echo 'PHP 버전: ' . PHP_VERSION . "\n";
echo 'pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'OK' : '없음') . "\n";
echo 'mbstring: ' . (extension_loaded('mbstring') ? 'OK' : '없음') . "\n";
echo 'inc/ 폴더 쓰기 가능: ' . (is_writable(__DIR__ . '/inc') ? '예' : '아니오') . "\n\n";

try {
    $pdo = db();
    echo 'DB 버전: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
    echo '기본 엔진: ' . $pdo->query('SELECT @@default_storage_engine')->fetchColumn() . "\n";
    echo 'sql_mode: ' . $pdo->query('SELECT @@sql_mode')->fetchColumn() . "\n\n";

    $all = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo 'DB 안의 전체 테이블(' . count($all) . "개): " . implode(', ', $all) . "\n\n";

    $names = ['users', 'categories', 'items', 'partners', 'doc_sequences', 'trades', 'trade_lines', 'stock_ledger', 'audit_logs'];
    foreach ($names as $n) {
        if (in_array($n, $all, true)) {
            $cols = $pdo->query('SHOW COLUMNS FROM `' . $n . '`')->fetchAll(PDO::FETCH_COLUMN);
            echo '[' . $n . '] 이미 있음 -> 컬럼: ' . implode(', ', $cols) . "\n";
        } else {
            echo '[' . $n . "] 없음\n";
        }
    }
} catch (Throwable $e) {
    echo 'DB 오류: ' . $e->getMessage() . "\n";
}
