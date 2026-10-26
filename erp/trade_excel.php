<?php
// trades_excel.php - 전표 목록 엑셀 다운로드 (조회 조건 동일 적용, 읽기 전용). 미실행 코드
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_once __DIR__ . '/inc/excel_io.php';
require_once __DIR__ . '/inc/trade_list.php';
$pdo = db();

$f = tl_filters($_GET);
$isSale = $f['type'] === 'sale';

$st = $pdo->prepare(
    'SELECT t.trade_no, t.trade_date, t.channel, t.customer_name, t.vehicle_no,
            t.supply_amount, t.vat_amount, t.total_amount, t.status, t.memo, p.name AS partner_name
       FROM trades t LEFT JOIN partners p ON p.id = t.partner_id
      WHERE ' . implode(' AND ', $f['where']) . '
      ORDER BY t.trade_date DESC, t.id DESC LIMIT ' . (int)XL_MAX_ROWS
);
$st->execute($f['args']);

$head = ['전표번호', '일자'];
if ($isSale) {
    $head[] = '구분';
}
array_push($head, '거래처/고객', '차량', '공급가액', '부가세', '합계', '상태', '비고');
$rows = [$head];

foreach ($st as $r) {
    $row = [(string)$r['trade_no'], (string)$r['trade_date']];
    if ($isSale) {
        $row[] = channel_label($r['channel']);
    }
    $name = ($r['partner_name'] ?? '') !== '' ? $r['partner_name'] : (($r['customer_name'] ?? '') !== '' ? $r['customer_name'] : '');
    array_push(
        $row,
        (string)$name, (string)$r['vehicle_no'],
        (int)$r['supply_amount'], (int)$r['vat_amount'], (int)$r['total_amount'],
        status_label((string)$r['status']), (string)$r['memo']
    );
    $rows[] = $row;
}

audit('trade_list_excel', 'trades', null, mb_substr($f['type'] . ' ' . $f['from'] . '~' . $f['to'] . ' ' . (count($rows) - 1) . '건', 0, 200));
xl_send($isSale ? 'sale_trades' : 'purchase_trades', $rows);
