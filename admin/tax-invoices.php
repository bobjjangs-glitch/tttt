<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('tax-invoices'); // 주문/결제 관련
ensure_tax_invoice_fax_schema();
ensure_tax_invoice_table();

$pdo = Database::connection();

/* ===== 공급자 정보 저장 ===== */
if (is_post() && ($_POST['action'] ?? '') === 'save_supplier') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/tax-invoices.php');
    }
    $supplierFields = ['supplier_biz_number', 'supplier_biz_name', 'supplier_ceo', 'supplier_address', 'supplier_uptae', 'supplier_jongmok'];
    try {
        foreach ($supplierFields as $sf) {
            $sv = trim($_POST[$sf] ?? '');
            /* [v44] 빈 값도 저장 — get_setting이 빈 저장값을 기본값(사진 기준)으로 되돌리므로
               칸을 비우면 "기본값 사용"이 된다 */
            set_setting($sf, $sv);
        }
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'supplier_info_update', '세금계산서 공급자 정보 저장');
        flash('admin_success', '공급자 정보가 저장되었습니다.');
    } catch (Throwable $e) {
        error_log('[tax-invoices supplier save] ' . $e->getMessage());
        flash('admin_error', '저장 중 오류가 발생했습니다.');
    }
    redirect('/admin/tax-invoices.php');
}

$statusLabels = ['pending' => '발행대기', 'issued' => '발행완료', 'rejected' => '반려'];
$statusColors  = ['pending' => '#f59e0b', 'issued' => '#16a34a', 'rejected' => '#dc2626'];
$faxLabels = ['none' => '팩스미발송', 'sent' => '팩스발송완료', 'failed' => '팩스발송실패'];

/* ===== 목록으로 되돌릴 때 검색/필터 조건 유지 ===== */
function ti_back_url(): string
{
    $keep = [];
    foreach (['status', 'kw', 'from', 'to'] as $k) {
        if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') $keep[$k] = $_GET[$k];
    }
    return '/admin/tax-invoices.php' . ($keep ? '?' . http_build_query($keep) : '');
}

/* ===== 상태 변경 / 팩스 발송 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect(ti_back_url());
    }
    $reqId = (int)($_POST['invoice_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM tt_tax_invoices WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $reqId]);
    $inv = $stmt->fetch();
    if (!$inv) {
        flash('admin_error', '해당 요청을 찾을 수 없습니다.');
        redirect(ti_back_url());
    }

    if ($action === 'issue') {
        $adminMemo = trim($_POST['admin_memo'] ?? '');
        $pdo->prepare('UPDATE tt_tax_invoices SET status = "issued", issued_at = NOW(), admin_memo = :m WHERE id = :id')
            ->execute(['m' => $adminMemo !== '' ? $adminMemo : null, 'id' => $reqId]);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'tax_invoice_issue', "세금계산서 발행 처리 {$inv['req_no']}");
        flash('admin_success', "{$inv['req_no']} 발행 완료 처리되었습니다.");
    } elseif ($action === 'reject') {
        $adminMemo = trim($_POST['admin_memo'] ?? '');
        $pdo->prepare('UPDATE tt_tax_invoices SET status = "rejected", admin_memo = :m WHERE id = :id')
            ->execute(['m' => $adminMemo !== '' ? $adminMemo : null, 'id' => $reqId]);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'tax_invoice_reject', "세금계산서 반려 {$inv['req_no']}");
        flash('admin_success', "{$inv['req_no']} 반려 처리되었습니다.");
    } elseif ($action === 'memo') {
        $adminMemo = trim($_POST['admin_memo'] ?? '');
        $pdo->prepare('UPDATE tt_tax_invoices SET admin_memo = :m WHERE id = :id')
            ->execute(['m' => $adminMemo !== '' ? $adminMemo : null, 'id' => $reqId]);
        flash('admin_success', "{$inv['req_no']} 메모가 저장되었습니다.");
    } elseif ($action === 'fax') {
        if ($inv['status'] !== 'issued') {
            flash('admin_error', '발행 완료 처리된 건만 팩스 발송할 수 있습니다.');
        } else {
            $rawFax = $_POST['fax_number'] ?? '';
            $faxNo = (is_string($rawFax) && trim($rawFax) !== '') ? $rawFax : (string)($inv['fax_number'] ?? '');
            $result = send_tax_invoice_fax($inv, $faxNo, (int)AdminAuth::currentAdminId());
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'tax_invoice_fax',
                "세금계산서 팩스 발송 {$inv['req_no']} → " . normalize_fax_number($faxNo) . " (" . ($result['ok'] ? '성공' : '실패') . ')');
            if ($result['ok'] && $result['mode'] === 'manual') {
                // 수동 모드: 팩스 표지 + 세금계산서 인쇄 화면을 열어 팩스기로 발송
                flash('admin_success', "{$inv['req_no']} 팩스 대장 기록 완료. 인쇄 화면이 열리면 팩스기로 출력해 발송해주세요. (수신: " . format_fax_number($faxNo) . ')');
                redirect('/admin/tax-invoice-print.php?id=' . $reqId . '&fax=1&to=' . urlencode(normalize_fax_number($faxNo)) . '&autoprint=1');
            }
            if ($result['ok']) {
                flash('admin_success', "{$inv['req_no']} 팩스 발송(API) 완료. " . $result['message']);
            } else {
                flash('admin_error', "{$inv['req_no']} 팩스 발송 실패 — " . $result['message']);
            }
        }
    }
    redirect(ti_back_url());
}

/* ===== 필터: 상태 / 검색어 / 기간 ===== */
$filterStatus = $_GET['status'] ?? '';
if ($filterStatus && !isset($statusLabels[$filterStatus])) $filterStatus = '';

$kw   = trim((string)($_GET['kw'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

$where  = '1=1';
$params = [];
if ($filterStatus !== '') {
    $where .= ' AND t.status = :st';
    $params['st'] = $filterStatus;
}
if ($kw !== '') {
    $where .= ' AND (t.req_no LIKE :kw1 OR t.buyer_biz_name LIKE :kw2 OR t.buyer_biz_number LIKE :kw3 OR t.buyer_ceo LIKE :kw4 OR u.name LIKE :kw5 OR u.email LIKE :kw6)';
    $params['kw1'] = $params['kw2'] = $params['kw3'] = $params['kw4'] = $params['kw5'] = $params['kw6'] = '%' . $kw . '%';
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where .= ' AND DATE(t.created_at) >= :dfrom';
    $params['dfrom'] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where .= ' AND DATE(t.created_at) <= :dto';
    $params['dto'] = $to;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_tax_invoices t LEFT JOIN tt_users u ON u.id = t.user_id WHERE {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare("SELECT t.*, u.name AS user_name, u.biz_name AS user_biz_name, u.email AS user_email
                         FROM tt_tax_invoices t
                         LEFT JOIN tt_users u ON u.id = t.user_id
                         WHERE {$where}
                         ORDER BY FIELD(t.status,'pending','issued','rejected'), t.created_at DESC
                         LIMIT {$perPage} OFFSET {$offset}");
$listStmt->execute($params);
$invoices = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== 통계 ===== */
$stats = ['pending' => 0, 'issued' => 0, 'rejected' => 0, 'fax' => 0, 'amount' => 0];
try {
    $stats['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM tt_tax_invoices WHERE status='pending'")->fetchColumn();
    $stats['issued']  = (int)$pdo->query("SELECT COUNT(*) FROM tt_tax_invoices WHERE status='issued'")->fetchColumn();
    $stats['rejected'] = (int)$pdo->query("SELECT COUNT(*) FROM tt_tax_invoices WHERE status='rejected'")->fetchColumn();
    $stats['fax']     = (int)$pdo->query("SELECT COUNT(*) FROM tt_tax_invoices WHERE fax_status='sent'")->fetchColumn();
    $stats['amount']  = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM tt_tax_invoices WHERE status='issued'")->fetchColumn();
} catch (Throwable $e) {}

/* 엑셀 내보내기 링크 (현재 필터 그대로 전달) */
$exportParams = [];
foreach (['status', 'kw', 'from', 'to'] as $k) {
    if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') $exportParams[$k] = $_GET[$k];
}
$exportUrl = BASE_URL . '/admin/tax-invoice-export.php' . ($exportParams ? '?' . http_build_query($exportParams) : '');

$pageTitle = '세금계산서 발행 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">🧾 세금계산서 발행 관리</h2>
      <p class="admin-list-sub">발행 요청 처리 · 인쇄 · 📠 팩스 발송 · 📊 엑셀 내보내기를 한 곳에서 관리합니다.</p>
    </div>
    <div class="admin-list-actions" style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="<?= h($exportUrl) ?>" class="btn-admin-primary" style="text-decoration:none;display:inline-flex;align-items:center">📊 엑셀로 내보내기</a>
      <a href="<?= BASE_URL ?>/admin/tax-invoice-faxes.php" class="btn-admin-secondary" style="text-decoration:none;display:inline-flex;align-items:center">📠 팩스 발송 관리</a>
    </div>
  </div>

  <!-- 통계 -->
  <div class="admin-stat-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:20px">
    <div class="admin-stat-card">
      <div class="admin-stat-label">발행대기</div>
      <div class="admin-stat-value" style="color:#f59e0b"><?= number_format($stats['pending']) ?></div>
    </div>
    <div class="admin-stat-card">
      <div class="admin-stat-label">발행완료</div>
      <div class="admin-stat-value" style="color:#16a34a"><?= number_format($stats['issued']) ?></div>
    </div>
    <div class="admin-stat-card">
      <div class="admin-stat-label">반려</div>
      <div class="admin-stat-value" style="color:#dc2626"><?= number_format($stats['rejected']) ?></div>
    </div>
    <div class="admin-stat-card">
      <div class="admin-stat-label">📠 팩스발송</div>
      <div class="admin-stat-value" style="color:#2563eb"><?= number_format($stats['fax']) ?></div>
    </div>
    <div class="admin-stat-card">
      <div class="admin-stat-label">발행 누적금액</div>
      <div class="admin-stat-value" style="font-size:15px"><?= number_format($stats['amount']) ?>원</div>
    </div>
  </div>

  <!-- 상태 필터 -->
  <div class="admin-filter-tabs" style="margin-bottom:12px">
    <a href="?<?= h(http_build_query(array_merge($exportParams, ['status' => '']))) ?>" class="admin-filter-tab <?= $filterStatus === '' ? 'active' : '' ?>">전체</a>
    <a href="?<?= h(http_build_query(array_merge($exportParams, ['status' => 'pending']))) ?>" class="admin-filter-tab <?= $filterStatus === 'pending' ? 'active' : '' ?>">발행대기</a>
    <a href="?<?= h(http_build_query(array_merge($exportParams, ['status' => 'issued']))) ?>" class="admin-filter-tab <?= $filterStatus === 'issued' ? 'active' : '' ?>">발행완료</a>
    <a href="?<?= h(http_build_query(array_merge($exportParams, ['status' => 'rejected']))) ?>" class="admin-filter-tab <?= $filterStatus === 'rejected' ? 'active' : '' ?>">반려</a>
  </div>

  <!-- 검색 / 기간 필터 -->
  <form method="get" class="ti-search-form">
    <input type="hidden" name="status" value="<?= h($filterStatus) ?>">
    <input type="search" name="kw" value="<?= h($kw) ?>" placeholder="요청번호 · 상호 · 사업자번호 · 대표자 · 요청자 이름/이메일">
    <input type="date" name="from" value="<?= h($from) ?>" title="요청 시작일" aria-label="요청 시작일">
    <span style="font-size:12px;color:#94a3b8">~</span>
    <input type="date" name="to" value="<?= h($to) ?>" title="요청 종료일" aria-label="요청 종료일">
    <button type="submit" class="btn-admin-primary">🔍 검색</button>
    <a href="<?= BASE_URL ?>/admin/tax-invoices.php" class="btn-admin-secondary" style="text-decoration:none">초기화</a>
  </form>

  <?php if (empty($invoices)): ?>
    <div class="admin-empty" style="padding:50px 0">해당하는 요청이 없습니다.</div>
  <?php else: ?>
    <div class="ti-admin-list">
      <?php foreach ($invoices as $inv): ?>
      <div class="ti-admin-item">
        <div class="ti-admin-head">
          <span class="ti-admin-no"><?= h($inv['req_no']) ?></span>
          <span class="ti-admin-status" style="color:<?= $statusColors[$inv['status']] ?>;background:<?= $statusColors[$inv['status']] ?>18">
            <?= h($statusLabels[$inv['status']] ?? $inv['status']) ?>
          </span>
          <?php if (($inv['fax_status'] ?? 'none') === 'sent'): ?>
          <span class="ti-admin-status" style="color:#2563eb;background:#2563eb18">📠 <?= h($faxLabels[$inv['fax_status']] ?? '팩스발송') ?><?= $inv['fax_sent_at'] ? ' · ' . h(date('m/d H:i', strtotime($inv['fax_sent_at']))) : '' ?></span>
          <?php elseif (($inv['fax_status'] ?? 'none') === 'failed'): ?>
          <span class="ti-admin-status" style="color:#dc2626;background:#dc262618">📠 팩스발송실패</span>
          <?php endif; ?>
          <span class="ti-admin-date"><?= h(date('Y.m.d H:i', strtotime($inv['created_at']))) ?></span>
        </div>

        <div class="ti-admin-body">
          <div class="ti-admin-info">
            <div class="ti-admin-row">
              <span>대상</span>
              <strong><?= $inv['target_type'] === 'order' ? '📦 주문' : '🗓️ 예약' ?> <?= h((string)($inv['target_no'] ?? '')) ?> · <?= number_format((int)$inv['amount']) ?>원</strong>
            </div>
            <div class="ti-admin-row">
              <span>요청자</span>
              <strong><?= h($inv['user_biz_name'] ?: $inv['user_name']) ?> (<?= h($inv['user_name']) ?>)</strong>
            </div>
            <div class="ti-admin-row">
              <span>공급받는자</span>
              <strong><?= h($inv['buyer_biz_name']) ?> · <?= h($inv['buyer_biz_number']) ?> · 대표 <?= h($inv['buyer_ceo']) ?></strong>
            </div>
            <div class="ti-admin-row">
              <span>주소</span>
              <strong><?= h($inv['buyer_address']) ?></strong>
            </div>
            <div class="ti-admin-row">
              <span>업태/종목</span>
              <strong><?= h($inv['buyer_uptae'] ?: '-') ?> / <?= h($inv['buyer_jongmok'] ?: '-') ?></strong>
            </div>
            <div class="ti-admin-row">
              <span>수신</span>
              <strong><?= h($inv['contact_email'] ?: '-') ?><?= $inv['contact_phone'] ? ' · ' . h($inv['contact_phone']) : '' ?><?= !empty($inv['fax_number']) ? ' · 📠 ' . h(format_fax_number((string)$inv['fax_number'])) : '' ?></strong>
            </div>
            <?php if (!empty($inv['memo'])): ?>
            <div class="ti-admin-row">
              <span>요청사항</span>
              <strong><?= h($inv['memo']) ?></strong>
            </div>
            <?php endif; ?>
          </div>

          <div class="ti-admin-actions">
            <?php if ($inv['status'] === 'pending'): ?>
            <form method="post" class="ti-admin-form">
              <?= Csrf::field() ?>
              <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
              <input type="hidden" name="action" value="issue">
              <input type="hidden" name="admin_memo" value="">
              <button type="submit" class="btn-admin-primary">✅ 발행 완료 처리</button>
            </form>
            <form method="post" class="ti-admin-form" onsubmit="return confirm('반려 처리하시겠습니까?')">
              <?= Csrf::field() ?>
              <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
              <input type="hidden" name="action" value="reject">
              <input type="text" name="admin_memo" placeholder="반려 사유 (회원에게 표시)">
              <button type="submit" style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca;padding:10px 16px;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer">반려</button>
            </form>
            <?php else: ?>
            <form method="post" class="ti-admin-form">
              <?= Csrf::field() ?>
              <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
              <input type="hidden" name="action" value="memo">
              <input type="text" name="admin_memo" value="<?= h($inv['admin_memo'] ?? '') ?>" placeholder="관리자 메모 (회원에게 표시)">
              <button type="submit" class="btn-admin-secondary">메모 저장</button>
            </form>
            <?php endif; ?>
            <?php if ($inv['status'] === 'issued'): ?>
            <a href="<?= BASE_URL ?>/admin/tax-invoice-print.php?id=<?= (int)$inv['id'] ?>" target="_blank" class="btn-admin-primary" style="text-decoration:none;text-align:center">🖨️ 세금계산서 인쇄</a>
            <form method="post" class="ti-admin-form" onsubmit="return tiConfirmFax(this)">
              <?= Csrf::field() ?>
              <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
              <input type="hidden" name="action" value="fax">
              <input type="text" name="fax_number" value="<?= h(!empty($inv['fax_number']) ? format_fax_number((string)$inv['fax_number']) : '') ?>" placeholder="수신 팩스번호 (02-1234-5678)">
              <button type="submit" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;padding:10px 16px;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer">📠 팩스로 발송</button>
            </form>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!empty($inv['admin_memo']) && $inv['status'] !== 'pending'): ?>
        <p class="ti-admin-memo">✏️ <?= h($inv['admin_memo']) ?><?= $inv['issued_at'] ? ' · 발행일 ' . h(date('Y.m.d', strtotime($inv['issued_at']))) : '' ?></p>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <?php
      $pageParams = $exportParams; // status/kw/from/to 유지
      for ($p = 1; $p <= $totalPages; $p++):
          $pageParams['page'] = $p;
      ?>
      <a href="?<?= h(http_build_query($pageParams)) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ===== 공급자 정보 (세금계산서 발행용) ===== -->
<?php $supplierInfo = get_supplier_info(); /* 저장값 우선, 없으면 사진 기준 기본값 */ ?>
<div class="admin-card" style="margin-top:18px;max-width:720px">
  <h2>🏢 공급자 정보 (세금계산서 발행용)</h2>
  <p class="admin-form-hint" style="margin-bottom:14px">
    세금계산서 발행 시 "공급자"란에 표시되는 쇼핑몰 사업자 정보입니다. 인쇄 화면에 그대로 반영됩니다.<br>
    <strong style="color:#16a34a">✅ 사진 기준 기본값이 이미 적용되어 있습니다.</strong> 사업장 주소 등 추가·수정할 항목만 입력해서 저장하면 저장값이 우선 반영됩니다.
  </p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save_supplier">
    <div class="ti-supplier-grid">
      <div class="admin-form-row">
        <label>사업자등록번호</label>
        <input type="text" name="supplier_biz_number" value="<?= h($supplierInfo['biz_number']) ?>" placeholder="426-02-01904">
      </div>
      <div class="admin-form-row">
        <label>상호명</label>
        <input type="text" name="supplier_biz_name" value="<?= h($supplierInfo['biz_name']) ?>" placeholder="시카스튜디오(SICASTUDIO)">
      </div>
      <div class="admin-form-row">
        <label>대표자명</label>
        <input type="text" name="supplier_ceo" value="<?= h($supplierInfo['ceo']) ?>" placeholder="나지원">
      </div>
      <div class="admin-form-row">
        <label>사업장 주소</label>
        <input type="text" name="supplier_address" value="<?= h($supplierInfo['address']) ?>" placeholder="사업장 주소를 입력해주세요">
      </div>
      <div class="admin-form-row">
        <label>업태</label>
        <input type="text" name="supplier_uptae" value="<?= h($supplierInfo['uptae']) ?>" placeholder="도매 및 소매업">
      </div>
      <div class="admin-form-row">
        <label>종목</label>
        <input type="text" name="supplier_jongmok" value="<?= h($supplierInfo['jongmok']) ?>" placeholder="전자상거래 판매">
      </div>
    </div>
    <div class="admin-form-actions">
      <button type="submit" class="btn-admin-primary">공급자 정보 저장</button>
    </div>
  </form>
</div>
<style>
.ti-supplier-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px;}
@media(max-width:640px){.ti-supplier-grid{grid-template-columns:1fr;}}
</style>

<style>
.ti-admin-list{display:flex;flex-direction:column;gap:12px;}
.ti-admin-item{border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;}
.ti-admin-item:hover{border-color:#cbd5e1;}
.ti-admin-head{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap;}
.ti-admin-no{font-size:12px;font-weight:800;color:#94a3b8;letter-spacing:.3px;}
.ti-admin-status{font-size:11px;font-weight:800;padding:3px 10px;border-radius:999px;}
.ti-admin-date{font-size:11px;color:#94a3b8;margin-left:auto;}
.ti-admin-body{display:grid;grid-template-columns:1.6fr 1fr;gap:16px;align-items:start;}
.ti-admin-info{display:flex;flex-direction:column;gap:6px;}
.ti-admin-row{display:flex;gap:10px;font-size:12px;}
.ti-admin-row span{color:#94a3b8;font-weight:700;flex-shrink:0;width:70px;}
.ti-admin-row strong{color:#1e293b;font-weight:600;word-break:break-word;}
.ti-admin-actions{display:flex;flex-direction:column;gap:8px;}
.ti-admin-form{display:flex;flex-direction:column;gap:6px;}
.ti-admin-form input[type=text]{padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;}
.ti-admin-memo{font-size:11px;color:#64748b;background:#f8fafc;border-radius:8px;padding:8px 12px;margin:10px 0 0;}
.ti-search-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;margin-bottom:16px;}
.ti-search-form input[type=search]{flex:1;min-width:200px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;}
.ti-search-form input[type=date]{padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:12px;color:#475569;}
@media(max-width:768px){
  .ti-admin-body{grid-template-columns:1fr;}
  .ti-admin-row span{width:60px;}
  .admin-stat-grid{grid-template-columns:repeat(2,1fr) !important;}
  .ti-search-form input[type=search]{min-width:100%;}
}
</style>

<script>
function tiConfirmFax(form){
  var input = form.querySelector('input[name=fax_number]');
  var digits = (input ? input.value : '').replace(/[^0-9]/g, '');
  if (digits.length < 8) {
    alert('수신 팩스번호를 지역번호 포함해서 입력해주세요. (예: 02-1234-5678)');
    if (input) input.focus();
    return false;
  }
  return confirm('팩스로 발송하시겠습니까?\n수동 모드: 팩스 표지+세금계산서 인쇄 화면이 열립니다 → 팩스기로 출력해 발송하세요.');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
