<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('work-logs');
ensure_work_log_tables();

$pdo = Database::connection();
$pageTitle = '작업일지 관리';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');

$jobLabels = work_job_type_labels();

/* ===== 필터 ===== */
$filterDate = $_GET['date'] ?? '';
if ($filterDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) $filterDate = '';
$filterSource = $_GET['source'] ?? '';
if ($filterSource && !in_array($filterSource, ['reservation','walkin'], true)) $filterSource = '';
$filterWorker = (int)($_GET['worker'] ?? 0);
$filterJob = $_GET['job_type'] ?? '';
if ($filterJob && !array_key_exists($filterJob, $jobLabels)) $filterJob = '';
$search = trim((string)($_GET['q'] ?? ''));

$where = 'WHERE 1=1';
$params = [];
if ($filterDate) { $where .= ' AND w.work_date = :d'; $params[':d'] = $filterDate; }
if ($filterSource) { $where .= ' AND w.source = :s'; $params[':s'] = $filterSource; }
if ($filterWorker) { $where .= ' AND w.worker_id = :w'; $params[':w'] = $filterWorker; }
if ($filterJob) { $where .= ' AND w.job_type = :j'; $params[':j'] = $filterJob; }
if ($search !== '') {
    $where .= ' AND (w.work_content LIKE :q1 OR w.customer_name LIKE :q2 OR w.car_info LIKE :q3 OR w.res_no_snap LIKE :q4 OR w.product_name_snap LIKE :q5)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = $params[':q5'] = '%' . $search . '%';
}

/* ===== 삭제/상태 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/work-logs.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM tt_work_logs WHERE id = :id LIMIT 1');
    $stmt->execute(['id'=>$id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$log) {
        flash('admin_error', '작업일지를 찾을 수 없습니다.');
    } elseif (!empty($log['settlement_id'])) {
        flash('admin_error', '일정산 마감이 완료된 작업일지는 변경할 수 없습니다. 먼저 마감 해제하세요.');
    } elseif (($_POST['action'] ?? '') === 'delete') {
        /* 첨부 사진 정리(행+파일) 후 삭제 */
        try {
            $photoStmt = $pdo->prepare('SELECT id FROM tt_work_log_photos WHERE work_log_id = :id');
            $photoStmt->execute(['id'=>$id]);
            delete_work_log_photos(array_map(fn($r) => (int)$r['id'], $photoStmt->fetchAll()));
        } catch (Throwable $e) {}
        $pdo->prepare('DELETE FROM tt_work_logs WHERE id = :id')->execute(['id'=>$id]);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'work_log_delete', "작업일지 #{$id} 삭제");
        flash('admin_success', '작업일지를 삭제했습니다.');
    } elseif (($_POST['action'] ?? '') === 'toggle_status') {
        $newStatus = $log['status'] === 'done' ? 'working' : 'done';
        $pdo->prepare('UPDATE tt_work_logs SET status = :s WHERE id = :id')->execute(['s'=>$newStatus, 'id'=>$id]);
        /* [v64] 작업 완료 → 구매관리 자동 적용(단가=매입단가) + 사이트 재고 차감 / 되돌림 시 복원 */
        $epMsg = '';
        try {
            $ep = ecount_apply_worklog_purchase($id, $newStatus === 'done');
            if ($ep['ok'] && $ep['message'] !== '') $epMsg = ' ' . $ep['message'];
        } catch (Throwable $e) { sg_error_log('[work-logs toggle ecount] ' . $e->getMessage()); }
        flash('admin_success', ($newStatus === 'done' ? '작업 완료로 표시했습니다.' : '작업중으로 되돌렸습니다.') . $epMsg);
    } elseif (($_POST['action'] ?? '') === 'toggle_receipt') {
        /* [v42] 현금영수증 발행 처리/해제 — 현금결제 건만 */
        if (($log['pay_method'] ?? '') !== 'cash') {
            flash('admin_error', '현금결제 건만 현금영수증 발행 처리를 할 수 있습니다.');
        } else {
            $newVal = ((int)($log['cash_receipt_issued'] ?? 0) === 1) ? 0 : 1;
            $pdo->prepare('UPDATE tt_work_logs SET cash_receipt_issued = :v WHERE id = :id')->execute(['v'=>$newVal, 'id'=>$id]);
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'work_log_receipt', "작업일지 #{$id} 현금영수증 " . ($newVal ? '발행 처리' : '해제'));
            flash('admin_success', $newVal ? '현금영수증 발행 처리했습니다.' : '현금영수증 발행을 해제했습니다.');
        }
    }
    redirect('/admin/work-logs.php?' . http_build_query(array_filter([
        'date'=>$filterDate, 'source'=>$filterSource, 'worker'=>$filterWorker ?: null,
        'job_type'=>$filterJob, 'q'=>$search ?: null,
    ])));
}

/* ===== 목록 ===== */
$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_work_logs w {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$listStmt = $pdo->prepare(
    "SELECT w.*, r.res_no AS live_res_no,
            (SELECT ti.id FROM tt_tax_invoices ti
              WHERE ti.target_type = 'reservation' AND ti.target_id = w.reservation_id AND ti.status = 'issued' LIMIT 1) AS tax_invoice_issued
     FROM tt_work_logs w
     LEFT JOIN tt_reservations r ON r.id = w.reservation_id
     {$where}
     ORDER BY w.work_date DESC, w.work_time DESC, w.id DESC
     LIMIT {$perPage} OFFSET {$offset}");
$listStmt->execute($params);
$logs = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/* 첨부 사진 맵 */
$photosMap = get_work_log_photos_by_logs(array_map(fn($l) => (int)$l['id'], $logs));

/* 합계 (필터 구간) — [v40] 결제수단별 합계 포함 */
$sumStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(w.work_amount),0) AS amt,
            COALESCE(SUM(w.cost_factory * w.qty),0) AS cft, COALESCE(SUM(w.cost_supply * w.qty),0) AS cst,
            COALESCE(SUM(CASE WHEN w.pay_method = 'card' THEN COALESCE(NULLIF(w.pay_amount,0), w.work_amount) ELSE 0 END),0) AS card_amt,
            COALESCE(SUM(CASE WHEN w.pay_method = 'cash' THEN COALESCE(NULLIF(w.pay_amount,0), w.work_amount) ELSE 0 END),0) AS cash_amt,
            COALESCE(SUM(CASE WHEN w.pay_method = 'transfer' THEN COALESCE(NULLIF(w.pay_amount,0), w.work_amount) ELSE 0 END),0) AS tf_amt,
            COALESCE(SUM(CASE WHEN w.pay_method IS NULL OR w.pay_method = '' THEN w.work_amount ELSE 0 END),0) AS unpaid_amt
     FROM tt_work_logs w {$where}");
$sumStmt->execute($params);
$sum = $sumStmt->fetch(PDO::FETCH_ASSOC);

$workers = get_workers(false);
$returnQuery = http_build_query(array_filter([
    'date'=>$filterDate, 'source'=>$filterSource, 'worker'=>$filterWorker ?: null,
    'job_type'=>$filterJob, 'q'=>$search ?: null,
]));

require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">📋 작업일지 관리</h2>
      <p class="admin-list-sub">태블릿에서 기록된 작업일지 전체 조회 — 일정산 마감은 <a href="<?= BASE_URL ?>/admin/daily-settlement.php">일정산 마감 페이지</a>에서</p>
    </div>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/admin/daily-settlement.php">💰 일정산 마감으로</a>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <!-- 필터 [v42 — 검색란 확대] -->
  <form method="get" class="admin-filter-form wl-filter" style="margin-bottom:14px;">
    <div><label class="admin-filter-label">날짜</label><input type="date" name="date" value="<?= h($filterDate) ?>"></div>
    <div>
      <label class="admin-filter-label">구분</label>
      <select name="source">
        <option value="">전체</option>
        <option value="reservation" <?= $filterSource === 'reservation' ? 'selected' : '' ?>>예약작업</option>
        <option value="walkin" <?= $filterSource === 'walkin' ? 'selected' : '' ?>>일반 로드</option>
      </select>
    </div>
    <div>
      <label class="admin-filter-label">작업종류</label>
      <select name="job_type">
        <option value="">전체</option>
        <?php foreach ($jobLabels as $k => $l): ?>
        <option value="<?= h($k) ?>" <?= $filterJob === $k ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="admin-filter-label">작업자</label>
      <select name="worker">
        <option value="">전체</option>
        <?php foreach ($workers as $w): ?>
        <option value="<?= (int)$w['id'] ?>" <?= $filterWorker === (int)$w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="wl-filter-search">
      <label class="admin-filter-label">검색</label>
      <input type="text" name="q" class="wl-search-input" value="<?= h($search) ?>" placeholder="작업내용 · 고객명 · 차량번호 · 예약번호 · 상품명">
    </div>
    <div style="display:flex;align-items:flex-end;"><button class="btn btn-primary wl-search-btn" type="submit">🔍 검색</button></div>
  </form>

  <!-- 합계 -->
  <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px;padding:12px 16px;background:#f8fafc;border-radius:10px;font-size:14px;">
    <span>검색 결과 <b><?= number_format((int)$sum['cnt']) ?></b>건</span>
    <span>작업금액 합계 <b style="color:#2563eb;"><?= number_format((int)$sum['amt']) ?></b>원</span>
    <span>💳 카드 <b style="color:#4f46e5;"><?= number_format((int)$sum['card_amt']) ?></b>원 · 💵 현금·계좌 <b style="color:#16a34a;"><?= number_format((int)$sum['cash_amt'] + (int)$sum['tf_amt']) ?></b>원 · ⏳ 외상 <b style="color:#c2410c;"><?= number_format((int)$sum['unpaid_amt']) ?></b>원</span>
    <span>공장도가 합계 <b><?= number_format((int)$sum['cft']) ?></b>원</span>
    <span>매입단가 합계 <b><?= number_format((int)$sum['cst']) ?></b>원</span>
  </div>

  <div class="admin-table-wrap" style="overflow-x:auto;">
  <table class="admin-table" style="min-width:1020px;">
    <thead>
      <tr>
        <th>날짜/시각</th><th>구분</th><th>작업내용</th><th>작업자</th><th>고객/차량</th>
        <th>금액</th><th>결제수단</th><th>원가(공장도/매입)</th><th>상태</th><th>관리</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($logs)): ?>
      <tr><td colspan="10" class="empty">작업일지가 없습니다. 태블릿 사이트(/tablet/)에서 등록하거나, 태블릿 로그인 후 작업을 시작해 주세요.</td></tr>
      <?php else: foreach ($logs as $log):
          $logWeekday = ['일','월','화','수','목','금','토'][(int)date('w', strtotime((string)$log['work_date']))] ?? '';
          $logMd = explode('-', (string)$log['work_date']);
          $isTodayLog = (string)$log['work_date'] === date('Y-m-d');
      ?>
      <tr class="<?= $isTodayLog ? 'wl-row-today' : '' ?>">
        <td class="wl-cell-date">
          <div class="wl-date <?= $isTodayLog ? 'is-today' : '' ?>">
            <div class="wl-cal">
              <span class="wl-cal-m"><?= (int)$logMd[1] ?>월</span>
              <span class="wl-cal-d"><?= h((string)$logMd[2]) ?></span>
              <span class="wl-cal-w">(<?= $logWeekday ?>)</span>
            </div>
            <div class="wl-date-txt">
              <b><?= h(date('Y.m.d', strtotime((string)$log['work_date']))) ?></b>
              <span class="wl-time">🕒 <?= h(substr((string)$log['work_time'], 0, 5)) ?></span>
            </div>
          </div>
        </td>
        <td class="wl-cell-kind">
          <?php
            $wlKindBadge = $log['source'] === 'reservation'
                ? '<span class="wl-badge wl-badge-resv">🛒 온라인예약</span>'
                : '<span class="wl-badge wl-badge-walk">🚶 현장로드</span>';
          ?>
          <?= $wlKindBadge ?>
          <span class="wl-kind-sub"><?= h($jobLabels[$log['job_type']] ?? $log['job_type']) ?></span>
        </td>
        <td class="wl-content-cell">
          <?= h(mb_strimwidth((string)$log['work_content'], 0, 60, '…')) ?>
          <?php if ($log['res_no_snap'] || $log['live_res_no']): ?><br><small class="text-muted">예약번호: <?= h($log['res_no_snap'] ?: $log['live_res_no']) ?></small><?php endif; ?>
          <?php if ($log['product_name_snap']): ?><br><small class="text-muted"><?= h($log['product_name_snap']) ?> ×<?= (int)$log['qty'] ?></small><?php endif; ?>
          <?php $adminLogPhotos = $photosMap[(int)$log['id']] ?? []; ?>
          <?php if (!empty($adminLogPhotos)): ?>
          <div class="wl-photo-strip">
            <span class="badge" style="background:#ede9fe;color:#6d28d9;">📷 <?= count($adminLogPhotos) ?>장</span>
            <?php foreach ($adminLogPhotos as $wlPhoto): ?>
            <a href="<?= h($wlPhoto['image_url']) ?>" target="_blank" rel="noopener" class="wl-photo-thumb" style="background-image:url('<?= h($wlPhoto['image_url']) ?>')" title="사진 크게 보기"></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </td>
        <td><?= h(worker_name((int)$log['worker_id']) ?: '미지정') ?></td>
        <td>
          <?= h($log['customer_name'] ?: '-') ?>
          <?php if ($log['car_info']): ?><br><small class="text-muted"><?= h($log['car_info']) ?></small><?php endif; ?>
        </td>
        <td><b><?= number_format((int)$log['work_amount']) ?></b>원</td>
        <td>
          <?php $wlPay = (string)($log['pay_method'] ?? '') ?: ''; $wlPayLabels = work_pay_method_labels(); ?>
          <?php if ($wlPay !== '' && isset($wlPayLabels[$wlPay])): ?>
          <span class="badge badge-info"><?= $wlPayLabels[$wlPay] ?></span><br>
          <small class="text-muted"><?= number_format((int)($log['pay_amount'] ?? 0) ?: (int)$log['work_amount']) ?>원</small>
          <?php else: ?><span class="badge" style="background:#fff7ed;color:#c2410c;">⏳ 외상</span><?php endif; ?>
          <?php if ((int)($log['cash_receipt_issued'] ?? 0) === 1): ?><br><span class="badge" style="background:#ecfeff;color:#0e7490;">🧾 현금영수증 발행</span><?php endif; ?>
          <?php if (!empty($log['tax_invoice_issued'])): ?><br><span class="badge" style="background:#fef2f2;color:#b91c1c;">📄 세금계산서 발행</span><?php endif; ?>
        </td>
        <td>
          <?php if ($log['job_type'] === 'tire' && ((int)$log['cost_factory'] || (int)$log['cost_supply'])): ?>
            공장 <b><?= number_format((int)$log['cost_factory']) ?></b> · 매입 <b><?= number_format((int)$log['cost_supply']) ?></b><br>
            <small class="text-muted">×<?= (int)$log['qty'] ?>본</small>
          <?php else: ?>-<?php endif; ?>
        </td>
        <td>
          <?php if ($log['settlement_id']): ?><span class="badge badge-success">🔒 마감</span>
          <?php elseif ($log['status'] === 'working'): ?><span class="badge badge-warning">🔧 작업중</span>
          <?php else: ?><span class="badge badge-success">✅ 완료</span><?php endif; ?>
        </td>
        <td>
          <?php if (empty($log['settlement_id'])): ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="id" value="<?= (int)$log['id'] ?>">
            <button type="submit" class="btn btn-sm"><?= $log['status'] === 'done' ? '↩ 진행중으로' : '✓ 완료' ?></button>
          </form>
          <?php if (($log['pay_method'] ?? '') === 'cash'): ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('<?= (int)($log['cash_receipt_issued'] ?? 0) === 1 ? '현금영수증 발행을 해제할까요?' : '이 현금결제 건을 현금영수증 발행 처리할까요?' ?>');">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="action" value="toggle_receipt">
            <input type="hidden" name="id" value="<?= (int)$log['id'] ?>">
            <button type="submit" class="btn btn-sm"><?= (int)($log['cash_receipt_issued'] ?? 0) === 1 ? '🧾 영수증 해제' : '🧾 현금영수증' ?></button>
          </form>
          <?php endif; ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('이 작업일지를 삭제할까요?');">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$log['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">🗑</button>
          </form>
          <?php else: ?>
          <a class="btn btn-sm" href="<?= BASE_URL ?>/admin/daily-settlement.php?date=<?= h((string)$log['work_date']) ?>">정산 보기</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="admin-pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a class="<?= $p === $page ? 'current' : '' ?>" href="?<?= h($returnQuery) . '&page=' . $p ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<style>
/* ===== 작업일지 필터/검색 [v42 — 검색란 확대] ===== */
.wl-filter{display:grid;grid-template-columns:150px 140px 140px 150px minmax(280px,1fr) auto;gap:10px;align-items:end;}
.wl-filter label.admin-filter-label{display:block;font-size:12px;font-weight:700;color:#64748b;margin-bottom:4px;}
.wl-filter input[type="date"],
.wl-filter select{width:100%;padding:9px 10px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13.5px;font-weight:600;background:#fff;color:#1f2937;}
.wl-filter input[type="date"]:focus,
.wl-filter select:focus,
.wl-search-input:focus{border-color:#2563eb;outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
.wl-search-input{
  width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:10px;
  font-size:14.5px;font-weight:600;background:#fff;color:#1f2937;
}
.wl-search-input::placeholder{color:#94a3b8;font-weight:500;}
.wl-search-btn{padding:11px 18px;font-size:13.5px;font-weight:800;white-space:nowrap;}
@media(max-width:1100px){.wl-filter{grid-template-columns:repeat(3,1fr);}.wl-filter-search{grid-column:span 2;}}
@media(max-width:640px){.wl-filter{grid-template-columns:repeat(2,1fr);}.wl-filter-search{grid-column:span 2;}}

.wl-photo-strip{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:6px;}
.wl-photo-thumb{width:56px;height:42px;background-size:cover;background-position:center;border-radius:6px;border:1px solid #e2e8f0;display:inline-block;cursor:zoom-in;}
.wl-photo-thumb:hover{border-color:#2563eb;}

/* ===== 작업일지 날짜/구분 셀 스타일 [v41 재정리] ===== */
.wl-cell-date,.wl-cell-kind{white-space:nowrap;}
.wl-date{display:flex;align-items:center;gap:12px;}
.wl-cal{
  flex:none;width:54px;text-align:center;overflow:hidden;
  border:1px solid #e2e8f0;border-radius:10px;background:#fff;
  box-shadow:0 1px 2px rgba(15,23,42,.06);
}
.wl-cal-m{display:block;font-size:10.5px;font-weight:800;color:#fff;background:#94a3b8;padding:3px 0;letter-spacing:.3px;}
.wl-cal-d{display:block;font-size:19px;font-weight:800;color:#1f2937;padding:2px 0 1px;line-height:1.15;}
.wl-cal-w{display:block;font-size:10.5px;font-weight:700;color:#64748b;padding:0 0 4px;}
.wl-date-txt{min-width:0;line-height:1.45;display:flex;flex-direction:column;gap:4px;}
.wl-date-txt b{display:block;font-size:13.5px;color:#1f2937;letter-spacing:-.2px;}
.wl-time{
  display:inline-flex;align-items:center;gap:3px;align-self:flex-start;
  font-size:12px;font-weight:800;color:#2563eb;
  background:#eff6ff;border-radius:99px;padding:2px 10px;
}
/* 오늘 날짜 강조 */
.wl-date.is-today .wl-cal{border-color:#93c5fd;}
.wl-date.is-today .wl-cal-m{background:#2563eb;}
.wl-date.is-today .wl-time{background:#2563eb;color:#fff;}
.wl-row-today{background:linear-gradient(90deg,rgba(37,99,235,.05),transparent 30%);}
/* 구분 배지 — 한 줄 정렬 */
.wl-cell-kind{min-width:150px;}
/* 작업내용 셀 — 가로 스크롤 표에서 폭이 눌리지 않도록 최소폭 보장 */
.wl-content-cell{min-width:300px;max-width:360px;word-break:keep-all;}
.wl-badge{display:inline-flex;align-items:center;font-size:11.5px;font-weight:800;border-radius:99px;padding:3px 10px;}
.wl-badge-resv{background:#eff6ff;color:#1d4ed8;}
.wl-badge-walk{background:#f0fdf4;color:#15803d;}
.wl-kind-sub{font-size:11.5px;font-weight:700;color:#64748b;margin-left:6px;}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
