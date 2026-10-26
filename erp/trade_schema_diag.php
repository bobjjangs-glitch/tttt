<?php
// trade_schema_diag.php — 전표 관련 테이블 구조 확인 (읽기 전용, 관리자 전용)
// 확인이 끝나면 서버에서 삭제하세요.
require __DIR__ . '/inc/bootstrap.php';
require_login();
if ((current_user()['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('관리자만 볼 수 있습니다.');
}
$pdo = db();
header('Cache-Control: no-store');

$tables = ['erp_trades', 'erp_trade_lines', 'erp_stock_ledger', 'erp_items', 'erp_partners'];
?>
<!doctype html>
<html lang="ko" translate="no">
<head>
<meta charset="utf-8">
<meta name="google" content="notranslate">
<meta name="robots" content="noindex,nofollow">
<title>전표 구조 진단</title>
<style>body{font:14px/1.5 sans-serif;padding:16px}pre{background:#f3f4f6;padding:12px;overflow:auto;white-space:pre-wrap}</style>
</head>
<body>
<h1>전표 구조 진단 (읽기 전용)</h1>
<p>이 화면 전체를 복사해 보내 주세요. 확인 후 이 파일을 서버에서 삭제하세요.</p>
<?php
foreach ($tables as $t) {
    echo '<h2>' . h($t) . '</h2>';
    try {
        // $t 는 위 고정 목록의 값만 쓰므로 식별자 주입 위험 없음
        $cnt = (int)$pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
        $row = $pdo->query('SHOW CREATE TABLE ' . $t)->fetch();
        $ddl = (string)($row['Create Table'] ?? '');
        echo '<p>행 수: ' . number_format($cnt) . '</p><pre>' . h($ddl) . '</pre>';
    } catch (Throwable $e) {
        echo '<p style="color:#b91c1c">조회 실패: ' . h($e->getMessage()) . '</p>';
    }
}
?>
</body>
</html>
