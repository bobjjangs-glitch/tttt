<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('stock-requests');
$pdo = Database::connection();

// 상태 변경
if (is_post() && ($_POST['form_type'] ?? '') === 'update_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/stock-requests.php');
    }
    $reqId = (int)($_POST['request_id'] ?? 0);
    $status = $_POST['status'] ?? 'pending';
    $allowed = ['pending', 'notified', 'closed'];
    if (!in_array($status, $allowed, true)) $status = 'pending';

    try {
        $pdo->prepare('UPDATE tt_stock_requests SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $reqId]);
        flash('admin_success', '재고 요청 상태가 변경되었습니다.');
    } catch (Throwable $e) {
        flash('admin_error', '상태 변경 중 오류가 발생했습니다.');
    }
    redirect('/admin/stock-requests.php');
}

// 재고 요청 목록 (테이블이 없어도 에러 안 나게 try-catch)
$requests = [];
$tableExists = false;
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'tt_stock_requests'")->fetch();
    if ($tableExists) {
        $requests = $pdo->query("
            SELECT sr.id, sr.product_id, sr.dot_code, sr.qty, sr.phone, sr.status, sr.created_at,
                   p.name AS product_name, p.spec,
                   u.email AS user_email, u.nickname AS user_name
            FROM tt_stock_requests sr
            LEFT JOIN tt_products p ON p.id = sr.product_id
            LEFT JOIN tt_users u ON u.id = sr.user_id
            ORDER BY sr.created_at DESC
            LIMIT 100
        ")->fetchAll();
    }
} catch (Throwable $e) {
    error_log('[admin/stock-requests] ' . $e->getMessage());
    $requests = [];
}

$statusLabels = ['pending' => '대기중', 'notified' => '안내완료', 'closed' => '종료'];
$statusColors = ['pending' => '#f59e0b', 'notified' => '#22c55e', 'closed' => '#9ca3af'];

$pageTitle = '재고 요청 관리';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
  <h2 class="admin-page-title">재고 요청 관리 <span class="admin-count-pill"><?= count($requests) ?>건</span></h2>
  <p class="admin-mini-hint">고객이 품절 상품의 재고를 요청한 목록입니다. 입고 시 안내 후 상태를 "안내완료"로 변경하세요.</p>

  <?php if (!$tableExists): ?>
    <div class="admin-alert admin-alert-error" style="margin-bottom:16px;">
      ⚠️ <strong>tt_stock_requests</strong> 테이블이 아직 DB에 없습니다. phpMyAdmin SQL 탭에서 아래를 실행해 주세요:
      <pre style="margin-top:10px;background:#1e293b;color:#e2e8f0;padding:14px;border-radius:8px;font-size:12px;overflow-x:auto;">CREATE TABLE IF NOT EXISTS tt_stock_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  dot_code VARCHAR(20) NULL,
  qty INT NOT NULL DEFAULT 1,
  phone VARCHAR(20) NULL,
  status ENUM('pending','notified','closed') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
    </div>
  <?php elseif (empty($requests)): ?>
    <p class="admin-empty-row" style="padding:40px;text-align:center;color:#999;">
      ✅ 테이블이 정상적으로 생성되었습니다. 아직 접수된 재고 요청이 없습니다.<br>
      고객이 상품 페이지에서 "재고 요청하기" 버튼을 누르면 여기에 목록이 표시됩니다.
    </p>
  <?php else: ?>
    <table class="admin-table-trendy">
      <thead>
        <tr>
          <th style="width:60px">No</th>
          <th>상품명</th>
          <th style="width:80px">DOT</th>
          <th style="width:60px">수량</th>
          <th style="width:120px">회원</th>
          <th style="width:120px">연락처</th>
          <th style="width:100px">상태</th>
          <th style="width:140px">요청일</th>
          <th style="width:120px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td class="mono">#<?= (int)$r['id'] ?></td>
          <td><strong><?= h($r['product_name'] ?? '상품#' . (int)$r['product_id']) ?></strong>
              <?php if (!empty($r['spec'])): ?><br><span class="admin-text-sub"><?= h($r['spec']) ?></span><?php endif; ?>
          </td>
          <td class="mono"><?= h($r['dot_code'] ?: '-') ?></td>
          <td class="mono"><?= (int)$r['qty'] ?>개</td>
          <td><?= h($r['user_name'] ?? $r['user_email'] ?? '비회원') ?></td>
          <td class="mono"><?= h($r['phone'] ?: '-') ?></td>
          <td>
            <span class="status-badge" style="background:<?= $statusColors[$r['status']] ?? '#999' ?>22;color:<?= $statusColors[$r['status']] ?? '#999' ?>">
              <?= $statusLabels[$r['status']] ?? $r['status'] ?>
            </span>
          </td>
          <td class="admin-text-sub"><?= h($r['created_at']) ?></td>
          <td>
            <form method="post" style="display:inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="form_type" value="update_status">
              <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
              <select name="status" onchange="this.form.submit()" style="font-size:12px;padding:4px 8px;border-radius:6px;border:1px solid #ddd;">
                <option value="pending" <?= $r['status']==='pending'?'selected':'' ?>>대기중</option>
                <option value="notified" <?= $r['status']==='notified'?'selected':'' ?>>안내완료</option>
                <option value="closed" <?= $r['status']==='closed'?'selected':'' ?>>종료</option>
              </select>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
