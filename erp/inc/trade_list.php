<?php
// inc/trade_list.php - 전표 목록 조회 조건 (trades.php, trades_excel.php 공용). 미실행 코드
// 전제: functions.php가 먼저 로드되어 있어야 함 (in_str, valid_date)

function tl_filters(array $get): array
{
    $type = in_str($get, 'type', 10);
    if (!in_array($type, ['sale', 'purchase'], true)) {
        $type = 'sale';
    }
    $status  = in_str($get, 'status', 12);
    $channel = in_str($get, 'channel', 10);
    $from    = in_str($get, 'from', 10);
    $to      = in_str($get, 'to', 10);
    if (!valid_date($from)) {
        $from = date('Y-m-01');
    }
    if (!valid_date($to)) {
        $to = date('Y-m-d');
    }
    $q = in_str($get, 'q', 50);

    $where = ['t.trade_type = ?', 't.trade_date BETWEEN ? AND ?'];
    $args  = [$type, $from, $to];
    if (in_array($status, ['draft', 'confirmed', 'canceled'], true)) {
        $where[] = 't.status = ?';
        $args[]  = $status;
    }
    if ($type === 'sale' && in_array($channel, ['retail', 'wholesale'], true)) {
        $where[] = 't.channel = ?';
        $args[]  = $channel;
    }
    if ($q !== '') {
        $like    = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(t.trade_no LIKE ? OR t.customer_name LIKE ? OR t.vehicle_no LIKE ? OR p.name LIKE ?)';
        array_push($args, $like, $like, $like, $like);
    }
    return [
        'type' => $type, 'status' => $status, 'channel' => $channel,
        'from' => $from, 'to' => $to, 'q' => $q,
        'where' => $where, 'args' => $args,
    ];
}
