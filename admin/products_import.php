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
        'dot_code'          => "VARCHAR(20) NULL COMMENT '대표 DOT 코드'",
    ];
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM tt_products")->fetchAll() as $row) {
        $existing[$row['Field']] = true;
    }
    foreach ($newColumns as $col => $def) {
        if (!isset($existing[$col])) {
            try { $pdo->exec("ALTER TABLE tt_products ADD COLUMN {$col} {$def}"); }
            catch (Throwable $e) { sg_error_log('[ensure_product_spec_columns] ' . $e->getMessage()); }
        }
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('admin:product_spec_columns');
}
ensure_product_spec_columns($pdo);
ensure_ecount_tables(); // [v51] ecount_prod_cd/sku 컬럼 보강

/* [v61] 운영 DB의 tt_categories 컬럼 보강 — 카테고리 자동생성 INSERT가
 * parent_id 부재("Unknown column 'parent_id'")로 죽는 것 방지 (구버전 초기 스키마 대응) */
(function () use ($pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $catCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM tt_categories")->fetchAll() as $row) {
        $catCols[$row['Field']] = true;
    }
    if (!isset($catCols['parent_id'])) {
        try { $pdo->exec("ALTER TABLE tt_categories ADD COLUMN parent_id INT UNSIGNED NULL COMMENT '상위 카테고리'"); }
        catch (Throwable $e) { sg_error_log('[ensure_category_columns:parent_id] ' . $e->getMessage()); }
    }
    if (!isset($catCols['sort_order'])) {
        try { $pdo->exec("ALTER TABLE tt_categories ADD COLUMN sort_order INT NOT NULL DEFAULT 0"); }
        catch (Throwable $e) { sg_error_log('[ensure_category_columns:sort_order] ' . $e->getMessage()); }
    }
    if (!isset($catCols['is_active'])) {
        try { $pdo->exec("ALTER TABLE tt_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1"); }
        catch (Throwable $e) { sg_error_log('[ensure_category_columns:is_active] ' . $e->getMessage()); }
    }
})();

/* 엑셀 헤더 "이름" 기준으로 매칭할 전체 항목 목록 (순서 무관, 컬럼 위치가 바뀌어도 정확히 매칭됨) */
const PRODUCT_IMPORT_EXPECTED_COLUMNS = [
    '상품ID', '카테고리', '브랜드', '상품명', '패턴코드', '사이즈',
    '하중속도규격', 'DOT', '원산지', '단면폭', '편평비', '림직경',
    '패턴명', 'OEM', 'Tech', 'Runflat',
    '정상가', '판매가', '공급가', '재고', '상태',
];
const PRODUCT_IMPORT_REQUIRED_COLUMNS = ['카테고리', '브랜드', '상품명', '정상가'];

function admin_parse_csv_flexible(string $tmpPath): array
{
    $raw = file_get_contents($tmpPath);
    if ($raw === false || $raw === '') return ['header' => [], 'rows' => []];

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
    if ($header === false) { fclose($stream); return ['header' => [], 'rows' => []]; }
    $header = array_map(fn($h) => trim((string)$h), $header);

    $rows = [];
    while (($line = fgetcsv($stream)) !== false) {
        if (count(array_filter($line, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $assoc = [];
        foreach ($header as $i => $col) {
            $assoc[$col] = trim((string)($line[$i] ?? ''));
        }
        $rows[] = $assoc;
    }
    fclose($stream);
    return ['header' => $header, 'rows' => $rows];
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

/**
 * Runflat 원본 표기(ZP, EMT, SELFSEAL, RFT, MOE, SSR 등)를 시스템이 저장하는 Y/N으로 정규화한다.
 * 정확히 일치하지 않아도 값 안에 런플랫 관련 토큰이 포함돼 있으면 'Y'로 인식한다.
 * 어떤 규칙에도 안 걸리면 null을 반환해 상위에서 오류로 처리하게 한다.
 */
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

/**
 * 브랜드명 키 정규화: 공백 전부 제거 + 대문자 통일.
 * "MICHELIN", "michelin", "  michelin  " 를 모두 동일 키로 만든다.
 */
function admin_import_normalize_brand_key(string $s): string
{
    $s = mb_strtoupper(trim($s));
    return preg_replace('/\s+/u', '', $s) ?? $s;
}

/**
 * 같은 브랜드를 가리키는 영문/한글/표기 변형을 하나의 그룹으로 묶어둔다.
 * DB(tt_brands.name)에 어느 표기로 등록돼 있어도(영문이든 한글이든) 매칭되게 하기 위함이다.
 * 그룹에 없는 완전히 새로운 브랜드는 여기 한 줄만 추가하면 된다.
 */
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

/**
 * CSV 원본 브랜드 표기를 받아 실제 tt_brands에 등록된 브랜드 id를 찾는다.
 * 1) DB 이름과 정규화 후 정확히 일치하면 바로 매칭
 * 2) 별칭 그룹에 속해 있으면, 같은 그룹 안에서 실제 DB에 등록된 표기를 찾아 매칭
 * 3) 둘 다 실패하면 null (완전히 새로운 브랜드이거나 DB에 아직 등록 안 된 경우)
 */
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

/**
 * 업로드한 CSV의 헤더와 시스템이 기대하는 컬럼을 비교해 매칭 리포트를 만든다.
 * "대칭 잡아서" 매칭되는지를 사람이 눈으로 확인할 수 있게 보여주는 용도.
 */
function admin_build_column_match_report(array $csvHeader): array
{
    $matched = array_values(array_intersect(PRODUCT_IMPORT_EXPECTED_COLUMNS, $csvHeader));
    $missing = array_values(array_diff(PRODUCT_IMPORT_EXPECTED_COLUMNS, $csvHeader));
    $extra   = array_values(array_diff($csvHeader, PRODUCT_IMPORT_EXPECTED_COLUMNS));
    $missingRequired = array_values(array_intersect($missing, PRODUCT_IMPORT_REQUIRED_COLUMNS));
    return compact('matched', 'missing', 'extra', 'missingRequired');
}

/**
 * 카테고리/브랜드 자동 생성용 slug 생성 (기존 slug와 겹치지 않게 -2, -3... 붙임).
 */
function admin_import_unique_slug(PDO $pdo, string $table, string $name): string
{
    $base = strtolower(trim($name));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim($base, '-');
    if ($base === '') $base = 'cat-' . bin2hex(random_bytes(3));
    $slug = $base;
    $i = 1;
    $chk = $pdo->prepare("SELECT id FROM {$table} WHERE slug = :slug LIMIT 1");
    while (true) {
        $chk->execute(['slug' => $slug]);
        if (!$chk->fetch()) break;
        $slug = $base . '-' . (++$i);
    }
    return $slug;
}

/**
 * CSV의 카테고리 이름을 tt_categories id로 매칭한다.
 * 없으면 그 이름 그대로 카테고리를 자동 생성해서라도 매칭시킨다.
 * (타이어 분류 카테고리가 있으면 그 밑에 붙여 계층을 유지한다.)
 */
function admin_import_ensure_category(PDO $pdo, string $rawName, array &$catMap, array &$createdCats): ?int
{
    $name = trim($rawName);
    if ($name === '') return null;
    $key = mb_strtolower($name);
    if (isset($catMap[$key])) return $catMap[$key];

    $parentId = null;
    foreach ($catMap as $k => $id) {
        if ($k === '타이어') { $parentId = $id; break; }
    }

    try {
        $slug = admin_import_unique_slug($pdo, 'tt_categories', $name);
        $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tt_categories')->fetchColumn();
        $pdo->prepare('INSERT INTO tt_categories (parent_id, name, slug, sort_order, is_active)
                       VALUES (:parent, :name, :slug, :sort, 1)')
            ->execute(['parent' => $parentId, 'name' => $name, 'slug' => $slug, 'sort' => $sort]);
        $newId = (int)$pdo->lastInsertId();
        if ($newId > 0) {
            $catMap[$key] = $newId;
            $createdCats[$name] = $newId;
            return $newId;
        }
    } catch (Throwable $e) {
        error_log('[admin/products_import category-autocreate] ' . $e->getMessage());
    }
    return null;
}

/**
 * 별칭 그룹 매칭까지 실패한 브랜드는 그 이름 그대로 자동 생성한다.
 */
function admin_import_ensure_brand(PDO $pdo, string $rawName, array &$brandMap, array &$createdBrands): ?int
{
    $name = trim($rawName);
    if ($name === '') return null;
    $key = admin_import_normalize_brand_key($name);
    if ($key === '' || isset($brandMap[$key])) return $brandMap[$key] ?? null;

    try {
        $slug = admin_import_unique_slug($pdo, 'tt_brands', $name);
        $pdo->prepare('INSERT INTO tt_brands (name, slug, logo_url, is_active)
                       VALUES (:name, :slug, NULL, 1)')->execute(['name' => $name, 'slug' => $slug]);
        $newId = (int)$pdo->lastInsertId();
        if ($newId > 0) {
            $brandMap[$key] = $newId;
            $createdBrands[$name] = $newId;
            return $newId;
        }
    } catch (Throwable $e) {
        error_log('[admin/products_import brand-autocreate] ' . $e->getMessage());
    }
    return null;
}

function admin_bulk_import_products(PDO $pdo, array $rows): array
{
    $catStmt = $pdo->query('SELECT id, name, parent_id FROM tt_categories');
    $catMap = [];
    $tireCatId = null;
    foreach ($catStmt->fetchAll() as $c) {
        $catMap[mb_strtolower(trim($c['name']))] = (int)$c['id'];
        if (mb_strtolower(trim($c['name'])) === '타이어') $tireCatId = (int)$c['id'];
    }

    $brandStmt = $pdo->query('SELECT id, name FROM tt_brands');
    $brandMap = [];
    foreach ($brandStmt->fetchAll() as $b) {
        $brandMap[admin_import_normalize_brand_key($b['name'])] = (int)$b['id'];
    }

    $successCount = 0;
    $updateCount  = 0;
    $insertCount  = 0;
    $skippedSummary = 0;
    $errors = [];
    $createdCats  = [];
    $createdBrands = [];

    foreach ($rows as $i => $r) {
        $rowNo = $i + 2;

        $name = trim($r['상품명'] ?? '');
        if ($name === '(예시행) 상품명을 입력하세요' || $name === '') {
            if ($name === '') { $errors[] = "{$rowNo}행: 상품명이 비어있어 건너뛰었습니다."; }
            continue;
        }

        /* 합계/집계행(상품ID·스펙·DOT 전부 비어있고 이름+가격만 있는 행)은 상품이 아니므로 조용히 건너뜀 */
        $hasSpecData = false;
        foreach (['상품ID', '패턴코드', '사이즈', '하중속도규격', 'DOT', '단면폭', '편평비', '림직경'] as $specCol) {
            if (trim((string)($r[$specCol] ?? '')) !== '') { $hasSpecData = true; break; }
        }
        if (!$hasSpecData) { $skippedSummary++; continue; }

        $productId = (int)($r['상품ID'] ?? 0);
        $catName   = trim($r['카테고리'] ?? '');
        $brandRaw  = trim($r['브랜드'] ?? '');

        /* 카테고리: 있으면 매칭, 없으면 그 이름으로 자동 생성 → 업로드가 막히지 않게 */
        $categoryId = ($catName !== '') ? admin_import_ensure_category($pdo, $catName, $catMap, $createdCats) : null;
        if ($categoryId === null) {
            $errors[] = "{$rowNo}행 [{$name}]: 카테고리 '" . ($r['카테고리'] ?? '') . "'를 찾을 수 없어 자동 생성도 실패했습니다.";
            continue;
        }

        /* 브랜드: 별칭 매칭 → 실패 시 자동 생성 */
        $brandId = admin_import_resolve_brand_id($brandRaw, $brandMap);
        if ($brandId === null && $brandRaw !== '') {
            $brandId = admin_import_ensure_brand($pdo, $brandRaw, $brandMap, $createdBrands);
        }
        if ($brandId === null) {
            $errors[] = "{$rowNo}행 [{$name}]: 브랜드 '{$brandRaw}'를 찾을 수 없어 자동 생성도 실패했습니다.";
            continue;
        }

        $spec          = trim($r['사이즈'] ?? '');
        $origin        = trim($r['원산지'] ?? '');
        $patternCode   = trim($r['패턴코드'] ?? '');
        $loadSpeed     = trim($r['하중속도규격'] ?? '');
        $dotCode       = trim($r['DOT'] ?? '');
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
            'dot_code' => $dotCode !== '' ? $dotCode : null,
            'sku' => null, 'ecount_prod_cd' => null,
            'spec' => $spec !== '' ? $spec : null, 'origin' => $origin !== '' ? $origin : null,
            'pattern_code' => $patternCode !== '' ? $patternCode : null,
            'load_speed_rating' => $loadSpeed !== '' ? $loadSpeed : null,
            'width_mm' => $widthMm, 'aspect_ratio' => $aspectRatio,
            'rim_diameter' => $rimDiameter !== '' ? $rimDiameter : null,
            'pattern_name' => $patternName !== '' ? $patternName : null,
            'oem' => $oem !== '' ? $oem : null, 'tech' => $tech !== '' ? $tech : null,
            'runflat' => $runflat,
            'price_original' => $priceOriginal, 'price_sale' => $priceSale, 'supply_price' => $supplyPrice,
            'stock' => $stock, 'status' => $status,
        ];

        try {
            if ($productId > 0) {
                $chk = $pdo->prepare('SELECT id FROM tt_products WHERE id = :id');
                $chk->execute(['id' => $productId]);
                if (!$chk->fetch()) {
                    /* [v51] 상품ID가 로컬 상품 id가 아니면(외부 시트 고유번호 등)
                       에러로 막지 않고 신규 등록으로 전환 — 업로드가 전부 되게 */
                    $productId = 0;
                } else {
                $params['id'] = $productId;
                /* UPDATE 에는 sku/ecount_prod_cd 자리가 없다 — 남아있으면 HY093(파라미터 수 불일치) */
                unset($params['sku'], $params['ecount_prod_cd']);
                $pdo->prepare('UPDATE tt_products SET category_id=:category_id, brand_id=:brand_id, name=:name,
                    dot_code=:dot_code,
                    spec=:spec, origin=:origin, pattern_code=:pattern_code, load_speed_rating=:load_speed_rating,
                    width_mm=:width_mm, aspect_ratio=:aspect_ratio, rim_diameter=:rim_diameter,
                    pattern_name=:pattern_name, oem=:oem, tech=:tech, runflat=:runflat,
                    price_original=:price_original, price_sale=:price_sale, supply_price=:supply_price,
                    stock=:stock, status=:status WHERE id=:id')->execute($params);
                $updateCount++;
                }
            } else {
                /* [v51] 상품ID가 숫자(기존 상품 id)가 아닌 코드(0HK1004313 등)면
                   품번(sku) + 이카운트 품목코드(ecount_prod_cd)로 함께 저장 —
                   일괄 업로드만으로 ERP 창고별 재고 자동연동 매핑까지 완성 */
                $rawId = trim((string)($r['상품ID'] ?? ''));
                if ($rawId !== '' && !ctype_digit($rawId)) {
                    $params['sku'] = $rawId;
                    $params['ecount_prod_cd'] = $rawId;
                }
                $pdo->prepare('INSERT INTO tt_products (category_id, brand_id, name, dot_code, spec, origin,
                    sku, ecount_prod_cd,
                    pattern_code, load_speed_rating, width_mm, aspect_ratio, rim_diameter,
                    pattern_name, oem, tech, runflat,
                    price_original, price_sale, supply_price, stock, status, created_at)
                    VALUES (:category_id, :brand_id, :name, :dot_code, :spec, :origin,
                    :sku, :ecount_prod_cd,
                    :pattern_code, :load_speed_rating, :width_mm, :aspect_ratio, :rim_diameter,
                    :pattern_name, :oem, :tech, :runflat,
                    :price_original, :price_sale, :supply_price, :stock, :status, NOW())')->execute($params);
                $insertCount++;
            }
            $successCount++;
        } catch (Throwable $e) {
            error_log('[admin/products_import] ' . $e->getMessage());
            $errors[] = "{$rowNo}행 [{$name}]: 저장 중 오류가 발생했습니다.";
        }
    }

    return ['success' => $successCount, 'insert' => $insertCount, 'update' => $updateCount, 'errors' => $errors,
            'createdCats' => $createdCats, 'createdBrands' => $createdBrands, 'skippedSummary' => $skippedSummary];
}

$result = null;
$matchReport = null;

if (is_post()) {
    // [v58] 대용량 CSV 처리 — 공유호스팅 실행시간 제한 대비 (무시되는 환경이면 그대로 진행)
    @set_time_limit(300);
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

    if (!empty($matchReport['missingRequired'])) {
        flash('admin_error', '필수 컬럼이 없습니다: ' . implode(', ', $matchReport['missingRequired']));
        redirect('/admin/products_import.php');
    }

    if (empty($parsed['rows'])) {
        flash('admin_error', '업로드한 파일에 데이터 행이 없습니다.');
        redirect('/admin/products_import.php');
    }

    /* [v60] 처리 중 오류가 나면 일반 500 대신 실제 원인을 화면에 표시 */
    try {
        $result = admin_bulk_import_products($pdo, $parsed['rows']);
    } catch (Throwable $e) {
        flash('admin_error', '업로드 처리 중 오류: ' . $e->getMessage() . ' — 반복되면 이 메시지를 그대로 알려주세요.');
        redirect('/admin/products_import.php');
    }

    AdminAuth::log(
        (int)AdminAuth::currentAdminId(),
        'product_bulk_import',
        "엑셀 일괄 업로드: 신규 {$result['insert']}건, 수정 {$result['update']}건, 실패 " . count($result['errors']) . '건'
    );
}

$pageTitle = '상품 엑셀 일괄 업로드';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card" style="background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1px solid #e0e7ff;">
  <h2 class="admin-page-title">📁 상품 엑셀(CSV) 일괄 업로드</h2>
  <p class="admin-form-hint" style="line-height:1.7;">
    이번 양식에는 <b>패턴코드, 사이즈, 하중&속도규격, 원산지, 단면폭/편평비/림직경, 패턴명, OEM, Tech, Runflat</b>까지
    타이어 스펙 전체가 포함되어 있습니다. 엑셀에서 컬럼 순서를 바꿔서 올려도 <b>헤더 이름으로 자동 매칭</b>되니
    순서는 신경 쓰지 않으셔도 됩니다. (다만 헤더 문구 자체는 양식과 동일해야 매칭됩니다.)
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

<?php if ($result !== null): ?>
<div class="admin-card">
  <h3 class="admin-form-section-title">처리 결과</h3>
  <p>
    ✅ 성공 <b><?= (int)$result['success'] ?></b>건 (신규 <?= (int)$result['insert'] ?>건 / 수정 <?= (int)$result['update'] ?>건)
    &nbsp;·&nbsp; ❌ 실패 <b><?= count($result['errors']) ?></b>건
    <?php if (!empty($result['skippedSummary'])): ?> · 🧾 합계/집계행 자동건너뜀 <?= (int)$result['skippedSummary'] ?>행<?php endif; ?><?php if (!empty($result['createdCats'])): ?> · 🏷️ 카테고리 자동생성 <b><?= implode(', ', array_map('h', array_keys($result['createdCats']))) ?></b><?php endif; ?><?php if (!empty($result['createdBrands'])): ?> · 🏷️ 브랜드 자동생성 <b><?= implode(', ', array_map('h', array_keys($result['createdBrands']))) ?></b><?php endif; ?>
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
