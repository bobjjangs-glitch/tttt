<?php
// erp/check_all.php - 읽기 전용 통합 점검 (미실행 코드). 확인 후 반드시 삭제
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$base = __DIR__;
$skip = ['check_all.php', 'lint_all.php', 'menu_test.php', 'menu_test2.php', 'diag.php', 'prefix_test.php'];

// ---- 접근 제한: install_key (config 내용은 출력하지 않음) ----
$cfgFile = $base . '/inc/config.php';
if (!is_file($cfgFile)) { exit("inc/config.php 없음\n"); }
$r = require $cfgFile;                       // return 배열 방식과 $GLOBALS['CFG'] 대입 방식 둘 다 대응
if (is_array($r)) { $GLOBALS['CFG'] = $r; }
if (!isset($GLOBALS['CFG']) || !is_array($GLOBALS['CFG'])) { exit("설정 형식을 읽을 수 없음\n"); }
$key = (string)($GLOBALS['CFG']['app']['install_key'] ?? '');
$in  = (string)($_GET['key'] ?? '');
if (strlen($key) < 8 || !hash_equals($key, $in)) {
    exit("접근 불가: 주소 끝에 ?key=설정한install_key 를 붙이세요.\n");
}

echo 'PHP ' . PHP_VERSION . "\n\n";

// ---- [1] 파일별 문법 + 크기 + 마지막 줄 (끊긴 파일 탐지) ----
echo "[1] 문법 / 크기 / 마지막 줄\n";
$files = array_merge(glob($base . '/*.php') ?: [], glob($base . '/inc/*.php') ?: []);
sort($files);
$targets = [];
foreach ($files as $p) {
    if (in_array(basename($p), $skip, true)) { continue; }
    $name = substr($p, strlen($base) + 1);
    $src  = (string)file_get_contents($p);
    $bom  = (substr($src, 0, 3) === "\xEF\xBB\xBF") ? ' [BOM]' : '';
    try {
        token_get_all($src, TOKEN_PARSE);
        $res = 'OK';
        $targets[$name] = $src;
    } catch (ParseError $e) {
        $res = 'ERROR ' . $e->getMessage() . ' (줄 ' . $e->getLine() . ')';
    }
    $lines = array_values(array_filter(explode("\n", $src), function ($l) { return trim($l) !== ''; }));
    $last  = $lines ? trim((string)end($lines)) : '';
    if ($name === 'inc/config.php') { $last = '(비밀 보호: 생략)'; }
    echo sprintf("%-22s %6d B %4d줄 %s%s\n      끝: %s\n",
        $name, strlen($src), substr_count($src, "\n") + 1, $res, $bom, mb_substr($last, 0, 70));
}

// ---- [2] 공통 파일 로드 + 함수 시그니처 ----
echo "\n[2] 공통 함수 시그니처\n";
foreach (['inc/functions.php', 'inc/menu.php', 'inc/layout.php', 'inc/trade_service.php'] as $f) {
    $p = $base . '/' . $f;
    if (!is_file($p)) { echo "$f : 없음\n"; continue; }
    try {
        require_once $p;
    } catch (Throwable $e) {
        echo "$f 로드 실패: " . get_class($e) . ' ' . $e->getMessage() . "\n";
        continue;
    }
    $src = (string)file_get_contents($p);
    if (preg_match_all('/function\s+&?\s*(\w+)\s*\(([^)]*)\)\s*(?::\s*\??[\w\\\\|]+)?/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            echo '  ' . $f . ' : ' . $x[1] . '(' . preg_replace('/\s+/', ' ', trim($x[2])) . ")\n";
        }
    }
}

// ---- [3] 정의되지 않은 함수 호출 후보 ----
echo "\n[3] 정의되지 않은 함수 호출 후보 (오탐 가능, 화면 오류의 1순위 용의자)\n";
$found = 0;
$skipPrev = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
if (defined('T_NULLSAFE_OBJECT_OPERATOR')) { $skipPrev[] = T_NULLSAFE_OBJECT_OPERATOR; }
$ws = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
foreach ($targets as $name => $src) {
    if ($name === 'inc/config.php') { continue; }
    $t = token_get_all($src);
    $n = count($t);
    $declared = [];
    for ($i = 0; $i < $n; $i++) {
        if (is_array($t[$i]) && $t[$i][0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) { continue; }
                if ($t[$j] === '&') { continue; }
                if (is_array($t[$j]) && $t[$j][0] === T_STRING) { $declared[strtolower($t[$j][1])] = true; }
                break;
            }
        }
    }
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_STRING) { continue; }
        $j = $i + 1;
        while ($j < $n && is_array($t[$j]) && in_array($t[$j][0], $ws, true)) { $j++; }
        if ($j >= $n || $t[$j] !== '(') { continue; }
        $k = $i - 1;
        while ($k >= 0 && is_array($t[$k]) && in_array($t[$k][0], $ws, true)) { $k--; }
        if ($k >= 0 && is_array($t[$k]) && in_array($t[$k][0], $skipPrev, true)) { continue; }
        $fn = $t[$i][1];
        if (isset($declared[strtolower($fn)]) || function_exists($fn)) { continue; }
        echo "  $name:" . $t[$i][2] . " → $fn()\n";
        $found++;
    }
}
if ($found === 0) { echo "  없음\n"; }

// ---- [4] page_header 호출 형태 ----
echo "\n[4] page_header 호출 줄\n";
foreach ($targets as $name => $src) {
    if ($name === 'inc/config.php' || $name === 'inc/layout.php') { continue; }
    foreach (explode("\n", $src) as $no => $line) {
        if (strpos($line, 'page_header(') !== false) {
            echo '  ' . $name . ':' . ($no + 1) . ' ' . trim($line) . "\n";
        }
    }
}

// ---- [5] erp_ 테이블 구조 (데이터 내용은 출력하지 않음) ----
echo "\n[5] erp_ 테이블 구조\n";
try {
    $pdo  = db();
    $cols = $pdo->query(
        "SELECT TABLE_NAME t, COLUMN_NAME c, COLUMN_TYPE ty, IS_NULLABLE nu, COLUMN_DEFAULT df, EXTRA ex
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'erp\\_%'
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    )->fetchAll();
    $names = [];
    foreach ($cols as $c) {
        $names[$c['t']] = true;
        echo sprintf("  %s.%s %s %s default=%s %s\n", $c['t'], $c['c'], $c['ty'],
            $c['nu'] === 'YES' ? 'NULL' : 'NOT NULL', $c['df'] === null ? '-' : $c['df'], $c['ex']);
    }
    echo '  erp_ 테이블 수: ' . count($names) . " (기대값 9)\n";

    echo "\n  [인덱스]\n";
    $idx = $pdo->query(
        "SELECT TABLE_NAME t, INDEX_NAME i, NON_UNIQUE nu, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cs
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'erp\\_%'
         GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
         ORDER BY TABLE_NAME, INDEX_NAME"
    )->fetchAll();
    foreach ($idx as $x) {
        echo sprintf("  %s %s%s (%s)\n", $x['t'], $x['nu'] == 0 ? 'UNIQUE ' : '', $x['i'], $x['cs']);
    }

    echo "\n  [행 수]\n";
    foreach (array_keys($names) as $tn) {
        $cnt = $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $tn) . '`')->fetchColumn();
        echo "  $tn : $cnt\n";
    }
} catch (Throwable $e) {
    echo '  DB 조회 실패: ' . get_class($e) . ' (코드 ' . $e->getCode() . ") - 접속 정보가 섞일 수 있어 메시지는 생략\n";
}
echo "\n끝\n";
