<?php
// admin/tax-invoice-print.php — 세금계산서 인쇄 화면 [v43: 국세청 표준 전자세금계산서 양식 + 팩스 표지]
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../includes/tax-invoice-form.php';
AdminAuth::requirePermission('tax-invoices');
ensure_tax_invoice_table();
ensure_tax_invoice_fax_schema();

$pdo = Database::connection();
$invId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT t.*, u.name AS user_name FROM tt_tax_invoices t LEFT JOIN tt_users u ON u.id = t.user_id WHERE t.id = :id LIMIT 1');
$stmt->execute(['id' => $invId]);
$inv = $stmt->fetch();

if (!$inv) {
    flash('admin_error', '해당 세금계산서 요청을 찾을 수 없습니다.');
    redirect('/admin/tax-invoices.php');
}

$supplier = get_supplier_info();

// 팩스 발송용 파라미터 (fax=1 → 팩스 표지 포함 / to=수신 팩스번호 / autoprint=1 → 자동 인쇄)
$faxMode   = isset($_GET['fax']);
$faxTo     = normalize_fax_number((string)($_GET['to'] ?? ''));
$autoPrint = isset($_GET['autoprint']);

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
$itemCount = max(1, count($items));
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>세금계산서 <?= h($inv['req_no']) ?></title>
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Pretendard','Noto Sans KR','Apple SD Gothic Neo',sans-serif;background:#f1f5f9;padding:24px;color:#111;}
  .fax-banner{max-width:860px;margin:0 auto 14px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:10px;padding:12px 16px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
  .fax-cover{max-width:860px;margin:0 auto 24px;background:#fff;border:2px solid #0f172a;padding:36px 40px;page-break-after:always;break-after:page;}
  .fax-cover h2{font-size:22px;font-weight:900;letter-spacing:8px;text-align:center;margin:0 0 6px;}
  .fax-cover .cover-sub{text-align:center;font-size:12px;color:#64748b;margin:0 0 22px;letter-spacing:1px;}
  .fax-cover table{margin:0;width:100%;border-collapse:collapse;}
  .fax-cover th{background:#0f172a;color:#fff;width:120px;font-size:13px;border:1px solid #94a3b8;padding:7px 9px;}
  .fax-cover td{font-size:13px;font-weight:600;border:1px solid #94a3b8;padding:7px 9px;}
  .fax-cover .cover-note{margin-top:18px;font-size:12px;color:#475569;line-height:1.8;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:12px 16px;}
  @media print{ body{background:#fff;padding:0;} .fax-banner{display:none;} }
</style>
</head>
<body>
<?php if ($faxMode): ?>
<div class="fax-banner">📠 팩스 발송 모드 — 1페이지(팩스 표지) + 2페이지(전자세금계산서 양식)가 함께 출력됩니다. 인쇄 후 팩스기에 넣어 발송해주세요.<?= $faxTo !== '' ? ' (수신: ' . h(format_fax_number($faxTo)) . ')' : '' ?></div>

<div class="fax-cover">
  <h2>팩스 송신 통지서</h2>
  <p class="cover-sub">FAX TRANSMITTAL</p>
  <table>
    <tr>
      <th>수신처</th>
      <td><?= h($inv['buyer_biz_name']) ?> (대표: <?= h($inv['buyer_ceo']) ?>)</td>
      <th>발신처</th>
      <td><?= h($supplier['biz_name']) ?> (대표: <?= h($supplier['ceo']) ?>)</td>
    </tr>
    <tr>
      <th>수신 팩스</th>
      <td><?= $faxTo !== '' ? h(format_fax_number($faxTo)) : h(!empty($inv['fax_number']) ? format_fax_number((string)$inv['fax_number']) : '-') ?></td>
      <th>발신 팩스</th>
      <td><?= h(format_fax_number(get_setting('tax_fax_sender', '')) ?: '-') ?></td>
    </tr>
    <tr>
      <th>전달 사항</th>
      <td colspan="3">요청하신 세금계산서 (<?= h($inv['req_no']) ?>)를 송부합니다. 총 <?= $itemCount ?>개 품목, 합계 <?= number_format($inv['amount']) ?>원 (공급가액 <?= number_format($supplyValue) ?>원 + 부가세 <?= number_format($vat) ?>원)입니다.</td>
    </tr>
    <tr>
      <th>발신일</th>
      <td><?= h(date('Y년 m월 d일')) ?></td>
      <th>문의</th>
      <td><?= h($supplier['biz_name']) ?><?= $inv['contact_phone'] ? ' · ' . h($inv['contact_phone']) : '' ?></td>
    </tr>
  </table>
  <p class="cover-note">
    ▪ 다음 장에 전자세금계산서 양식이 첨부되어 있습니다.<br>
    ▪ 수신 확인 후 회신 필요 시 위 문의처로 연락 바랍니다.<br>
    ▪ 본 팩스는 <?= h($supplier['biz_name']) ?>에서 발송되었습니다. (사업자번호: <?= h($supplier['biz_number']) ?> / <?= h($supplier['address']) ?>)
  </p>
</div>
<?php endif; ?>
<?php
render_tax_invoice_form([
    'supplier' => $supplier,
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
    'meta_printer' => AdminAuth::currentAdminName(),
    'print_buttons' => true,
    'standalone'  => false,
    'site_note'   => true,
]);
?>
<?php if ($autoPrint): ?>
<script>
window.addEventListener('load', function(){
  setTimeout(function(){ window.print(); }, 300);
});
</script>
<?php endif; ?>
</body>
</html>
