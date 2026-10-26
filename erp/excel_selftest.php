<?php
// erp/excel_selftest.php - DB 접속 없음. 확인 후 반드시 삭제
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require __DIR__ . '/inc/excel_io.php';

$fail = 0;
function chk(string $name, bool $ok): void { global $fail; echo ($ok ? 'OK   ' : 'FAIL ') . $name . "\n"; if (!$ok) { $fail++; } }

echo 'PHP ' . PHP_VERSION . ' / ZipArchive+SimpleXML: ' . (xl_zip_ok() ? '있음' : '없음 (xlsx 불가, CSV만 사용)') . "\n\n";

chk('xl_col', xl_col(0) === 'A' && xl_col(25) === 'Z' && xl_col(26) === 'AA' && xl_col(27) === 'AB');
chk('xl_col_index', xl_col_index('A1') === 0 && xl_col_index('AA10') === 26);
chk('xl_int', xl_int('85,540') === 85540 && xl_int('85540.0') === 85540 && xl_int('') === null
    && xl_int('1.5') === false && xl_int('-1') === false && xl_int('1234567890123') === false);
chk('xl_yn', xl_yn('Y') === 1 && xl_yn('중지') === 0 && xl_yn('') === null && xl_yn('?') === false);

$tmpDir = is_writable(sys_get_temp_dir()) ? sys_get_temp_dir() : __DIR__ . '/inc';

if (xl_zip_ok()) {
    $p = tempnam($tmpDir, 'xl');
    $src = [['상호', '전화', '금액'], ['가&나<다>', '010-1234-5678', 85540], ['', '0101234567', 0]];
    $okW = xl_write_xlsx($p, $src);
    chk('xlsx 쓰기', $okW);
    if ($okW) {
        $got = xl_read_rows($p, 'xlsx');
        $exp = [1 => ['상호', '전화', '금액'], 2 => ['가&나<다>', '010-1234-5678', '85540'], 3 => ['', '0101234567', '0']];
        chk('xlsx 왕복(한글·특수문자·앞자리 0)', $got === $exp);
        if ($got !== $exp) { echo '  읽은 값: ' . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n"; }
    }
    @unlink($p);
}

$p = tempnam($tmpDir, 'xl');
file_put_contents($p, mb_convert_encoding("상호,전화\n가나다,\"010,1\"\n", 'CP949', 'UTF-8'));
chk('CSV(CP949) 읽기', xl_read_rows($p, 'csv') === [1 => ['상호', '전화'], 2 => ['가나다', '010,1']]);
file_put_contents($p, "\xEF\xBB\xBF" . "상호,전화\n라마바,02\n");
chk('CSV(UTF-8 BOM) 읽기', xl_read_rows($p, 'csv') === [1 => ['상호', '전화'], 2 => ['라마바', '02']]);
@unlink($p);

echo "\n" . ($fail === 0 ? '모두 통과' : $fail . '건 실패 - 업로드 기능을 쓰지 마세요') . "\n";
