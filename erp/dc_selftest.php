<?php
// erp/dc_selftest.php — DC 함수/엑셀 왕복 테스트 (DB 접속 없음, 통과 후 삭제)
require __DIR__ . '/inc/dc_lib.php';
require __DIR__ . '/inc/dc_xlsx.php';
header('Content-Type: text/plain; charset=utf-8');

$fail = 0;
function t(string $name, $got, $want): void
{
    global $fail;
    $ok = $got === $want;
    if (!$ok) { $fail++; }
    echo ($ok ? 'OK   ' : 'FAIL ') . $name . ($ok ? '' : '  got=' . var_export($got, true) . ' want=' . var_export($want, true)) . "\n";
}

foreach ([
    ['10', 1000], ['12.5', 1250], ['12.5%', 1250], ['0', 0], ['100', 10000], ['5.05', 505], ['5.5', 550],
    ['100.01', null], ['101', null], ['12.555', null], ['', null], ['abc', null], ['-1', null],
] as [$in, $want]) {
    t("dc_parse('$in')", dc_parse($in), $want);
}
foreach ([[1250, '12.5'], [1000, '10'], [0, '0'], [505, '5.05'], [10000, '100'], [10, '0.1']] as [$in, $want]) {
    t("dc_fmt($in)", dc_fmt($in), $want);
}
t('단가 100000, 10%', dc_unit_price(100000, 1000), 90000);
t('단가 98000, 12.5%', dc_unit_price(98000, 1250), 85750);
t('단가 333, 10% (299.7)', dc_unit_price(333, 1000), 300);
t('단가 155, 10% (139.5 올림)', dc_unit_price(155, 1000), 140);
t('단가 DC 0%', dc_unit_price(12345, 0), 12345);
t('단가 DC 100%', dc_unit_price(12345, 10000), 0);

$rows = [
    ['거래처코드', '상호', 'DC율(%)'],
    ['C00001', '하나로 & <특수> "문자"', '12.5'],
    ['C00002', '', '0'],
    ['007', '앞자리 0', '0.5%'],
];
$bin = dcx_write($rows, [14, 30, 10]);
$tmp = tempnam(sys_get_temp_dir(), 'dct');
file_put_contents($tmp, $bin);
$back = dcx_read($tmp);
@unlink($tmp);
t('xlsx 왕복', $back, $rows);

echo $fail === 0 ? "\n모두 통과\n" : "\n{$fail}건 실패 - 설치하지 마세요\n";
