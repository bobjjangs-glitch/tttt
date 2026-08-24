<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
$pdo = Database::connection();

/* ---------- GET 파라미터 ---------- */
$cat        = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

// 제조사 체크박스는 다중 선택이므로 배열로 받는다 (brand[]=1&brand[]=2 ...)
$brandIds   = array_values(array_filter(array_map('intval', (array)($_GET['brand'] ?? [])), fn($v) => $v > 0));

// 화면의 "품명" 입력칸
$name       = trim($_GET['name'] ?? '');

// 단면폭 / 편평비 / 인치 (spec 문자열 "235/45R18"을 패턴으로 매칭)
$width      = trim($_GET['width'] ?? '');
$ratio      = trim($_GET['ratio'] ?? '');
$inch       = trim($_GET['inch'] ?? '');

// 사이즈 통합 입력 "245451" — 슬래시/R을 뺀 숫자만으로 빠르게 찾는 검색
$sizeInput  = preg_replace('/[^0-9]/', '', trim($_GET['size_input'] ?? ''));

// 재고 있는 상품만 보기
$stockOnly  = isset($_GET['stock_only']) && $_GET['stock_only'] === '1';

$sort  = $_GET['sort'] ?? 'popular';
$page  = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

/* 안전한 정렬 화이트리스트 */
$sortMap = [
    'popular'    => 'p.sales_count DESC, p.review_count DESC, p.id DESC',
    'price_low'  => 'p.price_sale ASC',
    'price_high' => 'p.price_sale DESC',
    'new'        => 'p.id DESC',
];
$orderBy = $sortMap[$sort] ?? $sortMap['popular'];

/* ---------- WHERE 조건 ---------- */
$where = ['p.status = "active"'];
$params = [];

if ($cat > 0) {
    $where[] = 'p.category_id = :cat';
    $params['cat'] = $cat;
}

if (!empty($brandIds)) {
    $brandPlaceholders = [];
    foreach ($brandIds as $i => $bid) {
        $key = 'brand' . $i;
        $brandPlaceholders[] = ':' . $key;
        $params[$key] = $bid;
    }
    $where[] = 'p.brand_id IN (' . implode(',', $brandPlaceholders) . ')';
}

if ($name !== '') {
    $where[] = '(p.name LIKE :name OR p.model LIKE :name)';
    $params['name'] = '%' . $name . '%';
}

if ($width !== '' && ctype_digit($width)) {
    $where[] = 'p.spec LIKE :width';
    $params['width'] = $width . '/%';
}

if ($ratio !== '' && ctype_digit($ratio)) {
    $where[] = 'p.spec LIKE :ratio';
    $params['ratio'] = '%/' . $ratio . 'R%';
}

if ($inch !== '' && ctype_digit($inch)) {
    $where[] = 'p.spec LIKE :inch';
    $params['inch'] = '%R' . $inch;
}

if ($sizeInput !== '') {
    $where[] = "REPLACE(REPLACE(p.spec, '/', ''), 'R', '') LIKE :sizeInput";
    $params['sizeInput'] = '%' . $sizeInput . '%';
}

if ($stockOnly) {
    $where[] = 'EXISTS (
        SELECT 1 FROM tt_product_options o
        WHERE o.product_id = p.id AND o.is_active = 1 AND o.stock_qty > 0
    )';
}

$whereSql = implode(' AND ', $where);

/* ---------- 전체 개수 (페이지네이션) ---------- */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_products p WHERE {$whereSql}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$offset = ($page - 1) * $perPage;

/* ---------- 상품 목록 ---------- */
$sql = "SELECT p.id, p.name, p.model, p.spec, p.dot_code, p.thumbnail_url, p.price_original, p.price_sale,
               p.stock, p.rating_avg, p.review_count, b.name AS brand_name
        FROM tt_products p
        LEFT JOIN tt_brands b ON b.id = p.brand_id
        WHERE {$whereSql}
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

/* ---------- DOT 옵션 배치 조회 (N+1 방지) ---------- */
$dotOptionsByProduct = [];
if (!empty($products)) {
    $productIds = array_column($products, 'id');
    $inPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
    $optSql = "SELECT product_id, id, dot_code, price_sale, stock_qty
               FROM tt_product_options
               WHERE product_id IN ({$inPlaceholders})
                 AND is_active = 1
                 AND dot_code IS NOT NULL AND dot_code != ''
               ORDER BY
                 CASE WHEN dot_code REGEXP '^[0-9]{4}$' THEN 1 ELSE 0 END DESC,
                 CASE WHEN dot_code REGEXP '^[0-9]{4}$' THEN RIGHT(dot_code, 2) ELSE NULL END DESC,
                 CASE WHEN dot_code REGEXP '^[0-9]{4}$' THEN LEFT(dot_code, 2) ELSE NULL END DESC,
                 dot_code DESC";
    $optStmt = $pdo->prepare($optSql);
    $optStmt->execute($productIds);
    foreach ($optStmt->fetchAll() as $row) {
        $dotOptionsByProduct[(int)$row['product_id']][] = $row;
    }
}

// 제조사 체크박스 목록 — 실제 DB(tt_brands)에서 그대로 가져온다
$brandList = $pdo->query('SELECT id, name FROM tt_brands WHERE is_active = 1 ORDER BY name ASC')->fetchAll();

// 단면폭 / 편평비 / 인치 선택지 — 국내 유통 타이어 규격 기준 고정 목록
$widthOptions = [165,175,185,195,205,215,225,235,245,255,265,275,285,295,305,315,325,335];
$ratioOptions = [25,30,35,40,45,50,55,60,65,70,75,80,85];
$inchOptions  = [13,14,15,16,17,18,19,20,21,22,23,24];

/* ---------- 카테고리 탭 & 쿼리스트링 헬퍼 ---------- */
$categoryTabs = ['전체' => 0, '타이어' => 1, '엔진오일' => 2, '배터리' => 3, '와이퍼' => 4];
function qs(array $override = []): string {
    $params = array_merge($_GET, $override);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
}

$pageTitle = '공장도가 확인';
require __DIR__ . '/includes/header.php';
?>
<div class="tp-wrap">

  <div class="tp-page-title">
    <span class="tp-star">★</span> 공장도가 확인
  </div>

  <div class="category-tabs">
    <?php foreach ($categoryTabs as $label => $cid): ?>
      <a href="<?= qs(['cat'=>$cid ?: null, 'page'=>1]) ?>" class="tab <?= $cat===$cid?'active':'' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <form method="get" class="filter-bar-new" id="filterForm">
    <input type="hidden" name="cat" value="<?= $cat ?>">
    <?php if ($sort !== 'popular'): ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><?php endif; ?>

    <div class="fb-row fb-brand-row">
      <label class="fb-label">제조사</label>
      <div class="fb-brand-list" id="brandList">
        <?php foreach ($brandList as $i => $b): ?>
          <label class="fb-checkbox <?= $i >= 8 ? 'fb-brand-hidden' : '' ?>">
            <input type="checkbox" name="brand[]" value="<?= (int)$b['id'] ?>"
                   <?= in_array((int)$b['id'], $brandIds, true) ? 'checked' : '' ?>>
            <?= h($b['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <?php if (count($brandList) > 8): ?>
        <button type="button" class="fb-more-btn" id="brandMoreBtn">+ 더보기</button>
      <?php endif; ?>
    </div>

    <div class="fb-row fb-condition-row">
      <div class="fb-field">
        <label class="fb-label-sm">품명</label>
        <input type="text" name="name" value="<?= h($name) ?>" placeholder="상품명 입력">
      </div>

      <div class="fb-field">
        <label class="fb-label-sm">단면폭</label>
        <select name="width">
          <option value="">전체</option>
          <?php foreach ($widthOptions as $w): ?>
            <option value="<?= $w ?>" <?= $width == (string)$w ? 'selected' : '' ?>><?= $w ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fb-field">
        <label class="fb-label-sm">편평비</label>
        <select name="ratio">
          <option value="">전체</option>
          <?php foreach ($ratioOptions as $r): ?>
            <option value="<?= $r ?>" <?= $ratio == (string)$r ? 'selected' : '' ?>><?= $r ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fb-field">
        <label class="fb-label-sm">인치</label>
        <select name="inch">
          <option value="">전체</option>
          <?php foreach ($inchOptions as $inc): ?>
            <option value="<?= $inc ?>" <?= $inch == (string)$inc ? 'selected' : '' ?>><?= $inc ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fb-field fb-field-wide">
        <label class="fb-label-sm">사이즈 입력</label>
        <input type="text" name="size_input" value="<?= h($sizeInput) ?>" placeholder="예) 2454518" inputmode="numeric">
      </div>

      <button type="submit" class="fb-search-btn">🔍 검색</button>

      <label class="fb-checkbox fb-stock-only">
        <input type="checkbox" name="stock_only" value="1" <?= $stockOnly ? 'checked' : '' ?>>
        재고 있는 상품만
      </label>
    </div>
  </form>

  <div class="filter-bar-sub">
    <select name="sort" form="filterForm" onchange="document.getElementById('filterForm').submit()">
      <option value="popular" <?= $sort==='popular'?'selected':'' ?>>인기순</option>
      <option value="price_low" <?= $sort==='price_low'?'selected':'' ?>>낮은 가격순</option>
      <option value="price_high" <?= $sort==='price_high'?'selected':'' ?>>높은 가격순</option>
      <option value="new" <?= $sort==='new'?'selected':'' ?>>신상품순</option>
    </select>
    <p class="total-count">총 <strong><?= number_format($totalCount) ?></strong>개 상품</p>
  </div>

  <div class="tp-grid">
    <?php foreach ($products as $product):
      $pid = (int)$product['id'];
      $dotOptions = $dotOptionsByProduct[$pid] ?? [];
      $discount = 0;
      if ($product['price_original'] > 0 && $product['price_sale'] > 0) {
          $discount = round((1 - $product['price_sale'] / $product['price_original']) * 100);
      }
      $brandInitial = !empty($product['brand_name']) ? mb_substr($product['brand_name'], 0, 2) : '-';
    ?>
    <div class="tp-card" data-product-id="<?= $pid ?>">

      <div class="tp-card-top">
        <div class="tp-brand-badge"><?= h($brandInitial) ?></div>
        <div class="tp-name-block">
          <a href="<?= BASE_URL ?>/product-detail.php?id=<?= $pid ?>" class="tp-name"><?= h($product['name']) ?></a>
          <p class="tp-spec"><?= h($product['spec'] ?: $product['model']) ?></p>
        </div>
        <div class="tp-qty-block" data-price-original="<?= (int)$product['price_original'] ?>">
          <div class="tp-dc">
            DC율
            <input type="number" class="dc-input" value="<?= $discount ?>" min="0" max="100" step="1">
            <span>%</span>
          </div>
          <div class="tp-qty-stepper" data-product-id="<?= $pid ?>">
            수량
            <button type="button" class="qty-btn qty-minus">-</button>
            <input type="number" class="qty-input" value="1" min="1" readonly>
            <button type="button" class="qty-btn qty-plus">+</button>
          </div>
        </div>
      </div>

      <div class="tp-factory-row">
        <span class="tp-factory-label">공장도가 <strong><?= number_format($product['price_original']) ?>원</strong></span>
        <span class="tp-main-price"><?= number_format($product['price_sale']) ?>원</span>
      </div>

      <hr class="tp-divider">

      <?php if (!empty($dotOptions)): ?>
        <div class="tp-dot-row">
          <?php foreach ($dotOptions as $opt):
            $optDiscount = $product['price_original'] > 0
                ? round((1 - $opt['price_sale'] / $product['price_original']) * 100)
                : 0;
            $dotYear = '';
            if (preg_match('/(\d{4})$/', $opt['dot_code'] ?? '', $m)) {
                $dotYear = $m[1];
            } elseif (preg_match('/(\d{2})$/', $opt['dot_code'] ?? '', $m)) {
                $dotYear = '20' . $m[1];
            }
          ?>
          <div class="tp-dot-box">
            <p class="tp-dot-code">DOT <?= h($opt['dot_code']) ?><?= $dotYear ? ' (' . h($dotYear) . ')' : '' ?></p>
            <p class="tp-dot-price-line">
              <span class="tp-dot-discount"><?= $optDiscount ?>%</span>
              <span class="tp-dot-price"><?= number_format($opt['price_sale']) ?>원</span>
              <span class="tp-dot-stock">(재고 <?= (int)$opt['stock_qty'] ?>)</span>
            </p>
            <?php if ((int)$opt['stock_qty'] > 0): ?>
              <button type="button" class="tp-dot-buy" data-opt-id="<?= (int)$opt['id'] ?>" data-product-id="<?= $pid ?>">바로 구매하기</button>
            <?php else: ?>
              <div class="tp-dot-nostock">
                <p>해당 DOT 재고가 없습니다.</p>
                <div class="tp-restock-row">
                  <input type="number" class="restock-qty" placeholder="수량" min="1" value="1" style="width:60px">
                  <button type="button" class="btn-restock-request" data-product-id="<?= $pid ?>" data-dot-code="<?= h($opt['dot_code']) ?>">재고 요청하기</button>
                </div>
              </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php elseif ((int)($product['stock'] ?? 0) > 0): ?>
        <div class="tp-dot-row">
          <div class="tp-dot-box">
            <?php if (!empty($product['dot_code'])): ?>
              <p class="tp-dot-code">DOT <?= h($product['dot_code']) ?></p>
            <?php endif; ?>
            <p class="tp-dot-stock">재고: <?= (int)$product['stock'] ?></p>
            <button type="button" class="tp-dot-buy" data-opt-id="" data-product-id="<?= $pid ?>">바로 구매하기</button>
          </div>
        </div>
      <?php else: ?>
        <div class="tp-nostock">
          <p>판매중인 상품의 재고가 없습니다.</p>
          <div class="tp-restock-row">
            <input type="number" class="restock-qty" placeholder="수량" min="1" value="1" style="width:60px">
            <button type="button" class="btn-restock-request" data-product-id="<?= $pid ?>" data-dot-code="">재고 요청하기</button>
          </div>
        </div>
      <?php endif; ?>

    </div>
    <?php endforeach; ?>

    <?php if (empty($products)): ?>
      <p class="no-result">조건에 맞는 상품이 없습니다.</p>
    <?php endif; ?>
  </div>

  <div class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <a href="<?= qs(['page'=>$i]) ?>" class="<?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
</div>

<script>
const BASE_URL = "<?= BASE_URL ?>";
const isLoggedIn = <?= Auth::isLoggedIn() ? 'true' : 'false' ?>;

document.getElementById('brandMoreBtn')?.addEventListener('click', function () {
  const hidden = document.querySelectorAll('.fb-brand-hidden');
  const nowShow = this.dataset.expanded !== 'true';
  hidden.forEach(el => el.style.display = nowShow ? 'inline-flex' : 'none');
  this.textContent = nowShow ? '- 접기' : '+ 더보기';
  this.dataset.expanded = nowShow ? 'true' : 'false';
});

document.querySelectorAll('.tp-qty-stepper').forEach(function(box){
  const input = box.querySelector('.qty-input');
  box.querySelector('.qty-minus').addEventListener('click', function(){
    input.value = Math.max(1, parseInt(input.value, 10) - 1);
  });
  box.querySelector('.qty-plus').addEventListener('click', function(){
    input.value = parseInt(input.value, 10) + 1;
  });
});

function recalcPrice(card) {
  const qtyBlock = card.querySelector('.tp-qty-block');
  const priceOriginal = parseInt(qtyBlock.dataset.priceOriginal, 10) || 0;
  const dcInput = card.querySelector('.dc-input');
  const priceEl = card.querySelector('.tp-main-price');

  let dc = parseFloat(dcInput.value);
  if (isNaN(dc) || dc < 0) dc = 0;
  if (dc > 100) dc = 100;
  dcInput.value = dc;

  const calculated = Math.round(priceOriginal * (1 - dc / 100));
  priceEl.textContent = calculated.toLocaleString('ko-KR') + '원';
}

document.querySelectorAll('.tp-card').forEach(function(card){
  const dcInput = card.querySelector('.dc-input');
  if (!dcInput) return;
  dcInput.addEventListener('input', function(){ recalcPrice(card); });
  dcInput.addEventListener('blur', function(){ recalcPrice(card); });
});

/* [수정] 재고 요청 버튼: 로그인 안 된 상태면 fetch를 보내지 않고 바로 로그인 페이지로 보낸다.
   fetch가 성공해도 401이 오면 "로그인이 필요합니다" 안내를 명확히 보여준다.
   res.json() 파싱이 깨지는 경우(HTML 응답)에는 원인을 알 수 있도록 콘솔에 로그를 남긴다. */
document.querySelectorAll('.btn-restock-request').forEach(function(btn){
  btn.addEventListener('click', async function(){
    if (!isLoggedIn) {
      alert('로그인이 필요합니다.');
      location.href = BASE_URL + '/login.php';
      return;
    }

    const productId = parseInt(btn.dataset.productId, 10);
    const dotCode   = btn.dataset.dotCode || '';
    const card      = btn.closest('.tp-card') || btn.closest('.tp-dot-box');
    const qtyInput  = (card.querySelector('.restock-qty') || card.parentElement.querySelector('.restock-qty'));
    const qty       = Math.max(1, parseInt(qtyInput?.value, 10) || 1);

    btn.disabled = true;
    btn.textContent = '요청 중...';
    try {
      const formData = new FormData();
      formData.append('product_id', productId);
      formData.append('qty', qty);
      formData.append('dot_code', dotCode);
      formData.append('csrf_token', '<?= Csrf::token() ?>');

      const res = await fetch(BASE_URL + '/ajax-stock-request.php', {
        method: 'POST',
        body: formData
      });

      if (res.status === 401) {
        alert('로그인이 필요합니다.');
        location.href = BASE_URL + '/login.php';
        return;
      }

      let data;
      try {
        data = await res.json();
      } catch (parseErr) {
        console.error('재고 요청 응답이 JSON이 아닙니다. (status=' + res.status + ')', parseErr);
        alert('서버 응답을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.');
        btn.disabled = false;
        btn.textContent = '재고 요청하기';
        return;
      }

      if (data.success) {
        btn.textContent = '✓ 요청 완료';
        btn.style.background = '#22c55e';
        btn.style.color = '#fff';
        alert(data.message || '재고 요청이 접수되었습니다.');
      } else {
        btn.disabled = false;
        btn.textContent = '재고 요청하기';
        alert(data.message || '요청 중 오류가 발생했습니다.');
      }
    } catch (e) {
      console.error('재고 요청 네트워크 오류', e);
      btn.disabled = false;
      btn.textContent = '재고 요청하기';
      alert('네트워크 오류가 발생했습니다.');
    }
  });
});

// 바로 구매하기 (product-list 카드의 DOT별 버튼)
document.querySelectorAll('.tp-dot-buy').forEach(function(btn){
  btn.addEventListener('click', function(){
    const productId = btn.dataset.productId;
    const optId     = btn.dataset.optId;
    let url = BASE_URL + '/product-detail.php?id=' + productId;
    if (optId) url += '&opt=' + optId;
    window.location.href = url;
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
