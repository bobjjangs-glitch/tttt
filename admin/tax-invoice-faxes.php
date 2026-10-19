<?php
// admin/tax-invoice-faxes.php — 세금계산서 팩스 발송 관리 (발송 방식/설정 + 발송 대장)
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('tax-invoices');
ensure_tax_invoice_fax_schema();

$pdo = Database::connection();

$faxMode    = get_setting('tax_fax_mode', 'manual');   // manual | api
$faxSender  = get_setting('tax_fax_sender', '');
$faxApiUrl  = get_setting('tax_fax_api_url', '');
$faxApiKey  = get_setting('tax_fax_api_key', '');
$faxMode    = $faxMode === 'api' ? 'api' : 'manual';

/* ===== 설정 저장 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/tax-invoice-faxes.php');
    }

    $newMode   = ($_POST['fax_mode'] ?? 'manual') === 'api' ? 'api' : 'manual';
    $newSender = normalize_fax_number((string)($_POST['fax_sender'] ?? ''));
    $newUrl    = trim((string)($_POST['fax_api_url'] ?? ''));
    $newKey    = trim((string)($_POST['fax_api_key'] ?? ''));

    $errors = [];
    if ($newSender !== '' && !preg_match('/^\d{8,11}$/', $newSender)) {
        $errors[] = '발신 팩스번호는 숫자 8~11자리로 입력해주세요.';
    }
    if ($newMode === 'api') {
        if ($newUrl === '' ) $errors[] = 'API 모드에서는 팩스 API 주소가 필요합니다.';
        elseif (!filter_var($newUrl, FILTER_VALIDATE_URL)) $errors[] = '팩스 API 주소 형식이 올바르지 않습니다.';
        if ($faxApiKey === '' && $newKey === '') $errors[] = 'API 모드에서는 API 키가 필요합니다.';
    }

    if ($errors) {
        flash('admin_error', implode('<br>', $errors));
    } else {
        try {
            set_setting('tax_fax_mode', $newMode);
            set_setting('tax_fax_sender', $newSender);
            set_setting('tax_fax_api_url', $newUrl);
            // API 키: 빈칸 제출 시 기존 값 유지
            if ($newKey !== '') set_setting('tax_fax_api_key', $newKey);
            elseif ($faxApiKey !== '' && isset($_POST['fax_api_key_clear'])) set_setting('tax_fax_api_key', '');
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'tax_fax_setting_update',
                '세금계산서 팩스 설정 변경 (' . $newMode . ' 모드)');
            flash('admin_success', '팩스 발송 설정이 저장되었습니다.');
        } catch (Throwable $e) {
            error_log('[admin/tax-invoice-faxes save] ' . $e->getMessage());
            flash('admin_error', '저장 중 오류가 발생했습니다.');
        }
    }
    redirect('/admin/tax-invoice-faxes.php');
}

/* ===== 대장 목록 (기간 필터) ===== */
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));
$where  = '1=1';
$params = [];
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where .= ' AND DATE(f.sent_at) >= :dfrom';
    $params['dfrom'] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where .= ' AND DATE(f.sent_at) <= :dto';
    $params['dto'] = $to;
}

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_tax_invoice_faxes f WHERE {$where}");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare("SELECT f.*, t.buyer_biz_name, t.buyer_ceo, t.amount, t.status AS inv_status, a.name AS admin_name
                           FROM tt_tax_invoice_faxes f
                           LEFT JOIN tt_tax_invoices t ON t.id = f.invoice_id
                           LEFT JOIN tt_admins a ON a.id = f.admin_id
                           WHERE {$where}
                           ORDER BY f.sent_at DESC
                           LIMIT {$perPage} OFFSET {$offset}");
$listStmt->execute($params);
$faxes = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$stats = ['total' => 0, 'sent' => 0, 'failed' => 0];
try {
    $stats['total']  = (int)$pdo->query('SELECT COUNT(*) FROM tt_tax_invoice_faxes')->fetchColumn();
    $stats['sent']   = (int)$pdo->query("SELECT COUNT(*) FROM tt_tax_invoice_faxes WHERE status='sent'")->fetchColumn();
    $stats['failed'] = (int)$pdo->query("SELECT COUNT(*) FROM tt_tax_invoice_faxes WHERE status='failed'")->fetchColumn();
} catch (Throwable $e) {}

$pageTitle = '팩스 발송 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">📠 세금계산서 팩스 발송 관리</h2>
      <p class="admin-list-sub">팩스 발송 방식을 설정하고 발송 대장을 관리합니다. 발송은 발행 관리 목록의 "📠 팩스로 발송" 버튼에서 실행됩니다.</p>
    </div>
    <div class="admin-list-actions" style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="<?= BASE_URL ?>/admin/tax-invoices.php" class="btn-admin-secondary" style="text-decoration:none;display:inline-flex;align-items:center">← 발행 관리로</a>
    </div>
  </div>

  <!-- 통계 -->
  <div class="admin-stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
    <div class="admin-stat-card">
      <div class="admin-stat-label">총 발송</div>
      <div class="admin-stat-value"><?= number_format($stats['total']) ?></div>
    </div>
    <div class="admin-stat-card">
      <div class="admin-stat-label">발송 성공</div>
      <div class="admin-stat-value" style="color:#16a34a"><?= number_format($stats['sent']) ?></div>
    </div>
    <div class="admin-stat-card">
      <div class="admin-stat-label">발송 실패</div>
      <div class="admin-stat-value" style="color:#dc2626"><?= number_format($stats['failed']) ?></div>
    </div>
  </div>

  <!-- 팩스 설정 -->
  <div class="tfx-settings">
    <h3 class="tfx-settings-title">⚙️ 팩스 발송 설정</h3>
    <form method="post" class="tfx-settings-form">
      <?= Csrf::field() ?>

      <div class="tfx-field">
        <label>발송 방식 <span class="req">*</span></label>
        <div class="tfx-radio-group">
          <label class="tfx-radio">
            <input type="radio" name="fax_mode" value="manual" <?= $faxMode === 'manual' ? 'checked' : '' ?>>
            <span><strong>수동 발송 (기본)</strong> — 팩스 표지+세금계산서를 자동으로 인쇄 화면으로 열어주고, 팩스기에서 직접 발송합니다. 발송 기록은 대장에 자동 적립됩니다.</span>
          </label>
          <label class="tfx-radio">
            <input type="radio" name="fax_mode" value="api" <?= $faxMode === 'api' ? 'checked' : '' ?>>
            <span><strong>API 자동 발송</strong> — 아래 팩스 API 게이트웨이로 JSON 발송을 시도합니다. (팩스 서비스 제공사의 발신 API 주소+키 필요)</span>
          </label>
        </div>
      </div>

      <div class="tfx-field">
        <label>발신 팩스번호 (표지/대장에 기록)</label>
        <input type="text" name="fax_sender" value="<?= h($faxSender !== '' ? format_fax_number($faxSender) : '') ?>" placeholder="02-1234-5678">
        <p class="tfx-hint">매장 팩스번호를 입력하면 팩스 표지의 "발신 팩스"란에 표시됩니다.</p>
      </div>

      <div class="tfx-field">
        <label>팩스 API 주소 (API 모드에서만 사용)</label>
        <input type="text" name="fax_api_url" value="<?= h($faxApiUrl) ?>" placeholder="https://api.팩스서비스.example/fax/send">
        <p class="tfx-hint">JSON POST로 아래 형식으로 발송을 시도합니다: to(수신번호), from(발신번호), subject(제목), file_url(인쇄화면 URL), ref(요청번호). 인증은 Authorization: Bearer (API 키) 헤더로 보냅니다. 제공사에 맞는 파라미터인지 확인 후 사용해주세요.</p>
      </div>

      <div class="tfx-field">
        <label>팩스 API 키 (API 모드에서만 사용)</label>
        <input type="password" name="fax_api_key" value="" placeholder="<?= $faxApiKey !== '' ? '저장되어 있음 (변경 시에만 입력)' : 'API 키 입력' ?>" autocomplete="new-password">
        <label class="tfx-check" <?= $faxApiKey === '' ? 'style="display:none"' : '' ?>>
          <input type="checkbox" name="fax_api_key_clear" value="1"> 저장된 API 키 삭제
        </label>
      </div>

      <div class="tfx-actions">
        <button type="submit" class="btn-admin-primary">설정 저장</button>
      </div>
    </form>
  </div>

  <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0">

  <!-- 발송 대장 -->
  <div class="admin-list-header" style="margin-bottom:12px">
    <h3 style="font-size:15px;font-weight:800;color:#0f172a;margin:0">📋 팩스 발송 대장 <span style="font-size:12px;color:#94a3b8;font-weight:600">(<?= number_format($total) ?>건)</span></h3>
    <form method="get" class="tfx-log-filter">
      <input type="date" name="from" value="<?= h($from) ?>" title="발송 시작일" aria-label="발송 시작일">
      <span style="font-size:12px;color:#94a3b8">~</span>
      <input type="date" name="to" value="<?= h($to) ?>" title="발송 종료일" aria-label="발송 종료일">
      <button type="submit" class="btn-admin-secondary">기간 검색</button>
    </form>
  </div>

  <?php if (empty($faxes)): ?>
    <div class="admin-empty" style="padding:50px 0">발송 기록이 없습니다. 발행 관리에서 "📠 팩스로 발송"을 실행하면 이곳에 기록됩니다.</div>
  <?php else: ?>
    <div class="tfx-log-list">
      <?php foreach ($faxes as $fx): ?>
      <div class="tfx-log-item">
        <div class="tfx-log-head">
          <span class="tfx-log-no"><?= h($fx['req_no']) ?></span>
          <span class="tfx-log-status <?= $fx['status'] === 'sent' ? 'ok' : 'fail' ?>"><?= $fx['status'] === 'sent' ? '발송 성공' : '발송 실패' ?></span>
          <span class="tfx-log-mode"><?= $fx['mode'] === 'api' ? 'API 자동' : '수동' ?></span>
          <span class="tfx-log-date"><?= h(date('Y.m.d H:i', strtotime($fx['sent_at']))) ?></span>
        </div>
        <div class="tfx-log-body">
          <div class="tfx-log-row"><span>수신처</span><strong><?= h((string)($fx['buyer_biz_name'] ?? '-')) ?> · 📠 <?= h(format_fax_number((string)$fx['receiver_fax'])) ?></strong></div>
          <div class="tfx-log-row"><span>금액</span><strong><?= isset($fx['amount']) && $fx['amount'] !== null ? number_format((int)$fx['amount']) . '원' : '-' ?></strong></div>
          <div class="tfx-log-row"><span>발신번호</span><strong><?= $fx['sender_fax'] ? h(format_fax_number((string)$fx['sender_fax'])) : '-' ?></strong></div>
          <div class="tfx-log-row"><span>처리</span><strong><?= h((string)($fx['admin_name'] ?? '관리자')) ?></strong></div>
          <?php if (!empty($fx['result_message'])): ?>
          <div class="tfx-log-row"><span>결과</span><strong style="word-break:break-all"><?= h((string)$fx['result_message']) ?></strong></div>
          <?php endif; ?>
        </div>
        <div class="tfx-log-actions">
          <?php if (($fx['inv_status'] ?? '') === 'issued'): ?>
          <a href="<?= BASE_URL ?>/admin/tax-invoice-print.php?id=<?= (int)$fx['invoice_id'] ?>&fax=1&to=<?= h((string)$fx['receiver_fax']) ?>" target="_blank" class="btn-admin-secondary" style="text-decoration:none;text-align:center">🖨️ 팩스 표지+재인쇄</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a href="?from=<?= h($from) ?>&to=<?= h($to) ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<style>
.tfx-settings{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:20px;}
.tfx-settings-title{font-size:15px;font-weight:800;color:#0f172a;margin:0 0 14px;}
.tfx-settings-form{display:flex;flex-direction:column;gap:14px;}
.tfx-field label{display:block;font-size:12.5px;font-weight:800;color:#334155;margin-bottom:6px;}
.tfx-field .req{color:#ef4444;}
.tfx-field input[type=text],.tfx-field input[type=password]{width:100%;max-width:420px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;}
.tfx-field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.tfx-radio-group{display:flex;flex-direction:column;gap:8px;max-width:640px;}
.tfx-radio{display:flex;gap:10px;align-items:flex-start;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 14px;cursor:pointer;font-size:12.5px;color:#475569;line-height:1.6;}
.tfx-radio:has(input:checked){border-color:#2563eb;background:#eff6ff;}
.tfx-radio input{margin-top:2px;}
.tfx-radio strong{color:#0f172a;}
.tfx-hint{font-size:11px;color:#94a3b8;margin-top:4px;line-height:1.6;max-width:640px;}
.tfx-check{display:flex;gap:6px;align-items:center;font-size:11.5px;color:#64748b;font-weight:700;margin-top:6px;cursor:pointer;}
.tfx-actions{padding-top:4px;}
.tfx-log-filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.tfx-log-filter input[type=date]{padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:12px;color:#475569;}
.tfx-log-list{display:flex;flex-direction:column;gap:10px;}
.tfx-log-item{border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;}
.tfx-log-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;}
.tfx-log-no{font-size:12px;font-weight:800;color:#64748b;letter-spacing:.3px;}
.tfx-log-status{font-size:11px;font-weight:800;padding:3px 10px;border-radius:999px;}
.tfx-log-status.ok{color:#16a34a;background:#16a34a18;}
.tfx-log-status.fail{color:#dc2626;background:#dc262618;}
.tfx-log-mode{font-size:11px;font-weight:700;color:#2563eb;background:#2563eb12;padding:3px 10px;border-radius:999px;}
.tfx-log-date{font-size:11px;color:#94a3b8;margin-left:auto;}
.tfx-log-body{display:grid;grid-template-columns:1.6fr 1fr;gap:8px 16px;align-items:start;}
.tfx-log-row{display:flex;gap:8px;font-size:12px;flex-wrap:wrap;}
.tfx-log-row span{color:#94a3b8;font-weight:700;flex-shrink:0;width:60px;}
.tfx-log-row strong{color:#1e293b;font-weight:600;}
.tfx-log-actions{margin-top:8px;display:flex;justify-content:flex-end;}
@media(max-width:768px){
  .tfx-log-body{grid-template-columns:1fr;}
  .admin-stat-grid{grid-template-columns:1fr !important;}
  .tfx-log-row span{width:52px;}
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
