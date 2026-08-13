<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

$pdo = Database::connection();

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($productId <= 0) {
    http_response_code(404);
    echo '잘못된 상품 요청입니다.';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT p.id, p.name, p.model, p.spec, p.origin, p.thumbnail_url,
            p.price_original, p.price_sale, p.stock, p.status,
            p.rating_avg, p.review_count, p.description,
            b.name AS brand_name
     FROM tt_products p
     LEFT JOIN tt_brands b ON b.id = p.brand_id
     WHERE p.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product || $product['status'] !== 'active') {
    http_response_code(404);
    echo '존재하지 않거나 판매가 종료된 상품입니다.';
    exit;
}

// DOT 옵션 목록 — 테이블이나 컬럼이 없어도 에러 나지 않도록 try-catch
$options = [];
try {
    $optStmt = $pdo->prepare(
        "SELECT id, dot_code, price_sale, stock_qty
         FROM tt_product_options
         WHERE product_id = :pid AND is_active = 1
         ORDER BY dot_code"
    );
    $optStmt->execute([':pid' => $productId]);
    $options = $optStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[product-detail options] ' . $e->getMessage());
    $options = [];
}

// 찜 여부 확인 — 반드시 Auth 클래스를 통해서만 로그인 상태를 판단한다
$isWished = false;
if (Auth::isLoggedIn()) {
    $wStmt = $pdo->prepare("SELECT id FROM tt_wishlists WHERE user_id = :uid AND product_id = :pid LIMIT 1");
    $wStmt->execute([':uid' => Auth::currentUserId(), ':pid' => $productId]);
    $isWished = (bool)$wStmt->fetch();
}

// CSRF 토큰 — 자체 세션 키를 새로 만들지 말고 core/Csrf.php의 토큰을 그대로 사용한다
$csrfToken = Csrf::token();

// 할인율 계산
$discountPct = 0;
if ((int)$product['price_original'] > 0) {
    $discountPct = (int)round((1 - ((int)$product['price_sale'] / (int)$product['price_original'])) * 100);
}

$pageTitle = $product['name'];
require __DIR__ . '/includes/header.php';
?>

<main class="tt-main">
<div class="pd-wrap">
  <div class="pd-breadcrumb">
    <a href="<?= BASE_URL ?>/product-list.php">상품 목록</a> &gt; <?= h($product['name']) ?>
  </div>

  <div class="pd-top">
    <div class="pd-gallery">
      <div class="pd-main-img">
        <?php if (!empty($product['thumbnail_url'])): ?>
          <img src="<?= h($product['thumbnail_url']) ?>" alt="<?= h($product['name']) ?>">
        <?php else: ?>
          <span class="ph">🛞</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="pd-info">
      <?php if (!empty($product['brand_name'])): ?>
        <p class="pd-brand"><?= h($product['brand_name']) ?></p>
      <?php endif; ?>
      <h1 class="pd-title"><?= h($product['name']) ?></h1>
      <p class="pd-model"><?= h($product['model']) ?></p>

      <div class="pd-price-box" data-price-original="<?= (int)$product['price_original'] ?>">
        <div class="pd-price-top">
          <?php if ($discountPct > 0): ?>
            <span class="pd-discount-pct" id="pdDiscountPct"><?= $discountPct ?>%</span>
          <?php endif; ?>
          <span class="pd-price-now" id="pdPriceNow"><?= number_format((int)$product['price_sale']) ?>원</span>
        </div>
        <?php if ((int)$product['price_original'] > (int)$product['price_sale']): ?>
          <span class="pd-price-orig"><?= number_format((int)$product['price_original']) ?>원</span>
        <?php endif; ?>
      </div>

      <?php if (!empty($options)): ?>
      <div class="pd-option-row">
        <label for="pdOptionSelect">DOT 옵션 선택 (최신년도 우선)</label>
        <select id="pdOptionSelect">
          <option value="">옵션을 선택하세요</option>
          <?php foreach ($options as $opt):
            $dotYear = '';
            if (preg_match('/(\d{4})$/', $opt['dot_code'] ?? '', $m)) {
                $dotYear = $m[1];
            } elseif (preg_match('/(\d{2})$/', $opt['dot_code'] ?? '', $m)) {
                $dotYear = '20' . $m[1];
            }
          ?>
            <option value="<?= (int)$opt['id'] ?>"
                    data-price="<?= (int)$opt['price_sale'] ?>"
                    data-stock="<?= (int)$opt['stock_qty'] ?>">
              DOT <?= h($opt['dot_code']) ?><?= $dotYear ? ' (' . h($dotYear) . ')' : '' ?> (재고 <?= (int)$opt['stock_qty'] ?>개) - <?= number_format((int)$opt['price_sale']) ?>원
            </option>
          <?php endforeach; ?>
        </select>
        <p class="pd-stock-warn" id="pdStockWarn" style="display:none;">품절된 옵션입니다.</p>
      </div>
      <?php else: ?>
        <!-- 옵션은 없지만 products.stock 으로 재고 표시 -->
        <div class="pd-option-row">
          <label>재고 상태</label>
          <?php if (!empty($product['dot_code'])): ?>
            <p class="pd-dot-info">DOT: <strong><?= h($product['dot_code']) ?></strong></p>
          <?php endif; ?>
          <?php if ((int)$product['stock'] > 0): ?>
            <p class="pd-stock-ok">재고: <?= (int)$product['stock'] ?>개</p>
          <?php else: ?>
            <p class="pd-stock-warn" style="display:block;">판매중인 상품의 재고가 없습니다.</p>
            <div class="pd-restock-row">
              <input type="number" id="pdRestockQty" placeholder="수량" min="1" value="1" style="width:70px">
              <button type="button" class="btn-restock" id="pdRestockBtn" data-product-id="<?= (int)$productId ?>">재고 요청하기</button>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="pd-qty-row">
        <label>수량</label>
        <div class="pd-qty-stepper">
          <button type="button" id="pdQtyMinus">-</button>
          <input type="number" id="pdQtyInput" value="1" min="1" max="99">
          <button type="button" id="pdQtyPlus">+</button>
        </div>
      </div>

      <div class="pd-total-line">
        <span>총 결제금액</span>
        <strong id="pdTotalPrice"><?= number_format((int)$product['price_sale']) ?>원</strong>
      </div>

      <div class="pd-btn-row">
        <button type="button" class="pd-btn-wish <?= $isWished ? 'active' : '' ?>" id="pdWishBtn">
          <?= $isWished ? '♥' : '♡' ?>
        </button>
        <button type="button" class="btn-cart-lg" id="pdAddCartBtn">장바구니</button>
        <button type="button" class="btn-primary-lg" id="pdBuyNowBtn">바로 구매하기</button>
      </div>

      <div class="pd-benefit-box">
        <b>안내:</b> 표시된 가격은 부가세 포함가이며, DOT 옵션 선택 시 해당 재고 기준으로 결제가 진행됩니다.
      </div>
    </div>
  </div>

  <div class="pd-tabs">
    <button type="button" class="pd-tab-btn active" data-tab="info">상세정보</button>
    <button type="button" class="pd-tab-btn" data-tab="review">리뷰 (<?= (int)$product['review_count'] ?>)</button>
  </div>

  <div class="pd-tab-panel active" data-panel="info">
    <?php if (!empty($product['description'])): ?>
      <div><?= nl2br(h($product['description'])) ?></div>
    <?php else: ?>
      <p style="color:var(--gray4);">등록된 상세 설명이 없습니다.</p>
    <?php endif; ?>
  </div>

<div class="pd-tab-panel" data-panel="review" id="review">
    <?php
    $rvStmt = $pdo->prepare("
        SELECT r.id, r.rating, r.content, r.created_at, u.name AS user_name
        FROM tt_reviews r
        JOIN tt_users u ON u.id = r.user_id
        WHERE r.product_id = :pid
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    $rvStmt->execute(['pid' => $productId]);
    $productReviews = $rvStmt->fetchAll(PDO::FETCH_ASSOC);

    $canWriteReview = false;
    if (Auth::isLoggedIn()) {
        $eligStmt = $pdo->prepare("
            SELECT oi.id FROM tt_order_items oi
            JOIN tt_orders o ON o.id = oi.order_id
            WHERE o.user_id = :uid AND oi.product_id = :pid AND o.status = 'completed'
            LIMIT 1
        ");
        $eligStmt->execute(['uid' => Auth::currentUserId(), 'pid' => $productId]);
        $alreadyStmt = $pdo->prepare('SELECT id FROM tt_reviews WHERE user_id = :uid AND product_id = :pid LIMIT 1');
        $alreadyStmt->execute(['uid' => Auth::currentUserId(), 'pid' => $productId]);
        $canWriteReview = (bool)$eligStmt->fetch() && !$alreadyStmt->fetch();
    }
    ?>

    <?php if ($canWriteReview): ?>
        <form method="post" action="<?= BASE_URL ?>/review-submit.php" class="pd-review-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
            <div class="pd-review-rating-input">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <label><input type="radio" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>><?= $i ?>점</label>
                <?php endfor; ?>
            </div>
            <textarea name="content" rows="3" maxlength="1000" placeholder="사용해 보신 솔직한 후기를 남겨주세요." required></textarea>
            <button type="submit" class="btn-primary">리뷰 등록</button>
        </form>
    <?php elseif (Auth::isLoggedIn()): ?>
        <p style="color:var(--gray4);">구매 확정된 상품에 대해서만 리뷰를 작성할 수 있습니다.</p>
    <?php endif; ?>

    <?php if (empty($productReviews)): ?>
        <p style="color:var(--gray4);">등록된 리뷰가 없습니다.</p>
    <?php else: ?>
        <div class="pd-review-list">
            <?php foreach ($productReviews as $rv): ?>
                <div class="pd-review-item">
                    <div class="pd-review-meta">
                        <span class="pd-review-stars"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?></span>
                        <span class="pd-review-user"><?= h(mb_substr($rv['user_name'], 0, 1) . str_repeat('*', max(0, mb_strlen($rv['user_name']) - 1))) ?></span>
                        <span class="pd-review-date"><?= h(date('Y.m.d', strtotime($rv['created_at']))) ?></span>
                    </div>
                    <p class="pd-review-content"><?= nl2br(h($rv['content'])) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</div>

<!-- 바로구매용 히든 폼: checkout.php로 페이지 이동시키는 용도, AJAX 아님 -->
<form method="post" action="<?= BASE_URL ?>/buy-now.php" id="buyNowForm" style="display:none;">
  <?= Csrf::field() ?>
  <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
  <input type="hidden" name="option_id" id="buyNowOptionId" value="">
  <input type="hidden" name="qty" id="buyNowQty" value="1">
</form>

<input type="hidden" id="csrfToken" value="<?= h($csrfToken) ?>">

<script>
const BASE_URL   = "<?= BASE_URL ?>";
const csrfToken  = document.getElementById('csrfToken').value;
const productId  = <?= (int)$productId ?>;
const hasOptions = <?= !empty($options) ? 'true' : 'false' ?>;
const isLoggedIn = <?= Auth::isLoggedIn() ? 'true' : 'false' ?>;

const qtyInput     = document.getElementById('pdQtyInput');
const totalEl      = document.getElementById('pdTotalPrice');
const priceNowEl   = document.getElementById('pdPriceNow');
const optionSelect = document.getElementById('pdOptionSelect');
const stockWarn    = document.getElementById('pdStockWarn');
const buyBtn       = document.getElementById('pdBuyNowBtn');
const cartBtn      = document.getElementById('pdAddCartBtn');
const wishBtn      = document.getElementById('pdWishBtn');

function currentUnitPrice() {
  if (optionSelect && optionSelect.value !== '') {
    const opt = optionSelect.options[optionSelect.selectedIndex];
    return parseInt(opt.dataset.price, 10) || 0;
  }
  return parseInt(priceNowEl.textContent.replace(/[^0-9]/g, ''), 10) || 0;
}

function recalcTotal() {
  const qty = Math.max(1, Math.min(99, parseInt(qtyInput.value, 10) || 1));
  qtyInput.value = qty;
  totalEl.textContent = (currentUnitPrice() * qty).toLocaleString('ko-KR') + '원';
}

document.getElementById('pdQtyMinus').addEventListener('click', () => {
  qtyInput.value = Math.max(1, parseInt(qtyInput.value, 10) - 1);
  recalcTotal();
});
document.getElementById('pdQtyPlus').addEventListener('click', () => {
  qtyInput.value = Math.min(99, parseInt(qtyInput.value, 10) + 1);
  recalcTotal();
});
qtyInput.addEventListener('input', recalcTotal);

if (optionSelect) {
  optionSelect.addEventListener('change', () => {
    const opt = optionSelect.options[optionSelect.selectedIndex];
    const stock = parseInt(opt.dataset.stock, 10) || 0;
    stockWarn.style.display = (optionSelect.value !== '' && stock <= 0) ? 'block' : 'none';
    if (optionSelect.value !== '') {
      priceNowEl.textContent = (parseInt(opt.dataset.price, 10) || 0).toLocaleString('ko-KR') + '원';
    }
    recalcTotal();
  });
}

/**
 * 실제 서버 엔드포인트는 JSON body를 기대하고,
 * 응답은 {success, message, data:{...}} 구조로 온다.
 * fetch는 401/419/500 같은 HTTP 에러 상태에서도 reject하지 않으므로
 * status를 직접 같이 반환해 호출부에서 분기 처리한다.
 */
async function postJson(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  const data = await res.json(); // JSON이 아니면 여기서 throw → catch로 감
  return { status: res.status, data };
}

wishBtn.addEventListener('click', async function () {
  try {
    /* ★ 수정: /api/wish_toggle.php (존재하지 않는 경로) → /wish-toggle.php (루트, 실제 존재하는 파일) */
    const { status, data } = await postJson(BASE_URL + '/wish-toggle.php', {
      product_id: productId,
      csrf_token: csrfToken
    });

    if (status === 401) {
      alert('로그인이 필요합니다.');
      location.href = BASE_URL + '/login.php';
      return;
    }
    if (!data.success) {
      alert(data.message || '처리 중 오류가 발생했습니다.');
      return;
    }

    const wished = data.data.wished;
    wishBtn.classList.toggle('active', wished);
    wishBtn.textContent = wished ? '♥' : '♡';
  } catch (e) {
    alert('네트워크 오류가 발생했습니다.');
  }
});

cartBtn.addEventListener('click', async function () {
  if (hasOptions && (!optionSelect || optionSelect.value === '')) {
    alert('DOT 옵션을 선택해주세요.');
    return;
  }

  // 옵션이 없는 상품은 option_id를 반드시 null로 보내야 한다.
  // 빈 문자열('')을 보내면 서버에서 (int)''=0으로 캐스팅되어 존재하지 않는 옵션 id로 조회돼 실패한다.
  const optionIdPayload = (hasOptions && optionSelect && optionSelect.value !== '')
    ? parseInt(optionSelect.value, 10)
    : null;

  try {
    /* ★ 수정: /api/cart_add.php (존재하지 않는 경로) → /cart-add.php (루트, 실제 존재하는 파일) */
    const { status, data } = await postJson(BASE_URL + '/cart-add.php', {
      product_id: productId,
      option_id: optionIdPayload,
      qty: parseInt(qtyInput.value, 10) || 1,
      csrf_token: csrfToken
    });

    if (status === 401) {
      alert('로그인이 필요합니다.');
      location.href = BASE_URL + '/login.php';
      return;
    }
    if (!data.success) {
      alert(data.message || '담기에 실패했습니다.');
      return;
    }

    if (typeof window.ttSetCartCount === 'function') {
      window.ttSetCartCount(data.data.cart_count);
    }
    alert(data.data.message || '장바구니에 담았습니다.');
  } catch (e) {
    alert('네트워크 오류가 발생했습니다.');
  }
});

buyBtn.addEventListener('click', function () {
  if (!isLoggedIn) {
    alert('로그인이 필요합니다.');
    location.href = BASE_URL + '/login.php';
    return;
  }
  if (hasOptions && (!optionSelect || optionSelect.value === '')) {
    alert('DOT 옵션을 선택해주세요.');
    return;
  }
  if (hasOptions) {
    const opt = optionSelect.options[optionSelect.selectedIndex];
    if ((parseInt(opt.dataset.stock, 10) || 0) <= 0) {
      alert('품절된 옵션입니다.');
      return;
    }
    document.getElementById('buyNowOptionId').value = optionSelect.value;
  }
  document.getElementById('buyNowQty').value = Math.max(1, Math.min(99, parseInt(qtyInput.value, 10) || 1));

  buyBtn.disabled = true; // 중복 클릭으로 인한 이중 제출 방지
  document.getElementById('buyNowForm').submit();
});

// 탭 전환
document.querySelectorAll('.pd-tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.pd-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.pd-tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.querySelector(`.pd-tab-panel[data-panel="${btn.dataset.tab}"]`).classList.add('active');
  });
});

// 재고 요청 (product-detail 페이지)
const restockBtn = document.getElementById('pdRestockBtn');
if (restockBtn) {
  restockBtn.addEventListener('click', async function(){
    const productId = parseInt(restockBtn.dataset.productId, 10);
    const qtyInput  = document.getElementById('pdRestockQty');
    const qty       = Math.max(1, parseInt(qtyInput?.value, 10) || 1);

    restockBtn.disabled = true;
    restockBtn.textContent = '요청 중...';
    try {
      const formData = new FormData();
      formData.append('product_id', productId);
      formData.append('qty', qty);
      formData.append('csrf_token', csrfToken);

      const res = await fetch(BASE_URL + '/ajax-stock-request.php', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (data.success) {
        restockBtn.textContent = '✓ 요청 완료';
        restockBtn.style.background = '#22c55e';
        restockBtn.style.color = '#fff';
        alert(data.message || '재고 요청이 접수되었습니다.');
      } else {
        restockBtn.disabled = false;
        restockBtn.textContent = '재고 요청하기';
        alert(data.message || '요청 중 오류가 발생했습니다.');
      }
    } catch (e) {
      restockBtn.disabled = false;
      restockBtn.textContent = '재고 요청하기';
      alert('네트워크 오류가 발생했습니다.');
    }
  });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
