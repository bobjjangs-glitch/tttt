<?php
// admin/tax-invoice-export.php — 세금계산서 목록 엑셀(.xlsx) 내보내기
// 목록 화면과 동일한 필터(상태/검색어/기간)를 그대로 적용해 현재 조건으로 다운로드
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/includes/xlsx.php';
AdminAuth::requirePermission('tax-invoices');
ensure_tax_invoice_fax_schema();

$pdo = Database::connection();

$statusLabels = ['pending' => '발행대기', 'issued' => '발행완료', 'rejected' => '반려'];
$faxLabels    = ['none' => '미발송', 'sent' => '발송완료', 'failed' => '발송실패'];

$filterStatus = $_GET['status'] ?? '';
if ($filterStatus && !isset($statusLabels[$filterStatus])) $filterStatus = '';
$kw   = trim((string)($_GET['kw'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

$where  = '1=1';
$params = [];
if ($filterStatus !== '') {
    $where .= ' AND t.status = :st';
    $params['st'] = $filterStatus;
}
if ($kw !== '') {
    $where .= ' AND (t.req_no LIKE :kw OR t.buyer_biz_name LIKE :kw OR t.buyer_biz_number LIKE :kw OR t.buyer_ceo LIKE :kw OR u.name LIKE :kw OR u.email LIKE :kw)';
    $params['kw'] = '%' . $kw . '%';
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where .= ' AND DATE(t.created_at) >= :dfrom';
    $params['dfrom'] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where .= ' AND DATE(t.created_at) <= :dto';
    $params['dto'] = $to;
}

$stmt = $pdo->prepare("SELECT t.*, u.name AS user_name, u.email AS user_email
                       FROM tt_tax_invoices t
                       LEFT JOIN tt_users u ON u.id = t.user_id
                       WHERE {$where}
                       ORDER BY FIELD(t.status,'pending','issued','rejected'), t.created_at DESC");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== 행 구성 ===== */
$headers = [
    '요청번호', '상태', '대상구분', '대상번호', '발행금액(원)', '공급가액(원)', '부가세(원)',
    '공급받는자 상호', '사업자번호', '대표자', '사업장 주소', '업태', '종목',
    '수신 이메일', '연락처', '팩스번호', '팩스발송상태', '요청일', '발행일', '관리자 메모',
];
$rows = [];
foreach ($invoices as $inv) {
    $supplyValue = (int)round(((int)$inv['amount']) / 1.1);
    $vat = (int)$inv['amount'] - $supplyValue;
    $rows[] = [
        $inv['req_no'],
        $statusLabels[$inv['status']] ?? $inv['status'],
        $inv['target_type'] === 'order' ? '주문' : '예약',
        (string)($inv['target_no'] ?? ''),
        (int)$inv['amount'],
        $supplyValue,
        $vat,
        $inv['buyer_biz_name'],
        $inv['buyer_biz_number'],
        $inv['buyer_ceo'],
        $inv['buyer_address'],
        $inv['buyer_uptae'] ?: '',
        $inv['buyer_jongmok'] ?: '',
        $inv['contact_email'] ?: '',
        $inv['contact_phone'] ?: '',
        !empty($inv['fax_number']) ? format_fax_number((string)$inv['fax_number']) : '',
        $faxLabels[$inv['fax_status'] ?? 'none'] ?? '미발송',
        $inv['created_at'],
        $inv['issued_at'] ?: '',
        $inv['admin_memo'] ?: '',
    ];
}

AdminAuth::log((int)AdminAuth::currentAdminId(), 'tax_invoice_export', '세금계산서 목록 엑셀 다운로드 (' . count($rows) . '건)');

$dateTag = date('Ymd_His');
$delivered = false;

/* ===== 1차: 진짜 .xlsx ===== */
if (class_exists('ZipArchive')) {
    try {
        $bin = XlsxBuilder::build($headers, $rows, [
            17, 10, 9, 14, 13, 13, 11, 18, 14, 10, 40, 10, 12,
            24, 14, 14, 11, 11, 19, 18, 40,
        ]);
        $delivered = true;
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="tax_invoices_' . $dateTag . '.xlsx"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        echo $bin;
    } catch (Throwable $e) {
        error_log('[tax-invoice-export][xlsx] ' . $e->getMessage());
        $delivered = false; // → CSV 폴백
    }
}

/* ===== 2차: CSV 폴백 (엑셀에서 바로 열림) ===== */
if (!$delivered) {
    $csv = XlsxBuilder::buildCsvFallback($headers, $rows);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tax_invoices_' . $dateTag . '.csv"');
    header('Content-Length: ' . strlen($csv));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    echo $csv;
}
exit;
