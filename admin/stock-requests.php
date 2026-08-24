<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('stock-requests');
$pdo = Database::connection();

// [FIX] 지역 함수 정의를 삭제하고, core/functions.php의 공용 함수를 사용한다.
// 이 함수는 컬럼이 부족하면 자동으로 ALTER TABLE로 채워준다 (스키마 드리프트 방지).
ensure_stock_requests_table($pdo);
ensure_admin_logs_table($pdo);

const STOCK_REQUEST_STATUS_LABELS = [
    'pending'    => '대기',
    'processing' => '처리중',
    'done'       => '완료',
    'cancelled'  => '취소',
];

// 상태 변경 / 관리자 메모 저장 처리
// [FIX] DB 오류가 나더라도 절대 500 화면이 뜨지 않도록 try/catch로 감싼다.
if (is_post() && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다. 새로고침 후 다시 시도해 주세요.');
        redirect('/admin/stock-requests.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $adminMemo = trim((string)($_POST['admin_memo'] ?? ''));

    if ($id <= 0 || !isset(STOCK_REQUEST_STATUS_LABELS[$status])) {
        flash('admin_error', '요청 상태 값이 올바르지 않습니다.');
        redirect('/admin/stock-requests.php');
    }

    try {
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
    } catch (Throwable $e) {
        error_log('[admin/stock-requests update_status] ' . $e->getMessage());
        flash('admin_error', '상태 저장 중 오류가 발생했습니다. 관리자에게 문의해 주세요.');
    }

    redirect('/admin/stock-requests.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

// 목록 조회 (상태 필터 지원)
$filterStatus = $_GET['status'] ?? '';
$where = '';
$params = [];
if ($filterStatus !== '' && isset(STOCK_REQUEST_STATUS_LABELS[$filterStatus])) {
    $where = 'WHERE r.status = :status';
    $params['status'] = $filterStatus;
}

try {
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

    $countStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM tt_stock_requests GROUP BY status");
    $statusCounts = ['pending'=>0,'processing'=>0,'done'=>0,'cancelled'=>0];
    foreach ($countStmt->fetchAll() as $c) $statusCounts[$c['status']] = (int)$c['cnt'];
} catch (Throwable $e) {
    error_log('[admin/stock-requests list] ' . $e->getMessage());
    $requests = [];
    $statusCounts = ['pending'=>0,'processing'=>0,'done'=>0,'cancelled'=>0];
    flash('admin_error', '목록을 불러오는 중 오류가 발생했습니다.');
}

$statusMeta = stock_request_status_meta();
$pageTitle = '재고 요청 관리';
require __DIR__ . '/includes/header.php';
?>

<style>
/* ============ 재고 요청 관리 — 트렌디 UI (이 페이지 전용) ============ */
.sr-hero {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 55%, #a855f7 100%);
    border-radius: 18px;
    padding: 28px 30px;
    color: #fff;
    box-shadow: 0 10px 30px -10px rgba(79,70,229,.5);
    margin-bottom: 20px;
}
.sr-hero h2 { margin: 0 0 6px; font-size: 22px; font-weight: 800; letter-spacing: -0.3px; }
.sr-hero p  { margin: 0; opacity: .9; font-size: 13.5px; }
.sr-filter-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 18px; }
.sr-filter-chip {
    padding: 7px 16px; border-radius: 999px; font-size: 13px; font-weight: 600;
    background: rgba(255,255,255,.16); color: #fff; text-decoration: none;
    border: 1px solid rgba(255,255,255,.3); transition: all .15s ease;
}
.sr-filter-chip:hover { background: rgba(255,255,255,.28); }
.sr-filter-chip.active { background: #fff; color: #4f46e5; border-color: #fff; }

.sr-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
.sr-card {
    background: #fff; border: 1px solid #eef0f4; border-radius: 16px;
    padding: 18px 20px; box-shadow: 0 2px 10px -4px rgba(0,0,0,.06);
    transition: box-shadow .15s ease, transform .15s ease;
}
.sr-card:hover { box-shadow: 0 8px 24px -6px rgba(0,0,0,.12); transform: translateY(-2px); }
.sr-card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.sr-badge {
    display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700;
    padding: 4px 10px; border-radius: 999px;
}
.sr-time { font-size: 11.5px; color: #9aa0ac; margin-top: 4px; }
.sr-name { font-size: 16px; font-weight: 800; color: #1f2330; margin: 2px 0; }
.sr-phone a { color: #4f46e5; font-weight: 600; text-decoration: none; font-size: 13.5px; }
.sr-email { color: #6b7280; font-size: 12.5px; }
.sr-product-box {
    background: #f8f9fc; border-radius: 10px; padding: 10px 12px; margin: 12px 0;
    font-size: 13px; color: #374151;
}
.sr-product-box b { color: #111827; }
.sr-unregistered { color: #b45309; font-size: 11.5px; font-weight: 600; margin-top: 4px; }
.sr-qty { display: inline-block; background: #eef2ff; color: #4338ca; font-weight: 700; font-size: 12px; padding: 2px 8px; border-radius: 6px; }
.sr-memo { font-size: 12.5px; color: #6b7280; margin: 8px 0; white-space: pre-wrap; }
.sr-form { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
.sr-form select {
    flex: 0 0 110px; border: 1px solid #e0e3e8; border-radius: 8px; padding: 7px 8px; font-size: 12.5px;
}
.sr-form textarea {
    flex: 1 1 140px; border: 1px solid #e0e3e8; border-radius: 8px; padding: 7px 10px; font-size: 12.5px; resize: vertical; min-height: 34px;
}
.sr-form button {
    background: linear-gradient(135deg,#4f46e5,#7c3aed); color:#fff; border:none; border-radius: 8px;
    padding: 8px 16px; font-size: 12.5px; font-weight: 700; cursor: pointer; transition: opacity .15s;
}
.sr-form button:hover { opacity: .88; }
.sr-empty { text-align: center; padding: 60px 20px; color: #9aa0ac; font-size: 14px; }
</style>

<div class="sr-hero">
  <h2>📦 재고 요청 관리</h2>
  <p>회원이 상품 목록/상세페이지에서 접수한 재고 요청입니다. 회원가입 정보(이름·연락처·이메일)와 요청 상품을 확인하고 처리 상태를 관리하세요.</p>
  <div class="sr-filter-row">
    <a href="?status=" class="sr-filter-chip <?= $filterStatus==='' ? 'active' : '' ?>">전체 (<?= array_sum($statusCounts) ?>)</a>
    <?php foreach (STOCK_REQUEST_STATUS_LABELS as $key => $label): ?>
      <a href="?status=<?= $key ?>" class="sr-filter-chip <?= $filterStatus===$key ? 'active' : '' ?>">
        <?= $statusMeta[$key]['icon'] ?> <?= $label ?> (<?= $statusCounts[$key] ?>)
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (empty($requests)): ?>
  <div class="sr-empty">해당 조건의 재고 요청이 없습니다.</div>
<?php else: ?>
<div class="sr-card-grid">
  <?php foreach ($requests as $r):
      $meta = $statusMeta[$r['status']] ?? $statusMeta['pending'];
  ?>
  <div class="sr-card">
    <div class="sr-card-top">
      <div>
        <div class="sr-name"><?= h($r['customer_name']) ?></div>
        <div class="sr-phone"><a href="tel:<?= h($r['customer_phone']) ?>">📞 <?= h($r['customer_phone']) ?></a></div>
        <?php if (!empty($r['customer_email'])): ?>
          <div class="sr-email">✉️ <?= h($r['customer_email']) ?></div>
        <?php endif; ?>
      </div>
      <div style="text-align:right;">
        <span class="sr-badge" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>;"><?= $meta['icon'] ?> <?= $meta['label'] ?></span>
        <div class="sr-time"><?= h($r['created_at']) ?></div>
      </div>
    </div>

    <div class="sr-product-box">
      <?php if (!empty($r['product_name'])): ?>
        <b><?= h($r['product_name']) ?></b> <span style="color:#9aa0ac;">#<?= (int)$r['product_id'] ?></span>
      <?php else: ?>
        <?= !empty($r['brand_text']) ? h($r['brand_text']) . ' ' : '' ?><?= h($r['size_text']) ?>
        <div class="sr-unregistered">⚠ 미등록 상품</div>
      <?php endif; ?>
      <div style="margin-top:6px;">요청 수량 <span class="sr-qty"><?= (int)$r['requested_qty'] ?>개</span></div>
    </div>

    <?php if (!empty($r['memo'])): ?>
      <div class="sr-memo">💬 <?= nl2br(h($r['memo'])) ?></div>
    <?php endif; ?>

    <form method="post" class="sr-form">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <select name="status">
        <?php foreach (STOCK_REQUEST_STATUS_LABELS as $key => $label): ?>
          <option value="<?= $key ?>" <?= $r['status']===$key ? 'selected' : '' ?>><?= $statusMeta[$key]['icon'] ?> <?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <textarea name="admin_memo" placeholder="처리 메모"><?= h($r['admin_memo'] ?? '') ?></textarea>
      <button type="submit">저장</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
