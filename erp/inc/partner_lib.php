<?php
// inc/partner_lib.php — 거래처 공용 함수 (지역 / E카운트 확장 컬럼 / 거래처 코드 / 검색조건)
// bootstrap.php 를 먼저 로드한 뒤 require_once 하세요.

const PT_REGIONS = ['서울', '부산', '대구', '인천', '광주', '대전', '울산', '세종', '경기',
                    '강원', '충북', '충남', '전북', '전남', '경북', '경남', '제주'];

/** E카운트에서 넘어오는 확장 컬럼: 컬럼명 => [화면 라벨, 최대 글자수]
 *  주의: partner_code 는 여기에 넣지 않는다. (partners.php 가 이 목록을 입력칸/UPDATE 에 그대로 사용함) */
const PT_EXT = [
    'ecount_code'    => ['E카운트 코드', 20],
    'mobile'         => ['핸드폰번호', 30],
    'sms_phone'      => ['문자 발송 핸드폰', 30],
    'depositor'      => ['입금자명', 100],
    'search_keyword' => ['검색창내용', 100],
];

/** ERP 거래처 코드 형식 (inc/partner_code.php, partner_code_migrate.php 와 반드시 같아야 함) */
if (!defined('PC_PREFIX')) {
    define('PC_PREFIX', 'C');
    define('PC_DIGITS', 5);
}

function pt_region_aliases(): array
{
    static $m = null;
    if ($m !== null) {
        return $m;
    }
    $m = [];
    foreach (PT_REGIONS as $r) {
        $m[$r] = $r;
    }
    $long = [
        '서울특별시' => '서울', '서울시' => '서울', '부산광역시' => '부산', '부산시' => '부산',
        '대구광역시' => '대구', '대구시' => '대구', '인천광역시' => '인천', '인천시' => '인천',
        '광주광역시' => '광주', '대전광역시' => '대전', '대전시' => '대전', '울산광역시' => '울산',
        '울산시' => '울산', '세종특별자치시' => '세종', '세종시' => '세종', '경기도' => '경기',
        '강원도' => '강원', '강원특별자치도' => '강원', '충청북도' => '충북', '충청남도' => '충남',
        '전라북도' => '전북', '전북특별자치도' => '전북', '전라남도' => '전남', '경상북도' => '경북',
        '경상남도' => '경남', '제주특별자치도' => '제주', '제주도' => '제주',
    ];
    foreach ($long as $k => $v) {
        $m[$k] = $v;
    }
    return $m;
}

/** 입력값을 표준 시·도명으로. 인식 못 하면 ''. ('광주시'는 경기 광주와 혼동되므로 일부러 제외) */
function pt_norm_region(string $s): string
{
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    $m = pt_region_aliases();
    return $m[$s] ?? '';
}

/** 주소 앞부분에서 시·도 추정. 못 찾으면 ''. */
function pt_region_from_address(string $addr): string
{
    $addr = trim((string)preg_replace('/\s+/u', ' ', $addr));
    if ($addr === '') {
        return '';
    }
    $first = explode(' ', $addr, 2)[0];
    $r = pt_norm_region($first);
    if ($r !== '') {
        return $r;
    }
    $m = pt_region_aliases();
    uksort($m, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
    foreach ($m as $k => $v) {
        if (mb_strlen($k) >= 3 && mb_strpos($addr, $k) === 0) {
            return $v;
        }
    }
    return '';
}

/** 상호 끝의 괄호에서 시·도 추정. 예: '♣동림자동차공업사(서울)' → '서울'. 못 찾으면 ''. */
function pt_region_from_name(string $name): string
{
    if (preg_match_all('/[\(\[（]\s*([^\)\]）]{1,12}?)\s*[\)\]）]/u', $name, $m)) {
        foreach (array_reverse($m[1]) as $g) {
            $r = pt_norm_region((string)$g);
            if ($r !== '') {
                return $r;
            }
        }
    }
    return '';
}

/** 종사업장번호: 숫자만, 있으면 4자리 0 채움 */
function pt_norm_branch(string $s): string
{
    $d = (string)preg_replace('/\D/', '', $s);
    if ($d === '') {
        return '';
    }
    return str_pad(substr($d, -4), 4, '0', STR_PAD_LEFT);
}

/** erp_partners 컬럼 목록 (요청당 1회 조회) */
function pt_cols(PDO $pdo): array
{
    static $c = null;
    if ($c === null) {
        $c = [];
        foreach ($pdo->query('SHOW COLUMNS FROM erp_partners')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c[] = $r['Field'];
        }
    }
    return $c;
}

/** 검색어가 ERP 거래처 코드 형태(C1, C00012 ...)인지 */
function pt_is_code_query(string $q): bool
{
    return (bool)preg_match('/^' . preg_quote(PC_PREFIX, '/') . '\d{1,' . (int)PC_DIGITS . '}$/i', $q);
}

/** 목록/엑셀 공용 검색 조건. 반환: [' WHERE ...' 또는 '', 바인딩 배열]. 테이블 별칭은 p */
function pt_filter(array $f, array $cols): array
{
    $where = [];
    $args  = [];

    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $hasPCode = in_array('partner_code', $cols, true);
        if ($hasPCode && pt_is_code_query($q)) {
            // C00012 / C12 : 거래처 코드 앞부분 일치 (숫자만 있는 일반 검색과 섞이지 않게 코드로만 찾는다)
            $where[] = 'p.partner_code LIKE ?';
            $args[]  = strtoupper($q) . '%';
        } else {
            $like  = '%' . addcslashes($q, '%_\\') . '%';
            $parts = ['p.name LIKE ?', 'p.ceo_name LIKE ?', 'p.phone LIKE ?', 'p.address LIKE ?'];
            $a     = [$like, $like, $like, $like];
            foreach (array_keys(PT_EXT) as $k) {
                if (in_array($k, $cols, true)) {
                    $parts[] = 'p.' . $k . ' LIKE ?';
                    $a[]     = $like;
                }
            }
            if ($hasPCode) {
                $parts[] = 'p.partner_code LIKE ?';
                $a[]     = $like;
            }
            $d = (string)preg_replace('/\D/', '', $q);
            if ($d !== '') {
                $parts[] = 'p.business_no LIKE ?';
                $a[]     = '%' . $d . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
            foreach ($a as $v) {
                $args[] = $v;
            }
        }
    }

    $region = (string)($f['region'] ?? '');
    if (in_array('region', $cols, true) && $region !== '') {
        if ($region === '_none') {
            $where[] = "p.region = ''";
        } elseif (in_array($region, PT_REGIONS, true)) {
            $where[] = 'p.region = ?';
            $args[]  = $region;
        }
    }

    $type = (string)($f['type'] ?? '');
    if (in_array($type, ['customer', 'supplier', 'both'], true)) {
        $where[] = 'p.partner_type = ?';
        $args[]  = $type;
    }

    if (in_array('is_credit', $cols, true) && (string)($f['credit'] ?? '') === '1') {
        $where[] = 'p.is_credit = 1';
    }

    $active = (string)($f['active'] ?? '');
    if ($active === '1' || $active === '0') {
        $where[] = 'p.is_active = ?';
        $args[]  = (int)$active;
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $args];
}

/** 빈 값은 빼고 쿼리스트링 생성 */
function pt_url(string $file, array $params): string
{
    $p = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return $file . ($p ? '?' . http_build_query($p) : '');
}
