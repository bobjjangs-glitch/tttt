<?php
// inc/trades_query.php — 전표 목록 조건/조회 공용
const TQ_LIST_MAX  = 300;
const TQ_EXCEL_MAX = 5000;

function tq_params(array $src): array
{
    $type = in_str($src, 'type', 10);
    if (!in_array($type, ['sale', 'purchase'], true)) {
        $type = 'sale';
    }
    $status = in_str($src, 'status', 10);
    if (!in_array($status, ['draft', 'confirmed', 'canceled'], true)) {
        $status = '';
    }
    $channel = in_str($src, 'channel', 10);
    if ($type !== 'sale' || !in_array($channel, ['retail', 'wholesale'], true)) {
        $channel = '';
    }
    $from = in_str($src, 'from', 10);
    if (!valid_date($from)) {
        $from = date('Y-m-01');
    }
    $to = in_str($src, 'to', 10);
    if (!valid_date($to)) {
        $to = date('Y-m-d');
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $q = in_str($src, 'q', 50);
    return ['type' => $type, 'status' => $status, 'channel' => $channel, 'from' => $from, 'to' => $to, 'q' => $q];
}

/** 쿼리스트링용 배열 (빈 값 제외) */
function tq_qs(array $p): array
{
    $o = ['type' => $p['type'], 'from' => $p['from'], 'to' => $p['to']];
    foreach (['status', 'channel', 'q'] as $k) {
        if ($p[$k] !== '') {
            $o[$k] = $p[$k];
        }
    }
    return $o;
}

function tq_fetch(PDO $pdo, array $p, int $limit): array
{
    $w = ['t.trade_type = ?', 't.trade_date BETWEEN ? AND ?'];
    $a = [$p['type'], $p['from'], $p['to']];
    if ($p['status'] !== '') {
        $w[] = 't.status = ?';
        $a[] = $p['status'];
    }
    if ($p['channel'] !== '') {
        $w[] = 't.channel = ?';
        $a[] = $p['channel'];
    }
    if ($p['q'] !== '') {
        $like = '%' . addcslashes($p['q'], '%_\\') . '%';
        $w[] = '(t.trade_no LIKE ? OR t.customer_name LIKE ? OR t.vehicle_no LIKE ? OR p.name LIKE ?)';
        array_push($a, $like, $like, $like, $like);
    }
    $sql = 'SELECT t.id, t.trade_no, t.trade_type, t.channel, t.trade_date, t.customer_name, t.vehicle_no,
                   t.supply_amount, t.vat_amount, t.total_amount, t.status, t.memo, p.name AS partner_name
              FROM erp_trades t
              LEFT JOIN erp_partners p ON p.id = t.partner_id
             WHERE ' . implode(' AND ', $w) . '
             ORDER BY t.trade_date DESC, t.id DESC
             LIMIT ' . (int)$limit;
    $st = $pdo->prepare($sql);
    $st->execute($a);
    return $st->fetchAll();
}
