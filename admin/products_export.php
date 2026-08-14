<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');
$pdo = Database::connection();

function ensure_product_spec_columns(PDO $pdo): void
{
    $newColumns = [
        'pattern_code'      => "VARCHAR(60) NULL COMMENT '패턴코드'",
        'load_speed_rating' => "VARCHAR(30) NULL COMMENT '하중&속도규격'",
        'width_mm'          => "INT NULL COMMENT '단면폭(mm)'",
        'aspect_ratio'      => "INT NULL COMMENT '편평비(%)'",
        'rim_diameter'      => "VARCHAR(10) NULL COMMENT '림직경(인치)'",
        'pattern_name'      => "VARCHAR(100) NULL COMMENT '패턴명'",
        'oem'               => "VARCHAR(100) NULL COMMENT 'OEM 인증'",
        'tech'              => "VARCHAR(150) NULL COMMENT 'Tech.'",
        'runflat'           => "VARCHAR(5) NULL COMMENT 'Runflat Y/N'",
    ];
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM tt_products")->fetchAll() as $row) {
        $existing[$row['Field']] = true;
    }
    foreach ($newColumns as $col => $def) {
        if (!isset($existing[$col])) {
            $pdo->exec("ALTER TABLE tt_products ADD COLUMN {$col} {$def}");
        }
    }
}
ensure_product_spec_columns($pdo);

$isTemplate = ($_GET['template'] ?? '') === '1';

/* 컬럼 순서는 표시용일 뿐이며, 업로드 시에는 이 순서와 무관하게 헤더 "이름"으로 매칭됩니다. */
$headers = [
    '상품ID', '카테고리', '브랜드', '상품명', '패턴코드', '사이즈',
    '하중속도규격', '원산지', '단면폭', '편평비', '림직경',
    '패턴명', 'OEM', 'Tech', 'Runflat',
    '정상가', '판매가', '공급가', '재고', '상태',
];

$filename = $isTemplate
    ? 'products_template_' . date('Ymd') . '.csv'
    : 'products_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // 엑셀 한글 깨짐 방지 UTF-8 BOM
fputcsv($out, $headers);

if ($isTemplate) {
    fputcsv($out, [
        '', '카테고리관리에 등록된 이름', '브랜드관리에 등록된 이름', '(예시행) 상품명을 입력하세요',
        'K127', '235/45R18', '94V', '한국',
        '235', '45', '18',
        'Ventus S1 evo3', '벤츠 승인', 'Silent, EV등급', 'N',
        '150000', '120000', '90000', '20', '노출',
    ]);
    fclose($out);
    exit;
}

$keyword      = trim($_GET['kw'] ?? '');
$categoryId   = (int)($_GET['category_id'] ?? 0);
$brandId      = (int)($_GET['brand_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

$where = '1=1';
$params = [];
if ($keyword !== '') {
    $where .= ' AND (p.name LIKE :kw1 OR p.model LIKE :kw2 OR p.dot_code LIKE :kw3)';
    $params['kw1'] = '%' . $keyword . '%';
    $params['kw2'] = '%' . $keyword . '%';
    $params['kw3'] = '%' . $keyword . '%';
}
if ($categoryId > 0) { $where .= ' AND p.category_id = :cat'; $params['cat'] = $categoryId; }
if ($brandId > 0)    { $where .= ' AND p.brand_id = :brand'; $params['brand'] = $brandId; }
if ($statusFilter === 'active' || $statusFilter === 'hidden') { $where .= ' AND p.status = :status'; $params['status'] = $statusFilter; }

$stmt = $pdo->prepare("SELECT p.id, p.name, p.spec, p.origin,
                               p.pattern_code, p.load_speed_rating, p.width_mm, p.aspect_ratio, p.rim_diameter,
                               p.pattern_name, p.oem, p.tech, p.runflat,
                               p.price_original, p.price_sale, p.supply_price, p.stock, p.status,
                               c.name AS category_name, b.name AS brand_name
                        FROM tt_products p
                        LEFT JOIN tt_categories c ON c.id = p.category_id
                        LEFT JOIN tt_brands b ON b.id = p.brand_id
                        WHERE {$where}
                        ORDER BY p.id DESC");
$stmt->execute($params);

while ($row = $stmt->fetch()) {
    fputcsv($out, [
        $row['id'],
        $row['category_name'] ?? '',
        $row['brand_name'] ?? '',
        $row['name'],
        $row['pattern_code'] ?? '',
        $row['spec'] ?? '',
        $row['load_speed_rating'] ?? '',
        $row['origin'] ?? '',
        $row['width_mm'] ?? '',
        $row['aspect_ratio'] ?? '',
        $row['rim_diameter'] ?? '',
        $row['pattern_name'] ?? '',
        $row['oem'] ?? '',
        $row['tech'] ?? '',
        $row['runflat'] ?? '',
        (int)$row['price_original'],
        (int)$row['price_sale'],
        (int)$row['supply_price'],
        (int)$row['stock'],
        $row['status'] === 'hidden' ? '숨김' : '노출',
    ]);
}
fclose($out);
exit;
