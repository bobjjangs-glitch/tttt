<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('users');

$pdo = Database::connection();

$statusLabels = [
    'active'    => '활성',
    'dormant'   => '휴면',
    'withdrawn' => '탈퇴',
];
$statusBadgeClass = [
    'active'    => 'status-done',
    'dormant'   => 'status-preparing',
    'withdrawn' => 'status-cancelled',
];

/* ---------- 회원 삭제 (주문 기록 없는 회원만 허용) ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'delete_user') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/users.php');
    }
    $userId = (int)($_POST['user_id'] ?? 0);

    $chk = $pdo->prepare('SELECT COUNT(*) FROM tt_orders WHERE user_id = :id');
    $chk->execute(['id' => $userId]);
    $orderCount = (int)$chk->fetchColumn();

    if ($orderCount > 0) {
        flash('admin_error', "이 회원은 주문 내역이 {$orderCount}건 있어 삭제할 수 없습니다. '탈퇴' 상태로 변경해 주세요.");
        redirect('/admin/users.php?id=' . $userId);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM tt_wishlists WHERE user_id = :id')->execute(['id' => $userId]);
        $pdo->prepare('DELETE FROM tt_carts WHERE user_id = :id')->execute(['id' => $userId]);
        $pdo->prepare('DELETE FROM tt_users WHERE id = :id')->execute(['id' => $userId]);
        $pdo->commit();
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'user_delete', "회원#{$userId} 삭제");
        flash('admin_success', '회원이 삭제되었습니다.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[admin/users delete] ' . $e->getMessage());
        flash('admin_error', '삭제 중 오류가 발생했습니다.');
        redirect('/admin/users.php?id=' . $userId);
    }
    redirect('/admin/users.php');
}

/* ---------- 상태 변경 (활성/휴면/탈퇴) ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'change_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/users.php');
    }
    $userId = (int)($_POST['user_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    if (!array_key_exists($newStatus, $statusLabels)) {
        flash('admin_error', '올바르지 않은 상태값입니다.');
        redirect('/admin/users.php');
    }
    $pdo->prepare('UPDATE tt_users SET status = :status WHERE id = :id')
        ->execute(['status' => $newStatus, 'id' => $userId]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'user_status_change', "회원#{$userId} 상태를 '{$statusLabels[$newStatus]}'(으)로 변경");
    flash('admin_success', '회원 상태가 변경되었습니다.');
    redirect('/admin/users.php?id=' . $userId);
}

/* ---------- 로그인 잠금 강제 해제 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'unlock_account') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/users.php');
    }
    $userId = (int)($_POST['user_id'] ?? 0);
    $pdo->prepare('UPDATE tt_users SET login_fail_cnt = 0, locked_until = NULL WHERE id = :id')
        ->execute(['id' => $userId]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'user_unlock', "회원#{$userId} 로그인 잠금 해제");
    flash('admin_success', '로그인 잠금이 해제되었습니다.');
    redirect('/admin/users.php?id=' . $userId);
}

/* ================= 상세보기 (?id=) ================= */
if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
    $userId = (int)$_GET['id'];

    $stmt = $pdo->prepare('SELECT * FROM tt_users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        flash('admin_error', '존재하지 않는 회원입니다.');
        redirect('/admin/users.php');
    }

    $orderStmt = $pdo->prepare('SELECT id, order_no, total_amount, status, created_at
                                 FROM tt_orders WHERE user_id = :id ORDER BY created_at DESC LIMIT 10');
    $orderStmt->execute(['id' => $userId]);
    $recentOrders = $orderStmt->fetchAll();

    $orderCountStmt = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total FROM tt_orders WHERE user_id = :id');
    $orderCountStmt->execute(['id' => $userId]);
    $orderSummary = $orderCountStmt->fetch();

    $wishCountStmt = $pdo->prepare('SELECT COUNT(*) FROM tt_wishlists WHERE user_id = :id');
    $wishCountStmt->execute(['id' => $userId]);
    $wishCount = (int)$wishCountStmt->fetchColumn();

    $cartCountStmt = $pdo->prepare('SELECT COUNT(*) FROM tt_carts WHERE user_id = :id');
    $cartCountStmt->execute(['id' => $userId]);
    $cartCount = (int)$cartCountStmt->fetchColumn();

    $isLocked = $user['locked_until'] && strtotime($user['locked_until']) > time();
    $canDelete = (int)$orderSummary['cnt'] === 0;
    $initial = mb_substr($user['name'], 0, 1);

    $pageTitle = '회원 상세';
    require __DIR__ . '/includes/header.php';
    ?>
    <a href="<?= BASE_URL ?>/admin/users.php" class="admin-back-link">&larr; 목록으로</a>

    <div class="admin-card" style="margin-top:14px;">
      <div class="admin-detail-head">
        <div class="admin-detail-avatar"><?= h($initial) ?></div>
        <div class="admin-detail-title-group">
          <h2>
            <?= h($user['name']) ?> 님
            <span class="status-badge <?= $statusBadgeClass[$user['status']] ?? '' ?>"><?= $statusLabels[$user['status']] ?? $user['status'] ?></span>
            <?php if ($isLocked): ?><span class="admin-mini-badge">🔒 로그인 잠김</span><?php endif; ?>
          </h2>
          <div class="admin-detail-sub"><?= h($user['email']) ?> · 회원ID #<?= (int)$user['id'] ?></div>
        </div>
      </div>

      <div class="admin-detail-grid">
        <div class="admin-detail-item"><strong>휴대폰</strong><span><?= h($user['phone'] ?? '-') ?></span></div>
        <div class="admin-detail-item"><strong>가입일</strong><span><?= h($user['created_at']) ?></span></div>
        <div class="admin-detail-item"><strong>최근 로그인</strong><span><?= h($user['last_login_at'] ?? '기록 없음') ?></span></div>
        <div class="admin-detail-item"><strong>마케팅 수신 동의</strong><span><?= $user['marketing_agree'] ? '동의' : '미동의' ?></span></div>
        <div class="admin-detail-item"><strong>로그인 실패 횟수</strong><span><?= (int)$user['login_fail_cnt'] ?>회</span></div>
        <div class="admin-detail-item"><strong>잠금 해제 예정</strong><span><?= $isLocked ? h($user['locked_until']) : '-' ?></span></div>
      </div>

      <form method="post" class="admin-inline-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_type" value="change_status">
        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
        <select name="status">
          <?php foreach ($statusLabels as $key => $label): ?>
            <option value="<?= $key ?>" <?= $user['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-admin-primary">상태 변경 저장</button>

        <?php if ($isLocked): ?>
        </form>
        <form method="post" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="form_type" value="unlock_account">
          <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
          <button type="submit" class="btn-admin-unlock">🔓 로그인 잠금 해제</button>
        </form>
        <?php else: ?>
        </form>
        <?php endif; ?>

        <form method="post" style="display:inline; margin-left:auto"
              onsubmit="return confirm('<?= $canDelete ? h($user['name']).' 님을 정말 삭제하시겠습니까? 되돌릴 수 없습니다.' : '주문 내역이 있어 삭제할 수 없습니다.' ?>');">
          <?= Csrf::field() ?>
          <input type="hidden" name="form_type" value="delete_user">
          <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
          <button type="submit" class="btn-admin-danger" <?= $canDelete ? '' : 'disabled title="주문 내역이 있어 삭제할 수 없습니다"' ?>>회원 삭제</button>
        </form>
    </div>

    <div class="admin-card">
      <h2>활동 요약</h2>
      <div class="admin-summary-grid">
        <div class="admin-summary-card"><strong>총 주문 건수</strong><span><?= number_format((int)$orderSummary['cnt']) ?>건</span></div>
        <div class="admin-summary-card"><strong>총 결제 금액</strong><span><?= format_price((int)$orderSummary['total']) ?></span></div>
        <div class="admin-summary-card"><strong>찜한 상품</strong><span><?= number_format($wishCount) ?>개</span></div>
        <div class="admin-summary-card"><strong>장바구니</strong><span><?= number_format($cartCount) ?>개</span></div>
      </div>
    </div>

    <div class="admin-card">
      <h2>최근 주문 내역 <span class="admin-text-sub">(최근 10건)</span></h2>
      <table class="admin-table-trendy">
        <thead><tr><th>주문번호</th><th>주문금액</th><th>상태</th><th>주문일시</th></tr></thead>
        <tbody>
        <?php if (empty($recentOrders)): ?>
          <tr><td colspan="4" class="admin-empty-row">주문 내역이 없습니다.</td></tr>
        <?php else: foreach ($recentOrders as $o): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/admin/orders.php?id=<?= (int)$o['id'] ?>"><?= h($o['order_no']) ?></a></td>
            <td class="mono"><?= format_price((int)$o['total_amount']) ?></td>
            <td><?= h($o['status']) ?></td>
            <td><?= h($o['created_at']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* ================= 목록 ================= */
$keyword      = trim($_GET['kw'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = '1=1';
$params = [];
if ($keyword !== '') {
    $where .= ' AND (u.email LIKE :kw OR u.name LIKE :kw OR u.phone LIKE :kw)';
    $params['kw'] = '%' . $keyword . '%';
}
if (array_key_exists($statusFilter, $statusLabels)) {
    $where .= ' AND u.status = :status';
    $params['status'] = $statusFilter;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_users u WHERE {$where}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare("
    SELECT u.id, u.email, u.name, u.phone, u.status, u.marketing_agree,
           u.created_at, u.last_login_at, u.locked_until,
           COALESCE(o.order_count, 0) AS order_count,
           COALESCE(o.total_spent, 0) AS total_spent
    FROM tt_users u
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS order_count, SUM(total_amount) AS total_spent
        FROM tt_orders GROUP BY user_id
    ) o ON o.user_id = u.id
    WHERE {$where}
    ORDER BY u.created_at DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue('offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$users = $listStmt->fetchAll();

$totalPages = max(1, (int)ceil($totalCount / $perPage));
$exportQuery = http_build_query(['kw' => $keyword, 'status' => $statusFilter]);

$pageTitle = '회원 관리';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-toolbar">
  <form method="get" class="admin-filter-form-wide">
    <input type="text" name="kw" value="<?= h($keyword) ?>" placeholder="이메일, 이름, 휴대폰 검색" class="admin-input-search">
    <select name="status">
      <option value="">전체 상태</option>
      <?php foreach ($statusLabels as $key => $label): ?>
        <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-admin-primary">검색</button>
  </form>
  <div class="admin-toolbar-right">
    <a href="<?= BASE_URL ?>/admin/users_export.php?<?= $exportQuery ?>" class="btn-admin-excel">📊 엑셀 다운로드</a>
  </div>
</div>

<div class="admin-card">
  <h2>회원 목록 <span class="admin-count-pill"><?= number_format($totalCount) ?>명</span></h2>
  <table class="admin-table-trendy">
    <thead>
      <tr>
        <th>이름</th><th>이메일</th><th>휴대폰</th><th>주문건수</th><th>총 결제액</th><th>상태</th><th>가입일</th><th style="width:150px"></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($users)): ?>
      <tr><td colspan="8" class="admin-empty-row">👤 등록된 회원이 없습니다.</td></tr>
    <?php else: foreach ($users as $u):
        $locked = $u['locked_until'] && strtotime($u['locked_until']) > time();
    ?>
      <tr>
        <td><strong><?= h($u['name']) ?></strong></td>
        <td><?= h($u['email']) ?></td>
        <td><?= h($u['phone'] ?? '-') ?></td>
        <td class="mono"><?= number_format((int)$u['order_count']) ?>건</td>
        <td class="mono"><?= format_price((int)$u['total_spent']) ?></td>
        <td>
          <span class="status-badge <?= $statusBadgeClass[$u['status']] ?? '' ?>"><?= $statusLabels[$u['status']] ?? $u['status'] ?></span>
          <?php if ($locked): ?><span class="admin-mini-badge">🔒</span><?php endif; ?>
        </td>
        <td class="admin-text-sub"><?= h(substr($u['created_at'], 0, 10)) ?></td>
        <td>
          <a href="<?= BASE_URL ?>/admin/users.php?id=<?= (int)$u['id'] ?>" class="admin-link-btn">상세</a>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
  <div class="admin-pagination">
    <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
      <a href="?page=<?= $pg ?>&kw=<?= h($keyword) ?>&status=<?= h($statusFilter) ?>" class="<?= $pg === $page ? 'active' : '' ?>"><?= $pg ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
