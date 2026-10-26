<?php
// erp/trade_api.php — 전표 입력용 검색 (읽기 전용 JSON)
require __DIR__ . '/inc/bootstrap.php';

// 판매 전표 조회 시 admin 이외 계정에는 최근 매입가(원가)를 내려보내지 않음
const TA_HIDE_SALE_COST = true;

// 거래처 코드 규칙은 inc/partner_code.php 와 같은 값을 쓰고, 파일이 없으면 기본값 사용
$ta_pc_file = __DIR__ . '/inc/partner_code.php';
if (is_file($ta_pc_file)) {
    require_once $ta_pc_file;
}

function ta_out(array $a): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ta_esc(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

function ta_like(string $s): string
{
    return '%' . ta_esc($s) . '%';
}

function ta_tokens(string $q): array
{
    $t = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY);
    return array_slice($t ?: [], 0, 5);
}

// DECIMAL 문자열을 float 없이 보기 좋게 ("10.000" -> "10")
function ta_dec(string $d): string
{
    if (strpos($d, '.') !== false) {
        $d = rtrim(rtrim($d, '0'), '.');
    }
    return ($d === '' || $d === '-') ? '0' : $d;
}

// "C12" / "c00012" 형태면 정식 코드("C00012")를 돌려주고, 아니면 null
function ta_code_of(string $tok): ?string
{
    $prefix = defined('PC_PREFIX') ? (string)PC_PREFIX : 'C';
    $digits = defined('PC_DIGITS') ? (int)PC_DIGITS : 5;
    if ($digits < 1) {
        return null;
    }
    if (!preg_match('/^' . preg_quote($prefix, '/') . '(\d{1,' . $digits . '})$/i', $tok, $m)) {
        return null;
    }
    return strtoupper($prefix) . str_pad($m[1], $digits, '0', STR_PAD_LEFT);
}

function ta_is_admin(): bool
{
    $u = current_user();
    return is_array($u) && ($u['role'] ?? '') === 'admin';
}

function ta_partner_row(array $r): array
{
    $b = (string)($r['business_no'] ?? '');
    return [
        'id'      => (int)$r['id'],
        'code'    => (string)($r['partner_code'] ?? ''),
        'name'    => (string)$r['name'],
        'biz'     => (strlen($b) === 10 && ctype_digit($b)) ? fmt_biz_no($b) : $b,
        'ceo'     => (string)($r['ceo_name'] ?? ''),
        'phone'   => (string)($r['phone'] ?? ''),
        'region'  => (string)($r['region'] ?? ''),
        'channel' => (string)($r['default_channel'] ?? ''),
        'ptype'   => (string)($r['partner_type'] ?? ''),
        'credit'  => (int)($r['is_credit'] ?? 0) === 1,
        'active'  => (int)($r['is_active'] ?? 1) === 1,
    ];
}

if (!current_user()) {
    ta_out(['ok' => false, 'msg' => '로그인이 만료되었습니다. 새로고침 후 다시 로그인하세요.']);
}

$do   = in_str($_GET, 'do', 12);
$type = in_str($_GET, 'type', 10) === 'purchase' ? 'purchase' : 'sale';

try {
    $pdo = db();

    // ---------- 품목 검색 ----------
    if ($do === 'items') {
        $q     = in_str($_GET, 'q', 50);
        $cat   = in_int($_GET, 'cat');
        $where = ['i.is_active = 1'];
        $args  = [];

        if ($cat > 0) {
            $kids = [];
            foreach ($pdo->query('SELECT id, parent_id FROM erp_categories') as $r) {
                $kids[(int)$r['parent_id']][] = (int)$r['id'];
            }
            $set   = [$cat => true];
            $stack = [$cat];
            while ($stack) {
                $p = array_pop($stack);
                foreach ($kids[$p] ?? [] as $c) {
                    if (!isset($set[$c])) {
                        $set[$c] = true;
                        $stack[] = $c;
                    }
                }
            }
            $ids     = array_keys($set);
            $where[] = 'i.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) {
                $args[] = $id;
            }
        }

        foreach (ta_tokens($q) as $tok) {
            $or = ['i.name LIKE ?', 'i.item_code LIKE ?', 'i.brand LIKE ?', 'i.pattern_name LIKE ?', 'i.spec LIKE ?'];
            $a  = array_fill(0, 5, ta_like($tok));
            $digits = preg_replace('/\D/', '', $tok);
            if ($digits !== '' && strlen($digits) >= 3) {
                $or[] = 'i.spec_code LIKE ?';
                $a[]  = ta_like($digits);
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
            foreach ($a as $v) {
                $args[] = $v;
            }
        }

        $st = $pdo->prepare(
            'SELECT i.id, i.item_code, i.name, i.spec, i.brand, i.pattern_name, i.category_id, i.tax_type,'
            . ' i.retail_price, i.wholesale_price, i.last_cost, i.is_stock_item'
            . ' FROM erp_items i WHERE ' . implode(' AND ', $where)
            . ' ORDER BY i.name, i.id LIMIT 31'
        );
        $st->execute($args);
        $rows = $st->fetchAll();
        $more = count($rows) > 30;
        $rows = array_slice($rows, 0, 30);

        $stock = [];
        if ($rows) {
            $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
            $st2 = $pdo->prepare(
                'SELECT item_id, SUM(qty) AS q FROM erp_stock_ledger WHERE item_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY item_id'
            );
            $st2->execute($ids);
            foreach ($st2 as $r) {
                $stock[(int)$r['item_id']] = ta_dec((string)$r['q']);
            }
        }

        // 원가는 매입 전표이거나 admin 일 때만 내려보냄
     $showCost = ta_is_admin();

        $out = [];
        foreach ($rows as $r) {
            $id    = (int)$r['id'];
            $out[] = [
                'id'         => $id,
                'code'       => (string)$r['item_code'],
                'name'       => (string)$r['name'],
                'spec'       => (string)$r['spec'],
                'brand'      => (string)$r['brand'],
                'pattern'    => (string)$r['pattern_name'],
                'tax'        => (string)$r['tax_type'],
                'retail'     => (int)$r['retail_price'],
                'wholesale'  => (int)$r['wholesale_price'],
                'cost'       => $showCost ? (int)$r['last_cost'] : 0,
                'stock_item' => (int)$r['is_stock_item'] === 1,
                'stock'      => $stock[$id] ?? '0',
            ];
        }
        ta_out(['ok' => true, 'items' => $out, 'more' => $more]);
    }

    // 거래처 컬럼 확인 (있는 것만 사용)
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM erp_partners')->fetchAll() as $c) {
        $cols[] = $c['Field'];
    }
    $hasCredit = in_array('is_credit', $cols, true);
    $hasCode   = in_array('partner_code', $cols, true);
    $hasRegion = in_array('region', $cols, true);
    $sel = 'id, name, business_no, ceo_name, phone, default_channel, partner_type, is_active'
         . ($hasCredit ? ', is_credit' : '')
         . ($hasCode ? ', partner_code' : '')
         . ($hasRegion ? ', region' : '');

    // ---------- 거래처 검색 ----------
    if ($do === 'partners') {
        $q     = in_str($_GET, 'q', 50);
        $okT   = $type === 'purchase' ? ['supplier', 'both'] : ['customer', 'both'];
        $where = ['is_active = 1', 'partner_type IN (?, ?)'];
        $args  = $okT;

        foreach (ta_tokens($q) as $tok) {
            $fields = ['name', 'ceo_name', 'phone'];
            foreach (['partner_code', 'ecount_code', 'search_keyword', 'mobile'] as $x) {
                if (in_array($x, $cols, true)) {
                    $fields[] = $x;
                }
            }
            $or = [];
            $a  = [];
            foreach ($fields as $f) {
                $or[] = $f . ' LIKE ?';
                $a[]  = ta_like($tok);
            }

            // C12 / C00012 같은 코드 입력: 정식 코드 일치 또는 앞부분 일치
            $codeTok = $hasCode ? ta_code_of($tok) : null;
            if ($codeTok !== null) {
                $or[] = 'partner_code = ?';
                $a[]  = $codeTok;
                $or[] = 'partner_code LIKE ?';
                $a[]  = ta_esc(strtoupper($tok)) . '%';
            }

            // 사업자번호 검색 (코드형 토큰은 제외 — 숫자 부분이 사업자번호와 섞이는 것을 방지)
            $digits = preg_replace('/\D/', '', $tok);
            if ($codeTok === null && $digits !== '' && strlen($digits) >= 3) {
                $or[] = 'business_no LIKE ?';
                $a[]  = ta_like($digits);
            }

            $where[] = '(' . implode(' OR ', $or) . ')';
            foreach ($a as $v) {
                $args[] = $v;
            }
        }

        $st = $pdo->prepare(
            'SELECT ' . $sel . ' FROM erp_partners WHERE ' . implode(' AND ', $where) . ' ORDER BY name, id LIMIT 21'
        );
        $st->execute($args);
        $rows = $st->fetchAll();
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $out  = [];
        foreach ($rows as $r) {
            $out[] = ta_partner_row($r);
        }
        ta_out(['ok' => true, 'partners' => $out, 'more' => $more]);
    }

    // ---------- 거래처 상세 (외상 여부 최신값 + 미수금) ----------
    if ($do === 'partner') {
        $id = in_int($_GET, 'id');
        $st = $pdo->prepare('SELECT ' . $sel . ' FROM erp_partners WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            ta_out(['ok' => false, 'msg' => '거래처를 찾을 수 없습니다.']);
        }
        $p      = ta_partner_row($r);
        $okT    = $type === 'purchase' ? ['supplier', 'both'] : ['customer', 'both'];
        $p['type_ok'] = in_array($p['ptype'], $okT, true);

        $bal = null;
        if ($type === 'sale' && $p['credit']) {
            $lib = __DIR__ . '/inc/ledger_lib.php';
            if (is_file($lib)) {
                require_once $lib;
            }
            if (function_exists('lg_balance')) {
                try {
                    $bal = (int)lg_balance($id);
                } catch (Throwable $e) {
                    error_log('[ERP] trade_api balance: ' . $e->getMessage());
                }
            }
        }
        ta_out(['ok' => true, 'partner' => $p, 'balance' => $bal]);
    }

    ta_out(['ok' => false, 'msg' => '알 수 없는 요청입니다.']);
} catch (Throwable $e) {
    error_log('[ERP] trade_api: ' . $e->getMessage());
    ta_out(['ok' => false, 'msg' => '검색 중 오류가 발생했습니다.' . (cfg('app.debug', false) ? ' ' . $e->getMessage() : '')]);
}
