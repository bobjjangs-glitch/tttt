<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');

$pdo = Database::connection();

$keyword    = trim($_GET['kw'] ?? '');
$categoryId = (int)($_GET['category_id'] ?? 0);
$brandId    = (int)($_GET['brand_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

$where = '1=1';
$params = [];
if ($keyword !== '') { $where .= ' AND (p.name LIKE :kw OR p.model LIKE :kw)'; $params['kw'] = '%' . $keyword . '%'; }
if ($categoryId > 0) { $where .= ' AND p.category_id = :cat'; $params['cat'] = $categoryId; }
if ($brandId > 0) { $where .= ' AND p.brand_id = :brand'; $params['brand'] = $brandId; }
if ($statusFilter === 'active' || $statusFilter === 'hidden') { $where .= ' AND p.status = :status'; $params['status'] = $statusFilter; }

$stmt = $pdo->prepare("SELECT p.id, p.name, p.model, b.name AS brand_name, c.name AS category_name,
                               p.price_original, p.price_sale, p.stock, p.status, p.sales_count, p.created_at
                        FROM tt_products p
                        LEFT JOIN tt_brands b ON b.id = p.brand_id
                        LEFT JOIN tt_categories c ON c.id = p.category_id
                        WHERE {$where} ORDER BY p.id DESC");
$stmt->execute($params);
$products = $stmt->fetchAll();

AdminAuth::log((int)AdminAuth::currentAdminId(), 'product_export', '상품 목록 엑셀 다운로드 (' . count($products) . '건)');

$filename = '상품목록_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['ID', '상품명', '모델명', '브랜드', '카테고리', '정가', '판매가', '재고', '상태', '판매량', '등록일']);
foreach ($products as $p) {
    fputcsv($out, [
        $p['id'], $p['name'], $p['model'], $p['brand_name'], $p['category_name'],
        (int)$p['price_original'], (int)$p['price_sale'], (int)$p['stock'],
        $p['status'] === 'active' ? '판매중' : '숨김',
        (int)$p['sales_count'], date('Y-m-d H:i', strtotime($p['created_at'])),
    ]);
}
fclose($out);
exit;
