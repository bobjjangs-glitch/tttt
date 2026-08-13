<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');
$pdo = Database::connection();

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
$options = [];

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM tt_products WHERE id=:id');
    $stmt->execute(['id'=>$productId]);
    $row = $stmt->fetch();
    if (!$row) { flash('admin_error','존재하지 않는 상품입니다.'); redirect('/admin/products.php'); }
    $product = array_merge($product, $row);

    /* ★ 수정: DOT 코드(WWYY 4자리) 최신순 정렬.
       단순 dot_code DESC 문자열 정렬은 연도 경계(예: 5225 vs 0126)에서 틀리므로
       연도(뒤 2자리) → 주차(앞 2자리) 순으로 명시 정렬한다.
       4자리 숫자가 아닌 비정형 값은 뒤로 보내고 문자열 DESC + id ASC로 안전하게 처리. */
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
}

$categories = $pdo->query('SELECT id,name FROM tt_categories ORDER BY name ASC')->fetchAll();
$brands     = $pdo->query('SELECT id,name FROM tt_brands WHERE is_active=1 ORDER BY name ASC')->fetchAll();

/* ---------- 썸네일 업로드 ---------- */
function admin_handle_thumbnail_upload(array $file): array {
    if (!isset($file['error']) || $file['error']===UPLOAD_ERR_NO_FILE) return ['ok'=>true,'url'=>null];
    if ($file['error']!==UPLOAD_ERR_OK) {
        $map=[
            UPLOAD_ERR_INI_SIZE=>'서버에 설정된 업로드 최대 크기를 초과했습니다.',
            UPLOAD_ERR_FORM_SIZE=>'폼에서 지정한 업로드 최대 크기를 초과했습니다.',
            UPLOAD_ERR_PARTIAL=>'파일이 일부만 업로드되었습니다.',
            UPLOAD_ERR_NO_TMP_DIR=>'임시 폴더가 없습니다.',
            UPLOAD_ERR_CANT_WRITE=>'디스크에 파일을 쓸 수 없습니다.'
        ];
        return ['ok'=>false,'msg'=>$map[$file['error']] ?? '이미지 업로드 오류(code='.$file['error'].')'];
    }
    if (@getimagesize($file['tmp_name'])===false) return ['ok'=>false,'msg'=>'이미지 파일만 업로드할 수 있습니다.'];
    $allowed=['jpg','jpeg','png','webp','gif'];
    $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    if (!in_array($ext,$allowed,true)) return ['ok'=>false,'msg'=>'지원하지 않는 이미지 형식입니다. (jpg, png, webp, gif만 가능)'];
    if ($file['size']>5*1024*1024) return ['ok'=>false,'msg'=>'이미지 크기는 5MB 이하만 가능합니다.'];
    $uploadDir=__DIR__.'/../uploads/products';
    if (!is_dir($uploadDir)) @mkdir($uploadDir,0755,true);
    $filename='p_'.date('YmdHis').'_'.bin2hex(random_bytes(4)).'.'.$ext;
    $target=$uploadDir.'/'.$filename;
    if (!move_uploaded_file($file['tmp_name'],$target)) return ['ok'=>false,'msg'=>'이미지 저장에 실패했습니다.'];
    return ['ok'=>true,'url'=>BASE_URL.'/uploads/products/'.$filename];
}

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
    $dotCode       = trim($_POST['dot_code'] ?? '');       // 대표 DOT(선택)
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

    /* ★ 참고: DOT 코드는 자유 텍스트 필드다. "2025", "2026" 형태의 4자리는 물론
       그 외 형식도 입력 가능하도록 이미 열려 있으며, 별도 제한 로직이 없다.
       즉 DOT2025 / DOT2026 추가는 이미 가능하고, 이번 수정으로 목록/수정화면 모두에서
       연도가 최신인 DOT이 항상 먼저 표시된다. */
    foreach ($parsedOptions as $k => $opt) {
        if ($opt['delete']) continue;
        if ($opt['dot_code'] === '') { $errors[]='옵션 '.($k+1).'번: DOT 코드를 입력해 주세요.'; continue; }
        if ($opt['price_sale']<=0)   { $errors[]='DOT '.$opt['dot_code'].': 판매가를 입력해 주세요.'; }
        if ($priceOriginal>0 && $opt['price_sale']>$priceOriginal) { $errors[]='DOT '.$opt['dot_code'].': 판매가가 정상가('.$priceOriginal.'원)보다 큽니다.'; }
        if ($opt['stock_qty']<0)     { $errors[]='DOT '.$opt['dot_code'].': 재고는 0 이상이어야 합니다.'; }
    }

    $uploadResult = admin_handle_thumbnail_upload($_FILES['thumbnail'] ?? []);
    if (!$uploadResult['ok']) $errors[] = $uploadResult['msg'];

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $thumbnailUrl = $product['thumbnail_url'] ?? null;
            if (!empty($uploadResult['url'])) $thumbnailUrl = $uploadResult['url'];
            elseif (isset($_POST['remove_thumbnail'])) $thumbnailUrl = null;

            $params = [
                'category_id'=>$categoryId,'brand_id'=>$brandId,'name'=>$name,
                'model'=>$model!==''?$model:null,'spec'=>$spec!==''?$spec:null,
                'origin'=>$origin!==''?$origin:null,'dot_code'=>$dotCode!==''?$dotCode:null,
                'thumbnail_url'=>$thumbnailUrl,'price_original'=>$priceOriginal,
                'price_sale'=>$priceSale,'supply_price'=>$supplyPrice,'stock'=>$stock,
                'status'=>$status,'description'=>$description!==''?$description:null
            ];

            if ($isEdit) {
                $params['id'] = $productId;
                $pdo->prepare('UPDATE tt_products SET category_id=:category_id, brand_id=:brand_id, name=:name,
                    model=:model, spec=:spec, origin=:origin, dot_code=:dot_code, thumbnail_url=:thumbnail_url,
                    price_original=:price_original, price_sale=:price_sale, supply_price=:supply_price,
                    stock=:stock, status=:status, description=:description WHERE id=:id')->execute($params);
            } else {
                $pdo->prepare('INSERT INTO tt_products (category_id,brand_id,name,model,spec,origin,dot_code,
                    thumbnail_url,price_original,price_sale,supply_price,stock,status,description,created_at)
                    VALUES (:category_id,:brand_id,:name,:model,:spec,:origin,:dot_code,:thumbnail_url,
                    :price_original,:price_sale,:supply_price,:stock,:status,:description,NOW())')->execute($params);
                $productId = (int)$pdo->lastInsertId();
            }

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
}


$pageTitle = $isEdit ? '상품 수정' : '상품 등록';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
  <h2 class="admin-page-title"><?= $pageTitle ?></h2>

  <?php if (!empty($errors)): ?>
    <div class="admin-alert admin-alert-error">
      <ul><?php foreach ($errors as $err) echo '<li>'.h($err).'</li>'; ?></ul>
    </div>
  <?php endif; ?>

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

    <h3 class="admin-form-section-title">이미지</h3>
    <div class="admin-form-row">
      <?php if (!empty($product['thumbnail_url'])): ?>
        <img src="<?= h($product['thumbnail_url']) ?>" style="max-width:120px;display:block;margin-bottom:8px;">
        <label><input type="checkbox" name="remove_thumbnail" value="1"> 이미지 삭제</label>
      <?php endif; ?>
      <input type="file" name="thumbnail" accept=".jpg,.jpeg,.png,.webp,.gif">
    </div>

    <h3 class="admin-form-section-title">상세 설명</h3>
    <div class="admin-form-row">
      <textarea name="description" rows="6"><?= h($product['description']) ?></textarea>
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
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
