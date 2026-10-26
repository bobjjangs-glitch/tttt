<?php
// trades_excel.php — 현재 조회 조건으로 .xlsx 다운로드 (읽기 전용 계정도 가능)
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_once __DIR__ . '/inc/trades_query.php';

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('서버에 ZipArchive 가 없어 xlsx 를 만들 수 없습니다.');
}

$pdo  = db();
$P    = tq_params($_GET);
$rows = tq_fetch($pdo, $P, TQ_EXCEL_MAX + 1);

if (count($rows) > TQ_EXCEL_MAX) {
    flash('조건에 맞는 전표가 ' . number_format(TQ_EXCEL_MAX) . '건을 넘습니다. 기간이나 조건을 좁혀 다시 내려받으세요.', 'err');
    redirect('trades.php?' . http_build_query(tq_qs($P)));
    exit;
}

function te_col(int $i): string
{
    $s = '';
    for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return $s;
}

function te_esc(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function te_xlsx(string $sheetName, array $head, array $data, array $numCols, array $widths): string
{
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    $cols = '<cols>';
    foreach ($widths as $i => $w) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int)$w . '" customWidth="1"/>';
    }
    $cols .= '</cols>';

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="' . $ns . '">' . $cols . '<sheetData>';

    $sheet .= '<row r="1">';
    foreach ($head as $c => $text) {
        $sheet .= '<c r="' . te_col($c) . '1" s="1" t="inlineStr"><is><t xml:space="preserve">' . te_esc((string)$text) . '</t></is></c>';
    }
    $sheet .= '</row>';

    $r = 2;
    foreach ($data as $row) {
        $sheet .= '<row r="' . $r . '">';
        foreach ($row as $c => $v) {
            $ref = te_col($c) . $r;
            if (in_array($c, $numCols, true)) {
                $sheet .= '<c r="' . $ref . '" s="2"><v>' . (int)$v . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . te_esc((string)$v) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
        $r++;
    }
    $sheet .= '</sheetData></worksheet>';

    $parts = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . te_esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="' . $ns . '">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    if ($tmp === false) {
        $tmp = tempnam(__DIR__ . '/inc', 'xl');
    }
    if ($tmp === false) {
        throw new RuntimeException('임시 파일을 만들 수 없습니다.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('xlsx 압축 파일을 만들 수 없습니다.');
    }
    foreach ($parts as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    if ($bytes === false) {
        throw new RuntimeException('xlsx 파일을 읽을 수 없습니다.');
    }
    return $bytes;
}

$isSale = $P['type'] === 'sale';
$head = ['전표번호', '일자', '구분', '거래처', '고객명', '차량번호', '공급가액', '부가세', '합계', '상태', '비고'];
$data = [];
foreach ($rows as $r) {
    if ($r['trade_type'] === 'purchase') {
        $kind = '매입';
    } elseif (($r['channel'] ?? '') !== '') {
        $kind = channel_label($r['channel']);
    } else {
        $kind = '-';
    }
    $data[] = [
        $r['trade_no'], $r['trade_date'], $kind,
        (string)($r['partner_name'] ?? ''), (string)($r['customer_name'] ?? ''), (string)($r['vehicle_no'] ?? ''),
        (int)$r['supply_amount'], (int)$r['vat_amount'], (int)$r['total_amount'],
        status_label((string)$r['status']), (string)($r['memo'] ?? ''),
    ];
}

try {
    $bytes = te_xlsx($isSale ? '판매전표' : '매입전표', $head, $data, [6, 7, 8], [18, 12, 8, 22, 16, 14, 14, 12, 14, 8, 30]);
} catch (Throwable $e) {
    error_log('[ERP] trades_excel: ' . $e->getMessage());
    http_response_code(500);
    exit('엑셀 파일을 만들지 못했습니다.');
}

audit('trade_export', 'trades', null, ($isSale ? 'sale ' : 'purchase ') . $P['from'] . '~' . $P['to'] . ' ' . count($data) . '건');

$fname = ($isSale ? '판매전표_' : '매입전표_') . date('Ymd_His') . '.xlsx';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="trades.xlsx"; filename*=UTF-8\'\'' . rawurlencode($fname));
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: no-store');
echo $bytes;
exit;
