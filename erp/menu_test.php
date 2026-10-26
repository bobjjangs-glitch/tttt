<?php
// erp/menu_test.php - 읽기 전용 점검용 (미실행 코드). 확인 후 반드시 삭제
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

$files = ['inc/menu.php', 'inc/layout.php', 'inc/functions.php'];

echo "[1] 문법 검사 (파일을 실행하지 않음)\n";
foreach ($files as $f) {
    $p = __DIR__ . '/' . $f;
    if (!is_file($p)) { echo "$f : 파일 없음 → 업로드 위치 확인\n"; continue; }
    $src = file_get_contents($p);
    $bom = (substr($src, 0, 3) === "\xEF\xBB\xBF") ? ' / BOM 있음(제거 필요)' : '';
    try {
        token_get_all($src, TOKEN_PARSE);
        echo "$f : 문법 OK$bom\n";
    } catch (ParseError $e) {
        echo "$f : 문법 오류 - " . $e->getMessage() . ' (줄 ' . $e->getLine() . ")$bom\n";
    }
}

echo "\n[2] layout.php의 메뉴 연결 줄\n";
$lp = __DIR__ . '/inc/layout.php';
if (is_file($lp)) {
    foreach (file($lp) as $n => $line) {
        if (stripos($line, 'menu.php') !== false || stripos($line, 'erp_render_nav') !== false) {
            echo ($n + 1) . ': ' . rtrim($line) . "\n";
        }
    }
}

echo "\n[3] erp_ 함수 중복 선언 여부\n";
foreach (['inc/layout.php', 'inc/functions.php', 'inc/menu.php'] as $f) {
    $p = __DIR__ . '/' . $f;
    if (is_file($p) && preg_match_all('/function\s+(erp_\w+)/', file_get_contents($p), $m)) {
        echo "$f : " . implode(', ', $m[1]) . "\n";
    }
}
