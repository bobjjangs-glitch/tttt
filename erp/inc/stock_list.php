<?php
// 현재고 목록 조회 공용 함수 (stock.php 화면과 stock_excel.php 가 같은 조건을 쓰도록 분리)
// inc/functions.php (in_str, in_int) 가 먼저 로드되어 있어야 합니다.
require_once __DIR__ . '/cat_tree.php';

const STOCK_LIST_MAX   = 500;
const STOCK_EXPORT_MAX = 5000;

/**
 * 현재고 목록 조회.
 * @return array{rows: array, more: bool, q: string, neg: bool, cat: int}
 */
function stock_fetch(PDO $pdo, array $get, int $max): array
{
    $q   = in_str($get, 'q', 50);
    $neg = isset($get['neg']);
    $cat = in_int($get, 'cat');

    $where = ['i.is_stock_item = 1'];
    $args  = [];
    if ($q !== '') {
        $like    = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(i.name LIKE ? OR i.item_code LIKE ? OR i.spec LIKE ?)';
        array_push($args, $like, $like, $like);
    }
    if ($cat > 0) {
        $ids     = cat_descendant_ids($pdo, $cat);
        $where[] = 'i.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args    = array_merge($args, $ids);
    }

    // 주의: factory_price 는 현재 items.last_cost(최근 매입가)를 그대로 보여 줍니다.
    //       품목에 공장도가 컬럼이 따로 있으면 아래 i.last_cost 를 그 컬럼으로 바꾸세요.
    $sql = 'SELECT i.id, i.item_code, i.name, i.spec, i.category_id, i.last_cost AS factory_price,
                   COALESCE(SUM(l.qty), 0) AS qty
              FROM items i LEFT JOIN stock_ledger l ON l.item_id = i.id
             WHERE ' . implode(' AND ', $where) . ' GROUP BY i.id'
         . ($neg ? ' HAVING COALESCE(SUM(l.qty), 0) < 0' : '')
         . ' ORDER BY i.name LIMIT ' . ((int)$max + 1);

    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $more = count($rows) > $max;
    if ($more) {
        $rows = array_slice($rows, 0, $max);
    }
    return ['rows' => $rows, 'more' => $more, 'q' => $q, 'neg' => $neg, 'cat' => $cat];
}

/** DECIMAL(12,3) 문자열 → 엑셀용 숫자 문자열 ("10.000" → "10", "-2.500" → "-2.5") */
function stock_plain_qty($q): string
{
    $s = (string)$q;
    if (strpos($s, '.') !== false) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return ($s === '' || $s === '-') ? '0' : $s;
}

/** 금액 → 정수 문자열 (NULL 은 빈칸) */
function stock_plain_won($v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    return explode('.', (string)$v)[0];
}

/** 엑셀 수식 주입 방지: 문자열 셀이 = + - @ 로 시작하면 앞에 ' 를 붙임 */
function stock_csv_text($s): string
{
    $s = (string)$s;
    if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) {
        return "'" . $s;
    }
    return $s;
}
