<?php
// ledger_diag2.php - 원장 테이블 구조 확인 (읽기 전용, 관리자 전용). 확인 후 삭제. 미실행 코드
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ledger_lib.php';
require_login();
$u = current_user();
if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('관리자만 사용할 수 있습니다.');
}
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

/** 내부 상수 테이블명만 넘길 것 */
function d2_table(string $t, array $expect): void
{
    echo '<h4>' . h($t) . '</h4>';
    try {
        $cols = lg_cols($t);
        $cnt  = (int)db()->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
        $miss = array_values(array_diff($expect, $cols));
        $extra = array_values(array_diff($cols, $expect));
        echo '<p>행 수: <b>' . $cnt . '</b></p>';
        echo '<p>현재 컬럼: ' . h(implode(', ', $cols)) . '</p>';
        echo '<p>코드가 필요로 하는데 없는 컬럼: <b style="color:#c00">' . h($miss ? implode(', ', $miss) : '없음') . '</b></p>';
        echo '<p>코드가 안 쓰는 기존 컬럼: ' . h($extra ? implode(', ', $extra) : '없음') . '</p>';
        $row = db()->query('SHOW CREATE TABLE ' . $t)->fetch(PDO::FETCH_NUM);
        echo '<pre>' . h((string)($row[1] ?? '')) . '</pre>';
    } catch (Throwable $e) {
        echo '<p style="color:#c00">FAIL: ' . h(get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) . '</p>';
    }
}
?>
<!doctype html>
<html lang="ko" translate="no">
<head><meta charset="utf-8"><meta name="google" content="notranslate"><title>원장 구조 확인</title>
<style>body{font:14px sans-serif;padding:16px}pre{background:#f3f4f6;padding:8px;white-space:pre-wrap}</style>
</head>
<body>
<h3>원장 테이블 구조 (읽기 전용)</h3>
<?php
d2_table('erp_payments', ['id', 'partner_id', 'direction', 'pay_date', 'amount', 'method', 'memo',
                          'status', 'cancel_reason', 'created_by', 'created_at', 'canceled_at']);
d2_table('erp_partner_openings', ['partner_id', 'ar_balance', 'as_of', 'updated_at']);
?>
<p>이 화면 전체를 복사해서 보내 주세요. 확인 후 이 파일과 ledger_diag.php를 서버에서 삭제하세요.</p>
</body>
</html>
