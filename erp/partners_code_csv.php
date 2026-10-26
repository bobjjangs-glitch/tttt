<?php
// erp/partners_code_csv.php — 거래처 코드 대응표 CSV (읽기 전용)
require __DIR__ . '/inc/bootstrap.php';
require_login();
if (!can_write()) {
    http_response_code(403);
    exit('권한이 없습니다. (admin 또는 office 계정만 내려받을 수 있습니다.)');
}
$pdo = db();

const PCC_MAX = 5000;

$have = [];
foreach ($pdo->query('SHOW COLUMNS FROM erp_partners')->fetchAll() as $c) {
    $have[$c['Field']] = true;
}

// [DB 컬럼, 헤더명]  — 있는 컬럼만 출력
$spec = [
    ['partner_code',    '거래처코드'],
    ['name',            '상호'],
    ['business_no',     '사업자번호'],
    ['branch_no',       '종사업장번호'],
    ['ceo_name',        '대표자'],
    ['phone',           '전화번호'],
    ['mobile',          '핸드폰'],
    ['tax_email',       '세금계산서 이메일'],
    ['ecount_code',     'E카운트코드(참고)'],
    ['partner_type',    '유형'],
    ['default_channel', '매출구분'],
    ['is_credit',       '외상'],
    ['is_active',       '사용'],
];
$use = array_values(array_filter($spec, fn($s) => isset($have[$s[0]])));
if (!isset($have['name']) || !$use) {
    http_response_code(500);
    exit('erp_partners 구조를 확인할 수 없습니다.');
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM erp_partners')->fetchColumn();
if ($total > PCC_MAX) {
    exit('거래처가 ' . number_format($total) . '건이라 ' . number_format(PCC_MAX) . '건 제한을 넘습니다. 지역별로 나누어 내려받는 기능이 필요합니다.');
}

$typeLabel = ['customer' => '매출처', 'supplier' => '매입처', 'both' => '매출·매입'];
$chanLabel = ['retail' => '소매', 'wholesale' => '도매'];

function pcc_safe(string $v): string
{
    // 수식 주입 방지: 전화번호·번호처럼 숫자/하이픈/공백만 있는 값은 그대로 둔다.
    if (preg_match('/^[=+\-@\t\r]/', $v) && !preg_match('/^[-+]?[0-9][0-9\- ]*$/', $v)) {
        return "'" . $v;
    }
    return $v;
}

$select = implode(', ', array_map(fn($s) => $s[0], $use));
$orderBy = isset($have['partner_code']) ? "(partner_code IS NULL OR partner_code = ''), partner_code, id" : 'id';
$rows = $pdo->query("SELECT $select FROM erp_partners ORDER BY $orderBy LIMIT " . PCC_MAX)->fetchAll();

try {
    audit('partner_export', 'partner', null, 'CSV ' . count($rows) . '건');
} catch (Throwable $e) {
    error_log('[ERP] partners_code_csv audit: ' . $e->getMessage());
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="partners_' . date('Ymd') . '.csv"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, array_map(fn($s) => $s[1], $use), ',', '"', '', "\r\n");

foreach ($rows as $r) {
    $line = [];
    foreach ($use as [$col]) {
        $v = (string)($r[$col] ?? '');
        if ($col === 'business_no' && $v !== '') {
            $v = fmt_biz_no($v);
        } elseif ($col === 'partner_type') {
            $v = $typeLabel[$v] ?? $v;
        } elseif ($col === 'default_channel') {
            $v = $chanLabel[$v] ?? $v;
        } elseif ($col === 'is_credit') {
            $v = $v === '1' ? '외상' : '';
        } elseif ($col === 'is_active') {
            $v = $v === '1' ? 'Y' : 'N';
        }
        $line[] = pcc_safe($v);
    }
    fputcsv($out, $line, ',', '"', '', "\r\n");
}
fclose($out);
exit;
