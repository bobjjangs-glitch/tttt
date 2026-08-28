<?php
// /order-complete.php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

if (!Auth::isLoggedIn()) redirect('/login.php');

$orderNo = $_GET['no'] ?? '';
if (!$orderNo) redirect('/index.php');

$pdo = Database::connection();

// tt_orders 조회: order_no, user_id, total_amount, shipping_fee, discount_amount, status,
//                recipient_name, recipient_phone, recipient_addr, memo, created_at 등
$stmt = $pdo->prepare('SELECT id, order_no, total_amount FROM tt_orders WHERE order_no = :no AND user_id = :uid');
$stmt->execute(['no' => $orderNo, 'uid' => Auth::currentUserId()]);
$order = $stmt->fetch();

if (!$order) redirect('/index.php');

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

  <div class="oc-actions">
    <a href="<?= BASE_URL ?>/mypage.php#orders" class="oc-btn oc-btn-outline">마이페이지에서 확인하기</a>
    <a href="<?= BASE_URL ?>/index.php" class="oc-btn oc-btn-primary">홈으로 돌아가기</a>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
