<?php
// trades_diag.php - erp_trades 구조 확인 (읽기 전용, 관리자 전용). 확인 후 서버에서 삭제. 미실행 코드
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/inc/bootstrap.php';
require_login();
$u = current_user();
if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('관리자만 사용할 수 있습니다.');
}
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html>
<html lang="ko" translate="no">
<head><meta charset="utf-8"><meta name="google" content="notranslate"><title>전표 구조 확인</title>
<style>body{font:14px sans-serif;padding:16px}pre{background:#f3f4f6;padding:8px;white-space:pre-wrap}td,th{border:1px solid #999;padding:4px 8px}table{border-collapse:collapse}</style>
</head>
<body>
<h3>erp_trades 구조 (읽기 전용)</h3>
<?php
try {
    $row = db()->query('SHOW CREATE TABLE erp_trades')->fetch(PDO::FETCH_NUM);
    echo '<pre>' . h((string)($row[1] ?? '')) . '</pre>';
} catch (Throwable $e) {
    echo '<p style="color:#c00">FAIL: ' . h(get_class($e) . ': ' . $e->getMessage()) . '</p>';
}
echo '<h4>pay_method 값 분포</h4>';
try {
    $rows = db()->query('SELECT pay_method, COUNT(*) AS c FROM erp_trades GROUP BY pay_method ORDER BY c DESC')->fetchAll();
    echo '<table><tr><th>pay_method</th><th>건수</th></tr>';
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['pay_method'] === null ? '(NULL)' : (string)$r['pay_method']) . '</td><td>' . (int)$r['c'] . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="2">전표 없음</td></tr>';
    }
    echo '</table>';
} catch (Throwable $e) {
    echo '<p style="color:#c00">FAIL: ' . h(get_class($e) . ': ' . $e->getMessage()) . '</p>';
}
?>
<p>이 화면 전체를 복사해서 보내 주세요. 확인 후 이 파일을 서버에서 삭제하세요.</p>
</body>
</html>
