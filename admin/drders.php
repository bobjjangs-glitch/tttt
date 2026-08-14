<?php
// /admin/orders.php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

if (!AdminAuth::isLoggedIn()) redirect('/admin/login.php');
$pdo = Database::connection();

$statusLabel = [
    'pending' => '결제대기', 'paid' => '결제완료', 'preparing' => '상품준비중',
    'shipped' => '배송중', 'done' => '배송완료', 'cancelled' => '취소',
];

// ---- 상태 변경 처리 ----
if (is_post() && ($_POST['action'] ?? '') === 'update_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/orders.php');
    }
    $orderId = (int)($_POST['order_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    if (!isset($statusLabel[$newStatus])) {
        flash('admin_error', '올바르지 않은 상태값입니다.');
        redirect('/admin/orders.php');
    }
    $pdo->prepare('UPDATE tt_orders SET status = :s WHERE id = :id')
        ->execute(['s' => $newStatus, 'id' => $orderId]);
    $pdo->prepare('INSERT INTO tt_order_status_logs (order_id, status, memo) VALUES (:id, :s, :memo)')
        ->execute(['id' => $orderId, 's' => $newStatus, 'memo' => '관리자 상태 변경']);
    flash('admin_success', '주문 상태가 변경되었습니다.');
    redirect('/admin/orders.php');
}

// ---- 필터 ----
$filterStatus = $_GET['status'] ?? '';
$where = '1=1';
$params = [];
if ($filterStatus !== '' && isset($statusLabel[$filterStatus])) {
    $where = 'status = :status';
    $params['status'] = $filterStatus;
}

$stmt = $pdo->prepare("
    SELECT id, order_no, recipient_name, recipient_phone, total_amount, status, created_at
    FROM tt_orders
    WHERE {$where}
    ORDER BY id DESC
    LIMIT 100
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

$pageTitle = '주문관리';
require __DIR__ . '/includes/header.php';
?>

<?php if ($msg = flash('admin_success')): ?>
  <div class="admin-panel" style="background:#f0fdf4;color:#15803d;font-size:13px;padding:14px 20px"><?= h($msg) ?></div>
<?php endif; ?>
<?php if ($err = flash('admin_error')): ?>
  <div class="admin-panel" style="background:#fef2f2;color:#b91c1c;font-size:13px;padding:14px 20px"><?= h($err) ?></div>
<?php endif; ?>

<div class="admin-panel">
  <h2>주문 목록</h2>

  <div style="margin-bottom:16px;display:flex;gap:8px">
    <a href="<?= BASE_URL ?>/admin/orders.php" class="admin-btn-sm" style="background:<?= $filterStatus==='' ? 'var(--primary)' : 'var(--gray1)' ?>">전체</a>
    <?php foreach ($statusLabel as $key => $label): ?>
      <a href="<?= BASE_URL ?>/admin/orders.php?status=<?= h($key) ?>" class="admin-btn-sm"
         style="background:<?= $filterStatus===$key ? 'var(--primary)' : 'var(--gray1)' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($orders): ?>
    <table class="admin-table">
      <thead>
        <tr><th>주문번호</th><th>받는분</th><th>연락처</th><th>금액</th><th>상태</th><th>주문일시</th><th>상태변경</th></tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><?= h($o['order_no']) ?></td>
            <td><?= h($o['recipient_name']) ?></td>
            <td><?= h($o['recipient_phone']) ?></td>
            <td><?= format_price((int)$o['total_amount']) ?></td>
            <td><span class="admin-badge badge-<?= h($o['status']) ?>"><?= h($statusLabel[$o['status']] ?? $o['status']) ?></span></td>
            <td><?= h(date('Y-m-d H:i', strtotime($o['created_at']))) ?></td>
            <td>
              <form method="post" action="<?= BASE_URL ?>/admin/orders.php" style="display:flex;gap:6px">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <?= Csrf::field() ?>
                <select name="status" class="admin-select-status">
                  <?php foreach ($statusLabel as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= $o['status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="admin-btn-sm">변경</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="admin-empty">해당 조건의 주문이 없습니다.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
