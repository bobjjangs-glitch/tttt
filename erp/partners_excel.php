<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/partner_lib.php';
if (is_file(__DIR__ . '/inc/ledger_lib.php')) {
    require_once __DIR__ . '/inc/ledger_lib.php';
}
if (is_file(__DIR__ . '/inc/partner_code.php')) {
    require_once __DIR__ . '/inc/partner_code.php';
}
require_login();
$pdo = db();

const PE_MAX_ROWS   = 5000;
const PE_MAX_BYTES  = 5242880; // 5MB
const PE_EXTRA_COLS = true;    // false 로 바꾸면 E카운트 10개 열만 사용 (첫 열 '거래처코드' = E카운트 코드)

$types    = ['customer' => '매출처', 'supplier' => '매입처', 'both' => '매출·매입'];
$channels = ['retail' => '소매', 'wholesale' => '도매'];
$cols      = pt_cols($pdo);
$hasRegion = in_array('region', $cols, true);
$hasCredit = in_array('is_credit', $cols, true);
$ledgerOk  = false;
if ($hasCredit && function_exists('lg_ready_error')) {
    try {
        $ledgerOk = lg_ready_error() === null;
    } catch (Throwable $e) {
        $ledgerOk = false;
    }
}

/* ───────── 열 정의 ───────── */
function pe_columns(array $cols): array
{
    if (!PE_EXTRA_COLS) {
        // E카운트 양식 그대로: 첫 열 '거래처코드' 는 E카운트 코드 값
        return [
            'ecount_code'    => '거래처코드',
            'name'           => '거래처명',
            'ceo_name'       => '대표자명',
            'phone'          => '전화번호',
            'mobile'         => '핸드폰번호',
            'search_keyword' => '검색창내용',
            'depositor'      => '입금자명',
            'sms_phone'      => '문자 발송▶핸드폰',
            'is_active'      => '사용구분',
            '_xfer'          => '이체정보',
        ];
    }
    $c = [];
    if (in_array('partner_code', $cols, true)) {
        $c['partner_code'] = '거래처코드(ERP)';
    }
    $c += [
        'name'            => '거래처명',
        'business_no'     => '사업자번호',
        'branch_no'       => '종사업장번호',
        'ceo_name'        => '대표자명',
        'phone'           => '전화번호',
        'mobile'          => '핸드폰번호',
        'search_keyword'  => '검색창내용',
        'depositor'       => '입금자명',
        'sms_phone'       => '문자 발송▶핸드폰',
        'is_active'       => '사용구분',
        '_xfer'           => '이체정보',
        'tax_email'       => '세금계산서이메일',
        'address'         => '주소',
        'region'          => '지역',
        'partner_type'    => '거래처유형',
        'default_channel' => '기본매출구분',
        'is_credit'       => '외상여부',
        'memo'            => '비고',
        'ecount_code'     => 'E카운트코드(참고)',
    ];
    return $c;
}

function pe_widths(array $keys): array
{
    $w = ['partner_code' => 16, 'ecount_code' => 18, 'name' => 30, 'ceo_name' => 12, 'phone' => 15, 'mobile' => 15,
          'search_keyword' => 16, 'depositor' => 24, 'sms_phone' => 17, 'is_active' => 10, '_xfer' => 10,
          'business_no' => 15, 'branch_no' => 12, 'tax_email' => 26, 'address' => 40, 'region' => 8,
          'partner_type' => 12, 'default_channel' => 12, 'is_credit' => 10, 'memo' => 30];
    $out = [];
    foreach ($keys as $k) {
        $out[] = $w[$k] ?? 15;
    }
    return $out;
}

/** 머리글 비교용 키: 공백/별표/괄호/'필수' 제거, 대문자화 */
function pe_key(string $s): string
{
    return mb_strtoupper((string)preg_replace('/[\s\x{00A0}\*\(\)]|필수/u', '', $s));
}

/** 머리글 → 내부 키. 주의: 그냥 '거래처코드'/'코드' 는 E카운트 코드(= 사업자번호가 들어 있는 칸)로 본다. */
function pe_header_map(): array
{
    return [
        '거래처코드ERP' => 'partner_code', 'ERP코드' => 'partner_code', 'ERP거래처코드' => 'partner_code',
        'E카운트코드' => 'ecount_code', 'E카운트코드참고' => 'ecount_code', '이카운트코드' => 'ecount_code',
        '거래처코드' => 'ecount_code', '코드' => 'ecount_code',
        '상호' => 'name', '상호명' => 'name', '거래처명' => 'name', '거래처' => 'name', '업체명' => 'name',
        '대표자' => 'ceo_name', '대표자명' => 'ceo_name', '대표' => 'ceo_name',
        '전화번호' => 'phone', '전화' => 'phone', '연락처' => 'phone',
        '핸드폰번호' => 'mobile', '핸드폰' => 'mobile', '휴대폰' => 'mobile', '휴대폰번호' => 'mobile',
        '검색창내용' => 'search_keyword', '검색창' => 'search_keyword',
        '입금자명' => 'depositor', '입금자' => 'depositor',
        '문자발송▶핸드폰' => 'sms_phone', '문자발송핸드폰' => 'sms_phone', '문자발송' => 'sms_phone',
        '사용구분' => 'is_active', '사용여부' => 'is_active', '사용' => 'is_active',
        '이체정보' => '_xfer',
        '사업자번호' => 'business_no', '사업자등록번호' => 'business_no', '등록번호' => 'business_no',
        '종사업장번호' => 'branch_no', '종사업장' => 'branch_no',
        '세금계산서이메일' => 'tax_email', '전자세금계산서이메일' => 'tax_email', '이메일' => 'tax_email', '메일' => 'tax_email',
        '주소' => 'address',
        '지역' => 'region', '시도' => 'region', '지역구분' => 'region',
        '거래처유형' => 'partner_type', '유형' => 'partner_type', '거래처구분' => 'partner_type',
        '기본매출구분' => 'default_channel', '매출구분' => 'default_channel', '소매도매' => 'default_channel',
        '외상여부' => 'is_credit', '외상' => 'is_credit',
        '비고' => 'memo', '메모' => 'memo',
    ];
}

function pe_yn(string $v): ?int
{
    $v = mb_strtolower(trim($v));
    if (in_array($v, ['y', 'yes', '1', 'o', 'true', '예', '사용', '외상'], true)) {
        return 1;
    }
    if (in_array($v, ['n', 'no', '0', 'x', 'false', '아니오', '미사용', '아니요'], true)) {
        return 0;
    }
    return null;
}

/** 코드 표기 차이(하이픈·공백·대소문자)를 무시하고 같은 값인지 */
function pe_code_eq(string $a, string $b): bool
{
    $n = fn(string $s): string => strtoupper((string)preg_replace('/[^0-9A-Za-z]/', '', $s));
    return $n($a) !== '' && $n($a) === $n($b);
}

/** 개인정보 가림: 앞 3자만 보이게 */
function pe_mask(string $s): string
{
    $len = mb_strlen($s);
    return $len <= 3 ? str_repeat('*', $len) : mb_substr($s, 0, 3) . str_repeat('*', $len - 3);
}

function pe_empty_result(string $fatal = ''): array
{
    return ['fatal' => $fatal, 'errors' => [], 'warnings' => [], 'notes' => [],
            'rows' => 0, 'ins' => 0, 'upd' => 0, 'same' => 0, 'codes' => 0, 'saved' => false];
}

function pe_col_index(string $letters): int
{
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

function pe_col_letter(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

/* ───────── 읽기 ───────── */
/** @return array<int, array<int,string>> 엑셀 행번호 => 셀 배열(0부터) */
function pe_read_csv(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('파일을 읽을 수 없습니다.');
    }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'CP949');
    }
    $h = fopen('php://temp', 'r+');
    fwrite($h, $raw);
    rewind($h);
    $rows = [];
    $n = 0;
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        $n++;
        if ($r === [null]) {
            continue;
        }
        $rows[$n] = array_map(fn($v) => (string)$v, $r);
    }
    fclose($h);
    return $rows;
}

function pe_read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('서버에 ZipArchive가 없어 xlsx를 읽을 수 없습니다. CSV로 저장해 올려 주세요.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('xlsx 파일을 열 수 없습니다. 파일이 손상되었거나 xlsx 형식이 아닙니다.');
    }
    $sheetXml = false;
    $ssXml    = false;
    try {
        $sheetName = null;
        $best = PHP_INT_MAX;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $n, $m) && (int)$m[1] < $best) {
                $best = (int)$m[1];
                $sheetName = $n;
            }
        }
        if ($sheetName === null) {
            throw new RuntimeException('시트를 찾지 못했습니다.');
        }
        $limit = 50 * 1024 * 1024;
        foreach ([$sheetName, 'xl/sharedStrings.xml'] as $n) {
            $st = $zip->statName($n);
            if ($st && $st['size'] > $limit) {
                throw new RuntimeException('압축 해제 크기가 너무 큽니다.');
            }
        }
        $sheetXml = $zip->getFromName($sheetName);
        $ssXml    = $zip->getFromName('xl/sharedStrings.xml');
    } finally {
        $zip->close();
    }
    if ($sheetXml === false) {
        throw new RuntimeException('시트를 읽지 못했습니다.');
    }

    libxml_use_internal_errors(true);
    $shared = [];
    if ($ssXml !== false) {
        $sx = simplexml_load_string($ssXml, 'SimpleXMLElement', LIBXML_NONET);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $t = '';
                    foreach ($si->r as $r) {
                        $t .= (string)$r->t;
                    }
                    $shared[] = $t;
                }
            }
        }
    }
    $x = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
    if ($x === false) {
        throw new RuntimeException('시트 XML을 해석하지 못했습니다.');
    }

    $rows = [];
    $seq  = 0;
    foreach ($x->sheetData->row as $row) {
        $seq++;
        $ln = isset($row['r']) ? (int)$row['r'] : $seq;
        if ($ln <= 0) {
            $ln = $seq;
        }
        $line = [];
        $maxc = -1;
        $cseq = -1;
        foreach ($row->c as $c) {
            $cseq++;
            $ci = $cseq;
            if (isset($c['r']) && preg_match('/^([A-Z]+)/', (string)$c['r'], $m)) {
                $ci = pe_col_index($m[1]);
            }
            if ($ci > 200) {
                continue;
            }
            $t = isset($c['t']) ? (string)$c['t'] : '';
            if ($t === 's') {
                $val = $shared[(int)$c->v] ?? '';
            } elseif ($t === 'inlineStr') {
                if (isset($c->is->t)) {
                    $val = (string)$c->is->t;
                } else {
                    $val = '';
                    foreach ($c->is->r as $r) {
                        $val .= (string)$r->t;
                    }
                }
            } elseif ($t === 'b') {
                $val = ((string)$c->v) === '1' ? 'TRUE' : 'FALSE';
            } else {
                $val = (string)$c->v;
                if ($val !== '' && is_numeric($val) && stripos($val, 'e') !== false) {
                    $val = sprintf('%.0f', (float)$val);
                }
            }
            $line[$ci] = $val;
            $maxc = max($maxc, $ci);
        }
        $arr = $maxc >= 0 ? array_fill(0, $maxc + 1, '') : [];
        foreach ($line as $i => $v) {
            $arr[$i] = $v;
        }
        $rows[$ln] = $arr;
    }
    return $rows;
}

/* ───────── 쓰기 ───────── */
/**
 * @param array<int, array{s:int, c:array<int,string>}> $lines  s: 0 기본 / 1 텍스트 / 2 머리글(굵게+배경) / 3 제목(굵게)
 * @param int[] $widths
 */
function pe_send_xlsx(string $filename, array $lines, array $widths, int $freezeRows): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('서버에 ZipArchive가 없습니다.');
    }
    $x = fn(string $s): string => htmlspecialchars(
        (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $maxCols = 1;
    $sd = '';
    $r  = 0;
    foreach ($lines as $line) {
        $r++;
        $sd .= '<row r="' . $r . '">';
        foreach (array_values($line['c']) as $i => $v) {
            $ref = pe_col_letter($i) . $r;
            $v = (string)$v;
            if ($v === '') {
                $sd .= '<c r="' . $ref . '" s="' . (int)$line['s'] . '"/>';
            } else {
                $sd .= '<c r="' . $ref . '" s="' . (int)$line['s'] . '" t="inlineStr"><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
            }
        }
        $maxCols = max($maxCols, count($line['c']));
        $sd .= '</row>';
    }
    $colsXml = '<cols>';
    foreach (array_values($widths) as $i => $w) {
        $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int)$w . '" style="1" customWidth="1"/>';
    }
    $colsXml .= '</cols>';

    $ns   = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $decl = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $pane = $freezeRows > 0
        ? '<pane ySplit="' . $freezeRows . '" topLeftCell="A' . ($freezeRows + 1) . '" activePane="bottomLeft" state="frozen"/>'
        : '';
    $sheet = $decl . '<worksheet xmlns="' . $ns . '"><dimension ref="A1:' . pe_col_letter($maxCols - 1) . max(1, $r) . '"/>'
        . '<sheetViews><sheetView workbookViewId="0">' . $pane . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>' . $colsXml . '<sheetData>' . $sd . '</sheetData></worksheet>';
    $styles = $decl . '<styleSheet xmlns="' . $ns . '">'
        . '<fonts count="2"><font><sz val="11"/><name val="맑은 고딕"/></font><font><b/><sz val="11"/><name val="맑은 고딕"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE9EDF3"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $tmp = @tempnam(sys_get_temp_dir(), 'pex');
    if ($tmp === false) {
        $tmp = @tempnam(__DIR__ . '/inc', 'pex');
    }
    if ($tmp === false) {
        http_response_code(500);
        exit('임시 파일을 만들 수 없습니다.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        http_response_code(500);
        exit('xlsx 파일을 만들 수 없습니다.');
    }
    $zip->addFromString('[Content_Types].xml', $decl . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', $decl . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $decl . '<workbook xmlns="' . $ns . '" xmlns:r="' . $rel . '">'
        . '<sheets><sheet name="거래처등록" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $decl . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"partners.xlsx\"; filename*=UTF-8''" . rawurlencode($filename));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/** E카운트 형식 마지막 줄: 2026/10/21  오후 2:52:39 */
function pe_stamp(): string
{
    return date('Y/m/d') . '  ' . ((int)date('G') < 12 ? '오전' : '오후') . ' ' . date('g:i:s');
}

function pe_title_line(): array
{
    return ['s' => 3, 'c' => ['회사명 : ' . (string)cfg('company.name', '')]];
}

/* ───────── 업로드 처리 ───────── */
function pe_import(PDO $pdo, string $tmp, string $ext, bool $dry, array $cols, bool $ledgerOk, string $forceRegion): array
{
    $hasRegion = in_array('region', $cols, true);
    $hasCredit = in_array('is_credit', $cols, true);
    $hasCode   = in_array('ecount_code', $cols, true);
    $hasPCode  = in_array('partner_code', $cols, true);

    $res = pe_empty_result();
    $err = function (int $line, string $who, string $msg) use (&$res) {
        $res['errors'][] = ['line' => $line, 'who' => $who, 'msg' => $msg];
    };

    // 신규 거래처에 코드를 붙일 수 없는 환경이면 업로드 자체를 막는다. (코드 없는 거래처가 쌓이는 것 방지)
    if (!$hasPCode) {
        $res['fatal'] = '거래처 코드 컬럼(partner_code)이 없어 업로드할 수 없습니다. 관리자가 partner_code_migrate.php 를 먼저 실행하세요.';
        return $res;
    }
    if (!function_exists('pc_assign_new')) {
        $res['fatal'] = 'inc/partner_code.php 가 서버에 없어 신규 거래처에 코드를 부여할 수 없습니다. 파일을 올린 뒤 다시 시도하세요.';
        return $res;
    }

    $raw = $ext === 'csv' ? pe_read_csv($tmp) : pe_read_xlsx($tmp);

    /* 머리글: 첫 15개 비어 있지 않은 행 중 '거래처명(상호)' 열이 있는 줄 */
    $hm = pe_header_map();
    $headerLine = null;
    $map = [];
    $ignored = [];
    $legacyCode = false;   // 머리글이 그냥 '거래처코드'/'코드' (= E카운트 방식: 사업자번호가 코드 칸에 들어 있음)
    $scanned = 0;
    foreach ($raw as $ln => $cells) {
        if (trim(implode('', $cells)) === '') {
            continue;
        }
        if (++$scanned > 15) {
            break;
        }
        $m  = [];
        $ig = [];
        $lg = false;
        foreach ($cells as $ci => $hd) {
            $key = pe_key((string)$hd);
            if ($key === '') {
                continue;
            }
            if (isset($hm[$key]) && !isset($m[$hm[$key]])) {
                $m[$hm[$key]] = $ci;
                if ($key === '거래처코드' || $key === '코드') {
                    $lg = true;
                }
            } else {
                $ig[] = trim((string)$hd);
            }
        }
        if (isset($m['name'])) {
            $headerLine = $ln;
            $map = $m;
            $ignored = $ig;
            $legacyCode = $lg;
            break;
        }
    }
    if ($headerLine === null) {
        $res['fatal'] = "머리글 줄에서 '거래처명'(또는 상호) 열을 찾지 못했습니다. 양식 다운로드로 받은 파일이나 E카운트 거래처 파일을 쓰세요.";
        return $res;
    }
    unset($map['_xfer']); // 이체정보: '등록' 표시뿐이라 저장하지 않음

    // E카운트 방식 파일: '거래처코드' 칸의 값을 사업자번호로도 쓴다. (사업자번호 열이 따로 없을 때만)
    $deriveBiz = $legacyCode && isset($map['ecount_code']) && !isset($map['business_no']);
    if ($deriveBiz) {
        $res['notes'][] = "머리글이 '거래처코드'인 E카운트 방식 파일로 인식했습니다. 이 칸은 E카운트 코드로 저장하고, 사업자번호 형태(10자리, 체크섬 통과)이면 사업자번호로도 사용합니다.";
    }

    $miss = [];
    foreach (array_keys(PT_EXT) as $k) {
        if (isset($map[$k]) && !in_array($k, $cols, true)) {
            $miss[] = PT_EXT[$k][0];
        }
    }
    if ($miss) {
        $res['fatal'] = '거래처 테이블에 E카운트 연동 컬럼이 없어 업로드할 수 없습니다 (' . implode(', ', $miss)
            . '). 관리자가 partners_ecount_setup.php 를 먼저 실행하세요.';
        return $res;
    }
    if (!$hasRegion && isset($map['region'])) {
        $res['notes'][] = "'지역' 열은 region 컬럼이 없어 무시했습니다.";
        unset($map['region']);
    }
    if (!$hasRegion && $forceRegion !== '') {
        $res['notes'][] = '지역 일괄 지정은 region 컬럼이 없어 적용하지 않았습니다.';
    }
    if (!$hasCredit && isset($map['is_credit'])) {
        $res['notes'][] = "'외상여부' 열은 is_credit 컬럼이 없어 무시했습니다.";
        unset($map['is_credit']);
    }
    if ($ignored) {
        $res['notes'][] = '인식하지 못해 무시한 열: ' . implode(', ', array_slice($ignored, 0, 15));
    }

    $typeMap = ['매출처' => 'customer', '매입처' => 'supplier', '매출·매입' => 'both', '매출ㆍ매입' => 'both',
                '매출매입' => 'both', '매출/매입' => 'both', 'customer' => 'customer', 'supplier' => 'supplier', 'both' => 'both'];
    $chanMap = ['소매' => 'retail', '도매' => 'wholesale', 'retail' => 'retail', 'wholesale' => 'wholesale'];
    $pcodeRe = '/^' . preg_quote(PC_PREFIX, '/') . '\d{' . (int)PC_DIGITS . '}$/';

    $items      = [];
    $seen       = [];
    $seenCode   = [];
    $seenPCode  = [];
    $count      = 0;
    $skipFoot   = 0;
    $derived    = 0;
    $notBizCode = 0;
    foreach ($raw as $ln => $cells) {
        if ($ln <= $headerLine) {
            continue;
        }
        $allBlank = true;
        foreach ($map as $ci) {
            if (trim((string)($cells[$ci] ?? '')) !== '') {
                $allBlank = false;
                break;
            }
        }
        if ($allBlank) {
            continue;
        }
        $get = function (string $f) use ($map, $cells): string {
            return isset($map[$f]) ? trim((string)($cells[$map[$f]] ?? '')) : '';
        };

        $name  = $get('name');
        $code  = $get('ecount_code');
        $pcode = strtoupper($get('partner_code'));

        // E카운트 마지막 줄(출력 일시) 건너뛰기
        if ($name === '' && $code !== '' && preg_match('#^\d{4}[/.\-]\d{1,2}[/.\-]\d{1,2}#', $code)) {
            $skipFoot++;
            continue;
        }

        $count++;
        if ($count > PE_MAX_ROWS) {
            $res['fatal'] = '한 번에 ' . PE_MAX_ROWS . '행까지만 올릴 수 있습니다. 파일을 나눠서 올려 주세요.';
            return $res;
        }

        $bizRaw = $get('business_no');
        $who    = $name !== '' ? $name : ($pcode !== '' ? $pcode : ($code !== '' ? pe_mask($code) : ($bizRaw !== '' ? pe_mask($bizRaw) : '(상호 없음)')));
        $bad    = false;
        $e = function (string $m) use (&$bad, $err, $ln, $who) {
            $bad = true;
            $err($ln, $who, $m);
        };

        foreach ([['name', 100, '상호'], ['ceo_name', 50, '대표자'], ['phone', 30, '전화번호'],
                  ['mobile', 30, '핸드폰번호'], ['sms_phone', 30, '문자 발송 핸드폰'], ['depositor', 100, '입금자명'],
                  ['search_keyword', 100, '검색창내용'], ['tax_email', 100, '이메일'],
                  ['address', 200, '주소'], ['memo', 500, '비고']] as [$k, $max, $lab]) {
            if (mb_strlen($get($k)) > $max) {
                $e($lab . '은(는) ' . $max . '자 이하로 입력하세요.');
            }
        }

        // ERP 거래처 코드 (신규는 비워 둠)
        if ($pcode !== '') {
            if (!preg_match($pcodeRe, $pcode)) {
                $e('거래처코드(ERP) 형식이 올바르지 않습니다: ' . $pcode . ' (예: ' . PC_PREFIX . str_repeat('0', PC_DIGITS - 1) . '1). 신규 거래처는 이 칸을 비워 두세요.');
            } elseif (isset($seenPCode[$pcode])) {
                $e('파일 안에서 거래처코드(ERP)가 중복됩니다. (' . $seenPCode[$pcode] . '행과 동일)');
            } else {
                $seenPCode[$pcode] = $ln;
            }
        }

        // E카운트 코드
        if ($code !== '') {
            if (!preg_match('/^[A-Za-z0-9._\-]{1,20}$/', $code)) {
                $e('E카운트 코드는 영문/숫자/-/_/. 만 20자 이하입니다: ' . pe_mask($code));
            } elseif (isset($seenCode[$code])) {
                $e('파일 안에서 E카운트 코드가 중복됩니다. (' . $seenCode[$code] . '행과 동일)');
            } else {
                $seenCode[$code] = $ln;
            }
        }

        $biz = (string)preg_replace('/\D/', '', $bizRaw);
        if ($biz === '' && $deriveBiz && $code !== '') {
            if (preg_match('/^[0-9\s\-]+$/', $code)) {
                $cd = (string)preg_replace('/\D/', '', $code);
                if (strlen($cd) === 13) {
                    $e('거래처코드가 13자리 숫자(주민등록번호·법인등록번호 형태)입니다. 개인정보 보호를 위해 저장하지 않습니다: ' . pe_mask($cd));
                } elseif (strlen($cd) === 10 && is_valid_biz_no($cd)) {
                    $biz = $cd;
                    $derived++;
                } else {
                    $notBizCode++;
                }
            } else {
                $notBizCode++;
            }
        }
        if ($biz !== '') {
            if (strlen($biz) === 9) {
                $biz = '0' . $biz; // 엑셀 숫자 서식으로 앞자리 0이 빠진 경우 복구 시도 (체크섬으로 재검증)
            }
            if (!is_valid_biz_no($biz)) {
                $e('사업자번호가 올바르지 않습니다. (10자리 체크섬 불일치): ' . pe_mask($bizRaw !== '' ? $bizRaw : $biz));
            }
        }
        $branchDigits = (string)preg_replace('/\D/', '', $get('branch_no'));
        if (strlen($branchDigits) > 4) {
            $e('종사업장번호는 4자리 이하 숫자입니다.');
        }
        if ($branchDigits !== '' && $biz === '') {
            $e('종사업장번호는 사업자번호와 함께 입력하세요.');
        }
        $branch = pt_norm_branch($branchDigits);

        $email = $get('tax_email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $e('세금계산서 이메일 형식이 올바르지 않습니다: ' . $email);
        }

        $region      = '';
        $regExplicit = false;
        if ($hasRegion) {
            if ($forceRegion !== '') {
                $region      = $forceRegion;
                $regExplicit = true;
            } elseif ($get('region') !== '') {
                $region      = pt_norm_region($get('region'));
                $regExplicit = true;
                if ($region === '') {
                    $e('지역을 인식하지 못했습니다: ' . $get('region') . ' (서울·경기 등 17개 시·도 중 하나)');
                }
            } else {
                $region = pt_region_from_address($get('address')) ?: pt_region_from_name($name);
            }
        }

        $ptype = null;
        $tv = str_replace(' ', '', $get('partner_type'));
        if ($tv !== '') {
            $ptype = $typeMap[$tv] ?? null;
            if ($ptype === null) {
                $e('거래처유형은 매출처/매입처/매출·매입 중 하나여야 합니다: ' . $tv);
            }
        }
        $chan = null;
        $cv = str_replace(' ', '', $get('default_channel'));
        if ($cv !== '') {
            $chan = $chanMap[$cv] ?? null;
            if ($chan === null) {
                $e('기본매출구분은 소매/도매 중 하나여야 합니다: ' . $cv);
            }
        }
        $cr = null;
        if ($hasCredit && $get('is_credit') !== '') {
            $cr = pe_yn($get('is_credit'));
            if ($cr === null) {
                $e('외상여부는 Y 또는 N 으로 입력하세요: ' . $get('is_credit'));
            }
        }
        $ac = null;
        if ($get('is_active') !== '') {
            $ac = pe_yn($get('is_active'));
            if ($ac === null) {
                $e('사용구분은 YES/NO(또는 Y/N)로 입력하세요: ' . $get('is_active'));
            }
        }

        if ($cr === 1 && $ptype === 'supplier') {
            $e('매입처 전용 거래처는 외상으로 지정할 수 없습니다.');
        }
        if ($name === '' && $biz === '' && $code === '' && $pcode === '') {
            $e('거래처명, 거래처코드, 사업자번호 중 하나는 있어야 합니다.');
        }

        $key = $biz !== '' ? $biz . '|' . $branch : '';
        if ($key !== '') {
            if (isset($seen[$key])) {
                $e('파일 안에서 사업자번호(종사업장번호)가 중복됩니다. (' . $seen[$key] . '행과 동일)');
            } else {
                $seen[$key] = $ln;
            }
        }
        if ($bad) {
            continue;
        }

        // 엑셀에 값이 있는 항목만 담는다. (빈 칸 = 기존 값 유지)  ※ partner_code 는 절대 담지 않는다.
        $v = [];
        foreach (['name', 'ceo_name', 'phone', 'mobile', 'sms_phone', 'depositor', 'search_keyword',
                  'tax_email', 'address', 'memo'] as $k) {
            if ($get($k) !== '') {
                $v[$k] = $get($k);
            }
        }
        if ($code !== '') {
            $v['ecount_code'] = $code;
        }
        if ($biz !== '') {
            $v['business_no'] = $biz;
            $v['branch_no']   = $branch;
        }
        if ($region !== '') {
            $v['region'] = $region;
        }
        if ($ptype !== null) {
            $v['partner_type'] = $ptype;
        }
        if ($chan !== null) {
            $v['default_channel'] = $chan;
        }
        if ($cr !== null) {
            $v['is_credit'] = $cr;
        }
        if ($ac !== null) {
            $v['is_active'] = $ac;
        }
        $items[] = ['ln' => $ln, 'who' => $who, 'biz' => $biz, 'branch' => $branch, 'name' => $name,
                    'code' => $code, 'pcode' => $pcode, 'regAuto' => !$regExplicit, 'v' => $v];
    }
    $res['rows'] = $count;
    if ($skipFoot > 0) {
        $res['notes'][] = '마지막 출력 일시 줄 ' . $skipFoot . '개는 데이터가 아니므로 건너뛰었습니다.';
    }
    if ($derived > 0) {
        $res['notes'][] = "'거래처코드' 칸의 값 " . $derived . '건을 사업자번호로도 사용했습니다.';
    }
    if ($notBizCode > 0) {
        $res['notes'][] = $notBizCode . '건은 거래처코드가 사업자번호 형태가 아니라서 E카운트 코드로만 저장하고 사업자번호는 비워 둡니다. (세금계산서 발행 전에 입력 필요)';
    }
    if ($count === 0) {
        $res['fatal'] = '저장할 데이터 행이 없습니다.';
        return $res;
    }

    /* ── 기존 거래처와 매칭 (읽기만): ERP 코드 → 사업자번호+종사업장 → E카운트 코드 → 상호(사업자번호 없는 것만) ── */
    $selPCode = $pdo->prepare('SELECT * FROM erp_partners WHERE partner_code = ?');
    $selCode  = $hasCode ? $pdo->prepare('SELECT * FROM erp_partners WHERE ecount_code = ?') : null;
    $selBiz   = $pdo->prepare('SELECT * FROM erp_partners WHERE business_no = ? AND branch_no = ?');
    $selName  = $pdo->prepare('SELECT * FROM erp_partners WHERE business_no IS NULL AND name = ?');
    $selNameNoCode = $hasCode
        ? $pdo->prepare('SELECT * FROM erp_partners WHERE business_no IS NULL AND ecount_code IS NULL AND name = ?')
        : null;

    $defaults = ['name' => '', 'business_no' => null, 'branch_no' => '', 'ceo_name' => '', 'phone' => '',
                 'tax_email' => '', 'address' => '', 'partner_type' => 'both', 'default_channel' => 'wholesale',
                 'memo' => '', 'is_active' => 1];
    foreach (array_keys(PT_EXT) as $k) {
        if (in_array($k, $cols, true)) {
            $defaults[$k] = $k === 'ecount_code' ? null : '';
        }
    }
    if ($hasRegion) {
        $defaults['region'] = '';
    }
    if ($hasCredit) {
        $defaults['is_credit'] = 0;
    }

    $plan = [];
    foreach ($items as $it) {
        $old = null;

        // 1) ERP 거래처 코드: 적혀 있는데 없으면 오타로 보고 오류 처리 (새로 만들지 않는다)
        if ($it['pcode'] !== '') {
            $selPCode->execute([$it['pcode']]);
            $old = $selPCode->fetch() ?: null;
            if (!$old) {
                $err($it['ln'], $it['who'], '존재하지 않는 거래처코드(ERP)입니다: ' . $it['pcode'] . ' (신규 거래처는 이 칸을 비워 두세요.)');
                continue;
            }
        }

        // 2) 사업자번호 + 종사업장번호
        if (!$old && $it['biz'] !== '') {
            $selBiz->execute([$it['biz'], $it['branch']]);
            $old = $selBiz->fetch() ?: null;
            if ($old && $it['code'] !== '' && (string)($old['ecount_code'] ?? '') !== ''
                && !pe_code_eq((string)$old['ecount_code'], $it['code'])) {
                $err($it['ln'], $it['who'], '이 사업자번호는 이미 다른 E카운트 코드의 거래처에 연결되어 있습니다.');
                continue;
            }
        }

        // 3) E카운트 코드
        if (!$old && $it['code'] !== '' && $selCode) {
            $selCode->execute([$it['code']]);
            $old = $selCode->fetch() ?: null;
        }

        // 4) 상호 (사업자번호가 없는 행만, 사업자번호 없는 거래처 중에서)
        if (!$old && $it['biz'] === '' && $it['name'] !== '') {
            $stmt = ($it['code'] !== '' && $selNameNoCode) ? $selNameNoCode : $selName;
            $stmt->execute([$it['name']]);
            $cand = $stmt->fetchAll();
            if (count($cand) > 1) {
                $err($it['ln'], $it['who'], '같은 상호의 사업자번호 없는 거래처가 여러 건이라 특정할 수 없습니다. 거래처코드(ERP)나 사업자번호를 입력하세요.');
                continue;
            }
            $old = $cand[0] ?? null;
        }

        // 기존 사업자번호는 엑셀로 바꾸지 않는다. (세금계산서 발행 정보가 바뀌는 일이라 화면에서 직접 수정)
        if ($old && $it['biz'] !== '' && (string)($old['business_no'] ?? '') !== ''
            && (string)$old['business_no'] !== $it['biz']) {
            $err($it['ln'], $it['who'], '이 거래처에는 이미 다른 사업자번호가 있습니다. 사업자번호를 바꾸려면 거래처 화면에서 직접 수정하세요.');
            continue;
        }

        // 사업자번호를 새로 채우거나 종사업장이 바뀌는데, 그 번호가 다른 거래처에 이미 있는 경우
        if ($old && $it['biz'] !== ''
            && ((string)($old['business_no'] ?? '') !== $it['biz'] || (string)($old['branch_no'] ?? '') !== $it['branch'])) {
            $selBiz->execute([$it['biz'], $it['branch']]);
            $o2 = $selBiz->fetch();
            if ($o2 && (int)$o2['id'] !== (int)$old['id']) {
                $err($it['ln'], $it['who'], '이 사업자번호(종사업장번호)는 다른 거래처에 이미 등록되어 있습니다.');
                continue;
            }
        }

        $v = $it['v'];

        // E카운트 코드: 표기만 다르면(하이픈 등) 건드리지 않고, 다른 거래처가 이미 쓰고 있으면 오류
        if ($old && isset($v['ecount_code']) && pe_code_eq((string)($old['ecount_code'] ?? ''), $v['ecount_code'])) {
            unset($v['ecount_code']);
        }
        if (isset($v['ecount_code']) && $selCode) {
            $selCode->execute([$v['ecount_code']]);
            $o3 = $selCode->fetch();
            if ($o3 && (!$old || (int)$o3['id'] !== (int)$old['id'])) {
                $err($it['ln'], $it['who'], '이 E카운트 코드는 다른 거래처에 이미 등록되어 있습니다.');
                continue;
            }
        }

        if ($old && $it['regAuto'] && (string)($old['region'] ?? '') !== '') {
            unset($v['region']); // 추정한 값이 기존 지역을 덮어쓰지 않게
        }

        // 외상 관련 검증
        if ($hasCredit) {
            $effType = $v['partner_type'] ?? ($old['partner_type'] ?? 'both');
            $wasCr   = $old && (int)($old['is_credit'] ?? 0) === 1;
            $newCr   = $v['is_credit'] ?? null;
            if ($newCr === 1 && $effType === 'supplier') {
                $err($it['ln'], $it['who'], '매입처 전용 거래처는 외상으로 지정할 수 없습니다.');
                continue;
            }
            if ($newCr === 1 && !$wasCr && !$ledgerOk) {
                $err($it['ln'], $it['who'], '외상 원장이 준비되지 않아 외상으로 지정할 수 없습니다.');
                continue;
            }
            if ($wasCr && $newCr === 0) {
                if (!$ledgerOk || !function_exists('lg_balance')) {
                    $err($it['ln'], $it['who'], '원장 상태를 확인할 수 없어 외상 해제를 할 수 없습니다.');
                    continue;
                }
                if ((int)lg_balance((int)$old['id']) !== 0) {
                    $err($it['ln'], $it['who'], '미수 잔액이 남아 있어 외상을 해제할 수 없습니다.');
                    continue;
                }
            }
            if ($wasCr && $effType === 'supplier' && $newCr !== 0) {
                $err($it['ln'], $it['who'], '외상 거래처를 매입처 전용으로 바꿀 수 없습니다. 외상을 먼저 해제하세요.');
                continue;
            }
        }

        if ($old) {
            $diff = [];
            foreach ($v as $k => $val) {
                if ((string)($old[$k] ?? '') !== (string)$val) {
                    $diff[$k] = $val;
                }
            }
            if (!$diff) {
                $res['same']++;
                continue;
            }
            $plan[] = ['op' => 'upd', 'id' => (int)$old['id'], 'data' => $diff, 'ln' => $it['ln'], 'who' => $it['who']];
            $res['upd']++;
        } else {
            if (($v['name'] ?? '') === '') {
                $err($it['ln'], $it['who'], '신규 거래처는 거래처명이 필수입니다.');
                continue;
            }
            $plan[] = ['op' => 'ins', 'data' => array_merge($defaults, $v), 'ln' => $it['ln'], 'who' => $it['who']];
            $res['ins']++;
        }
    }

    if ($res['errors']) {
        $res['ins'] = $res['upd'] = 0; // 오류가 하나라도 있으면 아무것도 저장하지 않는다
        return $res;
    }
    $res['codes'] = $res['ins'];
    if ($res['ins'] > 0) {
        $res['notes'][] = '신규 ' . $res['ins'] . '건에는 저장할 때 거래처 코드가 자동으로 부여됩니다.';
    }
    if ($dry) {
        return $res;
    }

    /* ── 저장 (전부 저장 or 전부 취소) ── */
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        foreach ($plan as $p) {
            if ($p['op'] === 'ins') {
                $row = $p['data'] + ['created_at' => $now, 'updated_at' => $now];
                $pdo->prepare('INSERT INTO erp_partners (' . implode(', ', array_keys($row)) . ') VALUES ('
                    . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
                $newId = (int)$pdo->lastInsertId();   // INSERT 직후, 다른 쿼리 전에 읽는다
                if (pc_assign_new($pdo, $newId) === '') {
                    throw new RuntimeException('거래처 코드를 부여하지 못했습니다. (' . $p['who'] . ')');
                }
            } else {
                $set  = [];
                $args = [];
                foreach ($p['data'] as $k => $val) {
                    $set[]  = $k . ' = ?';
                    $args[] = $val;
                }
                $set[]  = 'updated_at = ?';
                $args[] = $now;
                $args[] = $p['id'];
                $pdo->prepare('UPDATE erp_partners SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
            }
        }
        $pdo->commit();
        $res['saved'] = true;
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $res['ins'] = $res['upd'] = 0;
        $res['codes'] = 0;
        if ($t instanceof PDOException && (int)($t->errorInfo[1] ?? 0) === 1062) {
            $res['fatal'] = '저장 중 코드 또는 사업자번호 중복이 발견되어 전부 취소했습니다. 아무것도 저장되지 않았습니다.';
        } elseif ($t instanceof PDOException) {
            error_log('[ERP] partners_excel import: ' . $t->getMessage());
            $res['fatal'] = '저장 중 DB 오류가 나서 전부 취소했습니다. 아무것도 저장되지 않았습니다.';
        } else {
            error_log('[ERP] partners_excel import: ' . get_class($t) . ': ' . $t->getMessage());
            $res['fatal'] = '저장 중 오류가 나서 전부 취소했습니다. 아무것도 저장되지 않았습니다. (' . $t->getMessage() . ')';
        }
        return $res;
    }

    // 감사 로그 실패가 "저장 취소"로 오해되지 않도록, 커밋 뒤에 따로 기록한다.
    try {
        audit('partner_import', 'partner', null, '신규 ' . $res['ins'] . ' / 수정 ' . $res['upd'] . ' / 변경없음 ' . $res['same']);
    } catch (Throwable $t) {
        error_log('[ERP] partners_excel audit: ' . $t->getMessage());
    }
    return $res;
}

/* ───────── 양식 / 내려받기 ───────── */
$mode   = (string)($_GET['mode'] ?? '');
$colMap = pe_columns($cols);
$widths = pe_widths(array_keys($colMap));

if ($mode === 'template') {
    $lines = [pe_title_line(), ['s' => 2, 'c' => array_values($colMap)]];
    pe_send_xlsx('거래처_업로드_양식.xlsx', $lines, $widths, 2);
}

if ($mode === 'export') {
    $f = ['q' => in_str($_GET, 'q', 50), 'region' => in_str($_GET, 'region', 10), 'type' => in_str($_GET, 'type', 10),
          'credit' => in_str($_GET, 'credit', 1), 'active' => in_str($_GET, 'active', 1)];
    [$whereSql, $args] = pt_filter($f, $cols);
    $order = $hasRegion ? "(p.region = ''), p.region, p.name, p.id" : 'p.name, p.id';
    $st = $pdo->prepare('SELECT p.* FROM erp_partners p' . $whereSql . ' ORDER BY ' . $order . ' LIMIT 20000');
    $st->execute($args);

    $lines = [pe_title_line(), ['s' => 2, 'c' => array_values($colMap)]];
    $n = 0;
    foreach ($st->fetchAll() as $r) {
        $line = [];
        foreach (array_keys($colMap) as $k) {
            $val = (string)($r[$k] ?? '');
            if ($k === '_xfer') {
                $val = '';
            } elseif ($k === 'business_no' && $val !== '') {
                $val = fmt_biz_no($val);
            } elseif ($k === 'partner_type') {
                $val = $types[$val] ?? $val;
            } elseif ($k === 'default_channel') {
                $val = $channels[$val] ?? $val;
            } elseif ($k === 'is_active') {
                $val = (int)$val === 1 ? 'YES' : 'NO';
            } elseif ($k === 'is_credit') {
                $val = $val === '' ? '' : ((int)$val === 1 ? 'Y' : 'N');
            }
            $line[] = $val;
        }
        $lines[] = ['s' => 1, 'c' => $line];
        $n++;
    }
    $lines[] = ['s' => 1, 'c' => [pe_stamp()]];
    try {
        audit('partner_export', 'partner', null, $n . '건');
    } catch (Throwable $t) {
        error_log('[ERP] partners_excel export audit: ' . $t->getMessage());
    }
    pe_send_xlsx('거래처_' . date('Ymd') . '.xlsx', $lines, $widths, 2);
}

/* ───────── 업로드 요청 처리 ───────── */
if (!is_post()) {
    redirect('partners.php');
}
require_write();
csrf_check();
$dry    = isset($_POST['dry']);
$result = null;
try {
    $up = $_FILES['xfile'] ?? null;
    if (!is_array($up) || (int)($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('파일이 업로드되지 않았습니다. 서버 업로드 용량 제한일 수 있습니다.');
    }
    if ((int)$up['size'] > PE_MAX_BYTES) {
        throw new RuntimeException('파일이 5MB를 넘습니다. 나눠서 올려 주세요.');
    }
    $ext = strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) {
        throw new RuntimeException('.xlsx 또는 .csv 파일만 올릴 수 있습니다. (.xls 는 xlsx 로 저장해서 올리세요)');
    }
    $force = in_str($_POST, 'force_region', 10);
    if ($force !== '') {
        $force = pt_norm_region($force);
        if ($force === '') {
            throw new RuntimeException('지역 값이 올바르지 않습니다.');
        }
    }
    $result = pe_import($pdo, (string)$up['tmp_name'], $ext, $dry, $cols, $ledgerOk, $force);
} catch (RuntimeException $t) {
    $result = pe_empty_result($t->getMessage());
} catch (Throwable $t) {
    error_log('[ERP] partners_excel: ' . get_class($t) . ': ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
    $result = pe_empty_result('처리 중 오류가 발생했습니다. 관리자에게 문의하세요.');
}

page_header('거래처 엑셀 결과', 'partners');
?>
<div style="max-width:900px;background:#fff;border:1px solid #dfe3ea;border-radius:12px;padding:18px">
  <h2 style="margin-top:0">거래처 엑셀 <?= $dry ? '검증' : '저장' ?> 결과</h2>

  <?php if ($result['fatal'] !== ''): ?>
    <p style="color:#8a1f17;font-weight:700"><?= h($result['fatal']) ?></p>
  <?php elseif ($result['errors']): ?>
    <p style="color:#8a1f17;font-weight:700">오류 <?= count($result['errors']) ?>건이 있어 아무것도 저장하지 않았습니다. 엑셀을 고친 뒤 다시 올려 주세요.</p>
  <?php elseif ($result['saved']): ?>
    <p style="color:#0b6b3a;font-weight:700">저장했습니다. 신규 <?= (int)$result['ins'] ?>건 · 수정 <?= (int)$result['upd'] ?>건 · 변경 없음 <?= (int)$result['same'] ?>건</p>
  <?php else: ?>
    <p style="color:#12306f;font-weight:700">검증 통과 (아직 저장 안 됨). 저장하면 신규 <?= (int)$result['ins'] ?>건 · 수정 <?= (int)$result['upd'] ?>건 · 변경 없음 <?= (int)$result['same'] ?>건입니다.
    같은 파일을 "검증만 하기" 체크를 풀고 다시 올리면 저장됩니다.</p>
  <?php endif; ?>

  <?php if ($result['rows'] > 0): ?><p>읽은 데이터 행: <?= (int)$result['rows'] ?>건</p><?php endif; ?>
  <?php foreach ($result['notes'] as $n): ?><p style="color:#4b5563;font-size:13px">· <?= h($n) ?></p><?php endforeach; ?>

  <?php if ($result['errors']): ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:10px">
      <thead><tr><th style="text-align:left;padding:6px;background:#f1f4f9">엑셀 행</th><th style="text-align:left;padding:6px;background:#f1f4f9">거래처</th><th style="text-align:left;padding:6px;background:#f1f4f9">내용</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($result['errors'], 0, 200) as $er): ?>
        <tr>
          <td style="padding:6px;border-bottom:1px solid #e5e9f0"><?= (int)$er['line'] ?></td>
          <td style="padding:6px;border-bottom:1px solid #e5e9f0"><?= h((string)$er['who']) ?></td>
          <td style="padding:6px;border-bottom:1px solid #e5e9f0"><?= h((string)$er['msg']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($result['errors']) > 200): ?><p style="font-size:13px">오류가 많아 앞 200건만 표시했습니다.</p><?php endif; ?>
  <?php endif; ?>

  <p style="margin-top:16px"><a class="btn" href="partners.php">거래처 화면으로</a></p>
</div>
<?php page_footer();
