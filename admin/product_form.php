<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');
$pdo = Database::connection();

if (!defined('MAIN_IMG_MAX_COUNT'))     define('MAIN_IMG_MAX_COUNT', 3);
if (!defined('MAIN_IMG_MAX_SIZE_MB'))   define('MAIN_IMG_MAX_SIZE_MB', 5);
if (!defined('DETAIL_IMG_MAX_COUNT'))   define('DETAIL_IMG_MAX_COUNT', 15);
if (!defined('DETAIL_IMG_MAX_SIZE_MB')) define('DETAIL_IMG_MAX_SIZE_MB', 5);

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
ensure_product_main_images_table($pdo);
ensure_product_detail_images_table($pdo);

/* ---------- 공통 다중 이미지 업로드 검증/저장 ---------- */
function admin_handle_multi_images_upload(array $files, int $maxSizeMb, string $subDir = 'products'): array {
    $result = ['ok' => true, 'files' => [], 'errors' => []];
    if (empty($files) || !isset($files['name']) || !is_array($files['name'])) {
        return $result; // 새로 선택한 파일 없음
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
            $result['errors'][] = ($files['name'][$i] ?? '파일') . ': 업로드 오류(code=' . $err . ')';
            continue;
        }
        if (@getimagesize($files['tmp_name'][$i]) === false) {
            $result['ok'] = false;
            $result['errors'][] = $files['name'][$i] . ': 이미지 파일만 업로드할 수 있습니다.';
            continue;
        }
        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $result['ok'] = false;
            $result['errors'][] = $files['name'][$i] . ': 지원하지 않는 형식입니다. (jpg, png, webp, gif만 가능)';
            continue;
        }
        if ($files['size'][$i] > $maxSizeMb * 1024 * 1024) {
            $result['ok'] = false;
            $result['errors'][] = $files['name'][$i] . ': 이미지 크기는 ' . $maxSizeMb . 'MB 이하만 가능합니다.';
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

/* ---------- 상태 초기화 ---------- */
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit    = $productId > 0;
$errors      = [];
$fieldErrors = [];

$product = [
    'category_id'=>'', 'brand_id'=>'', 'name'=>'', 'model'=>'', 'spec'=>'', 'origin'=>'',
    'dot_code'=>'', 'price_original'=>0, 'price_sale'=>0, 'supply_price'=>0,
    'stock'=>0, 'status'=>'active', 'description'=>'', 'thumbnail_url'=>''
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
    $description   = trim($_POST['description'] ?? '');

    if ($categoryId<=0) { $errors[]='카테고리를 선택해 주세요.'; $fieldErrors['category_id']=true; }
    if ($brandId<=0)    { $errors[]='브랜드를 선택해 주세요.';   $fieldErrors['brand_id']=true; }
    if ($name==='')     { $errors[]='상품명을 입력해 주세요.';   $fieldErrors['name']=true; }
    if ($priceOriginal<=0){ $errors[]='정상가(공장도가)를 입력해 주세요.'; $fieldErrors['price_original']=true; }
    if ($priceOriginal<0) $errors[]='정상가는 0 이상이어야 합니다.';
    if ($priceSale<0)     $errors[]='판매가는 0 이상이어야 합니다.';
    if ($priceSale>0 && $priceOriginal>0 && $priceSale>$priceOriginal) $errors[]='판매가는 정상가보다 클 수 없습니다.';
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
        if ($priceOriginal>0 && $opt['price_sale']>$priceOriginal) { $errors[]='DOT '.$opt['dot_code'].': 판매가가 정상가('.$priceOriginal.'원)보다 큽니다.'; }
        if ($opt['stock_qty']<0)     { $errors[]='DOT '.$opt['dot_code'].': 재고는 0 이상이어야 합니다.'; }
    }

    /* ---- 대표이미지: 삭제 체크 / 순서 / 신규 업로드 ---- */
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
        $errors[] = '대표 이미지는 최대 '.MAIN_IMG_MAX_COUNT.'장까지만 등록할 수 있습니다. (현재 '.$totalMainImgCount.'장)';
    }
    if ($totalMainImgCount === 0) {
        $errors[] = '대표 이미지를 최소 1장 이상 등록해 주세요.';
    }

    /* ---- 상세페이지 이미지: 삭제 체크 / 순서 / 신규 업로드 ---- */
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
        $errors[] = '상세페이지 이미지는 최대 '.DETAIL_IMG_MAX_COUNT.'장까지만 등록할 수 있습니다. (현재 '.$totalDetailImgCount.'장)';
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
                'status'=>$status,'description'=>$description!==''?$description:null
            ];

            if ($isEdit) {
                $params['id'] = $productId;
                $pdo->prepare('UPDATE tt_products SET category_id=:category_id, brand_id=:brand_id, name=:name,
                    model=:model, spec=:spec, origin=:origin, dot_code=:dot_code,
                    price_original=:price_original, price_sale=:price_sale, supply_price=:supply_price,
                    stock=:stock, status=:status, description=:description WHERE id=:id')->execute($params);
            } else {
                $pdo->prepare('INSERT INTO tt_products (category_id,brand_id,name,model,spec,origin,dot_code,
                    price_original,price_sale,supply_price,stock,status,description,created_at)
                    VALUES (:category_id,:brand_id,:name,:model,:spec,:origin,:dot_code,
                    :price_original,:price_sale,:supply_price,:stock,:status,:description,NOW())')->execute($params);
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

            /* ---- 대표이미지 저장: 삭제 → 순서갱신 → 신규삽입 → 썸네일 동기화 ---- */
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

            /* ---- 상세페이지 이미지 저장: 삭제 → 순서갱신 → 신규삽입 ---- */
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
        'thumbnail_url'=>$product['thumbnail_url'] ?? ''
    ];
    $options = array_values(array_filter($parsedOptions, fn($o) => !$o['delete']));
    // 검증 실패 시 이미지 목록은 최초 로드값 유지(신규 업로드 파일은 재선택 필요)
}

$pageTitle = $isEdit ? '상품 수정' : '상품 등록';
require __DIR__ . '/includes/header.php';
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
.pf-detail-main-img{width:100%;aspect-ratio:1/1;border-radius:12px;overflow:hidden;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin-bottom:8px;}
.pf-detail-main-img img{width:100%;height:100%;object-fit:cover;}
.pf-detail-thumbs{display:flex;gap:6px;margin-bottom:12px;}
.pf-detail-thumbs img{width:44px;height:44px;object-fit:cover;border-radius:8px;cursor:pointer;border:2px solid transparent;}
.pf-detail-thumbs img.active{border-color:#6366f1;}
.pf-detail-brand{font-size:12px;color:#94a3b8;margin:0;}
.pf-detail-name{font-size:16px;font-weight:800;margin:2px 0;word-break:break-all;}
.pf-detail-model{font-size:12px;color:#64748b;margin:0 0 8px;}
.pf-detail-price{font-size:18px;font-weight:800;color:#1e293b;margin-bottom:8px;}
.pf-detail-desc{font-size:13px;color:#475569;white-space:pre-line;word-break:break-all;}
.ph{font-size:26px;}

/* ===== 상세페이지 이미지 모달 미리보기 ===== */
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
      <input type="text" name="dot_code" value="<?= h($product['dot_code']) ?>" placeholder="목록 카드에 안 쓰이면 비워도 됨">
    </div>
    <div class="admin-form-row">
      <label>상태</label>
      <select name="status">
        <option value="active" <?= $product['status']==='active'?'selected':'' ?>>노출</option>
        <option value="hidden" <?= $product['status']==='hidden'?'selected':'' ?>>숨김</option>
      </select>
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
      <textarea name="description" rows="6"><?= h($product['description']) ?></textarea>
    </div>

    <h3 class="admin-form-section-title">상세페이지 이미지 (최대 <?= DETAIL_IMG_MAX_COUNT ?>장)</h3>
    <p class="admin-form-hint">등록한 순서대로 상세페이지 하단에 세로로 이어붙어 표시됩니다. 장당 <?= DETAIL_IMG_MAX_SIZE_MB ?>MB 이하 (jpg, png, webp, gif)</p>
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
        <button type="button" class="btn-detail-preview" id="btnDetailPreview">🔍 상세페이지 미리보기</button>
      </div>
    </div>

    <h3 class="admin-form-section-title">DOT 옵션 (제조연주차별 판매가/재고)</h3>
    <table class="admin-table-trendy admin-option-table" id="optionTable">
      <thead>
        <tr>
          <th>DOT 코드 *</th>
          <th>판매가(원) *</th>
          <th>사이즈(선택)</th>
          <th>재고</th>
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
          <td><input type="text" name="opt_size[<?= $idx ?>]" value="<?= h($opt['size'] ?? '') ?>" placeholder="선택"></td>
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
          <td><input type="text" name="opt_size[0]" placeholder="선택"></td>
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
      <button type="submit" class="btn-admin-primary"><?= $isEdit ? '수정 저장' : '상품 등록' ?></button>
    </div>
  </form>

  <aside class="admin-pf-preview">
    <div class="admin-pf-preview-sticky">
      <h3 class="admin-form-section-title" style="margin-top:0;">실시간 미리보기</h3>

      <div class="pf-card-preview">
        <div class="pf-card-thumb" id="pfCardThumb"><span class="ph">🛞</span></div>
        <div class="pf-card-body">
          <div class="pf-card-brand" id="pfCardBrand"></div>
          <div class="pf-card-name" id="pfCardName">상품명을 입력하세요</div>
          <div class="pf-card-price-row">
            <span class="pf-card-discount" id="pfCardDiscount" style="display:none;"></span>
            <span class="pf-card-price-sale" id="pfCardPriceSale">0원</span>
          </div>
          <div class="pf-card-price-orig" id="pfCardPriceOrig"></div>
        </div>
      </div>

      <div class="pf-detail-preview">
        <div class="pf-detail-main-img" id="pfDetailMainImg"><span class="ph">🛞</span></div>
        <div class="pf-detail-thumbs" id="pfDetailThumbs"></div>
        <p class="pf-detail-brand" id="pfDetailBrand"></p>
        <h4 class="pf-detail-name" id="pfDetailName">상품명을 입력하세요</h4>
        <p class="pf-detail-model" id="pfDetailModel"></p>
        <div class="pf-detail-price" id="pfDetailPrice">0원</div>
        <p class="pf-detail-desc" id="pfDetailDesc">상세 설명이 여기에 표시됩니다.</p>
      </div>
    </div>
  </aside>
  </div>
</div>

<!-- 상세페이지 이미지 미리보기 모달: 실제 product-detail.php의 .pd-detail-images 처럼 세로로 이어붙여 렌더 -->
<div class="admin-modal-overlay" id="detailPreviewModalOverlay">
  <div class="admin-modal-box">
    <div class="admin-modal-header">
      <h3>상세페이지 미리보기</h3>
      <button type="button" class="admin-modal-close" id="detailPreviewModalClose" aria-label="닫기">&times;</button>
    </div>
    <div class="admin-modal-body" id="detailPreviewModalBody"></div>
  </div>
</div>

<script>
document.getElementById('productForm').addEventListener('submit', function(e){
  const catSel = this.querySelector('[name="category_id"]');
  const brandSel = this.querySelector('[name="brand_id"]');
  const nameInp = this.querySelector('[name="name"]');
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
    <td><input type="text" name="opt_size[${optionIndex}]" placeholder="선택"></td>
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

/* ================= 대표이미지 + 실시간 미리보기 ================= */
(function(){
  const $ = (id) => document.getElementById(id);
  const nameInput      = document.querySelector('[name="name"]');
  const modelInput     = document.querySelector('[name="model"]');
  const brandSelect    = document.querySelector('[name="brand_id"]');
  const priceOrigInput = document.querySelector('[name="price_original"]');
  const priceSaleInput = document.querySelector('[name="price_sale"]');
  const descTextarea   = document.querySelector('[name="description"]');

  const cardThumb      = $('pfCardThumb');
  const cardBrand      = $('pfCardBrand');
  const cardName       = $('pfCardName');
  const cardDiscount   = $('pfCardDiscount');
  const cardPriceSale  = $('pfCardPriceSale');
  const cardPriceOrig  = $('pfCardPriceOrig');

  const detailMainImg  = $('pfDetailMainImg');
  const detailThumbs   = $('pfDetailThumbs');
  const detailBrand    = $('pfDetailBrand');
  const detailName     = $('pfDetailName');
  const detailModel    = $('pfDetailModel');
  const detailPrice    = $('pfDetailPrice');
  const detailDesc     = $('pfDetailDesc');

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
    mainImgCountInfo.textContent = `현재 ${total} / ${MAX_MAIN_COUNT}장 등록됨`;
  }

  function updatePreview(){
    const name  = nameInput.value.trim() || '상품명을 입력하세요';
    const model = modelInput.value.trim();
    const brandOpt = brandSelect.options[brandSelect.selectedIndex];
    const brandText = (brandOpt && brandOpt.value) ? brandOpt.text : '';
    const priceOrig = parseInt(priceOrigInput.value, 10) || 0;
    const priceSale = parseInt(priceSaleInput.value, 10) || 0;
    const desc = descTextarea.value.trim();

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
    detailDesc.textContent  = desc || '상세 설명이 여기에 표시됩니다.';

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
      alert(`대표 이미지는 최대 ${MAX_MAIN_COUNT}장까지 등록할 수 있습니다. 기존 이미지가 ${keptExistingMainCount()}장 있어 최대 ${maxNew}장만 추가로 선택할 수 있습니다.`);
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

/* ================= 상세페이지 이미지 관리 + 모달 미리보기 ================= */
(function(){
  const detailImgList      = document.getElementById('detailImgList');
  const detailImgFileInput = document.getElementById('detailImgFileInput');
  const detailImgCountInfo = document.getElementById('detailImgCountInfo');
  const MAX_DETAIL_COUNT   = <?= DETAIL_IMG_MAX_COUNT ?>;

  const btnPreview   = document.getElementById('btnDetailPreview');
  const modalOverlay = document.getElementById('detailPreviewModalOverlay');
  const modalBody    = document.getElementById('detailPreviewModalBody');
  const modalClose    = document.getElementById('detailPreviewModalClose');

  function keptExistingDetailCount(){
    return Array.from(detailImgList.querySelectorAll('.admin-detail-img-item'))
      .filter(li => !li.querySelector('input[type="checkbox"]').checked).length;
  }

  function updateDetailCountInfo(){
    const total = keptExistingDetailCount() + (detailImgFileInput.files ? detailImgFileInput.files.length : 0);
    detailImgCountInfo.textContent = `현재 ${total} / ${MAX_DETAIL_COUNT}장 등록됨`;
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
      alert(`상세페이지 이미지는 최대 ${MAX_DETAIL_COUNT}장까지 등록할 수 있습니다. 기존 이미지가 ${keptExistingDetailCount()}장 있어 최대 ${maxNew}장만 추가로 선택할 수 있습니다.`);
      const dt = new DataTransfer();
      Array.from(this.files).slice(0, maxNew).forEach(f => dt.items.add(f));
      this.files = dt.files;
    }
    updateDetailCountInfo();
  });

  /* 저장 전 실제 상품 상세페이지의 .pd-detail-images와 동일한 순서/모양으로 미리보기 렌더 */
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
