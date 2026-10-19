<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('reservations'); // 예약은 주문/일정 관련이므로 orders 권한
ensure_reservations_table();

$pdo = Database::connection();

$statusLabels = [
    'pending'   => '예약접수',
    'confirmed' => '예약확정',
    'visited'   => '방문완료',
    'canceled'  => '예약취소',
    'noshow'    => '노쇼',
];
$statusColors = [
    'pending'   => '#f59e0b',
    'confirmed' => '#2563eb',
    'visited'   => '#16a34a',
    'canceled'  => '#dc2626',
    'noshow'    => '#64748b',
];
$visitTypeLabels = resv_visit_type_labels();

/* ===== 상태 변경 / 메모 저장 / 시간대별 최대 인원 설정 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/reservations.php');
    }
    $resId = (int)($_POST['reservation_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    /* 시간대별 최대 예약 인원 설정 (타이어 예약 / 정비 / 기타) */
    if ($action === 'save_slot_max') {
        $maxTire   = max(1, min(50, (int)($_POST['max_tire'] ?? 4)));
        $maxRepair = max(1, min(50, (int)($_POST['max_repair'] ?? 2)));
        $maxEtc    = max(1, min(50, (int)($_POST['max_etc'] ?? 4)));
        set_setting('resv_max_tire', (string)$maxTire);
        set_setting('resv_max_repair', (string)$maxRepair);
        set_setting('resv_max_etc', (string)$maxEtc);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'reservation_slot_max',
            "시간대별 최대 예약 인원 설정 — 타이어 {$maxTire}명 / 정비 {$maxRepair}명 / 기타 {$maxEtc}명");
        flash('admin_success', "시간대별 최대 예약 인원을 저장했습니다 — 타이어 {$maxTire}명 / 정비 {$maxRepair}명 / 기타 {$maxEtc}명. 인원이 찬 시간대는 고객 예약 화면에 표시되지 않습니다.");
        redirect('/admin/reservations.php');
    }

    $stmt = $pdo->prepare('SELECT id, res_no, visitor_name, payment_method, payment_status, final_amount FROM tt_reservations WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $resId]);
    $resv = $stmt->fetch();

    if (!$resv) {
        flash('admin_error', '해당 예약을 찾을 수 없습니다.');
        redirect('/admin/reservations.php');
    }

    if ($action === 'set_status') {
        $newStatus = $_POST['new_status'] ?? '';
        if (isset($statusLabels[$newStatus])) {
            $pdo->prepare('UPDATE tt_reservations SET status = :s WHERE id = :id')
                ->execute(['s' => $newStatus, 'id' => $resId]);
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'reservation_status',
                "예약 {$resv['res_no']} 상태 변경 → {$statusLabels[$newStatus]}");
            flash('admin_success', "예약 {$resv['res_no']} 상태가 '{$statusLabels[$newStatus]}'(으)로 변경되었습니다.");
        }
    } elseif ($action === 'save_memo') {
        $adminMemo = trim($_POST['admin_memo'] ?? '');
        $pdo->prepare('UPDATE tt_reservations SET admin_memo = :m WHERE id = :id')
            ->execute(['m' => $adminMemo !== '' ? $adminMemo : null, 'id' => $resId]);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'reservation_memo', "예약 {$resv['res_no']} 관리자 메모 저장");
        flash('admin_success', "예약 {$resv['res_no']} 메모가 저장되었습니다.");
    } elseif ($action === 'toggle_paid') {
        /* [v48] 예약 결제확정 — 결제창을 거친(무통장·가상계좌 등 입금 대기) 예약을
           입금 확인 후 결제완료로 확정/해제 한다. 확정되면 회원 마이페이지·세금계산서
           발행 대상(결제완료 예약)에 반영된다. 카드·간편결제는 접수 시점에 이미 결제완료. */
        if (empty($resv['payment_method']) || (string)$resv['payment_status'] === 'none') {
            flash('admin_error', "예약 {$resv['res_no']}은(는) 결제창을 거치지 않아 결제 처리가 없습니다.");
        } else {
            $newPay = ((string)$resv['payment_status'] === 'paid') ? 'unpaid' : 'paid';
            $pdo->prepare('UPDATE tt_reservations SET payment_status = :ps WHERE id = :id')
                ->execute(['ps' => $newPay, 'id' => $resId]);
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'reservation_payment',
                "예약 {$resv['res_no']} 결제상태 " . ($newPay === 'paid' ? '결제완료 확정' : '미결제로 해제'));
            flash('admin_success', "예약 {$resv['res_no']} 결제상태를 " . ($newPay === 'paid' ? "'결제완료'로 확정했습니다 — 회원 마이페이지·세금계산서 발행 대상에 반영됩니다." : "'미결제'로 해제했습니다."));
        }
    }
    redirect('/admin/reservations.php' . (isset($_POST['return_query']) && $_POST['return_query'] !== '' ? '?' . $_POST['return_query'] : ''));
}

/* ===== 필터 & 검색 ===== */
$filterStatus = $_GET['status'] ?? '';
if ($filterStatus && !isset($statusLabels[$filterStatus])) $filterStatus = '';

$filterDate = $_GET['date'] ?? '';
if ($filterDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) $filterDate = '';

$search = trim($_GET['q'] ?? '');

$where = 'WHERE 1=1';
$params = [];
if ($filterStatus) { $where .= ' AND r.status = :st'; $params[':st'] = $filterStatus; }
if ($filterDate)   { $where .= ' AND r.visit_date = :vd'; $params[':vd'] = $filterDate; }
if ($search !== '') {
    $where .= ' AND (r.res_no LIKE :q OR r.visitor_name LIKE :q OR r.visitor_phone LIKE :q OR r.product_name_snap LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

$returnQuery = http_build_query(array_filter([
    'status' => $filterStatus, 'date' => $filterDate, 'q' => $search,
]));

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_reservations r {$where}");
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$listStmt = $pdo->prepare(
    "SELECT r.*, u.name AS user_name, u.biz_name
     FROM tt_reservations r
     LEFT JOIN tt_users u ON u.id = r.user_id
     {$where}
     ORDER BY FIELD(r.status,'pending','confirmed','visited','canceled','noshow'), r.visit_date ASC, r.visit_time ASC
     LIMIT {$perPage} OFFSET {$offset}"
);
foreach ($params as $k => $v) $listStmt->bindValue($k, $v);
$listStmt->execute();
$reservations = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// 통계
$stats = ['pending' => 0, 'confirmed' => 0, 'today' => 0];
try {
    $stats['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM tt_reservations WHERE status='pending'")->fetchColumn();
    $stats['confirmed'] = (int)$pdo->query("SELECT COUNT(*) FROM tt_reservations WHERE status='confirmed'")->fetchColumn();
    $stats['today'] = (int)$pdo->query("SELECT COUNT(*) FROM tt_reservations WHERE visit_date=CURDATE() AND status IN ('pending','confirmed')")->fetchColumn();
} catch (Throwable $e) {}

$pageTitle = '예약 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">🗓️ 방문 예약 관리</h2>
      <p class="admin-list-sub">고객 방문 예약 접수 내역을 관리합니다.</p>
    </div>
  </div>

  <!-- 통계 카드 -->
  <div class="admin-stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
    <div class="admin-stat-card">
      <div class="admin-stat-label">예약접수 대기</div>
      <div class="admin-stat-value" style="color:#f59e0b"><?= number_format($stats['pending']) ?></div>
    </div>
    <div class="admin-stat-card">
      <div class="admin-stat-label">예약확정</div>
      <div class="admin-stat-value" style="color:#2563eb"><?= number_format($stats['confirmed']) ?></div>
    </div>
    <div class="admin-stat-card">
      <div class="admin-stat-label">오늘 방문 예정</div>
      <div class="admin-stat-value" style="color:#16a34a"><?= number_format($stats['today']) ?></div>
    </div>
  </div>

  <!-- 시간대별 최대 예약 인원 설정 -->
  <div class="admin-card" style="margin-bottom:20px;padding:18px 20px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
      <h3 style="font-size:15px;font-weight:800;margin:0;">👥 시간대별 최대 예약 인원</h3>
      <span style="font-size:11.5px;color:#64748b;font-weight:600;">같은 날짜·같은 시간대에 접수 가능한 예약 인원 상한 — 인원이 찬 시간대는 고객 예약 화면에 표시되지 않습니다</span>
    </div>
    <form method="post" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="save_slot_max">
      <?php
      $maxVals = [
        'tire'   => ['label' => '🛞 타이어 예약', 'key' => 'max_tire', 'cur' => get_resv_slot_max('tire')],
        'repair' => ['label' => '🔧 정비',        'key' => 'max_repair', 'cur' => get_resv_slot_max('repair')],
        'etc'    => ['label' => '💬 기타',         'key' => 'max_etc', 'cur' => get_resv_slot_max('etc')],
      ];
      foreach ($maxVals as $v): ?>
      <div>
        <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px"><?= $v['label'] ?> 최대 인원</label>
        <input type="number" name="<?= $v['key'] ?>" value="<?= (int)$v['cur'] ?>" min="1" max="50" style="width:110px;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-weight:700;">
        <span style="font-size:11px;color:#94a3b8;">명 / 시간대</span>
      </div>
      <?php endforeach; ?>
      <button type="submit" class="btn-admin-primary" style="padding:10px 18px;font-size:13px;">저장</button>
    </form>
  </div>

  <!-- 필터 -->
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end">
    <div style="flex:1;min-width:180px">
      <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">검색 (예약번호/이름/전화/상품명)</label>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="검색어 입력" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
    </div>
    <div>
      <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">상태</label>
      <select name="status" style="padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
        <option value="">전체</option>
        <?php foreach ($statusLabels as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">방문일</label>
      <input type="date" name="date" value="<?= h($filterDate) ?>" style="padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
    </div>
    <button type="submit" class="btn-admin-primary" style="padding:9px 18px;font-size:13px">검색</button>
    <?php if ($filterStatus || $filterDate || $search !== ''): ?>
    <a href="/admin/reservations.php" class="btn-admin-secondary" style="padding:9px 14px;font-size:13px;text-decoration:none">초기화</a>
    <?php endif; ?>
  </form>

  <?php if (empty($reservations)): ?>
    <div class="admin-empty" style="padding:60px 0">예약 내역이 없습니다.</div>
  <?php else: ?>
    <!-- 데스크톱 테이블 -->
    <div class="admin-table-wrap" style="overflow-x:auto">
      <table class="admin-table">
        <thead>
          <tr>
            <th>예약번호</th>
            <th>상태</th>
            <th>결제</th>
            <th>방문구분</th>
            <th>예약자</th>
            <th>연락처</th>
            <th>상품</th>
            <th>수량</th>
            <th>방문예정</th>
            <th>차량</th>
            <th>접수일</th>
            <th>관리</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reservations as $r): ?>
          <tr>
            <td><strong style="font-size:12px"><?= h($r['res_no']) ?></strong></td>
            <td>
              <span class="resv-status-badge" style="color:<?= $statusColors[$r['status']] ?>;background:<?= $statusColors[$r['status']] ?>18">
                <?= h($statusLabels[$r['status']] ?? $r['status']) ?>
              </span>
            </td>
            <td>
              <?php /* [v48] 결제수단·결제상태 표시 + 입금확인(결제확정) 토글 */ ?>
              <?php if (!empty($r['payment_method'])): ?>
                <span style="font-size:11px;font-weight:800;padding:3px 8px;border-radius:999px;<?= $r['payment_status'] === 'paid' ? 'background:#dcfce7;color:#166534;' : 'background:#fef9c3;color:#854d0e;' ?>">
                  <?= $r['payment_status'] === 'paid' ? '✅ 결제완료' : '⏳ 입금대기' ?>
                </span>
                <div style="font-size:10px;color:#94a3b8;margin:2px 0 3px;"><?= h(payment_method_labels()[$r['payment_method']] ?? $r['payment_method']) ?></div>
                <form method="post" style="display:inline">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="toggle_paid">
                  <input type="hidden" name="return_query" value="<?= h($returnQuery . ($returnQuery ? '&' : '') . 'page=' . $page) ?>">
                  <button type="submit" class="btn-admin-<?= $r['payment_status'] === 'paid' ? 'secondary' : 'primary' ?>" style="padding:3px 9px;font-size:10.5px;<?= $r['payment_status'] === 'paid' ? 'text-decoration:none;' : '' ?>">
                    <?= $r['payment_status'] === 'paid' ? '결제 해제' : '💰 입금확인' ?>
                  </button>
                </form>
              <?php else: ?>
                <span style="font-size:11px;color:#cbd5e1;">-</span>
              <?php endif; ?>
            </td>
            <td>
              <?php $vt = $r['visit_type'] ?? 'tire'; if (!array_key_exists($vt, $visitTypeLabels)) $vt = 'tire'; ?>
              <span style="font-size:11px;font-weight:800;padding:3px 8px;border-radius:999px;background:<?= $vt === 'repair' ? '#dcfce7' : ($vt === 'etc' ? '#f1f5f9' : '#eff6ff') ?>;color:<?= $vt === 'repair' ? '#166534' : ($vt === 'etc' ? '#475569' : '#1e40af') ?>;">
                <?= h($visitTypeLabels[$vt]) ?>
              </span>
            </td>
            <td><?= h($r['visitor_name']) ?><?= $r['biz_name'] ? ' <span style="font-size:10px;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px">사업자</span>' : '' ?></td>
            <td style="font-size:12px"><?= h($r['visitor_phone']) ?></td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px" title="<?= h($r['product_name_snap']) ?>"><?= h($r['product_name_snap']) ?></td>
            <td><?= (int)$r['qty'] ?>개</td>
            <td><strong style="font-size:12px"><?= h(date('m/d', strtotime($r['visit_date']))) ?> <?= h($r['visit_time']) ?></strong></td>
            <td style="font-size:11px;color:#64748b"><?= h($r['car_info'] ?: '-') ?></td>
            <td style="font-size:11px;color:#94a3b8"><?= h(date('m/d', strtotime($r['created_at']))) ?></td>
            <td>
              <form method="post" style="display:inline-flex;gap:4px;align-items:center">
                <?= Csrf::field() ?>
                <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="return_query" value="<?= h($returnQuery . ($returnQuery ? '&' : '') . 'page=' . $page) ?>">
                <select name="new_status" style="padding:4px 6px;border:1px solid #e2e8f0;border-radius:6px;font-size:11px">
                  <?php foreach ($statusLabels as $k => $lbl): ?>
                  <option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" style="padding:4px 10px;font-size:11px" class="btn-admin-primary">변경</button>
              </form>
            </td>
          </tr>
          <?php if (!empty($r['memo']) || !empty($r['admin_memo'])): ?>
          <tr style="background:#f8fafc">
            <td colspan="12" style="font-size:11px;color:#64748b;padding:6px 14px">
              <?php if (!empty($r['memo'])): ?>💬 고객: <?= h($r['memo']) ?><?php endif; ?>
              <?php if (!empty($r['admin_memo'])): ?> ✏️ 관리자: <?= h($r['admin_memo']) ?><?php endif; ?>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- 페이지네이션 -->
    <?php if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <?php
      $qs = $returnQuery ? $returnQuery . '&' : '';
      for ($p = 1; $p <= $totalPages; $p++): ?>
      <a href="?<?= h($qs) ?>page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<style>
.resv-status-badge{display:inline-block;font-size:11px;font-weight:800;padding:3px 10px;border-radius:999px;}
@media(max-width:768px){
  .admin-table-wrap table{min-width:900px}
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
