<?php
// inc/excel_io.php - .xlsx / .csv 읽기·쓰기 (외부 라이브러리 없음, 미실행 코드)

const XL_MAX_ROWS  = 5000;
const XL_MAX_BYTES = 5242880; // 5MB

function xl_zip_ok(): bool
{
    return class_exists('ZipArchive') && function_exists('simplexml_load_string');
}

/** 열 번호(0부터) → A, B, ... AA */
function xl_col(int $i): string
{
    $s = '';
    for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return $s;
}

/** 셀 주소(C5) → 열 번호(0부터). 실패 시 -1 */
function xl_col_index(string $ref): int
{
    if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) {
        return -1;
    }
    $n = 0;
    foreach (str_split(strtoupper($m[1])) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

function xl_xml(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** $rows: 행 배열의 배열. int는 숫자 셀, 나머지는 문자열 셀(수식으로 해석되지 않음) */
function xl_write_xlsx(string $path, array $rows): bool
{
    if (!xl_zip_ok()) {
        return false;
    }
    $decl = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sheet = $decl . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach (array_values($rows) as $r => $row) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $ref = xl_col($c) . ($r + 1);
            if (is_int($v)) {
                $sheet .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xl_xml((string)$v) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $zip->addFromString('[Content_Types].xml', $decl
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', $decl
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', $decl
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="data" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $decl
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    return $zip->close();
}

function xl_si_text(SimpleXMLElement $n): string
{
    $t = '';
    if (isset($n->t)) {
        $t .= (string)$n->t;
    }
    foreach ($n->r as $run) {
        $t .= (string)$run->t;
    }
    return $t;
}

/** 첫 시트를 읽어 [엑셀 행번호 => [열값...]] 반환. 완전히 빈 행은 건너뜀 */
function xl_read_xlsx(string $path): array
{
    if (!xl_zip_ok()) {
        throw new RuntimeException('이 서버는 .xlsx를 읽을 수 없습니다. 엑셀에서 "CSV"로 저장해 올려 주세요.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('엑셀 파일을 열 수 없습니다. 손상되었거나 .xlsx가 아닙니다.');
    }
    $prev = libxml_use_internal_errors(true);
    try {
        $total = 0;
        $sheetName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $total += (int)($st['size'] ?? 0);
            if ($sheetName === null && preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string)$st['name'])) {
                $sheetName = (string)$st['name'];
            }
        }
        if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
            $sheetName = 'xl/worksheets/sheet1.xml';
        }
        if ($total > 30 * 1024 * 1024) {
            throw new RuntimeException('압축을 풀면 너무 큰 파일입니다.');
        }
        if ($sheetName === null) {
            throw new RuntimeException('시트를 찾을 수 없습니다.');
        }

        $shared = [];
        $x = $zip->getFromName('xl/sharedStrings.xml');
        if ($x !== false) {
            $sx = simplexml_load_string($x, 'SimpleXMLElement', LIBXML_NONET);
            if ($sx !== false) {
                foreach ($sx->si as $si) {
                    $shared[] = xl_si_text($si);
                }
            }
        }
        $sheet = simplexml_load_string((string)$zip->getFromName($sheetName), 'SimpleXMLElement', LIBXML_NONET);
        if ($sheet === false) {
            throw new RuntimeException('시트 내용을 읽을 수 없습니다.');
        }
    } finally {
        libxml_use_internal_errors($prev);
        $zip->close();
    }

    $rows = [];
    $rowNo = 0;
    foreach ($sheet->sheetData->row as $row) {
        $r = (int)$row['r'];
        $rowNo = $r > 0 ? $r : $rowNo + 1;
        $cells = [];
        $max = -1;
        $auto = 0;
        foreach ($row->c as $c) {
            $ci = xl_col_index((string)$c['r']);
            if ($ci < 0) {
                $ci = $auto;
            }
            $auto = $ci + 1;
            $t = (string)$c['t'];
            if ($t === 's') {
                $v = $shared[(int)$c->v] ?? '';
            } elseif ($t === 'inlineStr') {
                $v = isset($c->is) ? xl_si_text($c->is) : '';
            } elseif ($t === 'e') {
                $v = '';
            } else {
                $v = (string)$c->v;
            }
            $cells[$ci] = $v;
            $max = max($max, $ci);
        }
        if ($max < 0) {
            continue;
        }
        $dense = array_fill(0, $max + 1, '');
        foreach ($cells as $ci => $v) {
            $dense[$ci] = $v;
        }
        if (trim(implode('', $dense)) === '') {
            continue;
        }
        $rows[$rowNo] = $dense;
        if (count($rows) > XL_MAX_ROWS + 1) {
            throw new RuntimeException('한 번에 ' . XL_MAX_ROWS . '행까지만 올릴 수 있습니다. 나눠서 올려 주세요.');
        }
    }
    return $rows;
}

function xl_read_csv(string $path): array
{
    $s = (string)file_get_contents($path);
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
        $s = substr($s, 3);
    }
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'CP949'); // 한국어 엑셀의 일반 CSV 저장 방식
    }
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $s);
    rewind($fh);
    $rows = [];
    $n = 0;
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $n++;
        $r = array_map(function ($v) { return (string)$v; }, $r);
        if (trim(implode('', $r)) === '') {
            continue;
        }
        $rows[$n] = $r;
        if (count($rows) > XL_MAX_ROWS + 1) {
            fclose($fh);
            throw new RuntimeException('한 번에 ' . XL_MAX_ROWS . '행까지만 올릴 수 있습니다. 나눠서 올려 주세요.');
        }
    }
    fclose($fh);
    return $rows;
}

function xl_read_rows(string $path, string $ext): array
{
    $ext = strtolower($ext);
    if ($ext === 'xlsx') {
        return xl_read_xlsx($path);
    }
    if ($ext === 'csv') {
        return xl_read_csv($path);
    }
    throw new RuntimeException('.xlsx 또는 .csv 파일만 올릴 수 있습니다. (.xls는 .xlsx로 다시 저장해 주세요)');
}

/** CSV 수식 주입 방지: 문자열 셀이 = + - @ 로 시작하면 앞에 ' 를 붙임 */
function xl_csv_cell($v)
{
    if (is_string($v) && $v !== '' && strpos("=+-@\t\r", $v[0]) !== false) {
        return "'" . $v;
    }
    return $v;
}

/** 다운로드 전송 후 종료. 헤더 출력 전에 호출할 것 */
function xl_send(string $base, array $rows): void
{
    $stamp = date('Ymd_His');
    header('Cache-Control: no-store');
    if (xl_zip_ok()) {
        $dir = sys_get_temp_dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            $dir = __DIR__ . '/tmp';
            if (!is_dir($dir) && !mkdir($dir, 0755) && !is_dir($dir)) {
                throw new RuntimeException('임시 폴더를 만들 수 없습니다.');
            }
        }
        $tmp = $dir . '/erpx_' . bin2hex(random_bytes(8)) . '.xlsx';
        if (!xl_write_xlsx($tmp, $rows)) {
            @unlink($tmp);
            throw new RuntimeException('엑셀 파일을 만들 수 없습니다.');
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $base . '_' . $stamp . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $base . '_' . $stamp . '.csv"');
    echo "\xEF\xBB\xBF";
    $o = fopen('php://output', 'w');
    foreach ($rows as $row) {
        fputcsv($o, array_map('xl_csv_cell', array_values($row)), ',', '"', '');
    }
    fclose($o);
    exit;
}

/** 값 → 0 이상의 정수. 빈 값 null, 형식 오류 false */
function xl_int(string $s, int $maxDigits = 12)
{
    $s = str_replace([',', ' ', '원'], '', trim($s));
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^(\d+)(\.0+)?$/', $s, $m)) {
        return false;
    }
    $d = ltrim($m[1], '0');
    if (strlen($d) > $maxDigits) {
        return false;
    }
    return (int)($d === '' ? '0' : $d);
}

/** Y/N 계열 → 1/0. 빈 값 null, 해석 불가 false */
function xl_yn(string $s)
{
    $s = mb_strtolower(trim($s));
    if ($s === '') {
        return null;
    }
    if (in_array($s, ['y', 'yes', '1', '사용', '예', 'o', 'true'], true)) {
        return 1;
    }
    if (in_array($s, ['n', 'no', '0', '중지', '아니오', 'x', 'false'], true)) {
        return 0;
    }
    return false;
}

function xl_norm_header(string $s): string
{
    return mb_strtolower(preg_replace('/[\s\x{00A0}*＊]/u', '', $s) ?? '');
}

/** [키 => 열번호, 알 수 없는 헤더 목록, 빠진 필수 헤더 목록] */
function xl_map_header(array $cells, array $spec, array $required): array
{
    $want = [];
    foreach ($spec as $key => $labels) {
        foreach ((array)$labels as $l) {
            $want[xl_norm_header($l)] = $key;
        }
    }
    $map = [];
    $unknown = [];
    foreach ($cells as $ci => $label) {
        $n = xl_norm_header((string)$label);
        if ($n === '') {
            continue;
        }
        if (isset($want[$n])) {
            if (!isset($map[$want[$n]])) {
                $map[$want[$n]] = $ci;
            }
        } else {
            $unknown[] = (string)$label;
        }
    }
    $missing = [];
    foreach ($required as $key) {
        if (!isset($map[$key])) {
            $missing[] = (string)((array)$spec[$key])[0];
        }
    }
    return [$map, $unknown, $missing];
}
