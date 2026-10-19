<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('stock-movements');
ensure_stock_movement_tables();

$pdo = Database::connection();
$pageTitle = '재고 입출고 이력';

$okMsg  = flash('admin_success');
$errMsg = flash('admin_error');

/* ===== 필터 ===== */
$filterFrom = $_GET['from'] ?? date('Y-m-01');
if ($filterFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) $filterFrom = '';
$filterTo = $_GET['to'] ?? '';
if ($filterTo && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) $filterTo = '';
$filterType = $_GET['type'] ?? '';
if ($filterType && !in_array($filterType, ['in', 'out', 'adjust'], true)) $filterType = '';
$search = trim((string)($_GET['q'] ?? ''));

$typeLabels = ['in' => '📥 입고', 'out' => '📤 출고', 'adjust' => '⚙️ 조정'];

$where = 'WHERE 1=1';
$params = [];
if ($filterFrom) { $where .= ' AND m.created_at >= :from'; $params[':from'] = $filterFrom . ' 00:00:00'; }
if ($filterTo)   { $where .= ' AND m.created_at <= :to'; $params[':to'] = $filterTo . ' 23:59:59'; }
if ($filterType) { $where .= ' AND m.change_type = :t'; $params[':t'] = $filterType; }
if ($search !== '') {
    $where .= ' AND (p.name LIKE :q OR p.sku LIKE :q OR m.note LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/* [v39] 스키마 문제가 있어도 500 대신 안내 상자로 — 침묵 오류 방지 */
$schemaErrorMsg = null;

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_stock_movements m JOIN tt_products p ON p.id = m.product_id {$where}");
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
} catch (Throwable $e) {
    sg_maybe_invalidate($e);
    error_log('[stock-movements count] ' . $e->getMessage());
    $schemaErrorMsg = '재고 이력을 불러오는 중 오류가 발생했습니다. (DB 스키마 확인 필요)';
    $total = 0;
}
$totalPages = max(1, (int)ceil($total / $perPage));

$listStmt = $pdo->prepare(
    "SELECT m.*, p.name AS product_name, p.sku, p.spec, p.stock AS current_stock, p.min_stock,
            o.size AS option_size, o.dot_code AS option_dot
     FROM tt_stock_movements m
     JOIN tt_products p ON p.id = m.product_id
     LEFT JOIN tt_product_options o ON o.id = m.option_id
     {$where}
     ORDER BY m.id DESC
     LIMIT {$perPage} OFFSET {$offset}");
try {
    foreach ($params as $k => $v) $listStmt->bindValue($k, $v);
    $listStmt->execute();
    $movements = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sg_maybe_invalidate($e);
    error_log('[stock-movements list] ' . $e->getMessage());
    $schemaErrorMsg = $schemaErrorMsg ?: '재고 이력을 불러오는 중 오류가 발생했습니다. (DB 스키마 확인 필요)';
    $movements = [];
}

/* 기간 합계 */
$sumStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN m.change_type='in' THEN 1 ELSE 0 END),0) AS in_cnt,
            COALESCE(SUM(CASE WHEN m.change_type='out' THEN 1 ELSE 0 END),0) AS out_cnt,
            COALESCE(SUM(CASE WHEN m.change_type='adjust' THEN 1 ELSE 0 END),0) AS adj_cnt
     FROM tt_stock_movements m JOIN tt_products p ON p.id = m.product_id {$where}");
try {
    foreach ($params as $k => $v) $sumStmt->bindValue($k, $v);
    $sumStmt->execute();
    $sum = $sumStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sg_maybe_invalidate($e);
    error_log('[stock-movements sum] ' . $e->getMessage());
    $sum = ['in_cnt' => 0, 'out_cnt' => 0, 'adj_cnt' => 0];
}

/* 부족 재고 (알림) */
$lowStock = [];
try {
    $lowStock = $pdo->query(
        'SELECT id, name, spec, sku, stock, min_stock FROM tt_products
         WHERE status = "active" AND stock <= min_stock ORDER BY stock ASC LIMIT 50'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$returnQuery = http_build_query(array_filter([
    'from'=>$filterFrom ?: null, 'to'=>$filterTo ?: null, 'type'=>$filterType ?: null, 'q'=>$search ?: null,
]));

require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">📦 재고 입출고 이력</h2>
      <p class="admin-list-sub">주문 출고(자동)·재고 일괄 업데이트·상품 재고 수정이 모두 이력으로 기록됩니다.</p>
    </div>
    <a class="btn-admin-excel" href="<?= BASE_URL ?>/admin/stock_update.php" style="padding:8px 14px;font-size:13px;text-decoration:none;">📥 재고 일괄 업데이트</a>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>
  <?php if ($schemaErrorMsg): ?><div class="alert alert-error">⚠ <?= h($schemaErrorMsg) ?> — 페이지를 한 번 더 새로고침해주세요. (필요한 DB 컬럼이 자동으로 추가됩니다)</div><?php endif; ?>

  <!-- 부족 알림 -->
  <?php if (!empty($lowStock)): ?>
  <div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:12px;padding:14px 16px;margin-bottom:16px;">
    <div style="font-size:14px;font-weight:800;color:#dc2626;margin-bottom:6px;">⚠️ 재고 부족 상품 <?= count($lowStock) ?>건 (부족 기준 이하)</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php foreach (array_slice($lowStock, 0, 12) as $lp): ?>
      <a href="<?= BASE_URL ?>/admin/product_form.php?id=<?= (int)$lp['id'] ?>"
         style="font-size:12px;background:#fff;border:1px solid #fecaca;border-radius:8px;padding:4px 10px;text-decoration:none;color:#991b1b;">
        <?= h(mb_strlen($lp['name']) > 26 ? mb_substr($lp['name'], 0, 26) . '…' : $lp['name']) ?> — 재고 <b><?= (int)$lp['stock'] ?></b>/기준 <?= (int)$lp['min_stock'] ?>
      </a>
      <?php endforeach; ?>
      <?php if (count($lowStock) > 12): ?>
      <a href="<?= BASE_URL ?>/admin/products.php?stock_low=1" style="font-size:12px;font-weight:700;color:#dc2626;padding:4px 10px;">전체 보기 →</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- 필터 -->
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:flex-end;">
    <div>
      <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">기간 시작일</label>
      <input type="date" name="from" value="<?= h($filterFrom) ?>" style="padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
    </div>
    <div>
      <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">기간 종료일</label>
      <input type="date" name="to" value="<?= h($filterTo) ?>" style="padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
    </div>
    <div>
      <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">구분</label>
      <select name="type" style="padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
        <option value="">전체</option>
        <?php foreach ($typeLabels as $tk => $tl): ?>
        <option value="<?= $tk ?>" <?= $filterType === $tk ? 'selected' : '' ?>><?= $tl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:1;min-width:180px">
      <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">검색 (상품명/SKU/적요)</label>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="예: 225/45R18" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
    </div>
    <button type="submit" class="btn-admin-primary" style="padding:9px 18px;font-size:13px">검색</button>
  </form>

  <!-- 합계 -->
  <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px;padding:12px 16px;background:#f8fafc;border-radius:10px;font-size:14px;">
    <span>총 <b><?= number_format($total) ?></b>건</span>
    <span>📥 입고 <b><?= number_format((int)$sum['in_cnt']) ?></b>건</span>
    <span>📤 출고 <b><?= number_format((int)$sum['out_cnt']) ?></b>건</span>
    <span>⚙️ 조정 <b><?= number_format((int)$sum['adj_cnt']) ?></b>건</span>
  </div>

  <div class="admin-table-wrap" style="overflow-x:auto">
    <table class="admin-table">
      <thead>
        <tr><th>일시</th><th>구분</th><th>상품</th><th>옵션 (DOT · 사이즈)</th><th style="text-align:right;">변경 수량</th><th style="text-align:right;">변경 후 재고</th><th>적요</th><th>기록자</th></tr>
      </thead>
      <tbody>
        <?php if (empty($movements)): ?>
        <tr><td colspan="8" class="empty">재고 이력이 없습니다. 주문이 들어오거나 재고 일괄 업데이트를 하면 자동으로 기록됩니다.</td></tr>
        <?php else: foreach ($movements as $m): ?>
        <tr>
          <td style="font-size:12.5px;white-space:nowrap;"><?= h(date('Y-m-d H:i', strtotime($m['created_at']))) ?></td>
          <td>
            <?php if ($m['change_type'] === 'in'): ?><span style="color:#16a34a;font-weight:800;font-size:12.5px;">📥 입고</span>
            <?php elseif ($m['change_type'] === 'out'): ?><span style="color:#dc2626;font-weight:800;font-size:12.5px;">📤 출고</span>
            <?php else: ?><span style="color:#2563eb;font-weight:800;font-size:12.5px;">⚙️ 조정</span><?php endif; ?>
          </td>
          <td style="max-width:240px;">
            <a href="<?= BASE_URL ?>/admin/product_form.php?id=<?= (int)$m['product_id'] ?>" style="font-size:12.5px;text-decoration:none;color:#1e293b;font-weight:700;">
              <?= h(mb_strimwidth($m['product_name'], 0, 34, '…')) ?>
            </a>
            <?php /* 상품명에 규격이 이미 포함돼 있으면 중복 표시하지 않는다 */ ?>
            <?php if ($m['spec'] && mb_strpos((string)$m['product_name'], (string)$m['spec']) === false): ?><br><small class="text-muted"><?= h($m['spec']) ?></small><?php endif; ?>
          </td>
          <td style="font-size:12.5px;"><?= $m['option_id'] ? h('DOT ' . ($m['option_dot'] ?: '-') . ' · ' . ($m['option_size'] ?: '-')) : '기본 상품' ?></td>
          <td class="mono" style="text-align:right;">
            <?php $mq = (int)$m['qty']; ?>
            <?php if ($mq > 0): ?><b style="color:#16a34a;">+<?= number_format($mq) ?></b>
            <?php elseif ($mq < 0): ?><b style="color:#dc2626;"><?= number_format($mq) ?></b>
            <?php else: ?><b>0</b><?php endif; ?>
          </td>
          <td class="mono" style="text-align:right;"><b><?= number_format((int)$m['stock_after']) ?></b></td>
          <td style="font-size:12.5px;color:#475569;line-height:1.6;"><?= h((string)($m['note'] ?? '-')) ?></td>
          <td style="font-size:12.5px;color:#64748b;white-space:nowrap;"><?= h((string)($m['created_by'] ?? '-')) ?></td>
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
@media(max-width:768px){ .admin-table-wrap table{min-width:920px} }
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
