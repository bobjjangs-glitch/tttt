<?php
// item_lookup.php — 품목 검색(JSON, 읽기 전용): ?q=검색어
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function il_out(array $d): void
{
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** DECIMAL 문자열("10.000", "-2.500") → 표시용 ("10", "-2.5"). float 미사용 */
function il_dec(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '0';
    }
    $neg = $s[0] === '-';
    if ($neg || $s[0] === '+') {
        $s = substr($s, 1);
    }
    $parts = explode('.', $s, 2);
    $whole = ltrim($parts[0], '0');
    if ($whole === '') {
        $whole = '0';
    }
    $frac = rtrim($parts[1] ?? '', '0');

    $out = number_format((int)$whole);
    if ($frac !== '') {
        $out .= '.' . $frac;
    }
    if ($neg && $out !== '0') {
        $out = '-' . $out;
    }
    return $out;
}

try {
    $q = in_str($_GET, 'q', 50);

    $w = ['i.is_active = 1'];
    $a = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $or   = ['i.name LIKE ?', 'i.item_code LIKE ?', 'i.spec LIKE ?', 'i.brand LIKE ?', 'i.pattern_name LIKE ?'];
        $a    = [$like, $like, $like, $like, $like];
        $digits = preg_replace('/\D/', '', $q);
        if (strlen($digits) >= 3) {
            $or[] = 'i.spec_code LIKE ?';
            $a[]  = '%' . $digits . '%';
        }
        $w[] = '(' . implode(' OR ', $or) . ')';
    }

    $sql = 'SELECT i.id, i.item_code, i.name, i.brand, i.pattern_name, i.spec, i.unit, i.tax_type,
                   i.retail_price, i.wholesale_price, i.last_cost, i.is_stock_item
              FROM erp_items i
             WHERE ' . implode(' AND ', $w) . '
             ORDER BY i.name, i.id
             LIMIT 31';
    $st = $pdo->prepare($sql);
    $st->execute($a);
    $rows = $st->fetchAll();

    $more = count($rows) > 30;
    $rows = array_slice($rows, 0, 30);

    // 현재고 (실패해도 검색은 유지). SUM(decimal)은 문자열로 받는다.
    $stock   = [];
    $stockOk = true;
    if ($rows) {
        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s2 = $pdo->prepare("SELECT item_id, SUM(qty) AS s FROM erp_stock_ledger WHERE item_id IN ($in) GROUP BY item_id");
            $s2->execute($ids);
            foreach ($s2->fetchAll() as $r) {
                $stock[(int)$r['item_id']] = (string)$r['s'];
            }
        } catch (Throwable $e) {
            error_log('[ERP] item_lookup stock: ' . $e->getMessage());
            $stockOk = false;
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $iid     = (int)$r['id'];
        $isStock = (int)($r['is_stock_item'] ?? 0) === 1;
        $out[] = [
            'id'              => $iid,
            'item_code'       => (string)$r['item_code'],
            'name'            => (string)$r['name'],
            'brand'           => (string)($r['brand'] ?? ''),
            'pattern_name'    => (string)($r['pattern_name'] ?? ''),
            'spec'            => (string)($r['spec'] ?? ''),
            'unit'            => (string)($r['unit'] ?? ''),
            'tax_type'        => (string)($r['tax_type'] ?? ''),
            'retail_price'    => (int)($r['retail_price'] ?? 0),
            'wholesale_price' => (int)($r['wholesale_price'] ?? 0),
            'factory_price'   => (int)($r['last_cost'] ?? 0),   // 화면 표기: 공장도가
            'is_stock_item'   => $isStock,
            'stock'           => ($isStock && $stockOk) ? il_dec($stock[$iid] ?? '0') : null,
        ];
    }
    il_out(['ok' => true, 'items' => $out, 'more' => $more]);
} catch (Throwable $e) {
    error_log('[ERP] item_lookup: ' . get_class($e) . ': ' . $e->getMessage());
    il_out(['ok' => false, 'error' => '품목 조회 중 오류가 발생했습니다.']);
}
