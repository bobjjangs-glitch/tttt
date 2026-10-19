<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('orders');

$pdo = Database::connection();

$statusLabels = [
    'pending'   => '주문접수',
    'paid'      => '결제완료',
    'preparing' => '상품준비중',
    'shipped'   => '배송중',
    'done'      => '배송완료',
    'cancelled' => '주문취소',
];

// 목록 화면과 동일한 필터 조건을 그대로 적용 (지금 화면에 보이는 조건 그대로 다운로드)
$statusFilter = $_GET['status'] ?? '';

$where = '1=1';
$params = [];
if ($statusFilter !== '' && array_key_exists($statusFilter, $statusLabels)) {
    $where .= ' AND status = :status';
    $params['status'] = $statusFilter;
}

$stmt = $pdo->prepare("SELECT order_no, recipient_name, recipient_phone, recipient_addr,
                               total_amount, shipping_fee, status, created_at
                        FROM tt_orders
                        WHERE {$where}
                        ORDER BY id DESC");
$stmt->execute($params);
$orders = $stmt->fetchAll();

AdminAuth::log((int)AdminAuth::currentAdminId(), 'order_export', '주문 목록 엑셀 다운로드 (' . count($orders) . '건)');

$filename = '주문목록_' . date('Ymd_His') . '.csv';

// 다운로드 응답 헤더
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// UTF-8 BOM — 이게 없으면 엑셀에서 한글이 깨져 보입니다.
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// 헤더 행
fputcsv($out, ['주문번호', '수령인', '연락처', '배송지', '상품금액', '배송비', '총결제금액', '주문상태', '주문일시']);

foreach ($orders as $o) {
    $productAmount = (int)$o['total_amount'] - (int)$o['shipping_fee'];
    fputcsv($out, [
        $o['order_no'],
        $o['recipient_name'],
        $o['recipient_phone'],
        $o['recipient_addr'],
        $productAmount,
        (int)$o['shipping_fee'],
        (int)$o['total_amount'],
        $statusLabels[$o['status']] ?? $o['status'],
        date('Y-m-d H:i', strtotime($o['created_at'])),
    ]);
}

fclose($out);
exit;
