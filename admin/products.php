<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');

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
 * 반환값 예: [1, '…', 4, 5, 6, 7, 8, '…', 42]
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

/* [신규] 상품 전체 삭제 - 필터 조건 무시하고 DB의 모든 상품을 삭제하는 파괴적 동작.
   1) CSRF 검증, 2) super 권한 검증, 3) 확인 문구("전체삭제") 서버측 재검증까지 3중 체크 후에만 실행한다. */
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

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_products p WHERE {$where}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare("SELECT p.id, p.name, p.model, p.spec, p.origin, p.dot_code, p.thumbnail_url,
                                   p.price_original, p.price_sale, p.stock, p.status, p.sales_count, p.created_at,
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

$totalPages = max(1, (int)ceil($totalCount / $perPage));
$pageRange  = admin_build_page_range($page, $totalPages, 2);

$categories = $pdo->query("SELECT id, name FROM tt_categories ORDER BY name")->fetchAll();
$brands     = $pdo->query("SELECT id, name FROM tt_brands ORDER BY name")->fetchAll();

$backQuery = http_build_query(['kw' => $keyword, 'category_id' => $categoryId, 'brand_id' => $brandId, 'status' => $statusFilter, 'page' => $page]);

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
    <button type="submit" form="bulkDeleteForm" class="btn-bulk-delete" disabled>🗑 선택 삭제</button>
  </div>
</div>

<?php if (AdminAuth::isSuper()): ?>
<!-- [신규] 전체 삭제 영역: 최고관리자만 보이고, 확인 문구를 정확히 입력해야만 버튼이 활성화된다.
     오탐/오클릭 방지를 위해 일반 툴바와 시각적으로 분리(경고색 박스)해두었다. -->
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
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($products)): ?>
      <tr><td colspan="12" class="admin-empty-row">📦 등록된 상품이 없습니다.</td></tr>
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
        <td class="mono <?= (int)$p['stock'] < 5 ? 'admin-stock-low' : '' ?>">
          <?= number_format((int)$p['stock']) ?>
          <?php if ((int)$p['stock'] < 5): ?><span class="admin-mini-badge">부족</span><?php endif; ?>
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
        <td><a href="<?= BASE_URL ?>/admin/product_form.php?id=<?= (int)$p['id'] ?>" class="admin-link-btn">수정</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
  <!-- [수정] 페이지 수가 많아도 잘리지 않도록 flex-wrap을 인라인으로 강제 적용.
       숫자는 admin_build_page_range()가 만든 순서(1, …, 현재-2 ~ 현재+2, …, 마지막)대로 그대로 출력한다. -->
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

// [신규] 전체 삭제 확인 문구 입력 검증: "전체삭제" 를 정확히 입력해야만 실행 버튼이 활성화된다.
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
