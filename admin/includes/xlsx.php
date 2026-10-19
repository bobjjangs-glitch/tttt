<?php
// admin/includes/xlsx.php — 의존성 없는 최소 XLSX 빌더 (ZipArchive + inline string)
// 사용: XlsxBuilder::build(['헤더',...], [[값,...],...], [열너비,...]) → 바이너리 문자열 반환
// 셀 값: 정수/실수 → 숫자 셀, 나머지(문자열/null) → 문자열 셀
declare(strict_types=1);

class XlsxBuilder
{
    public static function escape(string $s): string
    {
        // 제어문자 제거 후 XML 이스케이프
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** 0-based 열 인덱스 → A, B, ... AA, AB ... */
    public static function colLetter(int $i): string
    {
        $r = '';
        $i += 1;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $r = chr(65 + $m) . $r;
            $i = intdiv($i - 1, 26);
        }
        return $r;
    }

    /**
     * @param array $headers 헤더 행 (문자열)
     * @param array $rows    데이터 행 (각 행 = 배열)
     * @param array $colWidths 열너비(정수 배열, 생략 가능)
     * @return string .xlsx 파일 바이너리
     */
    public static function build(array $headers, array $rows, array $colWidths = []): string
    {
        $colCount = count($headers);

        /* ---- sheet1.xml ---- */
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $sheet .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($colCount > 0) {
            $sheet .= '<cols>';
            for ($c = 0; $c < $colCount; $c++) {
                $w = isset($colWidths[$c]) ? max(4, (int)$colWidths[$c]) : 12;
                $sheet .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $sheet .= '</cols>';
        }
        $sheet .= '<sheetData>';

        // 헤더 (굵게 = 스타일 s="1")
        $r = 1;
        $sheet .= '<row r="' . $r . '">';
        for ($c = 0; $c < $colCount; $c++) {
            $ref = self::colLetter($c) . $r;
            $sheet .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t xml:space="preserve">'
                . self::escape((string)$headers[$c]) . '</t></is></c>';
        }
        $sheet .= '</row>';

        // 데이터 행
        foreach ($rows as $row) {
            $r++;
            $sheet .= '<row r="' . $r . '">';
            for ($c = 0; $c < $colCount; $c++) {
                $ref = self::colLetter($c) . $r;
                $v = array_key_exists($c, $row) ? $row[$c] : '';
                if (is_int($v) || is_float($v)) {
                    $sheet .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                } else {
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                        . self::escape((string)$v) . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        /* ---- 패키지 공통 부품 ---- */
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        /* ---- Zip 패키징 ---- */
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive unavailable');
        }
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmpFile === false) {
            throw new RuntimeException('tempnam failed');
        }
        $zip = new ZipArchive();
        $openRes = $zip->open($tmpFile, ZipArchive::OVERWRITE);
        if ($openRes !== true) {
            @unlink($tmpFile);
            throw new RuntimeException('ZipArchive open failed (' . $openRes . ')');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $bin = (string)file_get_contents($tmpFile);
        @unlink($tmpFile);
        if ($bin === '') {
            throw new RuntimeException('empty xlsx output');
        }
        return $bin;
    }

    /** XLSX 생성 실패 시 대비한 CSV 폴백 (UTF-8 BOM — 한글 안깨짐) */
    public static function buildCsvFallback(array $headers, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = (string)stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
