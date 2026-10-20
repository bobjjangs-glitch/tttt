<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('business-approvals');
$pdo = Database::connection();
ensure_ecount_tables($pdo);

/**
 * [신규] 사업자번호를 이카운트 거래처코드로 변환한다.
 * 사업자번호가 없으면 'CUST' + 회원ID 조합으로 대체 코드를 만든다.
 */
function admin_build_ecount_cust_cd(array $user): string
{
    $bizNo = preg_replace('/\D/', '', (string)($user['biz_number'] ?? ''));
    if ($bizNo !== '') return $bizNo;
    return 'CUST' . str_pad((string)$user['id'], 8, '0', STR_PAD_LEFT);
}

// 승인/반려 처리
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/business-approvals.php');
    }
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM tt_users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || $user['user_type'] !== 'business') {
        flash('admin_error', '해당 사업자 회원을 찾을 수 없습니다.');
        redirect('/admin/business-approvals.php');
    }

    if ($action === 'approve') {
        $pdo->prepare('UPDATE tt_users SET biz_status = "approved", biz_verified_at = NOW() WHERE id = :id')
            ->execute(['id' => $userId]);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'biz_approve', '사업자 승인: ' . $user['biz_name'] . ' (' . $user['name'] . ')');

        /* [신규] 승인과 동시에 이카운트 거래처 등록 시도 — 실패해도 승인 자체는 유지 */
        $custCd = admin_build_ecount_cust_cd($user);
        try {
            $ec = new EcountClient($pdo);
            $res = $ec->saveBasicCustomer([
                'CUST_CD'     => $custCd,
                'CUST_NAME'   => $user['biz_name'] ?? '',
                'BOSS_NAME'   => $user['biz_owner_name'] ?? '',
                'BUSINESS_NO' => preg_replace('/\D/', '', (string)($user['biz_number'] ?? '')),
                'TEL'         => $user['phone'] ?? '',
                'EMAIL'       => $user['email'] ?? '',
                'ADDR'        => $user['biz_address'] ?? '',
            ]);
            $ok = $res['Data']['ResultDetails'][0]['IsSuccess'] ?? false;
            if ($ok) {
                $pdo->prepare('UPDATE tt_users SET ecount_cust_cd = :cd, ecount_cust_synced_at = NOW(), ecount_cust_sync_error = NULL WHERE id = :id')
                    ->execute(['cd' => $custCd, 'id' => $userId]);
                flash('admin_success', $user['biz_name'] . ' 사업자 승인 및 이카운트 거래처 등록이 완료되었습니다. (거래처코드: ' . $custCd . ')');
            } else {
                $errMsg = (string)($res['Data']['ResultDetails'][0]['TotalError'] ?? '알 수 없는 오류');
                $pdo->prepare('UPDATE tt_users SET ecount_cust_sync_error = :err WHERE id = :id')
                    ->execute(['err' => mb_substr($errMsg, 0, 255), 'id' => $userId]);
                flash('admin_error', '사업자 승인은 완료됐지만 이카운트 거래처 등록에 실패했습니다: ' . $errMsg);
            }
        } catch (Throwable $e) {
            error_log('[business-approvals] ecount 거래처등록 예외: ' . $e->getMessage());
            $pdo->prepare('UPDATE tt_users SET ecount_cust_sync_error = :err WHERE id = :id')
                ->execute(['err' => mb_substr($e->getMessage(), 0, 255), 'id' => $userId]);
            flash('admin_error', '사업자 승인은 완료됐지만 이카운트 연동 설정이 안 되어 거래처 등록을 건너뛰었습니다. (' . $e->getMessage() . ')');
        }
    } elseif ($action === 'reject') {
        $reason = trim($_POST['reject_reason'] ?? '');
        $pdo->prepare('UPDATE tt_users SET biz_status = "rejected" WHERE id = :id')
            ->execute(['id' => $userId]);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'biz_reject', '사업자 반려: ' . $user['biz_name'] . ' (' . $user['name'] . ') ' . $reason);
        flash('admin_success', $user['biz_name'] . ' 사업자 가입이 반려되었습니다.');
    } elseif ($action === 'resync_ecount') {
        /* [신규] 이미 승인된 거래처를 이카운트에 재전송(수동 재시도) */
        $custCd = $user['ecount_cust_cd'] ?: admin_build_ecount_cust_cd($user);
        try {
            $ec = new EcountClient($pdo);
            $res = $ec->saveBasicCustomer([
                'CUST_CD'     => $custCd,
                'CUST_NAME'   => $user['biz_name'] ?? '',
                'BOSS_NAME'   => $user['biz_owner_name'] ?? '',
                'BUSINESS_NO' => preg_replace('/\D/', '', (string)($user['biz_number'] ?? '')),
                'TEL'         => $user['phone'] ?? '',
                'EMAIL'       => $user['email'] ?? '',
                'ADDR'        => $user['biz_address'] ?? '',
            ]);
            $ok = $res['Data']['ResultDetails'][0]['IsSuccess'] ?? false;
            if ($ok) {
                $pdo->prepare('UPDATE tt_users SET ecount_cust_cd = :cd, ecount_cust_synced_at = NOW(), ecount_cust_sync_error = NULL WHERE id = :id')
                    ->execute(['cd' => $custCd, 'id' => $userId]);
                flash('admin_success', '이카운트 거래처 재전송이 완료되었습니다.');
            } else {
                $errMsg = (string)($res['Data']['ResultDetails'][0]['TotalError'] ?? '알 수 없는 오류');
                flash('admin_error', '재전송 실패: ' . $errMsg);
            }
        } catch (Throwable $e) {
            flash('admin_error', '재전송 실패: ' . $e->getMessage());
        }
    }
    redirect('/admin/business-approvals.php');
}

// 필터
$filter = $_GET['filter'] ?? 'pending';
$validFilters = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($filter, $validFilters, true)) $filter = 'pending';

$whereSql = "WHERE user_type = 'business'";
if ($filter !== 'all') {
    $whereSql .= " AND biz_status = " . $pdo->quote($filter);
}

// biz_status 컬럼이 없으면 추가
$cols = $pdo->query("SHOW COLUMNS FROM tt_users")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('biz_status', $cols, true)) {
    try { $pdo->exec("ALTER TABLE tt_users ADD COLUMN biz_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER biz_verified"); } catch (Throwable $e) {}
    try { $pdo->exec("UPDATE tt_users SET biz_status = 'approved' WHERE user_type = 'business' AND biz_status = 'pending' AND created_at < NOW() - INTERVAL 1 HOUR"); } catch (Throwable $e) {}
}

$totalStmt = $pdo->query("SELECT COUNT(*) FROM tt_users {$whereSql}");
$total = (int)$totalStmt->fetchColumn();

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->query("SELECT id, email, name, phone, biz_number, biz_name, biz_owner_name, biz_address, biz_status, created_at,
                                 ecount_cust_cd, ecount_cust_synced_at, ecount_cust_sync_error
                         FROM tt_users {$whereSql}
                         ORDER BY created_at DESC
                         LIMIT {$perPage} OFFSET {$offset}");
$bizUsers = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'pending'  => '승인 대기',
    'approved' => '승인 완료',
    'rejected' => '반려',
];
$statusColors = [
    'pending'  => '#f59e0b',
    'approved' => '#16a34a',
    'rejected' => '#dc2626',
];

$pageTitle = '사업자 승인 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <h2 style="font-size:18px;font-weight:800">사업자 회원 승인 관리</h2>
    <div style="display:flex;gap:8px">
      <a href="?filter=pending" class="btn-admin-<?= $filter==='pending'?'primary':'secondary' ?>" style="padding:6px 14px;font-size:13px">승인 대기</a>
      <a href="?filter=approved" class="btn-admin-<?= $filter==='approved'?'primary':'secondary' ?>" style="padding:6px 14px;font-size:13px">승인 완료</a>
      <a href="?filter=rejected" class="btn-admin-<?= $filter==='rejected'?'primary':'secondary' ?>" style="padding:6px 14px;font-size:13px">반려</a>
      <a href="?filter=all" class="btn-admin-<?= $filter==='all'?'primary':'secondary' ?>" style="padding:6px 14px;font-size:13px">전체</a>
    </div>
  </div>

  <?php if (empty($bizUsers)): ?>
    <p style="text-align:center;color:var(--adm-text-sub);padding:40px 0">해당 상태의 사업자 회원이 없습니다.</p>
  <?php else: ?>
    <table class="admin-table">
      <thead>
        <tr>
          <th>상태</th>
          <th>상호명</th>
          <th>대표자</th>
          <th>사업자번호</th>
          <th>이메일</th>
          <th>연락처</th>
          <th>주소</th>
          <th>가입일</th>
          <th>ERP연동</th>
          <th>승인/반려</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bizUsers as $u): ?>
        <tr>
          <td>
            <span style="background:<?= $statusColors[$u['biz_status'] ?? 'approved'] ?>20;color:<?= $statusColors[$u['biz_status'] ?? 'approved'] ?>;font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px">
              <?= h($statusLabels[$u['biz_status'] ?? 'approved'] ?? '승인완료') ?>
            </span>
          </td>
          <td><strong><?= h($u['biz_name']) ?></strong></td>
          <td><?= h($u['biz_owner_name']) ?></td>
          <td><?= h($u['biz_number']) ?></td>
          <td><?= h($u['email']) ?></td>
          <td><?= h($u['phone']) ?></td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($u['biz_address']) ?></td>
          <td><?= h(date('Y-m-d', strtotime($u['created_at']))) ?></td>
          <td>
            <?php if (!empty($u['ecount_cust_cd']) && !empty($u['ecount_cust_synced_at'])): ?>
              <span style="color:#16a34a;font-size:12px;font-weight:700">🟢 연동됨 (<?= h($u['ecount_cust_cd']) ?>)</span>
            <?php elseif (!empty($u['ecount_cust_sync_error'])): ?>
              <span style="color:#dc2626;font-size:12px;font-weight:700" title="<?= h($u['ecount_cust_sync_error']) ?>">🔴 전송실패</span>
              <?php if (($u['biz_status'] ?? '') === 'approved'): ?>
              <form method="post" style="display:inline-block;margin-top:4px">
                <?= Csrf::field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="action" value="resync_ecount">
                <button type="submit" style="font-size:11px;padding:3px 8px;">재전송</button>
              </form>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:#94a3b8;font-size:12px">⚪ 미연동</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((($u['biz_status'] ?? 'approved') === 'pending')): ?>
            <form method="post" style="display:inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <button type="submit" class="btn-admin-primary" style="padding:5px 12px;font-size:12px">승인</button>
            </form>
            <form method="post" style="display:inline;margin-top:4px" onsubmit="return confirm('반려하시겠습니까?')">
              <?= Csrf::field() ?>
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="action" value="reject">
              <button type="submit" style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca;padding:5px 12px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer">반려</button>
            </form>
            <?php else: ?>
            <span style="color:#94a3b8;font-size:12px">-</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:8px;justify-content:center;margin-top:20px">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a href="?filter=<?= h($filter) ?>&page=<?= $p ?>" class="btn-admin-<?= $p===$page?'primary':'secondary' ?>" style="padding:6px 14px"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
