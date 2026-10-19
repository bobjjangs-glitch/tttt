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

/* ---------- 관리자 비밀번호 변경 (회원 계정) ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'reset_password') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $back = '/admin/users.php?id=' . $userId;
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect($back);
    }
    $newPassword = $_POST['new_password'] ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';

    $errors = [];
    if (strlen($newPassword) < 8) $errors[] = '비밀번호는 8자 이상이어야 합니다.';
    if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
        $errors[] = '비밀번호는 영문과 숫자를 모두 포함해야 합니다.';
    }
    if ($newPassword !== $confirm) $errors[] = '비밀번호 확인이 일치하지 않습니다.';

    if ($errors) {
        flash('admin_error', implode('<br>', $errors));
        redirect($back);
    }

    try {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE tt_users SET password_hash = :hash, login_fail_cnt = 0, locked_until = NULL WHERE id = :id')
            ->execute(['hash' => $hash, 'id' => $userId]);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'user_password_reset', "회원#{$userId} 비밀번호 변경 (관리자)");
        flash('admin_success', '비밀번호가 변경되었습니다. 새 비밀번호를 회원에게 전달해주세요.');
    } catch (Throwable $e) {
        error_log('[admin/users reset_password] ' . $e->getMessage());
        flash('admin_error', '비밀번호 변경 중 오류가 발생했습니다.');
    }
    redirect($back);
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

    // 회원 유형 안내
    $userTypeLabel = ($user['user_type'] ?? 'personal') === 'business' ? '사업자 회원' : '일반 회원';
    $isBizUser = ($user['user_type'] ?? 'personal') === 'business';

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
        <div class="admin-detail-avatar <?= $isBizUser ? 'biz' : '' ?>"><?= h($initial) ?></div>
        <div class="admin-detail-title-group">
          <h2>
            <?= h($user['name']) ?> 님
            <span class="usertype-badge <?= $isBizUser ? 'biz' : 'personal' ?>"><?= $userTypeLabel ?></span>
            <span class="status-badge <?= $statusBadgeClass[$user['status']] ?? '' ?>"><?= $statusLabels[$user['status']] ?? $user['status'] ?></span>
            <?php if ($isBizUser && isset($user['biz_status'])): ?>
              <span class="usertype-badge <?= $user['biz_status'] === 'approved' ? 'biz' : 'pending' ?>"><?= ['pending' => '승인대기', 'approved' => '승인완료', 'rejected' => '승인반려'][$user['biz_status']] ?? $user['biz_status'] ?></span>
            <?php endif; ?>
            <?php if ($isLocked): ?><span class="admin-mini-badge">🔒 로그인 잠김</span><?php endif; ?>
          </h2>
          <div class="admin-detail-sub"><?= h($user['email']) ?> · 회원ID #<?= (int)$user['id'] ?></div>
        </div>
      </div>

      <div class="admin-detail-grid">
        <div class="admin-detail-item"><strong>회원 유형</strong><span><?= $userTypeLabel ?></span></div>
        <div class="admin-detail-item"><strong>휴대폰</strong><span><?= h($user['phone'] ?? '-') ?></span></div>
        <div class="admin-detail-item"><strong>가입일</strong><span><?= h($user['created_at']) ?></span></div>
        <div class="admin-detail-item"><strong>최근 로그인</strong><span><?= h($user['last_login_at'] ?? '기록 없음') ?></span></div>
        <div class="admin-detail-item"><strong>마케팅 수신 동의</strong><span><?= $user['marketing_agree'] ? '동의' : '미동의' ?></span></div>
        <div class="admin-detail-item"><strong>로그인 실패 횟수</strong><span><?= (int)$user['login_fail_cnt'] ?>회</span></div>
      </div>

      <?php if ($isBizUser): ?>
      <!-- 사업자 정보 카드 -->
      <div class="biz-info-card">
        <h3>🏢 사업자 정보</h3>
        <div class="biz-info-grid">
          <div><strong>상호명</strong><span><?= h($user['biz_name'] ?? '-') ?></span></div>
          <div><strong>대표자명</strong><span><?= h($user['biz_owner_name'] ?? '-') ?></span></div>
          <div><strong>사업자등록번호</strong><span class="mono"><?= h($user['biz_number'] ?? '-') ?></span></div>
          <div><strong>승인 상태</strong><span><?= ['pending' => '승인대기', 'approved' => '승인완료', 'rejected' => '승인반려'][$user['biz_status'] ?? 'approved'] ?? '-' ?></span></div>
          <div class="full"><strong>사업장 주소</strong><span><?= h($user['biz_address'] ?? '-') ?></span></div>
        </div>
        <a href="<?= BASE_URL ?>/admin/business-approvals.php" class="biz-info-link">사업자 승인 관리로 이동 →</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- 계정 관리 -->
    <div class="admin-card">
      <h2>🔐 계정 관리</h2>

      <div class="account-mgmt-grid">
        <!-- 상태/잠금/삭제 -->
        <div class="account-mgmt-block">
          <h3>상태 · 잠금</h3>
          <form method="post" class="admin-inline-form" style="flex-wrap:wrap">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="change_status">
            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
            <select name="status">
              <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?= $key ?>" <?= $user['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-admin-primary">상태 저장</button>
          </form>

          <div class="account-mgmt-row">
            <?php if ($isLocked): ?>
            <form method="post">
              <?= Csrf::field() ?>
              <input type="hidden" name="form_type" value="unlock_account">
              <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
              <button type="submit" class="btn-admin-unlock">🔓 로그인 잠금 해제</button>
            </form>
            <?php else: ?>
            <span class="account-mgmt-ok">✔️ 로그인 정상</span>
            <?php endif; ?>
          </div>

          <div class="account-mgmt-row">
            <form method="post"
                  onsubmit="return confirm('<?= $canDelete ? h($user['name']).' 님을 정말 삭제하시겠습니까? 되돌릴 수 없습니다.' : '주문 내역이 있어 삭제할 수 없습니다.' ?>');">
              <?= Csrf::field() ?>
              <input type="hidden" name="form_type" value="delete_user">
              <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
              <button type="submit" class="btn-admin-danger" <?= $canDelete ? '' : 'disabled title="주문 내역이 있어 삭제할 수 없습니다"' ?>>🗑 회원 삭제</button>
            </form>
          </div>
        </div>

        <!-- 비밀번호 변경 -->
        <div class="account-mgmt-block">
          <h3>비밀번호 변경</h3>
          <form method="post" class="pw-reset-form" onsubmit="return validatePwReset(this)">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="reset_password">
            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
            <input type="password" name="new_password" placeholder="새 비밀번호 (영문+숫자 8자 이상)" minlength="8" maxlength="100" required autocomplete="new-password">
            <input type="password" name="confirm_password" placeholder="새 비밀번호 확인" maxlength="100" required autocomplete="new-password">
            <button type="submit" class="btn-admin-primary">🔑 비밀번호 변경</button>
            <p class="pw-reset-hint">변경 후 로그인 실패 횟수도 초기화됩니다. 새 비밀번호를 회원에게 안전하게 전달해주세요.</p>
          </form>
        </div>
      </div>
    </div>

    <div class="admin-card">
      <h2>📊 활동 요약</h2>
      <div class="admin-summary-grid">
        <div class="admin-summary-card"><strong>총 주문 건수</strong><span><?= number_format((int)$orderSummary['cnt']) ?>건</span></div>
        <div class="admin-summary-card"><strong>총 결제 금액</strong><span><?= format_price((int)$orderSummary['total']) ?></span></div>
        <div class="admin-summary-card"><strong>찜한 상품</strong><span><?= number_format($wishCount) ?>개</span></div>
        <div class="admin-summary-card"><strong>장바구니</strong><span><?= number_format($cartCount) ?>개</span></div>
      </div>
    </div>

    <div class="admin-card">
      <h2>📦 최근 주문 내역 <span class="admin-text-sub">(최근 10건)</span></h2>
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

    <script>
    function validatePwReset(form) {
      var pw = form.new_password.value;
      var cf = form.confirm_password.value;
      if (pw.length < 8) { alert('비밀번호는 8자 이상 입력해주세요.'); return false; }
      if (!/[A-Za-z]/.test(pw) || !/\d/.test(pw)) { alert('비밀번호는 영문과 숫자를 모두 포함해야 합니다.'); return false; }
      if (pw !== cf) { alert('비밀번호 확인이 일치하지 않습니다.'); return false; }
      return confirm('정말 이 회원의 비밀번호를 변경하시겠습니까?');
    }
    </script>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* ================= 목록 ================= */
$keyword      = trim($_GET['kw'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$typeFilter   = $_GET['type'] ?? '';
if (!in_array($typeFilter, ['', 'personal', 'business'], true)) $typeFilter = '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = '1=1';
$params = [];
if ($keyword !== '') {
    $where .= ' AND (u.email LIKE :kw OR u.name LIKE :kw OR u.phone LIKE :kw';
    if ($typeFilter === 'business' or $typeFilter === '') {
        // 사업자 검색이 포함된 경우 상호명으로도 검색
    }
    $where .= ')';
    $params['kw'] = '%' . $keyword . '%';
}
if (array_key_exists($statusFilter, $statusLabels)) {
    $where .= ' AND u.status = :status';
    $params['status'] = $statusFilter;
}
if ($typeFilter !== '') {
    $where .= ' AND u.user_type = :utype';
    $params['utype'] = $typeFilter;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_users u WHERE {$where}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

// 유형별 통계
try {
    $bizCount = (int)$pdo->query("SELECT COUNT(*) FROM tt_users WHERE user_type='business'")->fetchColumn();
    $personalCount = (int)$pdo->query("SELECT COUNT(*) FROM tt_users WHERE user_type='personal' OR user_type IS NULL")->fetchColumn();
} catch (Throwable $e) { $bizCount = 0; $personalCount = 0; }

$listStmt = $pdo->prepare("
    SELECT u.id, u.email, u.name, u.phone, u.status, u.marketing_agree,
           u.user_type, u.biz_name, u.biz_number,
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
$exportQuery = http_build_query(['kw' => $keyword, 'status' => $statusFilter, 'type' => $typeFilter]);

$pageTitle = '회원 관리';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-toolbar">
  <form method="get" class="admin-filter-form-wide">
    <input type="text" name="kw" value="<?= h($keyword) ?>" placeholder="이메일, 이름, 휴대폰 검색" class="admin-input-search">
    <select name="type">
      <option value="">전체 유형</option>
      <option value="personal" <?= $typeFilter === 'personal' ? 'selected' : '' ?>>일반 회원 (<?= number_format($personalCount) ?>)</option>
      <option value="business" <?= $typeFilter === 'business' ? 'selected' : '' ?>>사업자 회원 (<?= number_format($bizCount) ?>)</option>
    </select>
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
  <h2>회원 목록 <span class="admin-count-pill"><?= number_format($totalCount) ?>명 (사업자 <?= number_format($bizCount) ?> · 일반 <?= number_format($personalCount) ?>)</span></h2>
  <table class="admin-table-trendy">
    <thead>
      <tr>
        <th>이름</th><th>회원 유형</th><th>이메일</th><th>휴대폰</th><th>주문건수</th><th>총 결제액</th><th>상태</th><th>가입일</th><th style="width:150px"></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($users)): ?>
      <tr><td colspan="9" class="admin-empty-row">👤 등록된 회원이 없습니다.</td></tr>
    <?php else: foreach ($users as $u):
        $locked = $u['locked_until'] && strtotime($u['locked_until']) > time();
        $isBiz = ($u['user_type'] ?? 'personal') === 'business';
    ?>
      <tr>
        <td><strong><?= h($u['name']) ?></strong><?= $isBiz && !empty($u['biz_name']) ? ' <span class="usertype-inline-biz">' . h($u['biz_name']) . '</span>' : '' ?></td>
        <td>
          <span class="usertype-badge <?= $isBiz ? 'biz' : 'personal' ?>"><?= $isBiz ? '🏢 사업자' : '👤 일반' ?></span>
        </td>
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
      <a href="?page=<?= $pg ?>&kw=<?= h($keyword) ?>&status=<?= h($statusFilter) ?>&type=<?= h($typeFilter) ?>" class="<?= $pg === $page ? 'active' : '' ?>"><?= $pg ?></a>
     <?php endfor; ?>
   </div>
   <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
