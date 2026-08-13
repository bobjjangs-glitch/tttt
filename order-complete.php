<?php
// /order-complete.php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

if (!Auth::isLoggedIn()) redirect('/login.php');

$orderNo = $_GET['no'] ?? '';
if (!$orderNo) redirect('/index.php');

$pdo = Database::connection();

// tt_orders 실제 컬럼: order_no, user_id, total_amount, shipping_fee, status,
//                      recipient_name, recipient_phone, recipient_addr, memo, created_at
$stmt = $pdo->prepare('SELECT * FROM tt_orders WHERE order_no = :no AND user_id = :uid');
$stmt->execute(['no' => $orderNo, 'uid' => Auth::currentUserId()]);
$order = $stmt->fetch();

if (!$order) redirect('/index.php');

// tt_order_items 실제 컬럼: order_id, product_id, option_id, product_name, price, qty
// (size_snap, subtotal 컬럼은 존재하지 않음 — subtotal은 price*qty로 화면에서 계산)
$itemStmt = $pdo->prepare('SELECT * FROM tt_order_items WHERE order_id = :id');
$itemStmt->execute(['id' => $order['id']]);
$items = $itemStmt->fetchAll();

$pageTitle = '주문완료';
require __DIR__ . '/includes/header.php';
?>
<div class="order-complete-wrap">
  <div class="complete-icon">✅</div>
  <h1>주문이 완료되었습니다</h1>
  <p class="order-no">주문번호: <strong><?= h($order['order_no']) ?></strong></p>

  <table class="order-item-table">
    <thead>
      <tr><th>상품</th><th>수량</th><th>금액</th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <?php $lineTotal = (int)$it['price'] * (int)$it['qty']; ?>
        <tr>
          <td><?= h($it['product_name']) ?></td>
          <td><?= (int)$it['qty'] ?>개</td>
          <td><?= format_price($lineTotal) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p class="order-total">총 결제금액: <strong><?= format_price((int)$order['total_amount']) ?></strong></p>

  <div class="order-recipient-info">
    <p><strong>받는분</strong> <?= h($order['recipient_name']) ?> (<?= h($order['recipient_phone']) ?>)</p>
    <p><strong>배송지</strong> <?= h($order['recipient_addr']) ?></p>
    <?php if (!empty($order['memo'])): ?>
      <p><strong>배송메모</strong> <?= h($order['memo']) ?></p>
    <?php endif; ?>
  </div>

  <div class="payment-notice">
    <p><strong>무통장입금 안내</strong></p>
    <p>국민은행 123456-04-123456 (예금주: 타이어탑)</p>
    <p>입금 확인 후 상품 준비가 시작됩니다. 마이페이지 &gt; 주문내역에서 진행상황을 확인하실 수 있습니다.</p>
  </div>

  <div class="complete-actions">
    <a href="<?= BASE_URL ?>/mypage.php#orders" class="btn-outline">주문내역 보기</a>
    <a href="<?= BASE_URL ?>/index.php" class="btn-primary">홈으로</a>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
