<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');
$pdo = Database::connection();

function ensure_product_spec_columns(PDO $pdo): void
{
    static $checked = false;
    if (sg_skip('admin:product_spec_columns', $checked)) return;
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
        'dot_code'          => "VARCHAR(30) NULL COMMENT '제조연주차(DOT)'",
        /* [수정] 공급사 원본 상품코드(예: 0HK1004313) 저장용. 재업로드 시 이 값으로
           기존 상품을 찾아 중복 등록을 막는다. 내부 DB id와는 별개의 값이다. */
        'external_sku'      => "VARCHAR(60) NULL COMMENT '공급사 원본 상품코드'",
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

    /* [수정] external_sku 조회 성능을 위한 인덱스. 이미 있으면 조용히 건너뛴다(에러를 성공 실패로 취급하지 않음). */
    try {
        $idx = $pdo->query("SHOW INDEX FROM tt_products WHERE Key_name = 'idx_external_sku'")->fetchAll();
        if (empty($idx)) {
            $pdo->exec("ALTER TABLE tt_products ADD INDEX idx_external_sku (external_sku)");
        }
    } catch (Throwable $e) {
        error_log('[admin/products_import] external_sku index skip: ' . $e->getMessage());
    }

    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('admin:product_spec_columns');
}
ensure_product_spec_columns($pdo);

/* [수정] '공급사코드' 컬럼을 선택 항목으로 추가. 공급사 원본 CSV처럼 '상품ID' 자체가
   영문+숫자 코드인 경우는 이 컬럼이 없어도 '상품ID' 값을 그대로 공급사 코드로 인식한다. */
const PRODUCT_IMPORT_EXPECTED_COLUMNS = [
    '상품ID', '공급사코드', '카테고리', '브랜드', '상품명', '패턴코드', '사이즈',
    '하중속도규격', 'DOT', '원산지', '단면폭', '편평비', '림직경',
    '패턴명', 'OEM', 'Tech', 'Runflat',
    '정상가', '판매가', '공급가', '재고', '상태',
];
const PRODUCT_IMPORT_REQUIRED_COLUMNS = ['카테고리', '브랜드', '상품명', '정상가'];

function admin_parse_csv_flexible(string $tmpPath): array
{
    $raw = file_get_contents($tmpPath);
    if ($raw === false || $raw === '') return ['header' => [], 'rows' => [], 'broken' => []];

    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);

    $encoding = mb_detect_encoding($raw, ['UTF-8', 'EUC-KR', 'CP949', 'ISO-8859-1'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $converted = @mb_convert_encoding($raw, 'UTF-8', $encoding);
        if ($converted !== false) $raw = $converted;
    }

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $raw);
    rewind($stream);

    $header = fgetcsv($stream);
    if ($header === false) { fclose($stream); return ['header' => [], 'rows' => [], 'broken' => []]; }
    $header = array_map(fn($h) => trim((string)$h), $header);
    $colCount = count($header);

    $rows = [];
    $broken = [];
    $lineNo = 1;
    while (($line = fgetcsv($stream)) !== false) {
        $lineNo++;
        if (count(array_filter($line, fn($v) => trim((string)$v) !== '')) === 0) continue;

        /* [수정] 필드 개수가 헤더와 다른(중간에 잘린) 행은 조용히 무시하지 않고
           별도 목록에 담아 업로드 결과 화면에서 명확히 알려준다. */
        if (count($line) !== $colCount) {
            $broken[] = [
                'line' => $lineNo,
                'id'   => $line[0] ?? '(알수없음)',
                'got'  => count($line),
                'need' => $colCount,
            ];
            continue;
        }

        $assoc = [];
        foreach ($header as $i => $col) {
            $assoc[$col] = trim((string)($line[$i] ?? ''));
        }
        $rows[] = $assoc;
    }
    fclose($stream);
    return ['header' => $header, 'rows' => $rows, 'broken' => $broken];
}

function admin_parse_int_money(string $v): int
{
    $v = preg_replace('/[^\d\-]/', '', $v);
    return $v === '' ? 0 : (int)$v;
}

function admin_parse_int_or_null(string $v): ?int
{
    $v = trim($v);
    if ($v === '') return null;
    $v = preg_replace('/[^\d\-]/', '', $v);
    return $v === '' ? null : (int)$v;
}

function admin_normalize_runflat(string $raw): ?string
{
    $v = mb_strtoupper(trim($raw));
    if ($v === '') return 'N';

    $yesTokens = [
        'Y', 'YES', '예', 'O',
        'ZP', 'EMT', 'SELFSEAL', 'SELF-SEAL', 'SELF SEAL',
        'RFT', 'ROF', 'MOE', 'SSR', 'DSST',
        'RUNFLAT', 'RUN FLAT', 'RUN-FLAT',
    ];
    $noTokens = ['N', 'NO', '아니오', 'X', '해당없음', '-'];

    if (in_array($v, $yesTokens, true)) return 'Y';
    if (in_array($v, $noTokens, true)) return 'N';

    foreach ($yesTokens as $token) {
        if ($token !== '' && mb_strpos($v, $token) !== false) return 'Y';
    }

    return null;
}

function admin_import_normalize_brand_key(string $s): string
{
    $s = mb_strtoupper(trim($s));
    return preg_replace('/\s+/u', '', $s) ?? $s;
}

function admin_import_brand_alias_groups(): array
{
    static $groups = [
        ['MICHELIN', '미쉐린', '미쉐린타이어'],
        ['BFGOODRICH', 'BF-GOODRICH', 'BF GOODRICH', 'BF굿리치', 'BF굿리치타이어'],
        ['CONTINENTAL', '콘티넨탈'],
        ['BRIDGESTONE', '브릿지스톤', '브리지스톤'],
        ['HANKOOK', 'HANKOOK TIRE', '한국타이어'],
        ['KUMHO', 'KUMHO TIRE', '금호타이어', '금호'],
        ['NEXEN', 'NEXEN TIRE', '넥센타이어', '넥센'],
        ['GOODYEAR', '굿이어'],
        ['PIRELLI', '피렐리'],
        ['YOKOHAMA', '요코하마'],
        ['DUNLOP', '던롭'],
        ['TOYO', 'TOYO TIRES', '토요'],
        ['FALKEN', '팔켄'],
    ];
    return $groups;
}

function admin_import_resolve_brand_id(string $rawBrand, array $brandMap): ?int
{
    $normalized = admin_import_normalize_brand_key($rawBrand);
    if ($normalized === '') return null;

    if (isset($brandMap[$normalized])) {
        return $brandMap[$normalized];
    }

    foreach (admin_import_brand_alias_groups() as $group) {
        $groupKeys = array_map('admin_import_normalize_brand_key', $group);
        if (!in_array($normalized, $groupKeys, true)) continue;

        foreach ($groupKeys as $key) {
            if (isset($brandMap[$key])) {
                return $brandMap[$key];
            }
        }
    }

    return null;
}

function admin_import_normalize_category_key(string $s): string
{
    $s = trim($s);
    $s = str_replace('／', '/', $s);
    $s = preg_replace('/\s*\/\s*/', '/', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return mb_strtolower($s);
}

function admin_generate_category_slug(PDO $pdo, string $name): string
{
    $base = mb_strtolower(trim($name));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'category-' . bin2hex(random_bytes(3));
    }
    $slug = $base;
    $i = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM tt_categories WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        if (!$stmt->fetch()) break;
        $slug = $base . '-' . (++$i);
    }
    return $slug;
}

function admin_build_column_match_report(array $csvHeader): array
{
    $matched = array_values(array_intersect(PRODUCT_IMPORT_EXPECTED_COLUMNS, $csvHeader));
    $missing = array_values(array_diff(PRODUCT_IMPORT_EXPECTED_COLUMNS, $csvHeader));
    $extra   = array_values(array_diff($csvHeader, PRODUCT_IMPORT_EXPECTED_COLUMNS));
    $missingRequired = array_values(array_intersect($missing, PRODUCT_IMPORT_REQUIRED_COLUMNS));
    return compact('matched', 'missing', 'extra', 'missingRequired');
}

function admin_bulk_import_products(PDO $pdo, array $rows): array
{
    $catStmt = $pdo->query('SELECT id, name FROM tt_categories');
    $catMap = [];
    foreach ($catStmt->fetchAll() as $c) $catMap[admin_import_normalize_category_key($c['name'])] = (int)$c['id'];

    $brandStmt = $pdo->query('SELECT id, name FROM tt_brands');
    $brandMap = [];
    foreach ($brandStmt->fetchAll() as $b) {
        $brandMap[admin_import_normalize_brand_key($b['name'])] = (int)$b['id'];
    }

    /* [수정] 공급사코드(external_sku)로 기존 상품을 찾기 위한 맵. 재업로드 시 중복 등록 방지의 핵심. */
    $extStmt = $pdo->query("SELECT id, external_sku FROM tt_products WHERE external_sku IS NOT NULL AND external_sku <> ''");
    $extMap = [];
    foreach ($extStmt->fetchAll() as $e) {
        $extMap[$e['external_sku']] = (int)$e['id'];
    }

    $successCount = 0;
    $updateCount  = 0;
    $insertCount  = 0;
    $categoryCreatedCount = 0;
    $errors = [];

    foreach ($rows as $i => $r) {
        $rowNo = $i + 2;

        $name = trim($r['상품명'] ?? '');
        if ($name === '(예시행) 상품명을 입력하세요' || $name === '') {
            if ($name === '') { $errors[] = "{$rowNo}행: 상품명이 비어있어 건너뛰었습니다."; }
            continue;
        }

        /* ------------------------------------------------------------------
           [수정] 상품ID 판별 로직 — 기존에는 무조건 (int) 캐스팅해서
           '0HK1004313' 같은 공급사 코드가 전부 0이 되어 매번 신규 INSERT로
           처리되던 버그를 고쳤다.
           - 숫자로만 된 값 → 관리자가 엑셀 다운로드 후 수정해서 재업로드하는
             "내부 DB id" 용도로 간주 (기존 동작 그대로 유지)
           - 숫자+영문 혼합 값(예: 0HK1004313) → 공급사 원본 상품코드로 간주,
             '공급사코드' 컬럼이 따로 없어도 '상품ID' 값 자체를 그 코드로 사용
           ------------------------------------------------------------------ */
        $rawIdField  = trim($r['상품ID'] ?? '');
        $supplierCol = trim($r['공급사코드'] ?? '');
        $isNumericId = ($rawIdField !== '' && ctype_digit($rawIdField));

        $internalId   = $isNumericId ? (int)$rawIdField : 0;
        $externalSku  = null;
        if (!$isNumericId && $rawIdField !== '') {
            $externalSku = $rawIdField;            // '상품ID' 칸 자체가 공급사 코드인 경우
        } elseif ($supplierCol !== '') {
            $externalSku = $supplierCol;            // 별도 '공급사코드' 컬럼이 있는 경우
        }

        $isUpdate = false;
        if ($internalId === 0 && $externalSku !== null && isset($extMap[$externalSku])) {
            $internalId = $extMap[$externalSku];   // 같은 공급사 코드의 기존 상품을 찾음 → 업데이트 대상
            $isUpdate = true;
        } elseif ($internalId > 0) {
            $isUpdate = true;
        }

        $catRaw      = trim($r['카테고리'] ?? '');
        $catKey      = admin_import_normalize_category_key($catRaw);
        $brandRaw    = trim($r['브랜드'] ?? '');

        if ($catKey === '') {
            $errors[] = "{$rowNo}행 [{$name}]: 카테고리가 비어있습니다.";
            continue;
        }

        if (!isset($catMap[$catKey])) {
            try {
                $newSlug = admin_generate_category_slug($pdo, $catRaw);
                $insCat = $pdo->prepare('INSERT INTO tt_categories (name, slug) VALUES (:name, :slug)');
                $insCat->execute(['name' => $catRaw, 'slug' => $newSlug]);
                $newCatId = (int)$pdo->lastInsertId();
                $catMap[$catKey] = $newCatId;
                $categoryCreatedCount++;
            } catch (Throwable $e) {
                error_log('[admin/products_import] category auto-create failed: ' . $e->getMessage());
                $errors[] = "{$rowNo}행 [{$name}]: 카테고리 '{$catRaw}' 자동 등록에 실패했습니다. (" . $e->getMessage() . ")";
                continue;
            }
        }

        $brandId = admin_import_resolve_brand_id($brandRaw, $brandMap);
        if ($brandId === null) {
            $errors[] = "{$rowNo}행 [{$name}]: 브랜드 '{$brandRaw}'를 찾을 수 없습니다.";
            continue;
        }

        $categoryId    = $catMap[$catKey];
        $spec          = trim($r['사이즈'] ?? '');
        $dotCode       = trim($r['DOT'] ?? '');
        $origin        = trim($r['원산지'] ?? '');
        $patternCode   = trim($r['패턴코드'] ?? '');
        $loadSpeed     = trim($r['하중속도규격'] ?? '');
        $widthMm       = admin_parse_int_or_null($r['단면폭'] ?? '');
        $aspectRatio   = admin_parse_int_or_null($r['편평비'] ?? '');
        $rimDiameter   = trim($r['림직경'] ?? '');
        $patternName   = trim($r['패턴명'] ?? '');
        $oem           = trim($r['OEM'] ?? '');
        $tech          = trim($r['Tech'] ?? '');
        $runflatRaw    = trim($r['Runflat'] ?? '');
        $runflat       = admin_normalize_runflat($runflatRaw);

        $priceOriginal = admin_parse_int_money($r['정상가'] ?? '0');
        $priceSale     = admin_parse_int_money($r['판매가'] ?? '0');
        $supplyPrice   = admin_parse_int_money($r['공급가'] ?? '0');
        $stock         = admin_parse_int_money($r['재고'] ?? '0');
        $statusRaw     = trim($r['상태'] ?? '노출');
        $status        = ($statusRaw === '숨김' || $statusRaw === 'hidden') ? 'hidden' : 'active';

        if ($priceOriginal <= 0) {
            $errors[] = "{$rowNo}행 [{$name}]: 정상가는 0보다 커야 합니다. (원본값: '" . ($r['정상가'] ?? '') . "')";
            continue;
        }
        if ($priceSale > 0 && $priceSale > $priceOriginal) {
            $errors[] = "{$rowNo}행 [{$name}]: 판매가({$priceSale})가 정상가({$priceOriginal})보다 클 수 없습니다.";
            continue;
        }
        if ($runflat === null) {
            $errors[] = "{$rowNo}행 [{$name}]: Runflat 값은 Y 또는 N만 입력 가능합니다. (입력값: '{$runflatRaw}')";
            continue;
        }

        $params = [
            'category_id' => $categoryId, 'brand_id' => $brandId, 'name' => $name,
            'spec' => $spec !== '' ? $spec : null,
            'dot_code' => $dotCode !== '' ? $dotCode : null,
            'origin' => $origin !== '' ? $origin : null,
            'pattern_code' => $patternCode !== '' ? $patternCode : null,
            'load_speed_rating' => $loadSpeed !== '' ? $loadSpeed : null,
            'width_mm' => $widthMm, 'aspect_ratio' => $aspectRatio,
            'rim_diameter' => $rimDiameter !== '' ? $rimDiameter : null,
            'pattern_name' => $patternName !== '' ? $patternName : null,
            'oem' => $oem !== '' ? $oem : null, 'tech' => $tech !== '' ? $tech : null,
            'runflat' => $runflat,
            'price_original' => $priceOriginal, 'price_sale' => $priceSale, 'supply_price' => $supplyPrice,
            'stock' => $stock, 'status' => $status,
            'external_sku' => $externalSku,   // [수정] 공급사 코드 저장/갱신
        ];

        try {
            if ($isUpdate && $internalId > 0) {
                $chk = $pdo->prepare('SELECT id FROM tt_products WHERE id = :id');
                $chk->execute(['id' => $internalId]);
                if (!$chk->fetch()) {
                    $errors[] = "{$rowNo}행 [{$name}]: 상품ID {$internalId}에 해당하는 상품이 존재하지 않습니다.";
                    continue;
                }
                $params['id'] = $internalId;
                $pdo->prepare('UPDATE tt_products SET category_id=:category_id, brand_id=:brand_id, name=:name,
                    spec=:spec, dot_code=:dot_code, origin=:origin, pattern_code=:pattern_code, load_speed_rating=:load_speed_rating,
                    width_mm=:width_mm, aspect_ratio=:aspect_ratio, rim_diameter=:rim_diameter,
                    pattern_name=:pattern_name, oem=:oem, tech=:tech, runflat=:runflat,
                    price_original=:price_original, price_sale=:price_sale, supply_price=:supply_price,
                    stock=:stock, status=:status, external_sku=:external_sku WHERE id=:id')->execute($params);
                $updateCount++;
            } else {
                $pdo->prepare('INSERT INTO tt_products (category_id, brand_id, name, spec, dot_code, origin,
                    pattern_code, load_speed_rating, width_mm, aspect_ratio, rim_diameter,
                    pattern_name, oem, tech, runflat,
                    price_original, price_sale, supply_price, stock, status, external_sku, created_at)
                    VALUES (:category_id, :brand_id, :name, :spec, :dot_code, :origin,
                    :pattern_code, :load_speed_rating, :width_mm, :aspect_ratio, :rim_diameter,
                    :pattern_name, :oem, :tech, :runflat,
                    :price_original, :price_sale, :supply_price, :stock, :status, :external_sku, NOW())')->execute($params);
                $newId = (int)$pdo->lastInsertId();
                if ($externalSku !== null) $extMap[$externalSku] = $newId; // 같은 파일 내 중복 행 대비
                $insertCount++;
            }
            $successCount++;
        } catch (Throwable $e) {
            error_log('[admin/products_import] ' . $e->getMessage());
            $errors[] = "{$rowNo}행 [{$name}]: 저장 중 오류가 발생했습니다. (" . $e->getMessage() . ")";
        }
    }

    return [
        'success' => $successCount, 'insert' => $insertCount, 'update' => $updateCount,
        'category_created' => $categoryCreatedCount, 'errors' => $errors,
    ];
}

$result = null;
$matchReport = null;
$brokenRows = [];

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/products_import.php');
    }

    if (empty($_FILES['csv_file']['name'])) {
        flash('admin_error', '업로드할 CSV 파일을 선택해 주세요.');
        redirect('/admin/products_import.php');
    }
    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        flash('admin_error', '파일 업로드 중 오류가 발생했습니다. (code=' . $_FILES['csv_file']['error'] . ')');
        redirect('/admin/products_import.php');
    }
    $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        flash('admin_error', 'CSV 파일(.csv)만 업로드할 수 있습니다. 엑셀에서 "CSV(쉼표로 분리)"로 저장해 주세요.');
        redirect('/admin/products_import.php');
    }

    $parsed = admin_parse_csv_flexible($_FILES['csv_file']['tmp_name']);
    if (empty($parsed['header'])) {
        flash('admin_error', '업로드한 파일을 읽을 수 없습니다.');
        redirect('/admin/products_import.php');
    }

    $matchReport = admin_build_column_match_report($parsed['header']);
    $brokenRows  = $parsed['broken'];

    if (!empty($matchReport['missingRequired'])) {
        flash('admin_error', '필수 컬럼이 없습니다: ' . implode(', ', $matchReport['missingRequired']));
        redirect('/admin/products_import.php');
    }

    if (empty($parsed['rows'])) {
        flash('admin_error', '업로드한 파일에 유효한 데이터 행이 없습니다.' . (!empty($brokenRows) ? ' (구조가 깨진 행 ' . count($brokenRows) . '건 발견)' : ''));
        redirect('/admin/products_import.php');
    }

    $result = admin_bulk_import_products($pdo, $parsed['rows']);

    AdminAuth::log(
        (int)AdminAuth::currentAdminId(),
        'product_bulk_import',
        "엑셀 일괄 업로드: 신규 {$result['insert']}건, 수정 {$result['update']}건, 카테고리 자동생성 {$result['category_created']}건, 구조깨짐 " . count($brokenRows) . "건, 실패 " . count($result['errors']) . '건'
    );
}

$pageTitle = '상품 엑셀 일괄 업로드';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card" style="background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1px solid #e0e7ff;">
  <h2 class="admin-page-title">📁 상품 엑셀(CSV) 일괄 업로드</h2>
  <p class="admin-form-hint" style="line-height:1.7;">
    이번 양식에는 <b>패턴코드, 사이즈, 하중&속도규격, DOT, 원산지, 단면폭/편평비/림직경, 패턴명, OEM, Tech, Runflat</b>까지
    타이어 스펙 전체가 포함되어 있습니다. 엑셀에서 컬럼 순서를 바꿔서 올려도 <b>헤더 이름으로 자동 매칭</b>되니
    순서는 신경 쓰지 않으셔도 됩니다. (다만 헤더 문구 자체는 양식과 동일해야 매칭됩니다.)<br>
    카테고리는 시스템에 없는 이름이어도 <b>자동으로 새 카테고리가 생성</b>됩니다.<br>
    <b>'상품ID'</b>에 공급사 원본 코드(예: 0HK1004313)를 그대로 넣어 올리셔도, 같은 파일을 다시 올리면
    <b>중복 등록 없이 기존 상품이 갱신</b>됩니다.
  </p>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a href="<?= BASE_URL ?>/admin/products_export.php?template=1" class="btn-admin-excel">📄 빈 양식 다운로드 (신규 등록용)</a>
    <a href="<?= BASE_URL ?>/admin/products_export.php" class="btn-admin-excel">📊 현재 상품 전체 다운로드 (수정용)</a>
  </div>
</div>

<div class="admin-card">
  <h3 class="admin-form-section-title">CSV 파일 업로드</h3>
  <form method="post" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <div class="admin-form-row">
      <input type="file" name="csv_file" accept=".csv" required>
    </div>
    <div class="admin-form-actions">
      <a href="<?= BASE_URL ?>/admin/products.php" class="btn-admin-secondary">취소</a>
      <button type="submit" class="btn-admin-primary">일괄 업로드 실행</button>
    </div>
  </form>
</div>

<?php if ($matchReport !== null): ?>
<div class="admin-card">
  <h3 class="admin-form-section-title">📋 컬럼 매칭 리포트</h3>
  <p style="margin-bottom:10px;">
    ✅ 매칭된 컬럼 <b><?= count($matchReport['matched']) ?></b>개
    &nbsp;·&nbsp; ⚠️ 시스템엔 있으나 엑셀에 없는 컬럼 <b><?= count($matchReport['missing']) ?></b>개
    &nbsp;·&nbsp; ➕ 엑셀에만 있는 여분 컬럼 <b><?= count($matchReport['extra']) ?></b>개
  </p>
  <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
    <?php foreach ($matchReport['matched'] as $col): ?>
      <span class="status-badge status-done"><?= h($col) ?></span>
    <?php endforeach; ?>
  </div>
  <?php if (!empty($matchReport['missing'])): ?>
    <p class="admin-form-hint">아래 컬럼은 엑셀에 없어서 <b>빈 값으로 처리</b>됩니다(필수 항목이 아니라면 문제 없습니다):</p>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
      <?php foreach ($matchReport['missing'] as $col): ?>
        <span class="status-badge status-pending"><?= h($col) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($matchReport['extra'])): ?>
    <p class="admin-form-hint">아래 컬럼은 시스템에서 사용하지 않아 <b>무시됩니다</b>:</p>
    <div style="display:flex;flex-wrap:wrap;gap:6px;">
      <?php foreach ($matchReport['extra'] as $col): ?>
        <span class="status-badge status-cancelled"><?= h($col) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($brokenRows)): ?>
<div class="admin-card">
  <h3 class="admin-form-section-title">⚠️ 구조가 깨진 행 (업로드에서 제외됨)</h3>
  <p class="admin-form-hint">아래 행은 CSV 필드 개수가 맞지 않아(중간에 값이 잘려서) 자동으로 건너뛰었습니다. 원본 파일에서 직접 확인 후 복구해 주세요.</p>
  <div class="admin-alert admin-alert-error">
    <ul>
      <?php foreach ($brokenRows as $b): ?>
        <li><?= (int)$b['line'] ?>번째 줄 (상품ID: <?= h((string)$b['id']) ?>) — 필드 <?= (int)$b['got'] ?>개 감지됨 (정상은 <?= (int)$b['need'] ?>개)</li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<?php if ($result !== null): ?>
<div class="admin-card">
  <h3 class="admin-form-section-title">처리 결과</h3>
  <p>
    ✅ 성공 <b><?= (int)$result['success'] ?></b>건 (신규 <?= (int)$result['insert'] ?>건 / 수정 <?= (int)$result['update'] ?>건)
    &nbsp;·&nbsp; 🆕 카테고리 자동생성 <b><?= (int)$result['category_created'] ?></b>건
    &nbsp;·&nbsp; ❌ 실패 <b><?= count($result['errors']) ?></b>건
  </p>
  <?php if (!empty($result['errors'])): ?>
    <div class="admin-alert admin-alert-error">
      <ul>
        <?php foreach ($result['errors'] as $err): ?>
          <li><?= h($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <div class="admin-form-actions">
    <a href="<?= BASE_URL ?>/admin/products.php" class="btn-admin-primary">상품 목록으로 이동</a>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
