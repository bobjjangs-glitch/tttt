<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('stock-requests');
$pdo = Database::connection();

/* 재고요청 상태 라벨 + 기존 admin.css에 이미 있는 status-badge 클래스 재사용 */
$statusLabels = [
    'pending'    => '대기',
    'processing' => '처리중',
    'done'       => '완료',
    'cancelled'  => '취소',
];
$statusBadgeClass = [
    'pending'    => 'status-pending',
    'processing' => 'status-preparing', // admin.css에 이미 정의된 인디고 계열 재사용
    'done'       => 'status-done',
    'cancelled'  => 'status-cancelled',
];

/* =========================================================
 * 안전장치: 테이블이 없는 초기 상태에서도 죽지 않도록 최소 생성만 시도
 * (현재 운영 테이블은 이미 모든 컬럼을 갖추고 있음 - 확인됨)
 * ========================================================= */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tt_stock_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        dot_code VARCHAR(20) NULL,
        qty INT NOT NULL DEFAULT 1,
        phone VARCHAR(20) NULL,
        status ENUM('pending','processing','done','cancelled') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        requested_qty INT NOT NULL DEFAULT 1,
        customer_name VARCHAR(50) NULL,
        customer_phone VARCHAR(20) NULL,
        customer_email VARCHAR(120) NULL,
        admin_memo TEXT NULL,
        processed_by INT NULL,
        processed_at DATETIME NULL,
        ip_address VARCHAR(45) NULL,
        brand_text VARCHAR(100) NULL,
        size_text VARCHAR(60) NOT NULL DEFAULT '',
        memo TEXT NULL,
        INDEX idx_status (status),
        INDEX idx_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log('[admin/stock-requests schema] ' . $e->getMessage());
}

/* =========================================================
 * POST 처리: 상태 변경 / 삭제
 * ========================================================= */
if (is_post() && ($_POST['form_type'] ?? '') === 'update_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/stock-requests.php');
    }

    $id        = (int)($_POST['id'] ?? 0);
    $newStatus = (string)($_POST['status'] ?? '');
    $adminMemo = trim((string)($_POST['admin_memo'] ?? ''));

    if ($id <= 0 || !array_key_exists($newStatus, $statusLabels)) {
        flash('admin_error', '올바르지 않은 요청입니다.');
        redirect('/admin/stock-requests.php');
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE tt_stock_requests
             SET status = :status, admin_memo = :memo, processed_by = :admin_id, processed_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'status'   => $newStatus,
            'memo'     => $adminMemo,
            'admin_id' => AdminAuth::currentAdminId(),
            'id'       => $id,
        ]);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'stock_request_status', "요청#{$id} 상태를 '{$statusLabels[$newStatus]}'로 변경");
        flash('admin_success', "요청 #{$id} 상태가 저장되었습니다.");
    } catch (Throwable $e) {
        error_log('[admin/stock-requests update_status] ' . $e->getMessage());
        flash('admin_error', '상태 저장 중 오류: ' . $e->getMessage());
    }
    redirect('/admin/stock-requests.php?' . http_build_query(['status' => $_POST['back_status'] ?? '', 'q' => $_POST['back_q'] ?? '', 'page' => $_POST['back_page'] ?? 1]));
}

if (is_post() && ($_POST['form_type'] ?? '') === 'delete_one') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/stock-requests.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    try {
        $info = $pdo->prepare("SELECT customer_name, customer_phone, phone, brand_text, size_text FROM tt_stock_requests WHERE id = :id");
        $info->execute(['id' => $id]);
        $row = $info->fetch();

        $del = $pdo->prepare("DELETE FROM tt_stock_requests WHERE id = :id");
        $del->execute(['id' => $id]);

        if ($row) {
            $phone   = $row['customer_phone'] ?: $row['phone'];
            $summary = trim(($row['customer_name'] ?? '') . '/' . $phone . '/' . ($row['brand_text'] ?? '') . ' ' . ($row['size_text'] ?? ''));
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'stock_request_delete', "요청#{$id} 삭제 ({$summary})");
        }
        flash('admin_success', "요청 #{$id}이(가) 삭제되었습니다.");
    } catch (Throwable $e) {
        error_log('[admin/stock-requests delete] ' . $e->getMessage());
        flash('admin_error', '삭제 중 오류: ' . $e->getMessage());
    }
    redirect('/admin/stock-requests.php');
}

/* =========================================================
 * 목록 조회
 * ========================================================= */
$statusFilter = $_GET['status'] ?? '';
$keyword      = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = '1=1';
$params = [];
if ($statusFilter !== '' && array_key_exists($statusFilter, $statusLabels)) {
    $where .= ' AND status = :status';
    $params['status'] = $statusFilter;
}
if ($keyword !== '') {
    $where .= ' AND (customer_name LIKE :kw OR customer_phone LIKE :kw OR phone LIKE :kw OR brand_text LIKE :kw)';
    $params['kw'] = '%' . $keyword . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_stock_requests WHERE {$where}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$listStmt = $pdo->prepare(
    "SELECT id, product_id, dot_code,
            COALESCE(NULLIF(requested_qty,0), qty, 1) AS display_qty,
            COALESCE(customer_phone, phone) AS display_phone,
            customer_name, customer_email, brand_text, size_text, memo,
            status, admin_memo, processed_at, created_at
     FROM tt_stock_requests
     WHERE {$where}
     ORDER BY CASE status WHEN 'pending' THEN 0 WHEN 'processing' THEN 1 ELSE 2 END, created_at DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue('offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$requests = $listStmt->fetchAll();

$statCounts = ['pending' => 0, 'processing' => 0, 'done' => 0, 'cancelled' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) cnt FROM tt_stock_requests GROUP BY status")->fetchAll() as $r) {
    $statCounts[$r['status']] = (int)$r['cnt'];
}

$pageTitle = '재고요청 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-stat-grid">
    <?php foreach ($statusLabels as $key => $label): ?>
        <div class="admin-stat-card">
            <div class="admin-stat-label"><?= h($label) ?></div>
            <div class="admin-stat-value<?= $key === 'pending' && $statCounts[$key] > 0 ? ' danger' : '' ?>"><?= $statCounts[$key] ?? 0 ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="admin-toolbar">
    <select id="statusFilterSelect" onchange="location.href='<?= BASE_URL ?>/admin/stock-requests.php?status=' + this.value + '&q=<?= urlencode($keyword) ?>'">
        <option value="">전체 상태</option>
        <?php foreach ($statusLabels as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= h($label) ?> (<?= $statCounts[$key] ?? 0 ?>)</option>
        <?php endforeach; ?>
    </select>

    <form method="get" class="admin-filter-form-wide">
        <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
        <input type="text" name="q" class="admin-input-search" placeholder="고객명·연락처·브랜드 검색" value="<?= h($keyword) ?>">
        <button type="submit" class="btn-admin-primary">검색</button>
    </form>
</div>

<div class="admin-card">
    <h2>재고요청 목록 <span class="admin-count-pill"><?= number_format($totalCount) ?>건</span></h2>
    <table class="admin-table admin-table-trendy">
        <thead>
            <tr>
                <th>ID/요청일시</th>
                <th>고객정보</th>
                <th>상품정보</th>
                <th>수량</th>
                <th>메모</th>
                <th style="width:220px">상태 변경</th>
                <th style="width:70px"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($requests)): ?>
            <tr><td colspan="7" class="admin-empty-row">📭 조건에 맞는 재고요청이 없습니다.</td></tr>
        <?php else: foreach ($requests as $r): ?>
            <tr>
                <td class="mono">
                    #<?= (int)$r['id'] ?><br>
                    <span class="admin-text-sub"><?= h(date('Y-m-d H:i', strtotime($r['created_at']))) ?></span>
                </td>
                <td>
                    <?= h($r['customer_name'] ?: '-') ?><br>
                    <span class="admin-text-sub"><?= h($r['display_phone'] ?: '-') ?></span>
                    <?php if (!empty($r['customer_email'])): ?><br><span class="admin-text-sub"><?= h($r['customer_email']) ?></span><?php endif; ?>
                </td>
                <td>
                    <?= h($r['brand_text'] ?: '-') ?> / <?= h($r['size_text'] ?: '-') ?>
                    <?php if (!empty($r['dot_code'])): ?><br><span class="admin-text-sub">DOT <?= h($r['dot_code']) ?></span><?php endif; ?>
                    <br><span class="admin-text-sub">상품ID <?= (int)$r['product_id'] ?></span>
                </td>
                <td class="mono"><?= (int)$r['display_qty'] ?>개</td>
                <td style="max-width:200px">
                    <?php if (!empty($r['memo'])): ?><span class="admin-text-sub">📝 <?= nl2br(h($r['memo'])) ?></span><br><?php endif; ?>
                    <?php if (!empty($r['admin_memo'])): ?><span class="admin-text-sub">🗂 <?= nl2br(h($r['admin_memo'])) ?></span><br><?php endif; ?>
                    <?php if (!empty($r['processed_at'])): ?><span class="admin-text-sub">처리: <?= h(date('Y-m-d H:i', strtotime($r['processed_at']))) ?></span><?php endif; ?>
                </td>
                <td>
                    <span class="status-badge <?= $statusBadgeClass[$r['status']] ?? '' ?>"><?= h($statusLabels[$r['status']] ?? $r['status']) ?></span>
                    <form method="post" class="admin-status-form" style="margin-top:8px;flex-wrap:wrap">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="form_type" value="update_status">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="back_status" value="<?= h($statusFilter) ?>">
                        <input type="hidden" name="back_q" value="<?= h($keyword) ?>">
                        <input type="hidden" name="back_page" value="<?= (int)$page ?>">
                        <select name="status">
                            <?php foreach ($statusLabels as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= $r['status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="admin_memo" placeholder="관리자 메모" value="<?= h($r['admin_memo'] ?? '') ?>" style="flex:1;min-width:120px">
                        <button type="submit" class="btn-admin-primary">저장</button>
                    </form>
                </td>
                <td>
                    <form method="post" onsubmit="return confirm('요청 #<?= (int)$r['id'] ?>을(를) 완전히 삭제합니다.\n삭제된 데이터는 복구할 수 없습니다.');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="form_type" value="delete_one">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button type="submit" class="btn-admin-danger">삭제</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
        <div class="admin-pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a href="?status=<?= h($statusFilter) ?>&q=<?= urlencode($keyword) ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
