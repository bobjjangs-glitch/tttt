<?php
/**
 * inc/dc_label.php — E카운트 '코드' 열 값을 DC율로 해석한다.
 *
 * 독립 파일이다. dc_lib.php 를 수정하지 않으며, 의존하는 것은 mbstring 뿐이다.
 * 정수 연산만 쓴다 (float 금지). 단위: 0.01% (12.5% = 1250, 100% = 10000).
 *
 * 규칙
 *   "40코드" "41%" "41.5%"        → 같은 의미의 DC율로 읽는다
 *   공백 / 전각 숫자·%·소수점      → 허용 (정규화 후 해석)
 *   빈 값                          → 오류가 아님. 공임·택배비·A/L 같은 행이다
 *   "40"(접미사 없음), "101%", "특가", "12.555%" 등 → 추측하지 않고 invalid
 */

if (!function_exists('dc_parse_label')) {

    /**
     * @param mixed $raw 엑셀 셀 값
     * @return array{0:string,1:?int}
     *   ['empty',   null]  비어 있음
     *   ['ok',      정수]  DC율 (0.01% 단위)
     *   ['invalid', null]  해석 불가
     */
    function dc_parse_label($raw): array
    {
        $s = trim((string)$raw);

        // 전각 기호 → 반각, 각종 공백 제거
        $s = str_replace(
            ['％', '．', "\xC2\xA0", "\xE3\x80\x80", ' ', "\t", "\r", "\n"],
            ['%',  '.',  '',         '',             '',  '',   '',   ''],
            $s
        );
        // 전각 숫자 → 반각
        $s = mb_convert_kana($s, 'n', 'UTF-8');

        if ($s === '') {
            return ['empty', null];
        }

        if (!preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,2}))?(?:%|코드)$/u', $s, $m)) {
            return ['invalid', null];
        }

        $frac = str_pad($m[2] ?? '', 2, '0'); // "5" → "50"
        $rate = (int)$m[1] * 100 + (int)$frac;
        $max  = defined('DC_MAX') ? (int)DC_MAX : 10000;

        return $rate > $max ? ['invalid', null] : ['ok', $rate];
    }
}
