<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('business-orders');
$pdo = Database::connection();

$statusLabels = [
    'pending'   => '주문접수',
    'paid'      => '결제완료',
    'preparing' => '상품준비중',
    'shipped'   => '배송중',
    'done'      => '배송완료',
    'cancelled' => '주문취소',
];

// 상태별 필터
$filterStatus = $_GET['status'] ?? '';
if ($filterStatus && !isset($statusLabels[$filterStatus])) $filterStatus = '';

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = "WHERE u.user_type = 'business'";
$params = [];
if ($filterStatus) {
    $where .= " AND o.status = :status";
    $params[':status'] = $filterStatus;
}

$countSql = "SELECT COUNT(*) FROM tt_orders o JOIN tt_users u ON u.id = o.user_id {$where}";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$listSql = "SELECT o.id, o.order_no, o.recipient_name, o.total_amount, o.status, o.created_at,
                   u.name AS user_name, u.email AS user_email, u.biz_name, u.biz_number
            FROM tt_orders o
            JOIN tt_users u ON u.id = o.user_id
            {$where}
            ORDER BY o.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}";
$listStmt = $pdo->prepare($listSql);
foreach ($params as $k => $v) $listStmt->bindValue($k, $v);
$listStmt->execute();
$orders = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = '사업자 주문 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="font-size:18px;font-weight:800">사업자 주문 관리</h2>
      <p style="font-size:13px;color:var(--adm-text-sub);margin-top:4px">사업자 회원의 주문만 따로 관리할 수 있습니다.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="business-orders.php" class="btn-admin-<?= $filterStatus===''?'primary':'secondary' ?>" style="padding:6px 14px;font-size:13px">전체</a>
      <?php foreach ($statusLabels as $key => $label): ?>
      <a href="?status=<?= $key ?>" class="btn-admin-<?= $filterStatus===$key?'primary':'secondary' ?>" style="padding:6px 14px;font-size:13px"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="background:#fef3c7;border-radius:10px;padding:12px 16px;margin-bottom:16px">
    <span style="font-size:13px;color:#92400e">🏢 사업자 주문 총 <strong><?= number_format($total) ?></strong>건</span>
  </div>

  <?php if (empty($orders)): ?>
    <p style="text-align:center;color:var(--adm-text-sub);padding:40px 0">해당하는 사업자 주문이 없습니다.</p>
  <?php else: ?>
    <table class="admin-table">
      <thead>
        <tr>
          <th>주문번호</th>
          <th>사업자명</th>
          <th>사업자번호</th>
          <th>수령인</th>
          <th>금액</th>
          <th>상태</th>
          <th>주문일</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td><strong><?= h($o['order_no']) ?></strong></td>
          <td><?= h($o['biz_name'] ?: $o['user_name']) ?></td>
          <td style="font-size:12px;color:var(--adm-text-sub)"><?= h($o['biz_number'] ?: '-') ?></td>
          <td><?= h($o['recipient_name']) ?></td>
          <td><?= format_price((int)$o['total_amount']) ?></td>
          <td><span class="status-badge status-<?= h($o['status']) ?>"><?= h($statusLabels[$o['status']] ?? $o['status']) ?></span></td>
          <td><?= h(date('Y-m-d H:i', strtotime($o['created_at']))) ?></td>
          <td><a href="<?= BASE_URL ?>/admin/orders.php?id=<?= (int)$o['id'] ?>">상세</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:8px;justify-content:center;margin-top:20px">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a href="?status=<?= h($filterStatus) ?>&page=<?= $p ?>" class="btn-admin-<?= $p===$page?'primary':'secondary' ?>" style="padding:6px 14px"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
