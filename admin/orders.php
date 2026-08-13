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

function admin_delete_orders(PDO $pdo, array $ids): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (empty($ids)) return 0;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM tt_order_status_logs WHERE order_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM tt_order_items WHERE order_id IN ({$placeholders})")->execute($ids);
        $stmt = $pdo->prepare("DELETE FROM tt_orders WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        $affected = $stmt->rowCount();
        $pdo->commit();
        return $affected;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[admin/orders delete] ' . $e->getMessage());
        return -1;
    }
}

if (is_post() && ($_POST['form_type'] ?? '') === 'change_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/orders.php?id=' . (int)($_POST['order_id'] ?? 0));
    }

    $orderId   = (int)($_POST['order_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    $memo      = trim($_POST['memo'] ?? '');

    if (!array_key_exists($newStatus, $statusLabels)) {
        flash('admin_error', '올바르지 않은 상태값입니다.');
        redirect('/admin/orders.php?id=' . $orderId);
    }

    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare('UPDATE tt_orders SET status = :s WHERE id = :id');
        $upd->execute(['s' => $newStatus, 'id' => $orderId]);

        $log = $pdo->prepare('INSERT INTO tt_order_status_logs (order_id, status, memo) VALUES (:oid, :s, :memo)');
        $log->execute([
            'oid'  => $orderId,
            's'    => $newStatus,
            'memo' => $memo !== '' ? $memo : ($statusLabels[$newStatus] . '로 변경'),
        ]);

        AdminAuth::log((int)AdminAuth::currentAdminId(), 'order_status_change', "주문#{$orderId} 상태를 {$statusLabels[$newStatus]}로 변경");
        $pdo->commit();
        flash('admin_success', '주문 상태가 변경되었습니다.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[admin/orders] ' . $e->getMessage());
        flash('admin_error', '상태 변경 중 오류가 발생했습니다.');
    }

    redirect('/admin/orders.php?id=' . $orderId);
}

if (is_post() && ($_POST['form_type'] ?? '') === 'delete_one') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/orders.php');
    }
    $orderId = (int)($_POST['order_id'] ?? 0);
    $result = admin_delete_orders($pdo, [$orderId]);
    if ($result > 0) {
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'order_delete', "주문#{$orderId} 삭제");
        flash('admin_success', '주문이 삭제되었습니다.');
    } else {
        flash('admin_error', '주문 삭제 중 오류가 발생했습니다.');
    }
    redirect('/admin/orders.php');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'bulk_delete') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/orders.php');
    }
    $ids = $_POST['order_ids'] ?? [];
    $result = admin_delete_orders($pdo, is_array($ids) ? $ids : []);
    if ($result > 0) {
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'order_bulk_delete', "{$result}건 일괄 삭제");
        flash('admin_success', "{$result}건의 주문이 삭제되었습니다.");
    } elseif ($result === 0) {
        flash('admin_error', '선택된 주문이 없습니다.');
    } else {
        flash('admin_error', '주문 삭제 중 오류가 발생했습니다.');
    }
    redirect('/admin/orders.php');
}

$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($detailId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM tt_orders WHERE id = :id');
    $stmt->execute(['id' => $detailId]);
    $order = $stmt->fetch();

    if (!$order) {
        flash('admin_error', '존재하지 않는 주문입니다.');
        redirect('/admin/orders.php');
    }

    $itemStmt = $pdo->prepare('SELECT * FROM tt_order_items WHERE order_id = :id');
    $itemStmt->execute(['id' => $detailId]);
    $items = $itemStmt->fetchAll();

    $logStmt = $pdo->prepare('SELECT * FROM tt_order_status_logs WHERE order_id = :id ORDER BY id DESC');
    $logStmt->execute(['id' => $detailId]);
    $logs = $logStmt->fetchAll();

    $pageTitle = '주문 상세 - ' . $order['order_no'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="admin-detail-head">
      <a href="<?= BASE_URL ?>/admin/orders.php" class="admin-back-link">← 목록으로</a>
      <form method="post" onsubmit="return confirm('정말 이 주문을 삭제하시겠습니까?\n삭제된 주문은 복구할 수 없습니다.');" style="display:inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_type" value="delete_one">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <button type="submit" class="btn-admin-danger">🗑 주문 삭제</button>
      </form>
    </div>

    <div class="admin-card">
      <h2>주문 정보</h2>
      <table class="admin-table admin-table-kv">
        <tr><th style="width:160px">주문번호</th><td><?= h($order['order_no']) ?></td></tr>
        <tr><th>주문상태</th><td><span class="status-badge status-<?= h($order['status']) ?>"><?= h($statusLabels[$order['status']] ?? $order['status']) ?></span></td></tr>
        <tr><th>수령인</th><td><?= h($order['recipient_name']) ?></td></tr>
        <tr><th>연락처</th><td><?= h($order['recipient_phone']) ?></td></tr>
        <tr><th>배송지</th><td><?= h($order['recipient_addr']) ?></td></tr>
        <tr><th>상품금액</th><td><?= format_price((int)$order['total_amount'] - (int)$order['shipping_fee']) ?></td></tr>
        <tr><th>배송비</th><td><?= format_price((int)$order['shipping_fee']) ?></td></tr>
        <tr><th>총 결제금액</th><td><strong><?= format_price((int)$order['total_amount']) ?></strong></td></tr>
        <tr><th>주문일시</th><td><?= h(date('Y-m-d H:i', strtotime($order['created_at']))) ?></td></tr>
      </table>
    </div>

    <div class="admin-card">
      <h2>주문 상품</h2>
      <table class="admin-table">
        <thead><tr><th>상품명</th><th>단가</th><th>수량</th><th>소계</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= h($it['product_name']) ?></td>
            <td><?= format_price((int)$it['price']) ?></td>
            <td><?= (int)$it['qty'] ?></td>
            <td><?= format_price((int)$it['price'] * (int)$it['qty']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="admin-card">
      <h2>상태 변경</h2>
      <form method="post" class="admin-status-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_type" value="change_status">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <select name="status">
          <?php foreach ($statusLabels as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $key === $order['status'] ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="memo" placeholder="메모 (선택)" style="flex:1">
        <button type="submit" class="btn-admin-primary">변경</button>
      </form>
    </div>

    <div class="admin-card">
      <h2>상태 변경 이력</h2>
      <table class="admin-table">
        <thead><tr><th>상태</th><th>메모</th><th>일시</th></tr></thead>
        <tbody>
        <?php if (empty($logs)): ?>
          <tr><td colspan="3" style="text-align:center;color:var(--adm-text-sub)">이력이 없습니다.</td></tr>
        <?php else: foreach ($logs as $lg): ?>
          <tr>
            <td><span class="status-badge status-<?= h($lg['status']) ?>"><?= h($statusLabels[$lg['status']] ?? $lg['status']) ?></span></td>
            <td><?= h($lg['memo']) ?></td>
            <td><?= h(date('Y-m-d H:i', strtotime($lg['created_at']))) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];
if ($statusFilter !== '' && array_key_exists($statusFilter, $statusLabels)) {
    $where .= ' AND status = :status';
    $params['status'] = $statusFilter;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_orders WHERE {$where}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare("SELECT id, order_no, recipient_name, recipient_phone, total_amount, status, created_at
                            FROM tt_orders WHERE {$where}
                            ORDER BY id DESC LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue('offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$orders = $listStmt->fetchAll();

$totalPages = max(1, (int)ceil($totalCount / $perPage));

$pageTitle = '주문 관리';
require __DIR__ . '/includes/header.php';
?>
<form method="post" id="bulkDeleteForm" onsubmit="return confirm('선택한 주문을 정말 삭제하시겠습니까?\n삭제된 주문은 복구할 수 없습니다.');">
<?= Csrf::field() ?>
<input type="hidden" name="form_type" value="bulk_delete">

<div class="admin-toolbar">
  <form method="get" class="admin-filter-form">
    <select name="status" onchange="this.form.submit()">
      <option value="">전체 상태</option>
      <?php foreach ($statusLabels as $key => $label): ?>
        <option value="<?= h($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <div class="admin-toolbar-right">
    <a href="<?= BASE_URL ?>/admin/orders_export.php?status=<?= h($statusFilter) ?>" class="btn-admin-excel">📊 엑셀 다운로드</a>
    <button type="submit" form="bulkDeleteForm" class="btn-admin-danger btn-bulk-delete" disabled>🗑 선택 삭제</button>
  </div>
</div>

<div class="admin-card">
  <h2>주문 목록 <span class="admin-count-pill"><?= number_format($totalCount) ?>건</span></h2>
  <table class="admin-table admin-table-trendy">
    <thead>
      <tr>
        <th style="width:36px"><input type="checkbox" id="chkAll"></th>
        <th>주문번호</th><th>수령인</th><th>연락처</th><th>금액</th><th>상태</th><th>주문일시</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($orders)): ?>
      <tr><td colspan="8" class="admin-empty-row">📭 주문이 없습니다.</td></tr>
    <?php else: foreach ($orders as $o): ?>
      <tr>
        <td><input type="checkbox" name="order_ids[]" value="<?= (int)$o['id'] ?>" class="chkRow"></td>
        <td class="mono"><?= h($o['order_no']) ?></td>
        <td><?= h($o['recipient_name']) ?></td>
        <td><?= h($o['recipient_phone']) ?></td>
        <td class="mono"><?= format_price((int)$o['total_amount']) ?></td>
        <td><span class="status-badge status-<?= h($o['status']) ?>"><?= h($statusLabels[$o['status']] ?? $o['status']) ?></span></td>
        <td class="admin-text-sub"><?= h(date('Y-m-d H:i', strtotime($o['created_at']))) ?></td>
        <td><a href="<?= BASE_URL ?>/admin/orders.php?id=<?= (int)$o['id'] ?>" class="admin-link-btn">상세보기</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
  <div class="admin-pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a href="?page=<?= $p ?>&status=<?= h($statusFilter) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
</form>

<script>
(function(){
  const chkAll = document.getElementById('chkAll');
  const bulkBtn = document.querySelector('.btn-bulk-delete');
  function rows(){ return document.querySelectorAll('.chkRow'); }
  function updateBtn(){
    const checked = document.querySelectorAll('.chkRow:checked').length;
    bulkBtn.disabled = checked === 0;
    bulkBtn.textContent = checked > 0 ? `🗑 선택 삭제 (${checked}건)` : '🗑 선택 삭제';
  }
  chkAll?.addEventListener('change', function(){
    rows().forEach(r => r.checked = chkAll.checked);
    updateBtn();
  });
  rows().forEach(r => r.addEventListener('change', updateBtn));
  updateBtn();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
