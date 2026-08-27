<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');
$pdo = Database::connection();

if (!defined('MAIN_IMG_MAX_COUNT'))     define('MAIN_IMG_MAX_COUNT', 3);
if (!defined('MAIN_IMG_MAX_SIZE_MB'))   define('MAIN_IMG_MAX_SIZE_MB', 5);
if (!defined('DETAIL_IMG_MAX_COUNT'))   define('DETAIL_IMG_MAX_COUNT', 15);
if (!defined('DETAIL_IMG_MAX_SIZE_MB')) define('DETAIL_IMG_MAX_SIZE_MB', 5);

/* 노출 배지 옵션 상수 – 관리자 폼과 검증 로직에서 공용으로 사용 */
$CAR_TYPE_OPTIONS    = ['승용', 'SUV', '경차', '버스·트럭'];
$FEATURE_TAG_OPTIONS = ['승차감', '정숙성', '사계절', '저연비', '마모수명', '제동력', '코너링', '내구성'];

/* ==========================================================================
   [신규] 상세설명 리치텍스트(HTML) 정제
   - 관리자 에디터(contenteditable)에서 넘어온 HTML은 허용된 태그/속성만 남기고
     나머지는 전부 제거한다. (XSS 방지: script, on*, style의 위험 속성, javascript: 등)
   ========================================================================== */
function admin_sanitize_style_attr(string $style): string
{
    $allowedProps = ['color', 'background-color', 'font-size', 'font-family', 'font-weight', 'font-style', 'text-decoration', 'text-align'];
    $out = [];
    foreach (explode(';', $style) as $decl) {
        $decl = trim($decl);
        if ($decl === '') continue;
        $parts = explode(':', $decl, 2);
        if (count($parts) !== 2) continue;
        $prop = strtolower(trim($parts[0]));
        $val  = trim($parts[1]);
        if (!in_array($prop, $allowedProps, true)) continue;
        if (preg_match('/(javascript:|expression\(|url\(|@import)/i', $val)) continue;
        $out[] = $prop . ':' . $val;
    }
    return implode(';', $out);
}

function admin_sanitize_description_html(string $html): string
{
    if ($html === '') return '';

    /* 1) 허용 태그 이외는 모두 제거 (내용 텍스트는 보존) */
    $allowedTags = '<b><strong><i><em><u><s><span><div><p><br><ul><ol><li><a><font><h3><h4><blockquote>';
    $clean = strip_tags($html, $allowedTags);

    /* 2) DOM 파싱 후 속성 화이트리스트 적용 */
    $prevErr = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8">' . '<div id="__root__">' . $clean . '</div>');
    libxml_clear_errors();
    libxml_use_internal_errors($prevErr);

    $xpath = new DOMXPath($dom);
    $rootList = $xpath->query('//div[@id="__root__"]');
    if ($rootList === false || $rootList->length === 0) return '';
    $root = $rootList->item(0);

    $walk = function (DOMNode $node) use (&$walk): void {
        if ($node->nodeType === XML_ELEMENT_NODE && $node instanceof DOMElement) {
            $tag = strtolower($node->nodeName);
            $toRemove = [];
            foreach ($node->attributes as $attr) {
                $attrName = strtolower($attr->name);
                if ($attrName === 'style') {
                    $node->setAttribute('style', admin_sanitize_style_attr($attr->value));
                } elseif ($tag === 'a' && $attrName === 'href') {
                    if (preg_match('/^\s*(javascript:|data:)/i', $attr->value)) $toRemove[] = 'href';
                } elseif ($tag === 'a' && $attrName === 'target') {
                    if ($attr->value !== '_blank') $toRemove[] = 'target';
                } elseif ($tag === 'font' && in_array($attrName, ['color', 'face', 'size'], true)) {
                    /* 허용 */
                } else {
                    $toRemove[] = $attr->name;
                }
            }
            foreach ($toRemove as $a) $node->removeAttribute($a);
        }
        foreach (iterator_to_array($node->childNodes) as $child) {
            $walk($child);
        }
    };
    $walk($root);

    $result = '';
    foreach ($root->childNodes as $child) {
        $result .= $dom->saveHTML($child);
    }
    return trim($result);
}

function admin_desc_to_editor_html(string $desc): string
{
    if ($desc === '') return '';
    /* 기존(리치에디터 도입 전) 저장분은 태그가 없는 순수 텍스트이므로 줄바꿈만 <br>로 치환해 하위호환 유지 */
    if (strip_tags($desc) === $desc) {
        return nl2br(h($desc), false);
    }
    return $desc;
}

/* ---------- 대표이미지 테이블 자동 생성 ---------- */
function ensure_product_main_images_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tt_product_main_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        image_url VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pmi_product (product_id),
        CONSTRAINT fk_pmi_product FOREIGN KEY (product_id)
            REFERENCES tt_products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
/* ---------- 상세페이지 이미지 테이블 자동 생성 ---------- */
function ensure_product_detail_images_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tt_product_detail_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        image_url VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pdi_product (product_id),
        CONSTRAINT fk_pdi_product FOREIGN KEY (product_id)
            REFERENCES tt_products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
/* ---------- [기존] 타이어 스펙 컬럼 자동 추가 + [신규] 노출 배지 컬럼 자동 추가 ----------
   [강화] 컬럼 하나가 실패해도 나머지가 죽지 않도록 개별 try/catch + 실패 로그 기록 */
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
        /* [신규] 노출 배지 컬럼 */
        'car_type'          => "VARCHAR(20) NULL COMMENT '차량타입 배지'",
        'grade'             => "VARCHAR(20) NULL COMMENT '등급/트림 배지'",
        'feature_tags'      => "VARCHAR(200) NULL COMMENT '특징 태그(콤마구분)'",
    ];
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM tt_products")->fetchAll() as $row) {
        $existing[$row['Field']] = true;
    }
    foreach ($newColumns as $col => $def) {
        if (!isset($existing[$col])) {
            try {
                $pdo->exec("ALTER TABLE tt_products ADD COLUMN {$col} {$def}");
            } catch (Throwable $e) {
                error_log("[ensure_product_spec_columns] 컬럼 '{$col}' 추가 실패: " . $e->getMessage());
            }
        }
    }
}
ensure_product_main_images_table($pdo);
ensure_product_detail_images_table($pdo);
ensure_product_spec_columns($pdo);

/* ---------- 각종 다중 이미지 업로드 처리/검증 ---------- */
function admin_handle_multi_images_upload(array $files, int $maxSizeMb, string $subDir = 'products'): array {
    $result = ['ok' => true, 'files' => [], 'errors' => []];
    if (empty($files) || !isset($files['name']) || !is_array($files['name'])) {
        return $result; // 넘어온 업로드 파일 없음
    }
    $allowed   = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $uploadDir = __DIR__ . '/../uploads/' . $subDir;
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;

        if ($err !== UPLOAD_ERR_OK) {
            $result['ok'] = false;
            $result['errors'][] = ($files['name'][$i] ?? '파일') . ': 업로드 중 오류 발생(code=' . $err . ')';
            continue;
        }
        if (@getimagesize($files['tmp_name'][$i]) === false) {
            $result['ok'] = false;
            $result['errors'][] = $files['name'][$i] . ': 이미지 형식이 올바르지 않습니다.';
            continue;
        }
        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $result['ok'] = false;
            $result['errors'][] = $files['name'][$i] . ': 지원하지 않는 이미지 형식입니다. (jpg, png, webp, gif만 가능)';
            continue;
        }
        if ($files['size'][$i] > $maxSizeMb * 1024 * 1024) {
            $result['ok'] = false;
            $result['errors'][] = $files['name'][$i] . ': 이미지 용량이 ' . $maxSizeMb . 'MB 이하여야 합니다.';
            continue;
        }
        $filename = 'p_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target   = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($files['tmp_name'][$i], $target)) {
            $result['ok'] = false;
            $result['errors'][] = $files['name'][$i] . ': 저장에 실패했습니다.';
            continue;
        }
        $result['files'][] = BASE_URL . '/uploads/' . $subDir . '/' . $filename;
    }
    return $result;
}

/* ---------- 상품 폼 데이터 준비 ---------- */
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit    = $productId > 0;
$errors      = [];
$fieldErrors = [];

$product = [
    'category_id'=>'', 'brand_id'=>'', 'name'=>'', 'model'=>'', 'spec'=>'', 'origin'=>'',
    'dot_code'=>'', 'price_original'=>0, 'price_sale'=>0, 'supply_price'=>0,
    'stock'=>0, 'status'=>'active', 'description'=>'', 'thumbnail_url'=>'',
    /* [기존] 타이어 스펙 기본값 */
    'pattern_code'=>'', 'load_speed_rating'=>'', 'width_mm'=>'', 'aspect_ratio'=>'',
    'rim_diameter'=>'', 'pattern_name'=>'', 'oem'=>'', 'tech'=>'', 'runflat'=>'N',
    /* [신규] 노출 배지 기본값 */
    'car_type'=>'', 'grade'=>'', 'feature_tags'=>'',
];
$options      = [];
$mainImages   = []; // [['id'=>, 'image_url'=>, 'sort_order'=>], ...]
$detailImages = []; // [['id'=>, 'image_url'=>, 'sort_order'=>], ...]

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM tt_products WHERE id=:id');
    $stmt->execute(['id'=>$productId]);
    $row = $stmt->fetch();
    if (!$row) { flash('admin_error','존재하지 않는 상품입니다.'); redirect('/admin/products.php'); }
    $product = array_merge($product, $row);

    $optStmt = $pdo->prepare(
        "SELECT * FROM tt_product_options
         WHERE product_id = :pid
         ORDER BY
           CASE WHEN dot_code REGEXP '^[0-9]{4}$' THEN 1 ELSE 0 END DESC,
           CASE WHEN dot_code REGEXP '^[0-9]{4}$' THEN RIGHT(dot_code, 2) ELSE NULL END DESC,
           CASE WHEN dot_code REGEXP '^[0-9]{4}$' THEN LEFT(dot_code, 2) ELSE NULL END DESC,
           dot_code DESC,
           id ASC"
    );
    $optStmt->execute(['pid'=>$productId]);
    $options = $optStmt->fetchAll();

    $miStmt = $pdo->prepare('SELECT id, image_url, sort_order FROM tt_product_main_images WHERE product_id=:pid ORDER BY sort_order ASC, id ASC');
    $miStmt->execute(['pid'=>$productId]);
    $mainImages = $miStmt->fetchAll();

    $diStmt = $pdo->prepare('SELECT id, image_url, sort_order FROM tt_product_detail_images WHERE product_id=:pid ORDER BY sort_order ASC, id ASC');
    $diStmt->execute(['pid'=>$productId]);
    $detailImages = $diStmt->fetchAll();
}

$categories = $pdo->query('SELECT id,name FROM tt_categories ORDER BY name ASC')->fetchAll();
$brands     = $pdo->query('SELECT id,name FROM tt_brands WHERE is_active=1 ORDER BY name ASC')->fetchAll();

/* ---------- POST 처리 ---------- */
if (is_post()) {

    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error','잘못된 요청입니다. (CSRF 토큰 불일치)');
        redirect('/admin/product_form.php'.($isEdit?('?id='.$productId):''));
    }

    $categoryId    = (int)($_POST['category_id'] ?? 0);
    $brandId       = (int)($_POST['brand_id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $model         = trim($_POST['model'] ?? '');
    $spec          = trim($_POST['spec'] ?? '');
    $origin        = trim($_POST['origin'] ?? '');
    $dotCode       = trim($_POST['dot_code'] ?? '');
    $priceOriginal = (int)($_POST['price_original'] ?? 0);
    $priceSale     = (int)($_POST['price_sale'] ?? 0);
    $supplyPrice   = (int)($_POST['supply_price'] ?? 0);
    $stock         = (int)($_POST['stock'] ?? 0);
    $status        = ($_POST['status'] ?? 'active') === 'hidden' ? 'hidden' : 'active';
    /* [변경] 리치텍스트 에디터에서 넘어온 HTML을 화이트리스트 정제 후 저장 */
    $description   = admin_sanitize_description_html(trim($_POST['description'] ?? ''));

    /* [기존] 타이어 스펙 입력값 파싱 */
    $patternCode     = trim($_POST['pattern_code'] ?? '');
    $loadSpeedRating = trim($_POST['load_speed_rating'] ?? '');
    $widthMm         = trim($_POST['width_mm'] ?? '') === '' ? null : (int)$_POST['width_mm'];
    $aspectRatio     = trim($_POST['aspect_ratio'] ?? '') === '' ? null : (int)$_POST['aspect_ratio'];
    $rimDiameter     = trim($_POST['rim_diameter'] ?? '');
    $patternName     = trim($_POST['pattern_name'] ?? '');
    $oem             = trim($_POST['oem'] ?? '');
    $tech            = trim($_POST['tech'] ?? '');
    $runflat         = ($_POST['runflat'] ?? 'N') === 'Y' ? 'Y' : 'N';

    /* [신규] 노출 배지 입력값 파싱 */
    $carType = trim($_POST['car_type'] ?? '');
    if ($carType !== '' && !in_array($carType, $CAR_TYPE_OPTIONS, true)) { $carType = ''; }
    $grade = mb_substr(trim($_POST['grade'] ?? ''), 0, 10);

    $featureTagsChecked = $_POST['feature_tags'] ?? [];
    $featureTagsExtra   = trim($_POST['feature_tags_extra'] ?? '');
    $featureTagList = [];
    foreach ((array)$featureTagsChecked as $tag) {
        $tag = trim((string)$tag);
        if ($tag !== '' && in_array($tag, $FEATURE_TAG_OPTIONS, true)) $featureTagList[] = $tag;
    }
    if ($featureTagsExtra !== '') {
        foreach (preg_split('/[,\/]+/u', $featureTagsExtra) as $tag) {
            $tag = trim($tag);
            if ($tag !== '') $featureTagList[] = $tag;
        }
    }
    $featureTagList = array_slice(array_values(array_unique($featureTagList)), 0, 6); // 최대 6개
    $featureTags = implode(',', $featureTagList);

    if ($categoryId<=0) { $errors[]='카테고리를 선택해 주세요.'; $fieldErrors['category_id']=true; }
    if ($brandId<=0)    { $errors[]='브랜드를 선택해 주세요.';   $fieldErrors['brand_id']=true; }
    if ($name==='')     { $errors[]='상품명을 입력해 주세요.';   $fieldErrors['name']=true; }
    if ($priceOriginal<=0){ $errors[]='정상가(공장도가)를 입력해 주세요.'; $fieldErrors['price_original']=true; }
    if ($priceOriginal<0) $errors[]='정상가는 0 이상이어야 합니다.';
    if ($priceSale<0)     $errors[]='대표판매가는 0 이상이어야 합니다.';
    if ($priceSale>0 && $priceOriginal>0 && $priceSale>$priceOriginal) $errors[]='대표판매가가 정상가보다 클 수 없습니다.';
    if ($supplyPrice<0) $errors[]='공급가는 0 이상이어야 합니다.';
    if ($stock<0) $errors[]='재고는 0 이상이어야 합니다.';

    $optDotCode    = $_POST['opt_dot_code']    ?? [];
    $optPriceSale  = $_POST['opt_price_sale']  ?? [];
    $optSizes      = $_POST['opt_size']        ?? [];
    $optStockQty   = $_POST['opt_stock_qty']   ?? [];
    $optIsActive   = $_POST['opt_is_active']   ?? [];
    $optId         = $_POST['opt_id']          ?? [];
    $optDelete     = $_POST['opt_delete']      ?? [];

    $parsedOptions = [];
    foreach ($optDotCode as $i => $dotVal) {
        $dotVal  = trim((string)$dotVal);
        $sizeVal = trim((string)($optSizes[$i] ?? ''));
        $priceSaleOpt = (int)($optPriceSale[$i] ?? 0);

        if ($dotVal === '' && $sizeVal === '') continue;

        $parsedOptions[] = [
            'id'          => isset($optId[$i]) ? (int)$optId[$i] : 0,
            'size'        => $sizeVal !== '' ? $sizeVal : $spec,
            'dot_code'    => $dotVal,
            'price_sale'  => $priceSaleOpt,
            'stock_qty'   => (int)($optStockQty[$i] ?? 0),
            'is_active'   => ((int)($optIsActive[$i] ?? 0)) === 1 ? 1 : 0,
            'delete'      => ((int)($optDelete[$i] ?? 0)) === 1,
        ];
    }

    foreach ($parsedOptions as $k => $opt) {
        if ($opt['delete']) continue;
        if ($opt['dot_code'] === '') { $errors[]='옵션 '.($k+1).'번: DOT 코드를 입력해 주세요.'; continue; }
        if ($opt['price_sale']<=0)   { $errors[]='DOT '.$opt['dot_code'].': 판매가를 입력해 주세요.'; }
        if ($priceOriginal>0 && $opt['price_sale']>$priceOriginal) { $errors[]='DOT '.$opt['dot_code'].': 판매가('.$priceOriginal.'원)보다 클 수 없습니다.'; }
        if ($opt['stock_qty']<0)     { $errors[]='DOT '.$opt['dot_code'].': 재고는 0 이상이어야 합니다.'; }
    }

    /* ---- 대표이미지: 삭제 / 순서 / 신규 업로드 ---- */
    $mainImgDelete = $_POST['main_img_delete'] ?? [];
    $mainImgOrder  = $_POST['main_img_order']  ?? [];

    $keptMainImages = [];
    if ($isEdit) {
        foreach ($mainImages as $mi) {
            $mid = (int)$mi['id'];
            if (!empty($mainImgDelete[$mid])) continue;
            $keptMainImages[] = [
                'id'         => $mid,
                'image_url'  => $mi['image_url'],
                'sort_order' => isset($mainImgOrder[$mid]) ? (int)$mainImgOrder[$mid] : (int)$mi['sort_order'],
            ];
        }
        usort($keptMainImages, fn($a,$b) => $a['sort_order'] <=> $b['sort_order']);
    }

    $newMainImgResult = admin_handle_multi_images_upload($_FILES['main_images'] ?? [], MAIN_IMG_MAX_SIZE_MB, 'products');
    foreach ($newMainImgResult['errors'] as $errMsg) { $errors[] = $errMsg; }

    $totalMainImgCount = count($keptMainImages) + count($newMainImgResult['files']);
    if ($totalMainImgCount > MAIN_IMG_MAX_COUNT) {
        $errors[] = '대표 이미지는 최대 '.MAIN_IMG_MAX_COUNT.'장까지 등록할 수 있습니다. (현재 '.$totalMainImgCount.'장)';
    }
    if ($totalMainImgCount === 0) {
        $errors[] = '대표 이미지를 최소 1장 이상 등록해 주세요.';
    }

    /* ---- 상세페이지 이미지: 삭제 / 순서 / 신규 업로드 ---- */
    $detailImgDelete = $_POST['detail_img_delete'] ?? [];
    $detailImgOrder  = $_POST['detail_img_order']  ?? [];

    $keptDetailImages = [];
    if ($isEdit) {
        foreach ($detailImages as $di) {
            $did = (int)$di['id'];
            if (!empty($detailImgDelete[$did])) continue;
            $keptDetailImages[] = [
                'id'         => $did,
                'image_url'  => $di['image_url'],
                'sort_order' => isset($detailImgOrder[$did]) ? (int)$detailImgOrder[$did] : (int)$di['sort_order'],
            ];
        }
        usort($keptDetailImages, fn($a,$b) => $a['sort_order'] <=> $b['sort_order']);
    }

    $newDetailImgResult = admin_handle_multi_images_upload($_FILES['detail_images'] ?? [], DETAIL_IMG_MAX_SIZE_MB, 'products/detail');
    foreach ($newDetailImgResult['errors'] as $errMsg) { $errors[] = $errMsg; }

    $totalDetailImgCount = count($keptDetailImages) + count($newDetailImgResult['files']);
    if ($totalDetailImgCount > DETAIL_IMG_MAX_COUNT) {
        $errors[] = '상세페이지 이미지는 최대 '.DETAIL_IMG_MAX_COUNT.'장까지 등록할 수 있습니다. (현재 '.$totalDetailImgCount.'장)';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $params = [
                'category_id'=>$categoryId,'brand_id'=>$brandId,'name'=>$name,
                'model'=>$model!==''?$model:null,'spec'=>$spec!==''?$spec:null,
                'origin'=>$origin!==''?$origin:null,'dot_code'=>$dotCode!==''?$dotCode:null,
                'price_original'=>$priceOriginal,
                'price_sale'=>$priceSale,'supply_price'=>$supplyPrice,'stock'=>$stock,
                'status'=>$status,'description'=>$description!==''?$description:null,
                /* [기존] 타이어 스펙 params */
                'pattern_code'=>$patternCode!==''?$patternCode:null,
                'load_speed_rating'=>$loadSpeedRating!==''?$loadSpeedRating:null,
                'width_mm'=>$widthMm, 'aspect_ratio'=>$aspectRatio,
                'rim_diameter'=>$rimDiameter!==''?$rimDiameter:null,
                'pattern_name'=>$patternName!==''?$patternName:null,
                'oem'=>$oem!==''?$oem:null, 'tech'=>$tech!==''?$tech:null,
                'runflat'=>$runflat,
                /* [신규] 노출 배지 params */
                'car_type'=>$carType!==''?$carType:null,
                'grade'=>$grade!==''?$grade:null,
                'feature_tags'=>$featureTags!==''?$featureTags:null,
            ];

            if ($isEdit) {
                $params['id'] = $productId;
                $pdo->prepare('UPDATE tt_products SET category_id=:category_id, brand_id=:brand_id, name=:name,
                    model=:model, spec=:spec, origin=:origin, dot_code=:dot_code,
                    price_original=:price_original, price_sale=:price_sale, supply_price=:supply_price,
                    stock=:stock, status=:status, description=:description,
                    pattern_code=:pattern_code, load_speed_rating=:load_speed_rating,
                    width_mm=:width_mm, aspect_ratio=:aspect_ratio, rim_diameter=:rim_diameter,
                    pattern_name=:pattern_name, oem=:oem, tech=:tech, runflat=:runflat,
                    car_type=:car_type, grade=:grade, feature_tags=:feature_tags
                    WHERE id=:id')->execute($params);
            } else {
                $pdo->prepare('INSERT INTO tt_products (category_id,brand_id,name,model,spec,origin,dot_code,
                    price_original,price_sale,supply_price,stock,status,description,
                    pattern_code, load_speed_rating, width_mm, aspect_ratio, rim_diameter,
                    pattern_name, oem, tech, runflat,
                    car_type, grade, feature_tags, created_at)
                    VALUES (:category_id,:brand_id,:name,:model,:spec,:origin,:dot_code,
                    :price_original,:price_sale,:supply_price,:stock,:status,:description,
                    :pattern_code, :load_speed_rating, :width_mm, :aspect_ratio, :rim_diameter,
                    :pattern_name, :oem, :tech, :runflat,
                    :car_type, :grade, :feature_tags, NOW())')->execute($params);
                $productId = (int)$pdo->lastInsertId();
            }

            /* ---- 옵션 저장 ---- */
            $existingOptIds = [];
            if ($isEdit) {
                $existStmt = $pdo->prepare('SELECT id FROM tt_product_options WHERE product_id=:pid');
                $existStmt->execute(['pid'=>$productId]);
                $existingOptIds = array_column($existStmt->fetchAll(), 'id');
            }
            $keptOptIds = [];
            foreach ($parsedOptions as $opt) {
                if ($opt['delete']) {
                    if ($opt['id']>0) {
                        $pdo->prepare('DELETE FROM tt_product_options WHERE id=:id AND product_id=:pid')
                            ->execute(['id'=>$opt['id'],'pid'=>$productId]);
                    }
                    continue;
                }
                if ($opt['id']>0) {
                    $pdo->prepare('UPDATE tt_product_options SET size=:size, dot_code=:dot_code,
                        price_sale=:price_sale, stock_qty=:stock_qty, is_active=:is_active
                        WHERE id=:id AND product_id=:pid')->execute([
                        'size'=>$opt['size'],'dot_code'=>$opt['dot_code'],'price_sale'=>$opt['price_sale'],
                        'stock_qty'=>$opt['stock_qty'],'is_active'=>$opt['is_active'],
                        'id'=>$opt['id'],'pid'=>$productId
                    ]);
                    $keptOptIds[] = $opt['id'];
                } else {
                    $pdo->prepare('INSERT INTO tt_product_options (product_id,size,dot_code,price_sale,stock_qty,is_active)
                        VALUES (:pid,:size,:dot_code,:price_sale,:stock_qty,:is_active)')->execute([
                        'pid'=>$productId,'size'=>$opt['size'],'dot_code'=>$opt['dot_code'],
                        'price_sale'=>$opt['price_sale'],'stock_qty'=>$opt['stock_qty'],'is_active'=>$opt['is_active']
                    ]);
                    $keptOptIds[] = (int)$pdo->lastInsertId();
                }
            }
            foreach (array_diff($existingOptIds, $keptOptIds) as $rid) {
                $pdo->prepare('DELETE FROM tt_product_options WHERE id=:id AND product_id=:pid')
                    ->execute(['id'=>$rid,'pid'=>$productId]);
            }

            /* ---- 대표이미지 저장: 삭제 → 순서갱신 → 신규저장 ---- */
            if ($isEdit) {
                foreach ($mainImages as $mi) {
                    $mid = (int)$mi['id'];
                    if (!empty($mainImgDelete[$mid])) {
                        $pdo->prepare('DELETE FROM tt_product_main_images WHERE id=:id AND product_id=:pid')
                            ->execute(['id'=>$mid,'pid'=>$productId]);
                    }
                }
                foreach ($keptMainImages as $order => $mi) {
                    $pdo->prepare('UPDATE tt_product_main_images SET sort_order=:so WHERE id=:id AND product_id=:pid')
                        ->execute(['so'=>$order, 'id'=>$mi['id'], 'pid'=>$productId]);
                }
            }
            $nextMainOrder = count($keptMainImages);
            foreach ($newMainImgResult['files'] as $url) {
                $pdo->prepare('INSERT INTO tt_product_main_images (product_id, image_url, sort_order) VALUES (:pid,:url,:so)')
                    ->execute(['pid'=>$productId, 'url'=>$url, 'so'=>$nextMainOrder]);
                $nextMainOrder++;
            }
            $firstImgStmt = $pdo->prepare('SELECT image_url FROM tt_product_main_images WHERE product_id=:pid ORDER BY sort_order ASC, id ASC LIMIT 1');
            $firstImgStmt->execute(['pid'=>$productId]);
            $firstImgUrl = $firstImgStmt->fetchColumn();
            $pdo->prepare('UPDATE tt_products SET thumbnail_url=:url WHERE id=:id')
                ->execute(['url'=> $firstImgUrl !== false ? $firstImgUrl : null, 'id'=>$productId]);

            /* ---- 상세페이지 이미지 저장: 삭제 → 순서갱신 → 신규저장 ---- */
            if ($isEdit) {
                foreach ($detailImages as $di) {
                    $did = (int)$di['id'];
                    if (!empty($detailImgDelete[$did])) {
                        $pdo->prepare('DELETE FROM tt_product_detail_images WHERE id=:id AND product_id=:pid')
                            ->execute(['id'=>$did,'pid'=>$productId]);
                    }
                }
                foreach ($keptDetailImages as $order => $di) {
                    $pdo->prepare('UPDATE tt_product_detail_images SET sort_order=:so WHERE id=:id AND product_id=:pid')
                        ->execute(['so'=>$order, 'id'=>$di['id'], 'pid'=>$productId]);
                }
            }
            $nextDetailOrder = count($keptDetailImages);
            foreach ($newDetailImgResult['files'] as $url) {
                $pdo->prepare('INSERT INTO tt_product_detail_images (product_id, image_url, sort_order) VALUES (:pid,:url,:so)')
                    ->execute(['pid'=>$productId, 'url'=>$url, 'so'=>$nextDetailOrder]);
                $nextDetailOrder++;
            }

            $pdo->commit();
            flash('admin_success', $isEdit ? '상품이 수정되었습니다.' : '상품이 등록되었습니다.');
            redirect('/admin/products.php');

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[admin/product_form] '.$e->getMessage());
            $errors[] = '저장 중 오류가 발생했습니다. ('.$e->getMessage().')';
        }
    }

    $product = [
        'category_id'=>$categoryId,'brand_id'=>$brandId,'name'=>$name,'model'=>$model,'spec'=>$spec,
        'origin'=>$origin,'dot_code'=>$dotCode,'price_original'=>$priceOriginal,'price_sale'=>$priceSale,
        'supply_price'=>$supplyPrice,'stock'=>$stock,'status'=>$status,'description'=>$description,
        'thumbnail_url'=>$product['thumbnail_url'] ?? '',
        'pattern_code'=>$patternCode, 'load_speed_rating'=>$loadSpeedRating,
        'width_mm'=>$widthMm, 'aspect_ratio'=>$aspectRatio, 'rim_diameter'=>$rimDiameter,
        'pattern_name'=>$patternName, 'oem'=>$oem, 'tech'=>$tech, 'runflat'=>$runflat,
        'car_type'=>$carType, 'grade'=>$grade, 'feature_tags'=>$featureTags,
    ];
    $options = array_values(array_filter($parsedOptions, fn($o) => !$o['delete']));
}

$pageTitle = $isEdit ? '상품 수정' : '상품 등록';
require __DIR__ . '/includes/header.php';

/* 체크박스 렌더링용: 저장된 feature_tags 문자열 → 배열 */
$checkedTags = array_filter(array_map('trim', explode(',', (string)($product['feature_tags'] ?? ''))));
?>
<style>
.admin-pf-layout{display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:flex-start;}
@media (max-width:1100px){.admin-pf-layout{grid-template-columns:1fr;}}
.admin-pf-preview-sticky{position:sticky;top:16px;border:1px solid #e2e8f0;border-radius:16px;padding:18px;background:#fafbff;}
.admin-form-hint{font-size:12px;color:#64748b;margin:4px 0 10px;}
.admin-main-img-list,.admin-detail-img-list{list-style:none;margin:0 0 10px;padding:0;display:flex;flex-direction:column;gap:8px;}
.admin-main-img-item,.admin-detail-img-item{display:flex;align-items:center;gap:10px;padding:8px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;}
.admin-main-img-item img,.admin-detail-img-item img{width:56px;height:56px;object-fit:cover;border-radius:8px;flex-shrink:0;}
.admin-main-img-badge{font-size:11px;font-weight:700;color:#6366f1;background:#eef2ff;padding:3px 8px;border-radius:999px;white-space:nowrap;}
.admin-detail-img-badge{font-size:11px;font-weight:700;color:#059669;background:#ecfdf5;padding:3px 8px;border-radius:999px;white-space:nowrap;}
.admin-main-img-actions,.admin-detail-img-actions{display:flex;align-items:center;gap:6px;margin-left:auto;}
.admin-main-img-actions button,.admin-detail-img-actions button{padding:4px 8px;font-size:12px;}
.admin-main-img-del,.admin-detail-img-del{font-size:12px;color:#64748b;display:flex;align-items:center;gap:4px;white-space:nowrap;}
.admin-main-img-count-info,.admin-detail-img-count-info{font-size:12px;color:#64748b;margin-top:6px;}
.admin-detail-preview-btn-row{margin-top:10px;}
.btn-detail-preview{background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe;padding:8px 16px;border-radius:999px;font-weight:700;font-size:13px;cursor:pointer;}
.btn-detail-preview:hover{background:#e0e7ff;}
.pf-card-preview{display:flex;gap:12px;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;margin-bottom:20px;}
.pf-card-thumb{width:72px;height:72px;border-radius:10px;overflow:hidden;background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.pf-card-thumb img{width:100%;height:100%;object-fit:cover;}
.pf-card-brand{font-size:12px;color:#94a3b8;}
.pf-card-name{font-size:14px;font-weight:700;color:#1e293b;margin:2px 0 6px;word-break:break-all;}
.pf-card-price-row{display:flex;align-items:center;gap:6px;}
.pf-card-discount{font-size:13px;font-weight:800;color:#ef4444;}
.pf-card-price-sale{font-size:15px;font-weight:800;color:#1e293b;}
.pf-card-price-orig{font-size:12px;color:#94a3b8;text-decoration:line-through;display:none;}
.pf-detail-preview{padding-top:6px;border-top:1px dashed #e2e8f0;}
.pf-detail-main-img{width:100%;aspect-ratio:1/1;border-radius:12px;overflow:hidden;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin-bottom:8px;position:relative;}
.pf-detail-main-img img{width:100%;height:100%;object-fit:cover;}
.pf-detail-thumbs{display:flex;gap:6px;margin-bottom:12px;}
.pf-detail-thumbs img{width:44px;height:44px;object-fit:cover;border-radius:8px;cursor:pointer;border:2px solid transparent;}
.pf-detail-thumbs img.active{border-color:#6366f1;}
.pf-detail-brand{font-size:12px;color:#94a3b8;margin:0;}
.pf-detail-name{font-size:16px;font-weight:800;margin:2px 0;word-break:break-all;}
.pf-detail-model{font-size:12px;color:#64748b;margin:0 0 8px;}
.pf-detail-price{font-size:18px;font-weight:800;color:#1e293b;margin-bottom:8px;}
.pf-detail-desc{font-size:13px;color:#475569;word-break:break-all;}
.ph{font-size:26px;}

.admin-spec-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px 16px;}
@media (max-width:760px){.admin-spec-grid{grid-template-columns:repeat(2,1fr);}}

.admin-badge-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 16px;margin-bottom:14px;}
@media (max-width:760px){.admin-badge-grid{grid-template-columns:1fr;}}
.admin-tag-checkbox-group{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;}
.admin-tag-checkbox{display:inline-flex;align-items:center;gap:5px;padding:7px 12px;border:1px solid #e2e8f0;border-radius:999px;font-size:13px;cursor:pointer;background:#fff;transition:.12s;}
.admin-tag-checkbox:has(input:checked){background:#eef2ff;border-color:#a5b4fc;color:#4338ca;font-weight:700;}
.admin-badge-preview-row{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.admin-badge-preview-chip{font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;}

.admin-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.6);display:flex;align-items:center;justify-content:center;z-index:9999;opacity:0;visibility:hidden;transition:opacity .18s ease;}
.admin-modal-overlay.active{opacity:1;visibility:visible;}
.admin-modal-box{background:#fff;border-radius:16px;width:min(480px,92vw);max-height:88vh;display:flex;flex-direction:column;overflow:hidden;transform:translateY(14px) scale(.97);transition:transform .18s ease;}
.admin-modal-overlay.active .admin-modal-box{transform:translateY(0) scale(1);}
.admin-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #e2e8f0;flex-shrink:0;}
.admin-modal-header h3{margin:0;font-size:16px;font-weight:800;}
.admin-modal-close{background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;line-height:1;}
.admin-modal-body{overflow-y:auto;padding:0;background:#f1f5f9;}
.admin-modal-body img{display:block;width:100%;height:auto;}
.admin-modal-empty{padding:40px 20px;text-align:center;color:#94a3b8;font-size:13px;}

/* [신규] 상세설명 리치텍스트 에디터 */
.admin-rte-wrap{border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;background:#fff;}
.admin-rte-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:4px;padding:8px;background:#f8fafc;border-bottom:1px solid #e2e8f0;}
.admin-rte-btn{min-width:30px;height:30px;padding:0 6px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;font-size:13px;color:#334155;display:inline-flex;align-items:center;justify-content:center;}
.admin-rte-btn:hover{background:#eef2ff;border-color:#c7d2fe;}
.admin-rte-select{height:30px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;padding:0 4px;background:#fff;color:#334155;}
.admin-rte-divider{width:1px;height:22px;background:#e2e8f0;margin:0 2px;}
.admin-rte-color-label{display:inline-flex;align-items:center;gap:2px;height:30px;padding:0 6px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;}
.admin-rte-color-label input[type="color"]{width:18px;height:18px;padding:0;border:none;cursor:pointer;background:none;}
.admin-rte-editor{min-height:220px;max-height:520px;overflow-y:auto;padding:14px;font-size:15px;line-height:1.6;color:#1e293b;outline:none;}
.admin-rte-editor:empty::before{content:"상품 상세 설명을 입력해 주세요.";color:#94a3b8;}
.admin-rte-editor a{color:#4338ca;text-decoration:underline;}
</style>
<div class="admin-card">
  <h2 class="admin-page-title"><?= $pageTitle ?></h2>

  <?php if (!empty($errors)): ?>
    <div class="admin-alert admin-alert-error">
      <ul><?php foreach ($errors as $err) echo '<li>'.h($err).'</li>'; ?></ul>
    </div>
  <?php endif; ?>

  <div class="admin-pf-layout">
  <form method="post" enctype="multipart/form-data" class="admin-product-form" id="productForm" novalidate>
    <?= Csrf::field() ?>

    <h3 class="admin-form-section-title">기본 정보</h3>
    <div class="admin-form-row">
      <label>카테고리 *</label>
      <select name="category_id" class="<?= isset($fieldErrors['category_id'])?'input-error':'' ?>">
        <option value="">선택</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$product['category_id']===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-form-row">
      <label>브랜드 *</label>
      <select name="brand_id" class="<?= isset($fieldErrors['brand_id'])?'input-error':'' ?>">
        <option value="">선택</option>
        <?php foreach ($brands as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= (int)$product['brand_id']===(int)$b['id']?'selected':'' ?>><?= h($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-form-row">
      <label>상품명 *</label>
      <input type="text" name="name" value="<?= h($product['name']) ?>" class="<?= isset($fieldErrors['name'])?'input-error':'' ?>">
    </div>
    <div class="admin-form-row">
      <label>모델명</label>
      <input type="text" name="model" value="<?= h($product['model']) ?>">
    </div>
    <div class="admin-form-row">
      <label>규격/사이즈(spec)</label>
      <input type="text" name="spec" value="<?= h($product['spec']) ?>" placeholder="예: 235/45R18">
    </div>
    <div class="admin-form-row">
      <label>원산지</label>
      <input type="text" name="origin" value="<?= h($product['origin']) ?>">
    </div>
    <div class="admin-form-row">
      <label>대표 DOT 코드(선택)</label>
      <input type="text" name="dot_code" value="<?= h($product['dot_code']) ?>" placeholder="대표로 노출할 생산 DOT 코드를 입력해 주세요">
    </div>
    <div class="admin-form-row">
      <label>상태</label>
      <select name="status">
        <option value="active" <?= $product['status']==='active'?'selected':'' ?>>판매중</option>
        <option value="hidden" <?= $product['status']==='hidden'?'selected':'' ?>>숨김</option>
      </select>
    </div>

    <h3 class="admin-form-section-title">타이어 스펙</h3>
    <div class="admin-spec-grid">
      <div class="admin-form-row">
        <label>패턴코드</label>
        <input type="text" name="pattern_code" value="<?= h((string)$product['pattern_code']) ?>" placeholder="예: K127">
      </div>
      <div class="admin-form-row">
        <label>하중&속도규격</label>
        <input type="text" name="load_speed_rating" value="<?= h((string)$product['load_speed_rating']) ?>" placeholder="예: 94V">
      </div>
      <div class="admin-form-row">
        <label>단면폭(mm)</label>
        <input type="number" name="width_mm" value="<?= h((string)($product['width_mm'] ?? '')) ?>" placeholder="예: 235">
      </div>
      <div class="admin-form-row">
        <label>편평비(%)</label>
        <input type="number" name="aspect_ratio" value="<?= h((string)($product['aspect_ratio'] ?? '')) ?>" placeholder="예: 45">
      </div>
      <div class="admin-form-row">
        <label>림직경(인치)</label>
        <input type="text" name="rim_diameter" value="<?= h((string)$product['rim_diameter']) ?>" placeholder="예: 18">
      </div>
      <div class="admin-form-row">
        <label>패턴명</label>
        <input type="text" name="pattern_name" value="<?= h((string)$product['pattern_name']) ?>" placeholder="예: Ventus S1 evo3">
      </div>
      <div class="admin-form-row">
        <label>OEM</label>
        <input type="text" name="oem" value="<?= h((string)$product['oem']) ?>" placeholder="예: 현대 신차용">
      </div>
      <div class="admin-form-row">
        <label>Tech.</label>
        <input type="text" name="tech" value="<?= h((string)$product['tech']) ?>" placeholder="예: Silent, EV전용">
      </div>
      <div class="admin-form-row">
        <label>Runflat</label>
        <select name="runflat">
          <option value="N" <?= ($product['runflat'] ?? 'N')==='N'?'selected':'' ?>>N (일반)</option>
          <option value="Y" <?= ($product['runflat'] ?? '')==='Y'?'selected':'' ?>>Y (런플랫)</option>
        </select>
      </div>
    </div>

    <h3 class="admin-form-section-title">노출 배지 <span class="admin-form-hint" style="margin:0;">(상품 상세페이지 이미지 위/옆에 표시됩니다)</span></h3>
    <div class="admin-badge-grid">
      <div class="admin-form-row">
        <label>차량타입</label>
        <select name="car_type">
          <option value="">선택 안 함</option>
          <?php foreach ($CAR_TYPE_OPTIONS as $ct): ?>
            <option value="<?= h($ct) ?>" <?= ($product['car_type'] ?? '')===$ct?'selected':'' ?>><?= h($ct) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-form-row">
        <label>등급/트림 배지</label>
        <input type="text" name="grade" value="<?= h((string)($product['grade'] ?? '')) ?>" maxlength="10" placeholder="예: RC, 프리미엄 (최대 10자)">
      </div>
    </div>
    <div class="admin-form-row">
      <label>특징 태그 (최대 6개, 이미지 위에 캡슐 형태로 노출)</label>
      <div class="admin-tag-checkbox-group" id="featureTagCheckboxGroup">
        <?php foreach ($FEATURE_TAG_OPTIONS as $opt): ?>
          <label class="admin-tag-checkbox">
            <input type="checkbox" name="feature_tags[]" value="<?= h($opt) ?>" <?= in_array($opt, $checkedTags, true) ? 'checked' : '' ?>>
            <?= h($opt) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <input type="text" name="feature_tags_extra" placeholder="목록에 없는 태그는 콤마(,)로 구분해 직접 입력">
      <div class="admin-badge-preview-row" id="badgePreviewRow"></div>
    </div>

    <h3 class="admin-form-section-title">가격 / 재고</h3>
    <div class="admin-form-row">
      <label>정상가(공장도가) *</label>
      <input type="number" name="price_original" value="<?= (int)$product['price_original'] ?>" min="0"
             class="<?= isset($fieldErrors['price_original'])?'input-error':'' ?>">
    </div>
    <div class="admin-form-row">
      <label>대표 판매가</label>
      <input type="number" name="price_sale" value="<?= (int)$product['price_sale'] ?>" min="0">
    </div>
    <div class="admin-form-row">
      <label>공급가</label>
      <input type="number" name="supply_price" value="<?= (int)$product['supply_price'] ?>" min="0">
    </div>
    <div class="admin-form-row">
      <label>재고</label>
      <input type="number" name="stock" value="<?= (int)$product['stock'] ?>" min="0">
    </div>

    <h3 class="admin-form-section-title">대표 이미지 (최대 <?= MAIN_IMG_MAX_COUNT ?>장)</h3>
    <p class="admin-form-hint">첫 번째 이미지가 상품 목록·상세페이지의 대표 이미지로 사용됩니다. 장당 <?= MAIN_IMG_MAX_SIZE_MB ?>MB 이하 (jpg, png, webp, gif)</p>
    <div class="admin-form-row">
      <ul id="mainImgList" class="admin-main-img-list">
        <?php foreach ($mainImages as $i => $mi): ?>
        <li class="admin-main-img-item" data-id="<?= (int)$mi['id'] ?>">
          <span class="admin-main-img-badge"><?= $i === 0 ? '대표' : ($i+1).'번' ?></span>
          <img src="<?= h($mi['image_url']) ?>" alt="">
          <input type="hidden" name="main_img_order[<?= (int)$mi['id'] ?>]" value="<?= $i ?>" class="main-img-order-input">
          <div class="admin-main-img-actions">
            <button type="button" class="btn-admin-secondary btn-main-img-up">▲</button>
            <button type="button" class="btn-admin-secondary btn-main-img-down">▼</button>
            <label class="admin-main-img-del"><input type="checkbox" name="main_img_delete[<?= (int)$mi['id'] ?>]" value="1"> 삭제</label>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <input type="file" name="main_images[]" id="mainImgFileInput" accept=".jpg,.jpeg,.png,.webp,.gif" multiple>
      <p class="admin-main-img-count-info" id="mainImgCountInfo"></p>
    </div>

    <h3 class="admin-form-section-title">상세 설명</h3>
    <div class="admin-form-row">
      <div class="admin-rte-wrap">
        <div class="admin-rte-toolbar" id="descToolbar">
          <select class="admin-rte-select" id="descFontFamily" title="글꼴">
            <option value="">글꼴</option>
            <option value="'Noto Sans KR', sans-serif">Noto Sans KR</option>
            <option value="'Malgun Gothic', sans-serif">맑은 고딕</option>
            <option value="Georgia, serif">Georgia</option>
            <option value="'Courier New', monospace">Courier New</option>
          </select>
          <select class="admin-rte-select" id="descFontSize" title="글자 크기">
            <option value="">크기</option>
            <option value="12px">12px</option>
            <option value="14px">14px</option>
            <option value="16px">16px</option>
            <option value="18px">18px</option>
            <option value="20px">20px</option>
            <option value="24px">24px</option>
            <option value="28px">28px</option>
            <option value="32px">32px</option>
          </select>
          <span class="admin-rte-divider"></span>
          <button type="button" class="admin-rte-btn" data-cmd="bold" title="굵게"><b>B</b></button>
          <button type="button" class="admin-rte-btn" data-cmd="italic" title="기울임"><i>I</i></button>
          <button type="button" class="admin-rte-btn" data-cmd="underline" title="밑줄"><u>U</u></button>
          <button type="button" class="admin-rte-btn" data-cmd="strikeThrough" title="취소선"><s>S</s></button>
          <span class="admin-rte-divider"></span>
          <label class="admin-rte-color-label" title="글자색">
            <span style="color:#ef4444;font-weight:800;">A</span>
            <input type="color" id="descFontColor" value="#111111">
          </label>
          <label class="admin-rte-color-label" title="배경색(하이라이트)">
            <span style="background:#fde047;padding:0 3px;">A</span>
            <input type="color" id="descBgColor" value="#fff59d">
          </label>
          <span class="admin-rte-divider"></span>
          <button type="button" class="admin-rte-btn" data-cmd="justifyLeft" title="왼쪽 정렬">◄</button>
          <button type="button" class="admin-rte-btn" data-cmd="justifyCenter" title="가운데 정렬">≡</button>
          <button type="button" class="admin-rte-btn" data-cmd="justifyRight" title="오른쪽 정렬">►</button>
          <span class="admin-rte-divider"></span>
          <button type="button" class="admin-rte-btn" data-cmd="insertUnorderedList" title="글머리 기호">•</button>
          <button type="button" class="admin-rte-btn" data-cmd="insertOrderedList" title="번호 매기기">1.</button>
          <span class="admin-rte-divider"></span>
          <button type="button" class="admin-rte-btn" id="descLinkBtn" title="링크 삽입">🔗</button>
          <button type="button" class="admin-rte-btn" id="descClearBtn" title="서식 지우기">✕서식</button>
        </div>
        <div id="descEditor" class="admin-rte-editor" contenteditable="true"><?= admin_desc_to_editor_html((string)$product['description']) ?></div>
        <textarea name="description" id="descHidden" style="display:none;"><?= h($product['description']) ?></textarea>
      </div>
      <p class="admin-form-hint">굵기·색상·정렬·글꼴 등 서식을 적용할 수 있습니다. 허용되지 않은 태그/속성은 저장 시 자동으로 제거됩니다.</p>
    </div>

    <h3 class="admin-form-section-title">상세페이지 이미지 (최대 <?= DETAIL_IMG_MAX_COUNT ?>장)</h3>
    <p class="admin-form-hint">등록한 순서대로 상세페이지 하단에 세로로 이어붙여 표시됩니다. 장당 <?= DETAIL_IMG_MAX_SIZE_MB ?>MB 이하 (jpg, png, webp, gif)</p>
    <div class="admin-form-row">
      <ul id="detailImgList" class="admin-detail-img-list">
        <?php foreach ($detailImages as $i => $di): ?>
        <li class="admin-detail-img-item" data-id="<?= (int)$di['id'] ?>">
          <span class="admin-detail-img-badge"><?= $i+1 ?>번</span>
          <img src="<?= h($di['image_url']) ?>" alt="">
          <input type="hidden" name="detail_img_order[<?= (int)$di['id'] ?>]" value="<?= $i ?>" class="detail-img-order-input">
          <div class="admin-detail-img-actions">
            <button type="button" class="btn-admin-secondary btn-detail-img-up">▲</button>
            <button type="button" class="btn-admin-secondary btn-detail-img-down">▼</button>
            <label class="admin-detail-img-del"><input type="checkbox" name="detail_img_delete[<?= (int)$di['id'] ?>]" value="1"> 삭제</label>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <input type="file" name="detail_images[]" id="detailImgFileInput" accept=".jpg,.jpeg,.png,.webp,.gif" multiple>
      <p class="admin-detail-img-count-info" id="detailImgCountInfo"></p>
      <div class="admin-detail-preview-btn-row">
        <button type="button" class="btn-detail-preview" id="btnDetailPreview">🔍 상세페이지 이미지 이어붙임 미리보기</button>
      </div>
    </div>

    <h3 class="admin-form-section-title">DOT 옵션 (제조연주차별 판매가/재고 있을 시 옵션/등급)</h3>
    <table class="admin-table-trendy admin-option-table" id="optionTable">
      <thead>
        <tr>
          <th>DOT 코드 *</th>
          <th>판매가(원) *</th>
          <th>사이즈(선택)</th>
          <th>재고수량</th>
          <th>활성화</th>
          <th>삭제</th>
        </tr>
      </thead>
      <tbody>
        <?php $idx = 0; foreach ($options as $opt): ?>
        <tr>
          <td>
            <input type="hidden" name="opt_id[<?= $idx ?>]" value="<?= (int)($opt['id'] ?? 0) ?>">
            <input type="text" name="opt_dot_code[<?= $idx ?>]" value="<?= h($opt['dot_code'] ?? '') ?>" placeholder="예: 2026">
          </td>
          <td><input type="number" name="opt_price_sale[<?= $idx ?>]" value="<?= (int)($opt['price_sale'] ?? 0) ?>" min="0" step="10"></td>
          <td><input type="text" name="opt_size[<?= $idx ?>]" value="<?= h($opt['size'] ?? '') ?>" placeholder="사이즈"></td>
          <td><input type="number" name="opt_stock_qty[<?= $idx ?>]" value="<?= (int)($opt['stock_qty'] ?? 0) ?>" min="0"></td>
          <td style="text-align:center">
            <input type="hidden" name="opt_is_active[<?= $idx ?>]" value="0">
            <input type="checkbox" name="opt_is_active[<?= $idx ?>]" value="1" <?= (int)($opt['is_active'] ?? 1)===1?'checked':'' ?>>
          </td>
          <td style="text-align:center"><input type="checkbox" name="opt_delete[<?= $idx ?>]" value="1"> 삭제</td>
        </tr>
        <?php $idx++; endforeach; ?>
        <?php if (empty($options)): ?>
        <tr>
          <td><input type="text" name="opt_dot_code[0]" placeholder="예: 2026"></td>
          <td><input type="number" name="opt_price_sale[0]" value="0" min="0" step="10"></td>
          <td><input type="text" name="opt_size[0]" placeholder="사이즈"></td>
          <td><input type="number" name="opt_stock_qty[0]" value="0" min="0"></td>
          <td style="text-align:center">
            <input type="hidden" name="opt_is_active[0]" value="0">
            <input type="checkbox" name="opt_is_active[0]" value="1" checked>
          </td>
          <td style="text-align:center">-</td>
        </tr>
        <?php $idx = 1; endif; ?>
      </tbody>
    </table>
    <input type="hidden" id="optIndexCounter" value="<?= $idx ?>">
    <button type="button" id="btnAddOption" class="btn-admin-secondary">+ DOT 옵션 추가</button>

    <div class="admin-form-actions">
      <a href="<?= BASE_URL ?>/admin/products.php" class="btn-admin-secondary">취소</a>
      <button type="submit" class="btn-admin-primary"><?= $isEdit ? '수정 완료' : '상품 등록' ?></button>
    </div>
  </form>

  <aside class="admin-pf-preview">
    <div class="admin-pf-preview-sticky">
      <h3 class="admin-form-section-title" style="margin-top:0;">실시간 미리보기</h3>

      <div class="pf-card-preview">
        <div class="pf-card-thumb" id="pfCardThumb"><span class="ph">🛞</span></div>
        <div class="pf-card-body">
          <div class="pf-card-brand" id="pfCardBrand"></div>
          <div class="pf-card-name" id="pfCardName">상품명이 여기에 표시됩니다</div>
          <div class="pf-card-price-row">
            <span class="pf-card-discount" id="pfCardDiscount" style="display:none;"></span>
            <span class="pf-card-price-sale" id="pfCardPriceSale">0원</span>
          </div>
          <div class="pf-card-price-orig" id="pfCardPriceOrig"></div>
        </div>
      </div>

      <div class="pf-detail-preview">
        <div class="pf-detail-main-img" id="pfDetailMainImg">
          <span class="ph">🛞</span>
        </div>
        <div class="pf-detail-thumbs" id="pfDetailThumbs"></div>
        <p class="pf-detail-brand" id="pfDetailBrand"></p>
        <h4 class="pf-detail-name" id="pfDetailName">상품명이 여기에 표시됩니다</h4>
        <p class="pf-detail-model" id="pfDetailModel"></p>
        <div class="pf-detail-price" id="pfDetailPrice">0원</div>
        <p class="pf-detail-desc" id="pfDetailDesc">상품 설명이 여기에 표시됩니다.</p>
      </div>
    </div>
  </aside>
  </div>
</div>

<!-- 상세페이지 이미지 이어붙임 미리보기 모달: 실제 product-detail.php 의 .pd-detail-images 처럼 세로 이어보기 -->
<div class="admin-modal-overlay" id="detailPreviewModalOverlay">
  <div class="admin-modal-box">
    <div class="admin-modal-header">
      <h3>상세페이지 이미지 이어붙임 미리보기</h3>
      <button type="button" class="admin-modal-close" id="detailPreviewModalClose" aria-label="닫기">&times;</button>
    </div>
    <div class="admin-modal-body" id="detailPreviewModalBody"></div>
  </div>
</div>

<script>
document.getElementById('productForm').addEventListener('submit', function(e){
  const catSel   = this.querySelector('[name="category_id"]');
  const brandSel = this.querySelector('[name="brand_id"]');
  const nameInp  = this.querySelector('[name="name"]');
  const priceOrig = this.querySelector('[name="price_original"]');
  let ok = true;
  if (!catSel.value) { ok=false; catSel.classList.add('input-error'); }
  if (!brandSel.value) { ok=false; brandSel.classList.add('input-error'); }
  if (!nameInp.value.trim()) { ok=false; nameInp.classList.add('input-error'); }
  if (!priceOrig.value || parseInt(priceOrig.value,10)<=0) { ok=false; priceOrig.classList.add('input-error'); }

  const keptMainCount = Array.from(document.querySelectorAll('#mainImgList .admin-main-img-item'))
    .filter(li => !li.querySelector('input[type="checkbox"]').checked).length;
  const newMainCount = document.getElementById('mainImgFileInput').files.length;
  if (keptMainCount + newMainCount === 0) { ok=false; alert('대표 이미지를 최소 1장 이상 등록해 주세요.'); }
  if (keptMainCount + newMainCount > <?= MAIN_IMG_MAX_COUNT ?>) { ok=false; alert('대표 이미지는 최대 <?= MAIN_IMG_MAX_COUNT ?>장까지만 등록할 수 있습니다.'); }

  const keptDetailCount = Array.from(document.querySelectorAll('#detailImgList .admin-detail-img-item'))
    .filter(li => !li.querySelector('input[type="checkbox"]').checked).length;
  const newDetailCount = document.getElementById('detailImgFileInput').files.length;
  if (keptDetailCount + newDetailCount > <?= DETAIL_IMG_MAX_COUNT ?>) { ok=false; alert('상세페이지 이미지는 최대 <?= DETAIL_IMG_MAX_COUNT ?>장까지만 등록할 수 있습니다.'); }

  if (!ok) { e.preventDefault(); alert('필수 항목을 확인해 주세요.'); }
});

let optionIndex = parseInt(document.getElementById('optIndexCounter').value, 10);
document.getElementById('btnAddOption').addEventListener('click', () => {
  const tbody = document.querySelector('#optionTable tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input type="text" name="opt_dot_code[${optionIndex}]" placeholder="예: 2026"></td>
    <td><input type="number" name="opt_price_sale[${optionIndex}]" value="0" min="0" step="10"></td>
    <td><input type="text" name="opt_size[${optionIndex}]" placeholder="사이즈"></td>
    <td><input type="number" name="opt_stock_qty[${optionIndex}]" value="0" min="0"></td>
    <td style="text-align:center">
      <input type="hidden" name="opt_is_active[${optionIndex}]" value="0">
      <input type="checkbox" name="opt_is_active[${optionIndex}]" value="1" checked>
    </td>
    <td style="text-align:center"><button type="button" class="btn-admin-danger btn-remove-new-option">삭제</button></td>
  `;
  tbody.appendChild(tr);
  tr.querySelector('.btn-remove-new-option').addEventListener('click', () => tr.remove());
  optionIndex++;
});

/* ================ 노출 배지 실시간 미리보기 칩 ================ */
(function(){
  const carTypeSelect = document.querySelector('[name="car_type"]');
  const gradeInput    = document.querySelector('[name="grade"]');
  const tagGroup       = document.getElementById('featureTagCheckboxGroup');
  const extraInput     = document.querySelector('[name="feature_tags_extra"]');
  const previewRow      = document.getElementById('badgePreviewRow');

  function renderBadgePreview(){
    const chips = [];
    if (carTypeSelect.value) chips.push(carTypeSelect.value);
    if (gradeInput.value.trim()) chips.push(gradeInput.value.trim());
    tagGroup.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => chips.push(cb.value));
    if (extraInput.value.trim()) {
      extraInput.value.split(/[,\/]+/).map(s=>s.trim()).filter(Boolean).forEach(t => chips.push(t));
    }
    previewRow.innerHTML = chips.length
      ? chips.map(c => `<span class="admin-badge-preview-chip">${c}</span>`).join('')
      : '<span class="admin-badge-preview-chip" style="opacity:.5;">설정된 배지 없음</span>';
  }
  [carTypeSelect, gradeInput, extraInput].forEach(el => el.addEventListener('input', renderBadgePreview));
  tagGroup.addEventListener('change', renderBadgePreview);
  renderBadgePreview();
})();

/* ================ [신규] 상세설명 리치텍스트 에디터 툴바 동작 ================ */
(function(){
  const editor    = document.getElementById('descEditor');
  const hidden    = document.getElementById('descHidden');
  const toolbar   = document.getElementById('descToolbar');
  if (!editor || !hidden || !toolbar) return;

  const fontFamilySelect = document.getElementById('descFontFamily');
  const fontSizeSelect   = document.getElementById('descFontSize');
  const fontColorInput   = document.getElementById('descFontColor');
  const bgColorInput     = document.getElementById('descBgColor');
  const linkBtn          = document.getElementById('descLinkBtn');
  const clearBtn         = document.getElementById('descClearBtn');

  function syncHidden(){
    hidden.value = editor.innerHTML;
    // 기존 "실시간 미리보기" 로직이 [name="description"] 의 input 이벤트를 듣고 있으므로 강제로 발생시켜 재사용한다.
    hidden.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function focusEditor(){ editor.focus(); }

  toolbar.querySelectorAll('.admin-rte-btn[data-cmd]').forEach(btn => {
    btn.addEventListener('click', () => {
      focusEditor();
      document.execCommand(btn.dataset.cmd, false, null);
      syncHidden();
    });
  });

  fontFamilySelect.addEventListener('change', () => {
    if (!fontFamilySelect.value) return;
    focusEditor();
    document.execCommand('fontName', false, fontFamilySelect.value);
    syncHidden();
  });

  fontSizeSelect.addEventListener('change', () => {
    if (!fontSizeSelect.value) return;
    focusEditor();
    /* execCommand('fontSize')는 1~7 단계 값만 지원하므로 임시로 <font size="7">를 만든 뒤 실제 px 값을 가진 <span>으로 치환한다. */
    document.execCommand('fontSize', false, '7');
    editor.querySelectorAll('font[size="7"]').forEach(el => {
      const span = document.createElement('span');
      span.style.fontSize = fontSizeSelect.value;
      span.innerHTML = el.innerHTML;
      el.replaceWith(span);
    });
    syncHidden();
  });

  fontColorInput.addEventListener('input', () => {
    focusEditor();
    document.execCommand('foreColor', false, fontColorInput.value);
    syncHidden();
  });

  bgColorInput.addEventListener('input', () => {
    focusEditor();
    document.execCommand('hiliteColor', false, bgColorInput.value);
    syncHidden();
  });

  linkBtn.addEventListener('click', () => {
    const url = prompt('연결할 URL을 입력해 주세요 (http:// 또는 https://)');
    if (!url) return;
    if (!/^https?:\/\//i.test(url)) { alert('http:// 또는 https:// 로 시작하는 주소만 입력할 수 있습니다.'); return; }
    focusEditor();
    document.execCommand('createLink', false, url);
    syncHidden();
  });

  clearBtn.addEventListener('click', () => {
    focusEditor();
    document.execCommand('removeFormat', false, null);
    syncHidden();
  });

  editor.addEventListener('input', syncHidden);
  editor.addEventListener('blur', syncHidden);
  syncHidden(); // 최초 진입 시 1회 동기화

  const formEl = document.getElementById('productForm');
  formEl.addEventListener('submit', syncHidden); // 제출 직전 최종 동기화
})();

/* ================ 대표이미지 + 상세 카드 미리보기 ================ */
(function(){
  const $ = (id) => document.getElementById(id);
  const nameInput      = document.querySelector('[name="name"]');
  const modelInput     = document.querySelector('[name="model"]');
  const brandSelect    = document.querySelector('[name="brand_id"]');
  const priceOrigInput = document.querySelector('[name="price_original"]');
  const priceSaleInput = document.querySelector('[name="price_sale"]');
  const descTextarea   = document.querySelector('[name="description"]'); // = #descHidden

  const cardThumb      = $('pfCardThumb');
  const cardBrand      = $('pfCardBrand');
  const cardName       = $('pfCardName');
  const cardDiscount   = $('pfCardDiscount');
  const cardPriceSale  = $('pfCardPriceSale');
  const cardPriceOrig  = $('pfCardPriceOrig');

  const detailMainImg  = $('pfDetailMainImg');
  const detailThumbs    = $('pfDetailThumbs');
  const detailBrand     = $('pfDetailBrand');
  const detailName      = $('pfDetailName');
  const detailModel     = $('pfDetailModel');
  const detailPrice     = $('pfDetailPrice');
  const detailDesc      = $('pfDetailDesc');

  const mainImgList      = $('mainImgList');
  const mainImgFileInput = $('mainImgFileInput');
  const mainImgCountInfo = $('mainImgCountInfo');
  const MAX_MAIN_COUNT = <?= MAIN_IMG_MAX_COUNT ?>;

  function fmt(n){ return (n||0).toLocaleString('ko-KR'); }

  function keptExistingMainCount(){
    return Array.from(mainImgList.querySelectorAll('.admin-main-img-item'))
      .filter(li => !li.querySelector('input[type="checkbox"]').checked).length;
  }

  function currentGalleryUrls(){
    const kept = Array.from(mainImgList.querySelectorAll('.admin-main-img-item'))
      .filter(li => !li.querySelector('input[type="checkbox"]').checked)
      .map(li => li.querySelector('img').src);
    const newFiles = mainImgFileInput.files ? Array.from(mainImgFileInput.files).map(f => URL.createObjectURL(f)) : [];
    return kept.concat(newFiles).slice(0, MAX_MAIN_COUNT);
  }

  function renderGallery(){
    const urls = currentGalleryUrls();
    if (urls.length === 0) {
      cardThumb.innerHTML = '<span class="ph">🛞</span>';
      detailMainImg.innerHTML = '<span class="ph">🛞</span>';
      detailThumbs.innerHTML = '';
    } else {
      cardThumb.innerHTML = `<img src="${urls[0]}" alt="">`;
      detailMainImg.innerHTML = `<img src="${urls[0]}" alt="">`;
      detailThumbs.innerHTML = urls.map((u,i) => `<img src="${u}" data-i="${i}" class="${i===0?'active':''}">`).join('');
    }
    const total = keptExistingMainCount() + (mainImgFileInput.files ? mainImgFileInput.files.length : 0);
    mainImgCountInfo.textContent = `총 ${total} / ${MAX_MAIN_COUNT}장 등록됨`;
  }

  function updatePreview(){
    const name  = nameInput.value.trim() || '상품명이 여기에 표시됩니다';
    const model = modelInput.value.trim();
    const brandOpt = brandSelect.options[brandSelect.selectedIndex];
    const brandText = (brandOpt && brandOpt.value) ? brandOpt.text : '';
    const priceOrig = parseInt(priceOrigInput.value, 10) || 0;
    const priceSale = parseInt(priceSaleInput.value, 10) || 0;
    const desc = descTextarea.value.trim(); // [변경] 이제 HTML 문자열

    cardBrand.textContent = brandText;
    cardName.textContent  = name;
    cardPriceSale.textContent = fmt(priceSale) + '원';

    if (priceOrig > 0 && priceSale > 0 && priceSale < priceOrig) {
      const pct = Math.round((1 - priceSale/priceOrig) * 100);
      cardDiscount.style.display = 'inline-block';
      cardDiscount.textContent = pct + '%';
      cardPriceOrig.style.display = 'block';
      cardPriceOrig.textContent = fmt(priceOrig) + '원';
    } else {
      cardDiscount.style.display = 'none';
      cardPriceOrig.style.display = 'none';
      cardPriceOrig.textContent = '';
    }

    detailBrand.textContent = brandText;
    detailName.textContent  = name;
    detailModel.textContent = model;
    detailPrice.textContent = fmt(priceSale > 0 ? priceSale : priceOrig) + '원';
    detailDesc.innerHTML    = desc || '상품 설명이 여기에 표시됩니다.'; // [변경] textContent → innerHTML (서식 반영)

    renderGallery();
  }

  [nameInput, modelInput, priceOrigInput, priceSaleInput, descTextarea].forEach(el => {
    el.addEventListener('input', updatePreview);
  });
  brandSelect.addEventListener('change', updatePreview);

  mainImgList.addEventListener('change', (e) => {
    if (e.target.matches('input[type="checkbox"]')) updatePreview();
  });

  mainImgList.addEventListener('click', function(e){
    const li = e.target.closest('.admin-main-img-item');
    if (!li) return;
    if (e.target.classList.contains('btn-main-img-up')) {
      const prev = li.previousElementSibling;
      if (prev) li.parentNode.insertBefore(li, prev);
    } else if (e.target.classList.contains('btn-main-img-down')) {
      const next = li.nextElementSibling;
      if (next) li.parentNode.insertBefore(next, li);
    } else {
      return;
    }
    document.querySelectorAll('#mainImgList .admin-main-img-item').forEach((item, idx) => {
      item.querySelector('.main-img-order-input').value = idx;
      item.querySelector('.admin-main-img-badge').textContent = idx === 0 ? '대표' : (idx+1)+'번';
    });
    updatePreview();
  });

  mainImgFileInput.addEventListener('change', function(){
    const maxNew = Math.max(0, MAX_MAIN_COUNT - keptExistingMainCount());
    if (this.files.length > maxNew) {
      alert(`대표 이미지는 최대 ${MAX_MAIN_COUNT}장까지만 등록할 수 있습니다. 기존 이미지가 ${keptExistingMainCount()}장 있어 추가로 ${maxNew}장까지 등록할 수 있습니다.`);
      const dt = new DataTransfer();
      Array.from(this.files).slice(0, maxNew).forEach(f => dt.items.add(f));
      this.files = dt.files;
    }
    updatePreview();
  });

  detailThumbs.addEventListener('click', (e) => {
    if (e.target.tagName !== 'IMG') return;
    detailMainImg.innerHTML = `<img src="${e.target.src}" alt="">`;
    detailThumbs.querySelectorAll('img').forEach(img => img.classList.remove('active'));
    e.target.classList.add('active');
  });

  updatePreview();
})();

/* ================ 상세페이지 이미지 이어붙임 + 미리보기 모달 ================ */
(function(){
  const detailImgList      = document.getElementById('detailImgList');
  const detailImgFileInput = document.getElementById('detailImgFileInput');
  const detailImgCountInfo = document.getElementById('detailImgCountInfo');
  const MAX_DETAIL_COUNT   = <?= DETAIL_IMG_MAX_COUNT ?>;

  const btnPreview   = document.getElementById('btnDetailPreview');
  const modalOverlay = document.getElementById('detailPreviewModalOverlay');
  const modalBody    = document.getElementById('detailPreviewModalBody');
  const modalClose   = document.getElementById('detailPreviewModalClose');

  function keptExistingDetailCount(){
    return Array.from(detailImgList.querySelectorAll('.admin-detail-img-item'))
      .filter(li => !li.querySelector('input[type="checkbox"]').checked).length;
  }

  function updateDetailCountInfo(){
    const total = keptExistingDetailCount() + (detailImgFileInput.files ? detailImgFileInput.files.length : 0);
    detailImgCountInfo.textContent = `총 ${total} / ${MAX_DETAIL_COUNT}장 등록됨`;
  }

  detailImgList.addEventListener('change', (e) => {
    if (e.target.matches('input[type="checkbox"]')) updateDetailCountInfo();
  });

  detailImgList.addEventListener('click', function(e){
    const li = e.target.closest('.admin-detail-img-item');
    if (!li) return;
    if (e.target.classList.contains('btn-detail-img-up')) {
      const prev = li.previousElementSibling;
      if (prev) li.parentNode.insertBefore(li, prev);
    } else if (e.target.classList.contains('btn-detail-img-down')) {
      const next = li.nextElementSibling;
      if (next) li.parentNode.insertBefore(next, li);
    } else {
      return;
    }
    document.querySelectorAll('#detailImgList .admin-detail-img-item').forEach((item, idx) => {
      item.querySelector('.detail-img-order-input').value = idx;
      item.querySelector('.admin-detail-img-badge').textContent = (idx+1)+'번';
    });
  });

  detailImgFileInput.addEventListener('change', function(){
    const maxNew = Math.max(0, MAX_DETAIL_COUNT - keptExistingDetailCount());
    if (this.files.length > maxNew) {
      alert(`상세페이지 이미지는 최대 ${MAX_DETAIL_COUNT}장까지만 등록할 수 있습니다. 기존 이미지가 ${keptExistingDetailCount()}장 있어 추가로 ${maxNew}장까지 등록할 수 있습니다.`);
      const dt = new DataTransfer();
      Array.from(this.files).slice(0, maxNew).forEach(f => dt.items.add(f));
      this.files = dt.files;
    }
    updateDetailCountInfo();
  });

  function currentDetailImageUrls(){
    const kept = Array.from(detailImgList.querySelectorAll('.admin-detail-img-item'))
      .filter(li => !li.querySelector('input[type="checkbox"]').checked)
      .map(li => li.querySelector('img').src);
    const newFiles = detailImgFileInput.files ? Array.from(detailImgFileInput.files).map(f => URL.createObjectURL(f)) : [];
    return kept.concat(newFiles);
  }

  function openDetailPreview(){
    const urls = currentDetailImageUrls();
    if (urls.length === 0) {
      modalBody.innerHTML = '<p class="admin-modal-empty">등록된 상세페이지 이미지가 없습니다.</p>';
    } else {
      modalBody.innerHTML = urls.map(u => `<img src="${u}" alt="">`).join('');
    }
    modalOverlay.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
  function closeDetailPreview(){
    modalOverlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  btnPreview.addEventListener('click', openDetailPreview);
  modalClose.addEventListener('click', closeDetailPreview);
  modalOverlay.addEventListener('click', (e) => { if (e.target === modalOverlay) closeDetailPreview(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modalOverlay.classList.contains('active')) closeDetailPreview(); });

  updateDetailCountInfo();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
