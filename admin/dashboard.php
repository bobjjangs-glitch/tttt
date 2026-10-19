<?php
// /admin/dashboard.php
require __DIR__ . '/../includes/admin_layout_top.php';

$pdo = Database::connection();
$todaySales = $pdo->query("SELECT COALESCE(SUM(total_amount),0) AS s FROM orders WHERE DATE(created_at) = CURDATE() AND status != 'canceled'")->fetch()['s'];
$todayOrders = $pdo->query("SELECT COUNT(*) AS c FROM orders WHERE DATE(created_at) = CURDATE()")->fetch()['c'];
$pendingOrders = $pdo->query("SELECT COUNT(*) AS c FROM orders WHERE status = 'pending'")->fetch()['c'];
$totalUsers = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE status = 'active'")->fetch()['c'];
?>
<h1>대시보드</h1>
<div class="dash-cards">
  <div class="dash-card"><h3>오늘 매출</h3><p><?= format_price((int)$todaySales) ?></p></div>
  <div class="dash-card"><h3>오늘 주문</h3><p><?= (int)$todayOrders ?>건</p></div>
  <div class="dash-card"><h3>결제대기</h3><p><?= (int)$pendingOrders ?>건</p></div>
  <div class="dash-card"><h3>전체회원</h3><p><?= (int)$totalUsers ?>명</p></div>
</div>
<?php require __DIR__ . '/../includes/admin_layout_bottom.php'; ?>
