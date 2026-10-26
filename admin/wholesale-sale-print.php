<?php
// admin/wholesale-sale-print.php — 일반 도매매출 세금계산서 인쇄 [v43]
// 국세청 표준 전자세금계산서 양식(공용 렌더러)으로 출력 — 인쇄 시 발행 완료 처리
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../includes/tax-invoice-form.php';
AdminAuth::requirePermission('wholesale-sales');
ensure_wholesale_sales_table();

$pdo = Database::connection();
$sid = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM tt_wholesale_sales WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $sid]);
$sale = $stmt->fetch();

if (!$sale) {
    flash('admin_error', '해당 도매매출 기록을 찾을 수 없습니다.');
    redirect('/admin/wholesale-sales.php');
}

/* 인쇄 = 발행 완료 처리 (정산 마감의 세금계산서 발행 건수에 반영) */
if ((int)$sale['tax_invoice_issued'] !== 1) {
    try {
        $pdo->prepare('UPDATE tt_wholesale_sales SET tax_invoice_issued = 1 WHERE id = :id')
            ->execute(['id' => $sid]);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'wholesale_tax_invoice',
            '일반 도매매출 세금계산서 발행 처리 — 거래처 ' . $sale['client_name']);
    } catch (Throwable $e) {
        sg_error_log('[wholesale-sale-print:mark] ' . $e->getMessage());
    }
}

/* 금액 — 저장된 공급가액/세액 사용 (미기록 시 10% 분리 복원) */
$supply = (int)$sale['supply_amount'];
$vat = (int)$sale['vat_amount'];
$total = (int)$sale['total_amount'];
if ($supply <= 0 && $total > 0) {
    $supply = (int)round($total / 1.1);
    $vat = $total - $supply;
}

$mon = date('m', strtotime((string)$sale['sale_date']));
$day = date('d', strtotime((string)$sale['sale_date']));

/* 승인번호 표기 — 전자세금계산서 형식 참고: 작성일자 + 일련 (내부 대체 키) */
$approvalNo = date('Ymd', strtotime((string)$sale['sale_date'])) . '41' . str_pad((string)$sid, 8, '0', STR_PAD_LEFT)
    . 'w' . str_pad((string)($sid % 1000000), 6, '0', STR_PAD_LEFT);

/* 수금납 구분 — 현금/계좌이체=현금, 미결제=외상미수금 (카드는 표기 없음) */
$payKind = null;
if (($sale['pay_method'] ?? '') === 'cash' || ($sale['pay_method'] ?? '') === 'transfer') $payKind = 'cash';
elseif (($sale['pay_method'] ?? '') === 'unpaid') $payKind = 'credit';

render_tax_invoice_form([
    'supplier' => get_supplier_info(),
    'buyer' => [
        'biz_number' => (string)($sale['client_biz_number'] ?? ''),
        'biz_name'   => (string)$sale['client_name'],
        'ceo'        => (string)($sale['client_ceo'] ?? ''),
        'address'    => (string)($sale['client_address'] ?? ''),
        'uptae'      => '',
        'jongmok'    => '',
    ],
    'approval_no' => $approvalNo,
    'write_date'  => (string)$sale['sale_date'],
    'items' => [[
        'mon' => $mon, 'day' => $day,
        'name' => (string)$sale['item_name'],
        'spec' => (string)($sale['spec'] ?? ''),
        'qty' => (int)$sale['qty'],
        'unit_price' => (int)$sale['unit_price'],
        'supply' => $supply,
        'vat' => $vat,
        'memo' => '',
    ]],
    'supply_total' => $supply,
    'vat_total'    => $vat,
    'total_amount' => $total,
    'pay_kind'    => $payKind,
    'meta_printer' => AdminAuth::currentAdminName(),
    'print_buttons' => true,
    'back_url'    => BASE_URL . '/admin/wholesale-sales.php?date=' . urlencode((string)$sale['sale_date']),
    'site_note'   => false,
]);
