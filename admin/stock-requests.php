<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');
$pdo = Database::connection();

/**
 * tt_stock_requests 테이블이 없으면 생성한다.
 * 이 페이지에 처음 접속하는 순간 테이블이 만들어지므로, 반드시 이 파일이
 * products.php보다 먼저(혹은 최소한 함께) 서버에 올라가 있어야 한다.
 */
function ensure_stock_requests_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tt_stock_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT NULL COMMENT '연결된 상품ID (없으면 NULL)',
            brand_text VARCHAR(100) NULL COMMENT '요청 브랜드(자유입력)',
            size_text VARCHAR(60) NOT NULL COMMENT '요청 사이즈',
            requested_qty INT NOT NULL DEFAULT 1 COMMENT '요청 수량',
            customer_name VARCHAR(50) NOT NULL COMMENT '주문자명',
            customer_phone VARCHAR(20) NOT NULL COMMENT '주문자 연락처',
            customer_email VARCHAR(120) NULL COMMENT '주문자 이메일',
            memo TEXT NULL COMMENT '고객 요청 메모',
            status ENUM('pending','processing','done','cancelled') NOT NULL DEFAULT 'pending' COMMENT '처리 상태',
            admin_memo TEXT NULL COMMENT '관리자 처리 메모',
            processed_by INT NULL COMMENT '처리한 관리자 ID',
            processed_at DATETIME NULL COMMENT '처리 완료 시각',
            ip_address VARCHAR(45) NULL COMMENT '요청자 IP',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
ensure_stock_requests_table($pdo);

const STOCK_REQUEST_STATUS_LABELS = [
    'pending'    => '대기',
    'processing' => '처리중',
    'done'       => '완료',
    'cancelled'  => '취소',
];

// 상태 변경 / 관리자 메모 저장 처리
if (is_post() && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/stock_requests.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $adminMemo = trim((string)($_POST['admin_memo'] ?? ''));

    if ($id <= 0 || !isset(STOCK_REQUEST_STATUS_LABELS[$status])) {
        flash('admin_error', '요청 상태 값이 올바르지 않습니다.');
        redirect('/admin/stock_requests.php');
    }

    $stmt = $pdo->prepare("
        UPDATE tt_stock_requests
        SET status = :status,
            admin_memo = :memo,
            processed_by = :admin_id,
            processed_at = CASE WHEN :status2 IN ('done','cancelled') THEN NOW() ELSE processed_at END
        WHERE id = :id
    ");
    $stmt->execute([
        'status'   => $status,
        'status2'  => $status,
        'memo'     => $adminMemo !== '' ? $adminMemo : null,
        'admin_id' => (int)AdminAuth::currentAdminId(),
        'id'       => $id,
    ]);

    AdminAuth::log((int)AdminAuth::currentAdminId(), 'stock_request_update', "재고요청 #{$id} 상태를 '{$status}'로 변경");
    flash('admin_success', "요청 #{$id} 처리 상태가 저장되었습니다.");
    redirect('/admin/stock_requests.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

// 목록 조회 (상태 필터 지원)
$filterStatus = $_GET['status'] ?? '';
$where = '';
$params = [];
if ($filterStatus !== '' && isset(STOCK_REQUEST_STATUS_LABELS[$filterStatus])) {
    $where = 'WHERE r.status = :status';
    $params['status'] = $filterStatus;
}

$sql = "
    SELECT r.*, p.name AS product_name
    FROM tt_stock_requests r
    LEFT JOIN tt_products p ON p.id = r.product_id
    {$where}
    ORDER BY r.created_at DESC
    LIMIT 200
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$countStmt = $pdo->query("
    SELECT status, COUNT(*) AS cnt FROM tt_stock_requests GROUP BY status
");
$statusCounts = ['pending'=>0,'processing'=>0,'done'=>0,'cancelled'=>0];
foreach ($countStmt->fetchAll() as $c) $statusCounts[$c['status']] = (int)$c['cnt'];

$pageTitle = '재고 요청 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card" style="background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1px solid #e0e7ff;">
  <h2 class="admin-page-title">📦 재고 요청 관리</h2>
  <p class="admin-form-hint">고객이 메인 화면에서 접수한 재고 문의/요청 목록입니다. 주문자 정보를 확인하고 처리 상태를 변경하세요.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
    <a href="?status=" class="btn-admin-secondary <?= $filterStatus==='' ? 'active' : '' ?>">전체 (<?= array_sum($statusCounts) ?>)</a>
    <?php foreach (STOCK_REQUEST_STATUS_LABELS as $key => $label): ?>
      <a href="?status=<?= $key ?>" class="btn-admin-secondary <?= $filterStatus===$key ? 'active' : '' ?>"><?= $label ?> (<?= $statusCounts[$key] ?>)</a>
    <?php endforeach; ?>
  </div>
</div>

<div class="admin-card">
  <?php if (empty($requests)): ?>
    <p class="admin-form-hint">해당 조건의 재고 요청이 없습니다.</p>
  <?php else: ?>
  <table class="admin-table" style="width:100%;font-size:13px;">
    <thead>
      <tr>
        <th>접수시각</th>
        <th>주문자명</th>
        <th>연락처</th>
        <th>이메일</th>
        <th>요청 상품/사이즈</th>
        <th>수량</th>
        <th>고객 메모</th>
        <th>상태 / 관리자 메모</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($requests as $r): ?>
      <tr>
        <td><?= h($r['created_at']) ?></td>
        <td><?= h($r['customer_name']) ?></td>
        <td><a href="tel:<?= h($r['customer_phone']) ?>"><?= h($r['customer_phone']) ?></a></td>
        <td><?= $r['customer_email'] ? h($r['customer_email']) : '-' ?></td>
        <td>
          <?php if ($r['product_name']): ?>
            <b><?= h($r['product_name']) ?></b> (#<?= (int)$r['product_id'] ?>)
          <?php else: ?>
            <?= $r['brand_text'] ? h($r['brand_text']) . ' ' : '' ?><?= h($r['size_text']) ?>
            <span style="color:#b45309;">(미등록 상품)</span>
          <?php endif; ?>
        </td>
        <td><?= (int)$r['requested_qty'] ?></td>
        <td><?= $r['memo'] ? nl2br(h($r['memo'])) : '-' ?></td>
        <td>
          <form method="post" style="display:flex;flex-direction:column;gap:6px;min-width:180px;">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <select name="status">
              <?php foreach (STOCK_REQUEST_STATUS_LABELS as $key => $label): ?>
                <option value="<?= $key ?>" <?= $r['status']===$key ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
            <textarea name="admin_memo" rows="2" placeholder="처리 메모"><?= h($r['admin_memo'] ?? '') ?></textarea>
            <button type="submit" class="btn-admin-primary" style="padding:5px 10px;font-size:12px;">저장</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
