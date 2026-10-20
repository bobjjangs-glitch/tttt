<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');
ensure_stock_movement_tables();
ensure_ecount_tables(Database::connection()); // [신규] 이카운트 관련 컬럼/테이블 자동 생성 보장

$pdo = Database::connection();

function admin_delete_products(PDO $pdo, array $ids): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (empty($ids)) return 0;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM tt_wishlists WHERE product_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM tt_carts WHERE product_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM tt_product_options WHERE product_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM tt_product_detail_images WHERE product_id IN ({$placeholders})")->execute($ids);
        $stmt = $pdo->prepare("DELETE FROM tt_products WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        $affected = $stmt->rowCount();
        $pdo->commit();
        return $affected;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[admin/products delete] ' . $e->getMessage());
        return -1;
    }
}

/**
 * 필터/검색 조건 상관없이 tt_products 전체 및 연관 테이블 데이터를 모두 삭제한다.
 * 매우 파괴적인 동작이므로 호출 전 반드시 super 권한 체크와 확인 문구 검증을 마친 상태여야 한다.
 */
function admin_delete_all_products(PDO $pdo): int
{
    $pdo->beginTransaction();
    try {
        $totalBefore = (int)$pdo->query("SELECT COUNT(*) FROM tt_products")->fetchColumn();
        $pdo->exec("DELETE FROM tt_wishlists");
        $pdo->exec("DELETE FROM tt_carts");
        $pdo->exec("DELETE FROM tt_product_options");
        $pdo->exec("DELETE FROM tt_product_detail_images");
        $pdo->exec("DELETE FROM tt_products");
        $pdo->commit();
        return $totalBefore;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[admin/products delete_all] ' . $e->getMessage());
        return -1;
    }
}

/**
 * 전체 페이지 수가 많아도 링크가 잘리지 않도록,
 * 현재 페이지 기준 앞뒤 $window개 + 처음/끝 페이지만 뽑아 "..." 로 축약한 배열을 만든다.
 */
function admin_build_page_range(int $current, int $total, int $window = 2): array
{
    if ($total <= 1) return [1];

    $range = [];
    $start = max(1, $current - $window);
    $end   = min($total, $current + $window);

    if ($start > 1) {
        $range[] = 1;
        if ($start > 2) $range[] = '…';
    }
    for ($i = $start; $i <= $end; $i++) {
        $range[] = $i;
    }
    if ($end < $total) {
        if ($end < $total - 1) $range[] = '…';
        $range[] = $total;
    }
    return $range;
}

/**
 * 메인화면에서 접수된 재고 요청 중 '대기(pending)' 상태인 건수를 센다.
 */
function admin_count_pending_stock_requests(PDO $pdo): int
{
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM tt_stock_requests WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * [신규] 이카운트 연동 현황 요약 — 품목코드 미등록 수 / 전송대기 수 / 전송실패 수.
 * tt_ecount_sync_queue 테이블이 아직 없는 서버(배포 전)에서도 화면이 죽지 않도록 방어한다.
 */
function admin_get_ecount_summary(PDO $pdo): array
{
    $summary = ['unmapped' => 0, 'pending' => 0, 'failed' => 0, 'table_missing' => false];
    try {
        $summary['unmapped'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM tt_products WHERE status='active' AND (ecount_item_cd IS NULL OR ecount_item_cd = '')"
        )->fetchColumn();
    } catch (Throwable $e) {
        $summary['table_missing'] = true;
        return $summary; // ecount_item_cd 컬럼 자체가 없으면 큐 조회도 의미 없음
    }
    try {
        $summary['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM tt_ecount_sync_queue WHERE status='pending'")->fetchColumn();
        $summary['failed']  = (int)$pdo->query("SELECT COUNT(*) FROM tt_ecount_sync_queue WHERE status='failed'")->fetchColumn();
    } catch (Throwable $e) {
        $summary['table_missing'] = true;
    }
    return $summary;
}

if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/products.php');
    }
    $pid = (int)($_POST['product_id'] ?? 0);
    $pdo->prepare("UPDATE tt_products SET status = IF(status='active','hidden','active') WHERE id = :id")
        ->execute(['id' => $pid]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'product_toggle_status', "상품#{$pid} 상태 전환");
    flash('admin_success', '상품 상태가 변경되었습니다.');
    redirect('/admin/products.php' . (isset($_POST['back']) ? '?' . $_POST['back'] : ''));
}

if (is_post() && ($_POST['form_type'] ?? '') === 'delete_products') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/products.php');
    }
    $ids = $_POST['product_ids'] ?? [];
    $result = admin_delete_products($pdo, is_array($ids) ? $ids : []);
    if ($result > 0) {
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'product_delete', "{$result}건 상품 삭제");
        flash('admin_success', "{$result}건의 상품이 삭제되었습니다.");
    } elseif ($result === 0) {
        flash('admin_error', '선택된 상품이 없습니다.');
    } else {
        flash('admin_error', '삭제 중 오류가 발생했습니다.');
    }
    redirect('/admin/products.php');
}

/* [신규] 상품 전체 삭제 - 필터 조건 무시하고 DB의 모든 상품을 삭제하는 파괴적 동작. */
if (is_post() && ($_POST['form_type'] ?? '') === 'delete_all_products') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/products.php');
    }
    if (!AdminAuth::isSuper()) {
        flash('admin_error', '전체 삭제는 최고관리자만 실행할 수 있습니다.');
        redirect('/admin/products.php');
    }
    $confirmText = trim($_POST['confirm_text'] ?? '');
    if ($confirmText !== '전체삭제') {
        flash('admin_error', '확인 문구가 일치하지 않아 전체 삭제가 취소되었습니다.');
        redirect('/admin/products.php');
    }
    $result = admin_delete_all_products($pdo);
    if ($result > 0) {
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'product_delete_all', "전체 상품 {$result}건 일괄 삭제");
        flash('admin_success', "전체 {$result}건의 상품이 모두 삭제되었습니다.");
    } elseif ($result === 0) {
        flash('admin_error', '삭제할 상품이 없습니다.');
    } else {
        flash('admin_error', '전체 삭제 중 오류가 발생했습니다.');
    }
    redirect('/admin/products.php');
}

$keyword    = trim($_GET['kw'] ?? '');
$categoryId = (int)($_GET['category_id'] ?? 0);
$brandId    = (int)($_GET['brand_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$stockLowFilter = isset($_GET['stock_low']) && $_GET['stock_low'] === '1';
$stockZeroFilter = isset($_GET['stock_zero']) && $_GET['stock_zero'] === '1';
$ecountFilter = $_GET['ecount'] ?? ''; // [신규] unmapped / pending / failed
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];
if ($keyword !== '') {
    $where .= ' AND (
        p.name LIKE :kw1
        OR p.model LIKE :kw2
        OR p.dot_code LIKE :kw3
        OR EXISTS (
            SELECT 1 FROM tt_product_options po
            WHERE po.product_id = p.id AND po.dot_code LIKE :kw4
        )
    )';
    $params['kw1'] = '%' . $keyword . '%';
    $params['kw2'] = '%' . $keyword . '%';
    $params['kw3'] = '%' . $keyword . '%';
    $params['kw4'] = '%' . $keyword . '%';
}
if ($categoryId > 0) {
    $where .= ' AND p.category_id = :cat';
    $params['cat'] = $categoryId;
}
if ($brandId > 0) {
    $where .= ' AND p.brand_id = :brand';
    $params['brand'] = $brandId;
}
if ($statusFilter === 'active' || $statusFilter === 'hidden') {
    $where .= ' AND p.status = :status';
    $params['status'] = $statusFilter;
}
if ($stockLowFilter) {
    $where .= ' AND p.stock <= p.min_stock';
}
if ($stockZeroFilter) {
    $where .= ' AND p.stock <= 0';
}
/* [신규] ERP 연동 상태 필터 — 미매핑 상품만 모아보기 */
if ($ecountFilter === 'unmapped') {
    $where .= " AND (p.ecount_item_cd IS NULL OR p.ecount_item_cd = '')";
}

/* 재고 부족/품절 상품 수 (알림 배너용) */
try {
    $lowCount = (int)$pdo->query('SELECT COUNT(*) FROM tt_products WHERE status = "active" AND stock <= min_stock')->fetchColumn();
} catch (Throwable $e) {
    $lowCount = 0;
}
try {
    $zeroCount = (int)$pdo->query('SELECT COUNT(*) FROM tt_products WHERE status = "active" AND stock <= 0')->fetchColumn();
} catch (Throwable $e) {
    $zeroCount = 0;
}

/* [신규] 이카운트 연동 현황 요약 */
$ecountSummary = admin_get_ecount_summary($pdo);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_products p WHERE {$where}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

/* [변경] ecount_item_cd / ecount_wh_cd / ecount_synced_at 과 최근 큐 상태(eq)를 함께 조회.
   tt_ecount_sync_queue가 없는 서버에서도 목록 자체는 죽지 않도록 try/catch 이중 경로를 둔다. */
try {
    $listStmt = $pdo->prepare("SELECT p.id, p.name, p.model, p.spec, p.origin, p.dot_code, p.thumbnail_url,
                                       p.price_original, p.price_sale, p.stock, p.min_stock, p.status, p.sales_count, p.created_at,
                                       p.ecount_item_cd, p.ecount_wh_cd, p.ecount_synced_at,
                                       eq.latest_status AS ecount_latest_status, eq.latest_error AS ecount_latest_error,
                                       c.name AS category_name, b.name AS brand_name
                                FROM tt_products p
                                LEFT JOIN tt_categories c ON c.id = p.category_id
                                LEFT JOIN tt_brands b ON b.id = p.brand_id
                                LEFT JOIN (
                                    SELECT q1.product_id, q1.status AS latest_status, q1.last_error AS latest_error
                                    FROM tt_ecount_sync_queue q1
                                    INNER JOIN (
                                        SELECT product_id, MAX(id) AS max_id FROM tt_ecount_sync_queue GROUP BY product_id
                                    ) q2 ON q1.product_id = q2.product_id AND q1.id = q2.max_id
                                ) eq ON eq.product_id = p.id
                                WHERE {$where}
                                ORDER BY p.id DESC
                                LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) {
        $listStmt->bindValue($k, $v);
    }
    $listStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $products = $listStmt->fetchAll();
} catch (Throwable $e) {
    /* tt_ecount_sync_queue 미생성 등으로 조인 실패 시, ERP 컬럼 없이라도 목록은 반드시 떠야 한다. */
    error_log('[admin/products] ecount join fallback: ' . $e->getMessage());
    $listStmt = $pdo->prepare("SELECT p.id, p.name, p.model, p.spec, p.origin, p.dot_code, p.thumbnail_url,
                                       p.price_original, p.price_sale, p.stock, p.min_stock, p.status, p.sales_count, p.created_at,
                                       c.name AS category_name, b.name AS brand_name
                                FROM tt_products p
                                LEFT JOIN tt_categories c ON c.id = p.category_id
                                LEFT JOIN tt_brands b ON b.id = p.brand_id
                                WHERE {$where}
                                ORDER BY p.id DESC
                                LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) {
        $listStmt->bindValue($k, $v);
    }
    $listStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $products = $listStmt->fetchAll();
}

$totalPages = max(1, (int)ceil($totalCount / $perPage));
$pageRange  = admin_build_page_range($page, $totalPages, 2);

$categories = $pdo->query("SELECT id, name FROM tt_categories ORDER BY name")->fetchAll();
$brands     = $pdo->query("SELECT id, name FROM tt_brands ORDER BY name")->fetchAll();
$pendingStockRequestCount = admin_count_pending_stock_requests($pdo);

$backQuery = http_build_query(['kw' => $keyword, 'category_id' => $categoryId, 'brand_id' => $brandId, 'status' => $statusFilter, 'page' => $page]);

/**
 * [신규] 상품 1건의 ERP 연동 상태 배지 HTML을 만든다.
 * 우선순위: 품목코드 미등록 > 직전 전송 실패 > 전송 대기중 > 한번도 동기화 안됨 > 정상 연동됨.
 */
function admin_render_ecount_badge(array $p): string
{
    $itemCd = trim((string)($p['ecount_item_cd'] ?? ''));
    if ($itemCd === '') {
        return '<span class="admin-mini-badge" style="background:#f1f5f9;color:#64748b;" title="상품 수정 화면에서 이카운트 품목코드를 등록하세요.">⚪ 미연동</span>';
    }
    $latestStatus = $p['ecount_latest_status'] ?? null;
    if ($latestStatus === 'failed') {
        $err = h(mb_substr((string)($p['ecount_latest_error'] ?? ''), 0, 80));
        return '<span class="admin-mini-badge" style="background:#fee2e2;color:#b91c1c;" title="' . $err . '">🔴 전송 실패</span>';
    }
    if ($latestStatus === 'pending') {
        return '<span class="admin-mini-badge" style="background:#fef9c3;color:#854d0e;">🟡 전송 대기중</span>';
    }
    $syncedAt = $p['ecount_synced_at'] ?? null;
    if (!$syncedAt) {
        return '<span class="admin-mini-badge" style="background:#fef9c3;color:#854d0e;">🟡 품목코드 등록됨(동기화 이력 없음)</span>';
    }
    return '<span class="admin-mini-badge" style="background:#dcfce7;color:#166534;" title="마지막 동기화: ' . h($syncedAt) . '">🟢 연동됨</span>';
}

$pageTitle = '상품 관리';
require __DIR__ . '/includes/header.php';
?>

<form method="post" id="bulkDeleteForm" onsubmit="return confirm('선택한 상품을 정말 삭제하시겠습니까?\n삭제된 상품은 복구할 수 없습니다.');">
<?= Csrf::field() ?>
<input type="hidden" name="form_type" value="delete_products">
</form>

<div class="admin-toolbar">
  <form method="get" class="admin-filter-form-wide">
    <input type="text" name="kw" value="<?= h($keyword) ?>" placeholder="상품명, 모델명, DOT 검색" class="admin-input-search">
    <select name="category_id">
      <option value="0">전체 카테고리</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $categoryId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="brand_id">
      <option value="0">전체 브랜드</option>
      <?php foreach ($brands as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= $brandId === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status">
      <option value="">전체 상태</option>
      <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>판매중</option>
      <option value="hidden" <?= $statusFilter === 'hidden' ? 'selected' : '' ?>>숨김</option>
    </select>
    <button type="submit" class="btn-admin-primary">검색</button>
  </form>
  <div class="admin-toolbar-right">
    <a href="<?= BASE_URL ?>/admin/product_form.php" class="btn-admin-add">➕ 상품 등록</a>
    <a href="<?= BASE_URL ?>/admin/products_import.php" class="btn-admin-excel">📁 엑셀 일괄 업로드</a>
    <a href="<?= BASE_URL ?>/admin/products_export.php?<?= h($backQuery) ?>" class="btn-admin-excel">📊 엑셀 다운로드</a>
    <a href="<?= BASE_URL ?>/admin/stock_update.php" class="btn-admin-excel">📦 재고 일괄 업데이트</a>
    <a href="<?= BASE_URL ?>/admin/stock-movements.php" class="btn-admin-excel">📋 재고 입출고 이력</a>
    <a href="<?= BASE_URL ?>/admin/stock-requests.php" class="btn-admin-excel" style="position:relative;">
      📥 재고 요청 관리
      <?php if ($pendingStockRequestCount > 0): ?>
        <span style="position:absolute;top:-8px;right:-8px;background:#d93025;color:#fff;border-radius:999px;font-size:11px;line-height:1;padding:3px 6px;font-weight:700;">
          <?= $pendingStockRequestCount > 99 ? '99+' : $pendingStockRequestCount ?>
        </span>
      <?php endif; ?>
    </a>
    <?php if ($lowCount > 0): ?>
    <a href="<?= BASE_URL ?>/admin/products.php?stock_low=1" class="btn-admin-excel" style="background:#fef2f2;color:#b91c1c;font-weight:700;">
      ⚠️ 재고 부족 <?= $lowCount ?>건
    </a>
    <?php endif; ?>
    <?php if ($zeroCount > 0): ?>
    <a href="<?= BASE_URL ?>/admin/products.php?stock_zero=1" class="btn-admin-excel" style="background:#fef2f2;color:#b91c1c;font-weight:700;">
      📦 품절 (고객 구매 차단) <?= $zeroCount ?>건
    </a>
    <?php endif; ?>
    <button type="submit" form="bulkDeleteForm" class="btn-bulk-delete" disabled>🗑 선택 삭제</button>
  </div>
</div>

<?php /* [신규] 이카운트(ERP) 연동 현황 요약 카드 — 화면 최상단에서 바로 보이도록 배치 */ ?>
<div class="admin-card" style="margin-top:14px;border:1px solid #c7d2fe;background:#eef2ff;">
  <h2 style="color:#3730a3;">🔗 이카운트(ERP) 연동 현황</h2>
  <?php if ($ecountSummary['table_missing']): ?>
    <p style="color:#7f1d1d;font-size:13px;line-height:1.6;">
      ⚠ 이카운트 연동 테이블/컬럼이 아직 생성되지 않았습니다. <code>core/EcountClient.php</code>가 서버에 배포되고
      관리자 페이지를 한 번이라도 거쳐야 <code>ensure_ecount_tables()</code>가 자동으로 컬럼을 만듭니다.
      이 메시지가 계속 보이면 배포 자체가 안 된 것이니 서버 파일을 다시 확인해 주세요.
    </p>
  <?php else: ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a href="<?= BASE_URL ?>/admin/products.php?ecount=unmapped" class="btn-admin-excel" style="background:#fff;color:#4338ca;font-weight:700;border:1px solid #c7d2fe;">
        ⚪ 품목코드 미등록 <?= number_format($ecountSummary['unmapped']) ?>건
      </a>
      <span class="btn-admin-excel" style="background:#fff;color:#854d0e;font-weight:700;border:1px solid #fde68a;">
        🟡 전송 대기중 <?= number_format($ecountSummary['pending']) ?>건
      </span>
      <?php if ($ecountSummary['failed'] > 0): ?>
      <span class="btn-admin-excel" style="background:#fff;color:#b91c1c;font-weight:700;border:1px solid #fecaca;">
        🔴 전송 실패 <?= number_format($ecountSummary['failed']) ?>건 — cron/ecount_push_worker.php 로그 확인 필요
      </span>
      <?php endif; ?>
    </div>
    <p style="color:#4338ca;font-size:12px;margin-top:8px;">
      ※ 이 숫자는 큐(tt_ecount_sync_queue)에 적재된 기록 기준입니다. 서버에 cron이 등록되어 있지 않으면
      "대기중" 건수가 계속 쌓이기만 하고 실제 이카운트에는 영원히 반영되지 않습니다. 반드시 cron 등록 여부를 확인하세요.
    </p>
  <?php endif; ?>
</div>

<?php if (AdminAuth::isSuper()): ?>
<div class="admin-card" style="margin-top:14px;border:1px solid #f87171;background:#fff5f5;">
  <h2 style="color:#b91c1c;">⚠ 위험 구역: 상품 전체 삭제</h2>
  <p style="color:#7f1d1d;font-size:13px;line-height:1.6;">
    검색/필터 조건과 무관하게 DB에 등록된 <b>모든 상품(현재 <?= number_format($totalCount) ?>개 기준 전체 <?= number_format((int)$pdo->query("SELECT COUNT(*) FROM tt_products")->fetchColumn()) ?>개)</b>이 영구적으로 삭제됩니다.<br>
    이 작업은 되돌릴 수 없습니다. 실행하려면 아래 입력란에 정확히 <b>전체삭제</b> 를 입력하세요.
  </p>
  <form method="post" id="deleteAllForm" onsubmit="return confirm('정말로 상품 전체를 삭제하시겠습니까?\n이 작업은 절대 되돌릴 수 없습니다.');">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_type" value="delete_all_products">
    <input type="text" name="confirm_text" id="confirmAllText" placeholder="여기에 '전체삭제' 입력" autocomplete="off" style="padding:6px 10px;border:1px solid #f87171;border-radius:6px;">
    <button type="submit" id="btnDeleteAll" class="btn-bulk-delete" disabled style="background:#b91c1c;">🧨 상품 전체 삭제 실행</button>
  </form>
</div>
<?php endif; ?>

<div class="admin-card">
  <h2>상품 목록 <span class="admin-count-pill"><?= number_format($totalCount) ?>개</span></h2>
  <table class="admin-table-trendy">
    <thead>
      <tr>
        <th style="width:36px"><input type="checkbox" id="chkAll"></th>
        <th style="width:64px">이미지</th>
        <th>상품명</th>
        <th>브랜드</th>
        <th>DOT</th>
        <th>사이즈/규격</th>
        <th>원산지</th>
        <th>정상가</th>
        <th>대표판매가</th>
        <th>재고</th>
        <th>상태</th>
        <th>ERP연동</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($products)): ?>
      <tr><td colspan="13" class="admin-empty-row">📦 등록된 상품이 없습니다.</td></tr>
    <?php else: foreach ($products as $p): ?>
      <tr>
        <td><input type="checkbox" name="product_ids[]" value="<?= (int)$p['id'] ?>" class="chkRow" form="bulkDeleteForm"></td>
        <td>
          <?php if ($p['thumbnail_url']): ?>
            <img src="<?= h($p['thumbnail_url']) ?>" alt="" class="admin-thumb-img">
          <?php else: ?>
            <div class="admin-thumb-placeholder">🛞</div>
          <?php endif; ?>
        </td>
        <td>
          <div class="admin-prod-name"><?= h($p['name']) ?></div>
          <?php if ($p['model']): ?><div class="admin-text-sub"><?= h($p['model']) ?></div><?php endif; ?>
        </td>
        <td><?= h($p['brand_name'] ?? '-') ?></td>
        <td class="admin-text-sub mono"><?= h($p['dot_code'] ?? '-') ?></td>
        <td class="admin-text-sub mono"><?= h($p['spec'] ?? '-') ?></td>
        <td class="admin-text-sub"><?= h($p['origin'] ?? '-') ?></td>
        <td class="mono admin-text-sub"><?= format_price((int)$p['price_original']) ?></td>
        <td class="mono"><strong><?= format_price((int)$p['price_sale']) ?></strong></td>
        <td class="mono <?= (int)$p['stock'] <= (int)($p['min_stock'] ?? 5) ? 'admin-stock-low' : '' ?>">
          <?= number_format((int)$p['stock']) ?>
          <?php if ((int)$p['stock'] <= 0): ?><span class="admin-mini-badge" style="background:#fee2e2;color:#b91c1c;">품절·구매차단</span>
          <?php elseif ((int)$p['stock'] < 5): ?><span class="admin-mini-badge">부족</span><?php endif; ?>
        </td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="toggle_status">
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="back" value="<?= h($backQuery) ?>">
            <button type="submit" class="status-toggle-btn status-badge status-<?= $p['status'] === 'active' ? 'done' : 'cancelled' ?>">
              <?= $p['status'] === 'active' ? '판매중' : '숨김' ?>
            </button>
          </form>
        </td>
        <td><?= admin_render_ecount_badge($p) ?></td>
        <td><a href="<?= BASE_URL ?>/admin/product_form.php?id=<?= (int)$p['id'] ?>" class="admin-link-btn">수정</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
  <div class="admin-pagination" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;overflow:visible;">
    <?php if ($page > 1): ?>
      <a href="?page=<?= $page - 1 ?>&kw=<?= h($keyword) ?>&category_id=<?= $categoryId ?>&brand_id=<?= $brandId ?>&status=<?= h($statusFilter) ?>">‹ 이전</a>
    <?php endif; ?>

    <?php foreach ($pageRange as $pg): ?>
      <?php if ($pg === '…'): ?>
        <span style="padding:4px 6px;color:#999;">…</span>
      <?php else: ?>
        <a href="?page=<?= $pg ?>&kw=<?= h($keyword) ?>&category_id=<?= $categoryId ?>&brand_id=<?= $brandId ?>&status=<?= h($statusFilter) ?>" class="<?= $pg === $page ? 'active' : '' ?>"><?= $pg ?></a>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($page < $totalPages): ?>
      <a href="?page=<?= $page + 1 ?>&kw=<?= h($keyword) ?>&category_id=<?= $categoryId ?>&brand_id=<?= $brandId ?>&status=<?= h($statusFilter) ?>">다음 ›</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<script>
(function(){
  const chkAll = document.getElementById('chkAll');
  const bulkBtn = document.querySelector('.btn-bulk-delete');
  function rows(){ return document.querySelectorAll('.chkRow'); }
  function updateBtn(){
    const checked = document.querySelectorAll('.chkRow:checked').length;
    bulkBtn.disabled = checked === 0;
    bulkBtn.textContent = checked > 0 ? `🗑 선택 삭제 (${checked}건)` : '🗑 선택 삭제';
  }
  chkAll?.addEventListener('change', function(){ rows().forEach(r => r.checked = chkAll.checked); updateBtn(); });
  rows().forEach(r => r.addEventListener('change', updateBtn));
  updateBtn();
})();

(function(){
  const input = document.getElementById('confirmAllText');
  const btn = document.getElementById('btnDeleteAll');
  if (!input || !btn) return;
  input.addEventListener('input', function(){
    btn.disabled = (input.value.trim() !== '전체삭제');
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
