<?php
// /order-complete.php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

if (!Auth::isLoggedIn()) redirect('/login.php');

$orderNo = $_GET['no'] ?? '';
if (!$orderNo) redirect('/index.php');

$pdo = Database::connection();

// tt_orders 조회: order_no, user_id, total_amount, payment_method, 결제상태, 현금영수증 요청 등
$stmt = $pdo->prepare('SELECT id, order_no, total_amount, payment_method, payment_status, cash_receipt_type, cash_receipt_no FROM tt_orders WHERE order_no = :no AND user_id = :uid');
$stmt->execute(['no' => $orderNo, 'uid' => Auth::currentUserId()]);
$order = $stmt->fetch();

if (!$order) redirect('/index.php');

/* [v45→v48] 선택한 결제수단 표시 — 공용 라벨(네이버페이·카카오페이 포함).
   [v48] 비현금(카드·간편결제)은 주문 접수와 동시에 결제완료 처리됨 */
$payMethodLabels = payment_method_labels();
$payMethod = $payMethodLabels[$order['payment_method'] ?? 'bank_transfer'] ?? '🏦 무통장입금';
$isCashPay = is_cash_payment_method((string)($order['payment_method'] ?? 'bank_transfer'));
$isPaidNow = !$isCashPay; // 비현금은 즉시 결제완료 처리 [v48]
$crType = (string)($order['cash_receipt_type'] ?? 'none');
$crNo   = (string)($order['cash_receipt_no'] ?? '');

// tt_order_items 조회: order_id, product_id, option_id, product_name, price, qty
$itemStmt = $pdo->prepare('SELECT product_name, qty FROM tt_order_items WHERE order_id = :id');
$itemStmt->execute(['id' => $order['id']]);
$items = $itemStmt->fetchAll();

$pageTitle = '주문완료';
require __DIR__ . '/includes/header.php';
?>
<style>
.oc-wrap{
  max-width:480px; margin:60px auto; padding:40px 32px;
  background:#fff; border-radius:20px; box-shadow:0 10px 30px rgba(0,0,0,.06);
  text-align:center;
}
.oc-icon{
  width:64px; height:64px; margin:0 auto 18px; border-radius:50%;
  background:linear-gradient(135deg,#6366f1,#8b5cf6);
  display:flex; align-items:center; justify-content:center;
  font-size:32px; color:#fff;
}
.oc-title{font-size:21px; font-weight:800; color:#1e293b; margin-bottom:8px}
.oc-orderno{font-size:13px; color:#94a3b8; margin-bottom:28px}
.oc-orderno strong{color:#6366f1; font-weight:700}
.oc-item-list{
  border-top:1px solid #f1f5f9; border-bottom:1px solid #f1f5f9;
  padding:18px 0; margin-bottom:24px; text-align:left;
}
.oc-item-row{
  display:flex; justify-content:space-between; align-items:center;
  padding:8px 4px; font-size:14.5px; color:#334155;
}
.oc-item-name{font-weight:600}
.oc-item-qty{color:#64748b; font-size:13.5px; white-space:nowrap; margin-left:12px}
.oc-actions{display:flex; gap:10px}
.oc-btn{
  flex:1; padding:13px 0; border-radius:999px; font-weight:700; font-size:14.5px;
  text-decoration:none; text-align:center; transition:transform .12s ease;
}
.oc-btn:hover{transform:translateY(-1px)}
.oc-btn-outline{background:#f1f5f9; color:#475569;}
.oc-btn-primary{background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; box-shadow:0 8px 18px rgba(99,102,241,.3);}
.oc-pay-box{
  border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc;
  padding:14px 16px; margin-bottom:24px; text-align:left;
}
.oc-pay-row{
  display:flex; justify-content:space-between; align-items:center;
  font-size:14px; color:#475569; padding:4px 0;
}
.oc-pay-row strong{color:#0f172a; font-weight:800;}
.oc-pay-note{
  margin-top:8px; font-size:12.5px; color:#64748b; line-height:1.7;
  border-top:1px dashed #cbd5e1; padding-top:8px;
}
.oc-pay-note b{color:#0f172a;}
</style>

<div class="oc-wrap">
  <div class="oc-icon">✓</div>
  <h1 class="oc-title">주문이 완료되었습니다</h1>
  <p class="oc-orderno">주문번호 <strong><?= h($order['order_no']) ?></strong></p>

  <div class="oc-item-list">
    <?php foreach ($items as $it): ?>
      <div class="oc-item-row">
        <span class="oc-item-name"><?= h($it['product_name']) ?></span>
        <span class="oc-item-qty"><?= (int)$it['qty'] ?>개</span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php /* [v45→v48] 결제수단 + 결제상태 + 현금영수증 요청 안내 */ ?>
  <div class="oc-pay-box">
    <div class="oc-pay-row"><span>결제수단</span><strong><?= h($payMethod) ?></strong></div>
    <div class="oc-pay-row"><span>결제하실 금액</span><strong><?= number_format((int)$order['total_amount']) ?>원</strong></div>
    <div class="oc-pay-row">
      <span>결제상태</span>
      <strong style="color:<?= $isPaidNow ? '#16a34a' : '#f59e0b' ?>;"><?= $isPaidNow ? '✅ 결제완료' : '⏳ 입금 확인 후 결제 확정' ?></strong>
    </div>
    <?php if ($crType !== 'none' && $crType !== ''): ?>
    <div class="oc-pay-row"><span>현금영수증</span><strong>🧾 <?= $crType === 'income' ? '소득공제' : '지출증빙' ?> (<?= h($crNo) ?>)</strong></div>
    <?php endif; ?>
    <?php if ($isCashPay): ?>
      <p class="oc-pay-note">아래 계좌로 입금해주시면 확인 후 상품을 준비합니다.<br><b>국민은행 123456-04-123456 (예금주: 타이어탑)</b><?php
        if ($crType === 'income'): ?><br>입금이 확인되면 소득공제 현금영수증이 <b>자동 발행</b>됩니다. (휴대폰 <?= h($crNo) ?>)
        <?php elseif ($crType === 'expense'): ?><br>입금 확인 후 지출증빙 현금영수증이 발행 처리됩니다. (사업자번호 <?= h($crNo) ?>)
        <?php endif; ?></p>
    <?php else: ?>
      <p class="oc-pay-note"><b>✅ 결제가 완료 처리되었습니다.</b><br><?= h(trim($payMethod)) ?> 결제는 PG(결제대행사) 연결 시 실제 승인 결제로 전환됩니다.</p>
    <?php endif; ?>
  </div>

  <div class="oc-actions">
    <a href="<?= BASE_URL ?>/mypage.php#orders" class="oc-btn oc-btn-outline">마이페이지에서 확인하기</a>
    <a href="<?= BASE_URL ?>/index.php" class="oc-btn oc-btn-primary">홈으로 돌아가기</a>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
