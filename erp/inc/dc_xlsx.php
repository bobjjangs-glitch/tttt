<?php
// inc/dc_xlsx.php — 최소 .xlsx 읽기/쓰기 (ZipArchive + SimpleXML). DC율 화면 전용.

const DCX_MAX_UNZIPPED = 52428800; // 압축 해제 합계 50MB 초과 시 거부 (zip bomb 방어)
const DCX_MAX_ROWNO    = 20000;
const DCX_MAX_COLS     = 60;

function dcx_col(int $i): string
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

function dcx_col_index(string $letters): int
{
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

function dcx_esc(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** $rows: 행 배열(각 행은 문자열 배열). 모든 셀을 "글자"로 저장해 앞자리 0 이 유지됩니다. 반환: xlsx 바이너리. */
function dcx_write(array $rows, array $widths = []): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'dcx');
    if ($tmp === false) {
        throw new RuntimeException('임시 파일을 만들 수 없습니다.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('엑셀 파일을 만들 수 없습니다.');
    }
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="DC율" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>');

    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="' . $ns . '">';
    if ($widths) {
        $x .= '<cols>';
        foreach (array_values($widths) as $i => $w) {
            $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int)$w . '" customWidth="1"/>';
        }
        $x .= '</cols>';
    }
    $x .= '<sheetData>';
    foreach (array_values($rows) as $ri => $row) {
        $x .= '<row r="' . ($ri + 1) . '">';
        foreach (array_values($row) as $ci => $v) {
            $x .= '<c r="' . dcx_col($ci) . ($ri + 1) . '" t="inlineStr"><is><t xml:space="preserve">'
                . dcx_esc((string)$v) . '</t></is></c>';
        }
        $x .= '</row>';
    }
    $x .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $x);
    $zip->close();

    $bin = file_get_contents($tmp);
    @unlink($tmp);
    if ($bin === false) {
        throw new RuntimeException('엑셀 파일을 읽지 못했습니다.');
    }
    return $bin;
}

function dcx_xml(string $xml): ?SimpleXMLElement
{
    // 기본 네임스페이스 제거 (접두어 없는 요소 접근을 위해)
    $xml = preg_replace('/\sxmlns="[^"]*"/', '', $xml, 1) ?? $xml;
    $prev = libxml_use_internal_errors(true);
    $o = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $o === false ? null : $o;
}

/** 첫 번째 시트를 행 배열로 읽습니다. 반환 배열의 인덱스 = 엑셀 행번호 - 1. 수식 결과값이 아닌 저장된 값을 읽습니다. */
function dcx_read(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('엑셀 파일을 열 수 없습니다. .xlsx 형식인지 확인하세요.');
    }
    try {
        $total = 0;
        $sheets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $s = $zip->statIndex($i);
            if ($s === false) {
                continue;
            }
            $total += (int)$s['size'];
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $s['name'])) {
                $sheets[] = $s['name'];
            }
        }
        if ($total > DCX_MAX_UNZIPPED) {
            throw new RuntimeException('파일이 너무 큽니다.');
        }
        if (!$sheets) {
            throw new RuntimeException('시트를 찾을 수 없습니다. 올바른 .xlsx 파일인지 확인하세요.');
        }
        natsort($sheets);
        $sheetName = reset($sheets);

        $ss = [];
        $sx = $zip->getFromName('xl/sharedStrings.xml');
        if ($sx !== false && ($o = dcx_xml($sx)) !== null) {
            foreach ($o->si as $si) {
                $t = isset($si->t) ? (string)$si->t : '';
                foreach ($si->r as $r) {
                    $t .= (string)$r->t;
                }
                $ss[] = $t;
            }
        }

        $raw = $zip->getFromName($sheetName);
        $sheet = $raw === false ? null : dcx_xml($raw);
        if ($sheet === null || !isset($sheet->sheetData)) {
            throw new RuntimeException('시트를 읽을 수 없습니다.');
        }

        $map = [];
        $maxRn = 0;
        $seq = 0;
        foreach ($sheet->sheetData->row as $row) {
            $seq++;
            $rn = (int)$row['r'];
            if ($rn <= 0) {
                $rn = $seq;
            }
            if ($rn > DCX_MAX_ROWNO) {
                throw new RuntimeException('행 번호가 너무 큽니다.');
            }
            $line = [];
            $next = 0;
            foreach ($row->c as $c) {
                $idx = $next;
                if (preg_match('/^([A-Z]+)/', (string)$c['r'], $m)) {
                    $idx = dcx_col_index($m[1]);
                }
                $next = $idx + 1;
                if ($idx >= DCX_MAX_COLS) {
                    continue;
                }
                $t = (string)$c['t'];
                if ($t === 's') {
                    $val = $ss[(int)$c->v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $val = isset($c->is->t) ? (string)$c->is->t : '';
                    foreach ($c->is->r as $r) {
                        $val .= (string)$r->t;
                    }
                } else {
                    $val = (string)$c->v;
                }
                $line[$idx] = $val;
            }
            if ($line) {
                $w = max(array_keys($line)) + 1;
                $full = array_fill(0, $w, '');
                foreach ($line as $k => $v) {
                    $full[$k] = $v;
                }
                $line = $full;
            }
            $map[$rn] = $line;
            $maxRn = max($maxRn, $rn);
        }
        $out = [];
        for ($i = 1; $i <= $maxRn; $i++) {
            $out[] = $map[$i] ?? [];
        }
        if (!$out) {
            throw new RuntimeException('읽을 수 있는 데이터가 없습니다.');
        }
        return $out;
    } finally {
        $zip->close();
    }
}
