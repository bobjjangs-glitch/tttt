<?php
// tax-invoice-view.php — 내 세금계산서 인쇄 (회원 전용 · 발행완료 건만) [v43: 국세청 표준 전자세금계산서 양식]
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/includes/tax-invoice-form.php';
ensure_tax_invoice_table();
ensure_tax_invoice_fax_schema();

if (!Auth::isLoggedIn()) {
    flash('error', '로그인이 필요합니다.');
    redirect('/login.php');
}

$uid = Auth::currentUserId();
$invId = (int)($_GET['id'] ?? 0);

$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT * FROM tt_tax_invoices WHERE id = :id AND user_id = :uid LIMIT 1');
$stmt->execute(['id' => $invId, 'uid' => $uid]);
$inv = $stmt->fetch();

if (!$inv) {
    flash('error', '해당 세금계산서 요청을 찾을 수 없습니다.');
    redirect('/tax-invoice.php');
}
if ($inv['status'] !== 'issued') {
    flash('error', '발행 완료된 세금계산서만 인쇄할 수 있습니다.');
    redirect('/tax-invoice.php');
}

/* 표준 양식 데이터 조립 — 품목의 월/일은 원본 주문/예약 일자에서 */
$items = [];
$baseDate = $inv['issued_at'] ?: date('Y-m-d H:i:s');
if ($inv['target_type'] === 'order') {
    $it = $pdo->prepare('SELECT o.created_at AS base_date, oi.product_name, oi.qty, oi.price
                        FROM tt_orders o JOIN tt_order_items oi ON oi.order_id = o.id
                        WHERE o.id = :id ORDER BY oi.id ASC');
    $it->execute(['id' => $inv['target_id']]);
    $rows = $it->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) $baseDate = $rows[0]['base_date'] ?: $baseDate;
    foreach ($rows as $r) {
        $lineTotal = (int)$r['price'] * (int)$r['qty'];
        $lineSupply = (int)round($lineTotal / 1.1);
        $items[] = [
            'mon' => date('m', strtotime($baseDate)), 'day' => date('d', strtotime($baseDate)),
            'name' => (string)$r['product_name'], 'spec' => '', 'qty' => (int)$r['qty'],
            'unit_price' => (int)$r['price'], 'supply' => $lineSupply, 'vat' => $lineTotal - $lineSupply,
            'memo' => '',
        ];
    }
} else {
    $r = $pdo->prepare('SELECT visit_date, created_at, product_name_snap, size_snap, qty, unit_price FROM tt_reservations WHERE id = :id');
    $r->execute(['id' => $inv['target_id']]);
    $resv = $r->fetch();
    if ($resv) {
        $baseDate = $resv['visit_date'] ?: ($resv['created_at'] ?: $baseDate);
        $lineTotal = (int)$resv['unit_price'] * (int)$resv['qty'];
        $lineSupply = (int)round($lineTotal / 1.1);
        $items[] = [
            'mon' => date('m', strtotime($baseDate)), 'day' => date('d', strtotime($baseDate)),
            'name' => (string)$resv['product_name_snap'], 'spec' => (string)($resv['size_snap'] ?? ''),
            'qty' => (int)$resv['qty'], 'unit_price' => (int)$resv['unit_price'],
            'supply' => $lineSupply, 'vat' => $lineTotal - $lineSupply, 'memo' => '',
        ];
    }
}

$supplyValue = (int)round($inv['amount'] / 1.1);
$vat = (int)$inv['amount'] - $supplyValue;
$writeDate = date('Y-m-d', strtotime($baseDate));

$userName = Auth::currentUserName();

render_tax_invoice_form([
    'supplier' => get_supplier_info(),
    'buyer' => [
        'biz_number' => (string)$inv['buyer_biz_number'],
        'biz_name'   => (string)$inv['buyer_biz_name'],
        'ceo'        => (string)$inv['buyer_ceo'],
        'address'    => (string)$inv['buyer_address'],
        'uptae'      => (string)($inv['buyer_uptae'] ?? ''),
        'jongmok'    => (string)($inv['buyer_jongmok'] ?? ''),
    ],
    'approval_no' => (string)$inv['req_no'],
    'write_date'  => $writeDate,
    'items'       => $items,
    'supply_total' => $supplyValue,
    'vat_total'    => $vat,
    'total_amount' => (int)$inv['amount'],
    'pay_kind'    => null,
    'meta_printer' => $userName,
    'print_buttons' => true,
    'back_url'    => BASE_URL . '/tax-invoice.php',
    'site_note'   => true,
]);
