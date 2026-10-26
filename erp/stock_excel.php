<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
require __DIR__ . '/inc/stock_list.php';

$cats = category_paths(false);
$res  = stock_fetch(db(), $_GET, STOCK_EXPORT_MAX);

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="stock_' . date('Ymd_His') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // 엑셀 한글 깨짐 방지(UTF-8 BOM)

// PHP 8.4 에서는 fputcsv 의 escape 인자를 생략하면 경고가 나므로 명시합니다.
fputcsv($out, ['품목코드', '품목명', '분류', '규격', '현재고', '공장도가'], ',', '"', '', "\n");
foreach ($res['rows'] as $r) {
    fputcsv($out, [
        stock_csv_text($r['item_code']),
        stock_csv_text($r['name']),
        stock_csv_text($cats[(int)$r['category_id']] ?? ''),
        stock_csv_text($r['spec']),
        stock_plain_qty($r['qty']),
        stock_plain_won($r['factory_price']),
    ], ',', '"', '', "\n");
}
if ($res['more']) {
    fputcsv($out, ['※ ' . STOCK_EXPORT_MAX . '건까지만 내려받았습니다. 검색어로 좁혀 주세요.'], ',', '"', '', "\n");
}
fclose($out);
