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
    $where .= ' AND (p.name LIKE :kw OR p.model LIKE :kw OR p.dot_code LIKE :kw)';
    $params['kw'] = '%' . $keyword . '%';
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
    <a href="<?= BASE_URL ?>/admin/products_export.php?<?= h($backQuery) ?>" class="btn-admin-excel">📊 엑셀 다운로드</a>
    <button type="submit" form="bulkDeleteForm" class="btn-bulk-delete" disabled>🗑 선택 삭제</button>
  </div>
</div>

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
  <div class="admin-pagination">
    <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
      <a href="?page=<?= $pg ?>&kw=<?= h($keyword) ?>&category_id=<?= $categoryId ?>&brand_id=<?= $brandId ?>&status=<?= h($statusFilter) ?>" class="<?= $pg === $page ? 'active' : '' ?>"><?= $pg ?></a>
    <?php endfor; ?>
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
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
