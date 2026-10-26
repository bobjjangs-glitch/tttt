<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');
// [v39] sku(품번) 컬럼 자동 보강 — 구버전 운영 DB에는 없어 일괄 업로드 INSERT가 죽을 수 있음
ensure_stock_movement_tables();
$pdo = Database::connection();
ensure_price_tier_columns();

$results = [];
$error = '';

if (is_post() && isset($_FILES['upload_file'])) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/bulk-upload.php');
    }

    $file = $_FILES['upload_file'];
    $fileName = $file['name'];
    $fileTmp = $file['tmp_name'];
    $fileSize = $file['size'];
    $fileError = $file['error'];
    $uploadMode = $_POST['upload_mode'] ?? 'register';
    $brandDefault = trim($_POST['brand_default'] ?? '');

    if ($fileError !== UPLOAD_ERR_OK) {
        $error = '파일 업로드 중 오류가 발생했습니다.';
    } elseif ($fileSize > 20 * 1024 * 1024) {
        $error = '파일 크기는 20MB 이하여야 합니다.';
    } else {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $rows = [];

        try {
            if ($ext === 'csv') {
                $rows = parse_csv_smart($fileTmp);
            } elseif ($ext === 'xlsx') {
                $rows = parse_xlsx_smart($fileTmp);
            } elseif ($ext === 'pdf') {
                $rows = parse_pdf_text($fileTmp);
            } elseif ($ext === 'txt') {
                $rows = parse_txt($fileTmp);
            } else {
                $error = '지원 형식: CSV, Excel(xlsx), PDF, TXT';
            }
        } catch (Throwable $e) {
            $error = '파일 파싱 오류: ' . $e->getMessage();
        }

        if (empty($error) && !empty($rows)) {
            $results = process_tire_rows($pdo, $rows, $uploadMode, $brandDefault);
        } elseif (empty($error) && empty($rows)) {
            $error = '파일에서 데이터를 추출하지 못했습니다. 헤더 행이 있는지 확인해주세요.';
        }
    }
}

/* ===== 컬럼명 매핑 (다양한 표현을 표준 필드로 변환) ===== */
function normalize_field_name(string $name): string {
    $name = trim(strtolower($name));
    $name = preg_replace('/\s+/', '', $name);

    // 상품명
    if (in_array($name, ['상품명', 'productname', 'name', '품명', '타이어명'], true)) return 'name';
    // 브랜드
    if (in_array($name, ['브랜드', 'brand', 'brandname', '제조사', 'manufacturer'], true)) return 'brand';
    // 사이즈
    if (in_array($name, ['사이즈', 'size', '규격', '단면폭/편평비/림직경', '규격(단면폭/편평비/림직경)', 'tire_size'], true)) return 'size';
    // 패턴명/패턴코드
    if (in_array($name, ['패턴명', 'patternname', 'pattern', '패턴'], true)) return 'pattern_name';
    if (in_array($name, ['패턴코드', 'patterncode', 'pattern_code', 'pcode'], true)) return 'pattern_code';
    // M코드/CAI/SKU
    if (in_array($name, ['m코드', 'mcode', 'm코드(code)', '품번', 'sku', 'itemcode'], true)) return 'sku';
    if (in_array($name, ['cai', '카이'], true)) return 'sku';
    // 인치
    if (in_array($name, ['인치', 'inch', 'rim', '림직경'], true)) return 'inch';
    // 하중&속도
    if (in_array($name, ['하중&속도', '하중/속도', 'loadspeed', 'load_speed', '하중및속도', 'load&speed'], true)) return 'load_speed';
    // XL
    if (in_array($name, ['xl', 'extraload'], true)) return 'xl';
    // Ply
    if (in_array($name, ['ply', '층수'], true)) return 'ply';
    // 원산지
    if (in_array($name, ['원산지', 'origin', 'country', '제조국'], true)) return 'origin';
    // 부가세별도 / 기표가격 / List Price / 판매가
    if (in_array($name, ['부가세별도', '기표가격', 'listprice(원)', 'listprice', '판매가', 'price_sale', 'saleprice', '공장도가격', '공장도가'], true)) return 'price_sale';
    // 부가세포함 / 정상가 / 공장도가
    if (in_array($name, ['부가세포함', '정상가', 'price_original', 'originalprice', 'priceoriginal'], true)) return 'price_original';
    // 부가세
    if (in_array($name, ['부가세', 'vat'], true)) return 'vat';
    // 사업자가
    if (in_array($name, ['사업자가', 'businessprice', 'business_price', '사업자가격'], true)) return 'business_price';
    // 공급가
    if (in_array($name, ['공급가', 'supplyprice', 'supply_price'], true)) return 'supply_price';
    // 재고
    if (in_array($name, ['재고', 'stock', 'qty', '수량'], true)) return 'stock';
    // 카테고리/구분
    if (in_array($name, ['카테고리', 'category', '구분', 'type', '차종'], true)) return 'category';
    // 계절성
    if (in_array($name, ['계절성', 'season', 'seasonality'], true)) return 'season';
    // 특이사항/비고
    if (in_array($name, ['특이사항', '비고', 'remark', 'note', 'memo'], true)) return 'note';
    // 아이템상세
    if (in_array($name, ['아이템상세', 'itemdetail', 'item_detail', '상세'], true)) return 'item_detail';
    // OEM
    if (in_array($name, ['oem'], true)) return 'oem';
    // Tech
    if (in_array($name, ['tech', 'technology', '기술'], true)) return 'tech';
    // Runflat
    if (in_array($name, ['runflat', '런플랫'], true)) return 'runflat';

    return '';
}

/* ===== 스마트 CSV 파싱 (헤더 자동 탐지) ===== */
function parse_csv_smart(string $tmpPath): array {
    $handle = fopen($tmpPath, 'r');
    if (!$handle) throw new Exception('CSV 파일을 열 수 없습니다.');

    // BOM 제거
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") fseek($handle, 0);

    $allLines = [];
    while (($data = fgetcsv($handle)) !== false) {
        $allLines[] = $data;
    }
    fclose($handle);

    return find_header_and_parse($allLines);
}

/* ===== 스마트 XLSX 파싱 ===== */
function parse_xlsx_smart(string $tmpPath): array {
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) throw new Exception('XLSX 파일을 열 수 없습니다.');

    // shared strings
    $sharedStrings = [];
    $ssContent = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssContent !== false) {
        if (preg_match_all('/<t[^>]*>([^<]*)<\/t>/', $ssContent, $matches)) {
            $sharedStrings = $matches[1];
        }
    }

    // 첫 번째 시트
    $sheetContent = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetContent === false) throw new Exception('시트를 읽을 수 없습니다.');

    $allRows = [];
    if (preg_match_all('/<row[^>]*r="(\d+)"[^>]*>(.*?)<\/row>/s', $sheetContent, $rowMatches, PREG_SET_ORDER)) {
        foreach ($rowMatches as $rm) {
            $rowXml = $rm[2];
            $cells = [];
            if (preg_match_all('/<c[^>]*r="([A-Z]+)\d+"[^>]*(?:t="(\w+)")?[^>]*>(?:<v>([^<]*)<\/v>)?(?:<is><t[^>]*>([^<]*)<\/t><\/is>)?/', $rowXml, $cellMatches, PREG_SET_ORDER)) {
                foreach ($cellMatches as $cm) {
                    $col = $cm[1];
                    $type = $cm[2] ?? '';
                    $val = $cm[3] ?? ($cm[4] ?? '');
                    if ($type === 's' && $val !== '') {
                        $val = $sharedStrings[(int)$val] ?? $val;
                    }
                    $cells[$col] = (string)$val;
                }
            }
            if (!empty($cells)) $allRows[] = $cells;
        }
    }

    // 컬럼명으로 변환 (A,B,C → 0,1,2 인덱스)
    $indexedRows = [];
    foreach ($allRows as $row) {
        $idx = 0;
        $line = [];
        foreach ($row as $col => $val) {
            // 컬럼 문자를 인덱스로
            $colIdx = col_to_index($col);
            $line[$colIdx] = $val;
        }
        $indexedRows[] = $line;
    }

    return find_header_and_parse_indexed($indexedRows);
}

function col_to_index(string $col): int {
    $idx = 0;
    for ($i = 0; $i < strlen($col); $i++) {
        $idx = $idx * 26 + (ord($col[$i]) - ord('A') + 1);
    }
    return $idx - 1;
}

/* ===== 헤더 자동 탐지 + 데이터 파싱 ===== */
function find_header_and_parse(array $allLines): array {
    $indexedRows = [];
    foreach ($allLines as $line) {
        $indexedRows[] = array_values($line);
    }
    return find_header_and_parse_indexed($indexedRows);
}

function find_header_and_parse_indexed(array $allRows): array {
    $headerIdx = -1;
    $fieldMap = [];

    // 최대 10행까지 헤더 탐색
    for ($i = 0; $i < min(10, count($allRows)); $i++) {
        $row = $allRows[$i];
        $matches = 0;
        $testMap = [];

        foreach ($row as $colIdx => $cell) {
            $cell = trim((string)$cell);
            if ($cell === '') continue;
            $field = normalize_field_name($cell);
            if ($field !== '') {
                $testMap[$colIdx] = $field;
                $matches++;
            }
        }

        // 2개 이상의 필드가 매칭되면 헤더로 인식
        if ($matches >= 2) {
            $headerIdx = $i;
            $fieldMap = $testMap;
            break;
        }
    }

    if ($headerIdx < 0) {
        throw new Exception('헤더 행을 찾을 수 없습니다. 첫 번째 행에 컬럼명(상품명, 사이즈, 판매가 등)이 있어야 합니다.');
    }

    // 데이터 행 추출
    $rows = [];
    for ($i = $headerIdx + 1; $i < count($allRows); $i++) {
        $row = $allRows[$i];
        $data = [];
        $hasData = false;

        foreach ($fieldMap as $colIdx => $fieldName) {
            $val = trim((string)($row[$colIdx] ?? ''));
            if ($val !== '') $hasData = true;
            $data[$fieldName] = $val;
        }

        if ($hasData) $rows[] = $data;
    }

    return $rows;
}

/* ===== PDF 텍스트 추출 ===== */
function parse_pdf_text(string $tmpPath): array {
    $content = file_get_contents($tmpPath);
    if ($content === false) throw new Exception('PDF 파일을 읽을 수 없습니다.');

    $text = '';
    if (preg_match_all('/stream\r?\n(.*?)endstream/s', $content, $streams)) {
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded !== false) {
                if (preg_match_all('/\(([^)]+)\)\s*Tj/', $decoded, $textMatches)) {
                    foreach ($textMatches[1] as $t) { $text .= $t . "\t"; }
                    $text .= "\n";
                }
                if (preg_match_all('/\[([^\]]+)\]\s*TJ/', $decoded, $arrayMatches)) {
                    foreach ($arrayMatches[1] as $arr) {
                        if (preg_match_all('/\(([^)]*)\)/', $arr, $parts)) {
                            foreach ($parts[1] as $p) { $text .= $p; }
                            $text .= "\t";
                        }
                    }
                    $text .= "\n";
                }
            }
        }
    }

    if (trim($text) === '') {
        throw new Exception('PDF에서 텍스트를 추출할 수 없습니다. 이미지 기반 PDF는 지원되지 않습니다.');
    }

    $lines = explode("\n", $text);
    $allRows = [];
    foreach ($lines as $line) {
        $cells = explode("\t", $line);
        $cells = array_map('trim', $cells);
        $cells = array_filter($cells, function($c) { return $c !== ''; });
        if (!empty($cells)) $allRows[] = array_values($cells);
    }

    return find_header_and_parse_indexed($allRows);
}

/* ===== TXT 파싱 ===== */
function parse_txt(string $tmpPath): array {
    $content = file_get_contents($tmpPath);
    if ($content === false) throw new Exception('TXT 파일을 읽을 수 없습니다.');

    $lines = explode("\n", $content);
    $allRows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, "\t") !== false) $cells = explode("\t", $line);
        elseif (strpos($line, ',') !== false) $cells = explode(',', $line);
        else continue;
        $allRows[] = array_map('trim', $cells);
    }

    return find_header_and_parse_indexed($allRows);
}

/* ===== 타이어 행 처리 ===== */
function process_tire_rows(PDO $pdo, array $rows, string $mode, string $brandDefault): array {
    $results = ['success' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

    // 브랜드 캐시 (이름 → id)
    $brands = [];
    $stmt = $pdo->query('SELECT id, name FROM tt_brands');
    while ($b = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $brands[strtolower(trim($b['name']))] = (int)$b['id'];
    }

    // 카테고리 캐시
    $categories = [];
    $stmt = $pdo->query('SELECT id, name, slug FROM tt_categories');
    while ($c = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $categories[strtolower(trim($c['name']))] = (int)$c['id'];
        $categories[strtolower(trim($c['slug']))] = (int)$c['id'];
    }

    foreach ($rows as $idx => $row) {
        $rowNum = $idx + 1;

        // 상품명: name 필드가 있으면 사용, 없으면 pattern_name + size 조합
        $name = $row['name'] ?? '';
        if ($name === '') {
            $pn = $row['pattern_name'] ?? '';
            $sz = $row['size'] ?? '';
            $ls = $row['load_speed'] ?? '';
            if ($pn && $sz) {
                $name = $pn . ' ' . $sz . ($ls ? ' ' . $ls : '');
            }
        }

        if ($name === '') {
            $results['skipped']++;
            continue;
        }

        // 브랜드: brand 필드가 있으면 사용, 없으면 기본값
        $brandName = $row['brand'] ?? '';
        if ($brandName === '') $brandName = $brandDefault;
        if ($brandName === '') {
            $results['errors'][] = "{$rowNum}행: 브랜드를 알 수 없습니다. 기본 브랜드를 지정해주세요.";
            $results['skipped']++;
            continue;
        }

        $brandKey = strtolower(trim($brandName));
        $brandId = $brands[$brandKey] ?? 0;
        if ($brandId === 0) {
            // 브랜드 자동 생성
            try {
                $slug = preg_replace('/[^a-z0-9]/', '', strtolower($brandName)) ?: 'brand' . count($brands);
                $pdo->prepare('INSERT INTO tt_brands (name, slug, is_active) VALUES (:name, :slug, 1)')->execute(['name' => $brandName, 'slug' => $slug]);
                $brandId = (int)$pdo->lastInsertId();
                $brands[$brandKey] = $brandId;
            } catch (Throwable $e) {
                $results['errors'][] = "{$rowNum}행: 브랜드 '{$brandName}' 생성 실패";
                $results['skipped']++;
                continue;
            }
        }

        // 카테고리
        $catName = $row['category'] ?? '타이어';
        $catKey = strtolower(trim($catName));
        $categoryId = $categories[$catKey] ?? 1;

        // 가격
        $priceSale = (int)($row['price_sale'] ?? 0);
        $priceOriginal = (int)($row['price_original'] ?? 0);
        $businessPrice = (int)($row['business_price'] ?? 0);
        $supplyPrice = (int)($row['supply_price'] ?? 0);
        $stock = (int)($row['stock'] ?? 0);

        // 부가세별도가 price_sale이고 부가세포함이 price_original인 경우 처리
        // AGB 엑셀: 기표가격 = price_sale, 공장도가격 = 기표가격 * 1.1
        if ($priceSale > 0 && $priceOriginal === 0) {
            $priceOriginal = (int)round($priceSale * 1.1);
        }

        // SKU
        $sku = $row['sku'] ?? ('SKU-' . date('ymd') . '-' . str_pad((string)$rowNum, 4, '0', STR_PAD_LEFT));

        // 사이즈
        $size = $row['size'] ?? '';
        $model = $row['pattern_name'] ?? '';
        $patternCode = $row['pattern_code'] ?? '';

        if ($mode === 'update_price') {
            // 가격 수정 모드: 상품명으로 검색
            $stmt = $pdo->prepare('SELECT id FROM tt_products WHERE name = :name OR sku = :sku LIMIT 1');
            $stmt->execute(['name' => $name, 'sku' => $sku]);
            $productId = $stmt->fetchColumn();

            if (!$productId) {
                $results['skipped']++;
                continue;
            }

            $updates = [];
            $params = ['id' => $productId];
            if ($priceSale > 0) { $updates[] = 'price_sale = :price_sale'; $params['price_sale'] = $priceSale; }
            if ($priceOriginal > 0) { $updates[] = 'price_original = :price_original'; $params['price_original'] = $priceOriginal; }
            if ($businessPrice > 0) { $updates[] = 'business_price = :business_price'; $params['business_price'] = $businessPrice; }
            if ($supplyPrice > 0) { $updates[] = 'supply_price = :supply_price'; $params['supply_price'] = $supplyPrice; }
            if ($stock > 0) { $updates[] = 'stock = :stock'; $params['stock'] = $stock; }

            if (empty($updates)) { $results['skipped']++; continue; }

            $sql = 'UPDATE tt_products SET ' . implode(', ', $updates) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
            $results['updated']++;

        } else {
            // 신규 등록 모드
            // 중복 확인
            $dup = $pdo->prepare('SELECT id FROM tt_products WHERE name = :name OR sku = :sku LIMIT 1');
            $dup->execute(['name' => $name, 'sku' => $sku]);
            if ($dup->fetch()) {
                // 이미 있으면 가격 업데이트
                $updates = [];
                $params = ['name' => $name];
                if ($priceSale > 0) { $updates[] = 'price_sale = :price_sale'; $params['price_sale'] = $priceSale; }
                if ($priceOriginal > 0) { $updates[] = 'price_original = :price_original'; $params['price_original'] = $priceOriginal; }
                if ($businessPrice > 0) { $updates[] = 'business_price = :business_price'; $params['business_price'] = $businessPrice; }
                if (!empty($updates)) {
                    $sql = 'UPDATE tt_products SET ' . implode(', ', $updates) . ' WHERE name = :name';
                    $pdo->prepare($sql)->execute($params);
                    $results['updated']++;
                } else {
                    $results['skipped']++;
                }
                continue;
            }

            try {
                $stmt = $pdo->prepare('INSERT INTO tt_products (category_id, brand_id, name, model, spec, sku, pattern_code, pattern_name,
                    price_original, price_sale, business_price, supply_price, stock, status, created_at)
                    VALUES (:cat, :brand, :name, :model, :spec, :sku, :pcode, :pname,
                    :orig, :sale, :biz, :supply, :stock, "active", NOW())');
                $stmt->execute([
                    'cat' => $categoryId, 'brand' => $brandId, 'name' => $name,
                    'model' => $model ?: null, 'spec' => $size ?: null, 'sku' => $sku,
                    'pcode' => $patternCode ?: null, 'pname' => $model ?: null,
                    'orig' => $priceOriginal, 'sale' => $priceSale, 'biz' => $businessPrice,
                    'supply' => $supplyPrice, 'stock' => $stock,
                ]);
                $results['success']++;
            } catch (Throwable $e) {
                $results['errors'][] = "{$rowNum}행: 등록 실패 — " . $e->getMessage();
                $results['skipped']++;
            }
        }
    }

    return $results;
}

$pageTitle = '상품 일괄 업로드';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <h2 class="admin-list-title">상품 일괄 업로드</h2>
  <p class="admin-list-sub">타이어 가격표(Excel, CSV, PDF)를 그대로 올리면 상품이 자동 등록됩니다.</p>

  <!-- 사용법 -->
  <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:16px;margin:20px 0">
    <h3 style="font-size:14px;font-weight:800;color:#0c4a6e;margin-bottom:10px">📋 사용 방법</h3>
    <p style="font-size:13px;color:#075985;line-height:1.7;margin-bottom:8px">
      타이어 제조사에서 받은 가격표 파일을 그대로 업로드하면 됩니다. 헤더 행(컬럼명)이 있으면 자동으로 인식합니다.<br>
      다음 컬럼명들을 자동 인식합니다 (유사한 이름도 인식):
    </p>
    <div style="background:#fff;border-radius:8px;padding:12px;font-size:12px;color:#1e293b;line-height:2">
      <strong>상품명</strong> (또는 패턴명+사이즈로 자동 생성) ·
      <strong>사이즈</strong> (규격) ·
      <strong>브랜드</strong> (제조사) ·
      <strong>부가세별도</strong> / <strong>기표가격</strong> / <strong>판매가</strong> → 판매가로 저장<br>
      <strong>부가세포함</strong> / <strong>정상가</strong> → 정상가로 저장 ·
      <strong>패턴명</strong> · <strong>패턴코드</strong> ·
      <strong>M코드</strong> / <strong>CAI</strong> → SKU로 저장 ·
      <strong>인치</strong> · <strong>하중&속도</strong> · <strong>원산지</strong> · <strong>재고</strong>
    </div>
    <p style="font-size:12px;color:#075985;margin-top:8px">
      ※ 브랜드가 파일에 없으면 아래 "기본 브랜드"에 입력해주세요 (예: 한국타이어, 미쉐린)<br>
      ※ 정상가가 없으면 판매가 × 1.1로 자동 계산됩니다
    </p>
  </div>

  <?php if ($error): ?>
    <div class="admin-alert admin-alert-error"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if (!empty($results)): ?>
    <div class="admin-alert admin-alert-success">
      ✅ 등록: <?= (int)$results['success'] ?>건 | 수정: <?= (int)$results['updated'] ?>건 | 건너뜀: <?= (int)$results['skipped'] ?>건
    </div>
    <?php if (!empty($results['errors'])): ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px;margin-bottom:20px;max-height:200px;overflow-y:auto">
      <strong style="font-size:13px;color:#b91c1c">상세 오류 (<?= count($results['errors']) ?>건)</strong>
      <ul style="margin:8px 0 0;padding-left:20px;font-size:12px;color:#b91c1c">
        <?php foreach (array_slice($results['errors'], 0, 50) as $err): ?>
        <li><?= h($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- 업로드 폼 -->
  <form method="post" enctype="multipart/form-data" style="margin-top:20px">
    <?= Csrf::field() ?>

    <div class="admin-form-row">
      <label>업로드 모드</label>
      <select name="upload_mode">
        <option value="register">신규 상품 등록 (중복은 가격 업데이트)</option>
        <option value="update_price">기존 상품 가격 수정만</option>
      </select>
    </div>

    <div class="admin-form-row">
      <label>기본 브랜드 (파일에 브랜드 열이 없을 때 사용)</label>
      <input type="text" name="brand_default" placeholder="예: 한국타이어, 미쉐린, 금호타이어" style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px">
      <p class="admin-form-hint">파일에 브랜드 정보가 있으면 이 값은 무시됩니다.</p>
    </div>

    <div class="admin-form-row">
      <label>파일 선택 <span class="req">*</span></label>
      <input type="file" name="upload_file" accept=".csv,.xlsx,.xls,.pdf,.txt" required style="padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;width:100%;font-size:14px">
      <p class="admin-form-hint">지원 형식: Excel(xlsx), CSV, PDF, TXT (최대 20MB) — 가격표 원본 그대로 올리시면 됩니다</p>
    </div>

    <div class="admin-form-actions" style="margin-top:16px">
      <button type="submit" class="btn-admin-primary" style="font-size:15px;padding:12px 28px">📤 업로드 실행</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
