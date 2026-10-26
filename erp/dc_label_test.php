<?php
require_once __DIR__ . '/inc/dc_label.php';

header('Content-Type: text/plain; charset=utf-8');

$cases = [
    // 정상
    ['40코드',     'ok', 4000],
    ['41%',        'ok', 4100],
    [' 37 코드 ',  'ok', 3700],
    ['40 코드',    'ok', 4000],
    ['41.5%',      'ok', 4150],
    ['41.05%',     'ok', 4105],
    ['５５코드',   'ok', 5500],
    ['１２．５％', 'ok', 1250],
    ['90%',        'ok', 9000],
    ['0%',         'ok', 0],
    ['100코드',    'ok', 10000],
    // 비어 있음 (오류 아님)
    ['',           'empty', null],
    ['   ',        'empty', null],
    [null,         'empty', null],
    // 해석 불가 (추측하지 않음)
    ['101%',       'invalid', null],
    ['100.01%',    'invalid', null],
    ['40',         'invalid', null],
    ['코드40',     'invalid', null],
    ['40코드%',    'invalid', null],
    ['-5%',        'invalid', null],
    ['12.555%',    'invalid', null],
    ['4O코드',     'invalid', null],
    ['특가',       'invalid', null],
];

$fail = 0;
foreach ($cases as [$in, $st, $rate]) {
    [$gs, $gr] = dc_parse_label($in);
    $ok = ($gs === $st && $gr === $rate);
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? 'OK   ' : 'FAIL ') . var_export($in, true)
        . ($ok ? '' : "   → 실제: {$gs}/" . var_export($gr, true)) . "\n";
}
echo $fail === 0 ? "\n모두 통과\n" : "\n{$fail}건 실패 - 적용하지 마세요\n";
