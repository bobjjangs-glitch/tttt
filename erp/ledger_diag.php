<?php
// ledger_diag.php - 원장 오류 진단 (읽기 전용, 관리자 전용). 확인 후 서버에서 삭제. 미실행 코드
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

function d_row(string $name, callable $fn): void
{
    echo '<tr><td>' . h($name) . '</td>';
    try {
        $r = $fn();
        if (is_array($r)) {
            $r = json_encode($r, JSON_UNESCAPED_UNICODE);
        }
        echo '<td>OK</td><td>' . h(mb_substr((string)$r, 0, 600)) . '</td>';
    } catch (Throwable $e) {
        echo '<td style="color:#c00"><b>FAIL</b></td><td>'
            . h(get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
            . '</td>';
    }
    echo '</tr>';
}
?>
<!doctype html>
<html lang="ko" translate="no">
<head><meta charset="utf-8"><meta name="google" content="notranslate"><title>원장 진단</title>
<style>body{font:14px sans-serif;padding:16px}td,th{border:1px solid #999;padding:4px 8px;vertical-align:top;word-break:break-all}table{border-collapse:collapse}</style>
</head>
<body>
<h3>원장 진단 (읽기 전용)</h3>
<table>
<tr><th>단계</th><th>결과</th><th>내용</th></tr>
<?php
d_row('1. ledger_lib.php 로드', function () {
    require_once __DIR__ . '/inc/ledger_lib.php';
    return '로드됨';
});
d_row('2. DB 버전', fn() => db()->query('SELECT VERSION()')->fetchColumn());
d_row('3. 함수 존재 확인', function () {
    $need = ['page_header', 'page_footer', 'fmt_won', 'fmt_biz_no', 'channel_label', 'valid_date',
             'in_int', 'in_str', 'can_write', 'audit', 'flash', 'redirect', 'is_post', 'csrf_field', 'csrf_check',
             'lg_has_table', 'lg_cols', 'lg_amount_col', 'lg_ready_error', 'lg_summary', 'lg_ledger', 'lg_balance'];
    $miss = array_values(array_filter($need, fn($f) => !function_exists($f)));
    return $miss ? '없는 함수: ' . implode(', ', $miss) : '전부 있음';
});
d_row('4. erp_partners 테이블', fn() => lg_has_table('erp_partners') ? '있음' : '없음');
d_row('5. erp_payments 테이블', fn() => lg_has_table('erp_payments') ? '있음' : '없음');
d_row('6. erp_partner_openings 테이블', fn() => lg_has_table('erp_partner_openings') ? '있음' : '없음');
d_row('7. erp_partners 컬럼', fn() => implode(', ', lg_cols('erp_partners')));
d_row('8. erp_trades 컬럼', fn() => implode(', ', lg_cols('erp_trades')));
d_row('9. 합계금액 컬럼 탐지', fn() => lg_amount_col() ?? '못 찾음');
d_row('10. lg_ready_error()', fn() => lg_ready_error() ?? '준비 완료(null)');

$ready = false;
try {
    $ready = lg_ready_error() === null;
} catch (Throwable $e) {
}

if ($ready) {
    d_row('11. lg_summary() (미수금 현황)', fn() => count(lg_summary()) . '개 거래처');
    d_row('12. 첫 외상 거래처 원장', function () {
        $pid = (int)db()->query('SELECT id FROM erp_partners WHERE is_credit = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($pid <= 0) {
            return '외상 거래처 없음 - 건너뜀';
        }
        $L = lg_ledger($pid, date('Y-m-01', strtotime('-2 months')), date('Y-m-d'));
        return 'partner=' . $pid . ' rows=' . count($L['rows']) . ' close=' . $L['close'];
    });
} else {
    echo '<tr><td>11~12</td><td>건너뜀</td><td>준비 미완료 또는 10단계 실패</td></tr>';
}
?>
</table>
<p>이 표 전체를 복사해서 보내 주세요. 비밀번호나 DB 접속 정보는 출력되지 않습니다. 확인 후 이 파일을 서버에서 삭제하세요.</p>
</body>
</html>
