<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requireLogin();

$pdo = Database::connection();

// 상태 라벨 매핑 (tt_orders.status 실제 enum: pending, paid, preparing, shipped, done, cancelled)
$statusLabels = [
    'pending'   => '주문접수',
    'paid'      => '결제완료',
    'preparing' => '상품준비중',
    'shipped'   => '배송중',
    'done'      => '배송완료',
    'cancelled' => '주문취소',
];

// 오늘 주문 수
$todayCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM tt_orders WHERE DATE(created_at) = CURDATE()"
)->fetchColumn();

// 처리 대기(pending) 주문 수
$pendingCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM tt_orders WHERE status = 'pending'"
)->fetchColumn();

// 이번 달 매출 합계 (취소 주문 제외)
$monthSales = (int)$pdo->query(
    "SELECT COALESCE(SUM(total_amount), 0) FROM tt_orders
     WHERE status != 'cancelled'
       AND YEAR(created_at) = YEAR(CURDATE())
       AND MONTH(created_at) = MONTH(CURDATE())"
)->fetchColumn();

// 활성 상품 수
$activeProductCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM tt_products WHERE status = 'active'"
)->fetchColumn();

// 최근 주문 5건
$recentOrders = $pdo->query(
    "SELECT id, order_no, recipient_name, total_amount, status, created_at
     FROM tt_orders
     ORDER BY id DESC
     LIMIT 5"
)->fetchAll();

$pageTitle = '대시보드';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-stat-grid">
  <div class="admin-stat-card">
    <div class="admin-stat-label">오늘 주문 수</div>
    <div class="admin-stat-value"><?= number_format($todayCount) ?>건</div>
  </div>
  <div class="admin-stat-card">
    <div class="admin-stat-label">처리 대기 주문</div>
    <div class="admin-stat-value <?= $pendingCount > 0 ? 'danger' : '' ?>"><?= number_format($pendingCount) ?>건</div>
  </div>
  <div class="admin-stat-card">
    <div class="admin-stat-label">이번 달 매출</div>
    <div class="admin-stat-value"><?= format_price($monthSales) ?></div>
  </div>
  <div class="admin-stat-card">
    <div class="admin-stat-label">판매 중 상품</div>
    <div class="admin-stat-value"><?= number_format($activeProductCount) ?>개</div>
  </div>
</div>

<div class="admin-card">
  <h2>최근 주문 5건</h2>
  <table class="admin-table">
    <thead>
      <tr>
        <th>주문번호</th>
        <th>수령인</th>
        <th>금액</th>
        <th>상태</th>
        <th>주문일시</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($recentOrders)): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--adm-text-sub);padding:24px 0">최근 주문이 없습니다.</td></tr>
      <?php else: foreach ($recentOrders as $o): ?>
        <tr>
          <td><?= h($o['order_no']) ?></td>
          <td><?= h($o['recipient_name']) ?></td>
          <td><?= format_price((int)$o['total_amount']) ?></td>
          <td><span class="status-badge status-<?= h($o['status']) ?>"><?= h($statusLabels[$o['status']] ?? $o['status']) ?></span></td>
          <td><?= h(date('Y-m-d H:i', strtotime($o['created_at']))) ?></td>
          <td><a href="<?= BASE_URL ?>/admin/orders.php?id=<?= (int)$o['id'] ?>">상세</a></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
