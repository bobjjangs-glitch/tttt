<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

if (!defined('REVIEW_WRITE_WINDOW_DAYS')) {
    define('REVIEW_WRITE_WINDOW_DAYS', 7);
}

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

$isWished = false;
if (Auth::isLoggedIn()) {
    $wStmt = $pdo->prepare("SELECT id FROM tt_wishlists WHERE user_id = :uid AND product_id = :pid LIMIT 1");
    $wStmt->execute([':uid' => Auth::currentUserId(), ':pid' => $productId]);
    $isWished = (bool)$wStmt->fetch();
}

$csrfToken = Csrf::token();

/* ===== [수정 1] 마이페이지에서 구매확정 후 리다이렉트로 들어왔는지 여부 ===== */
$autoOpenReview = (($_GET['write_review'] ?? '') === '1');

/* ===== [수정 2] 삭제 처리 후 세션 플래시 메시지 표시 ===== */
$flashMsg = null;
if (!empty($_SESSION['flash'])) {
    $flashMsg = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$discountPct = 0;
if ((int)$product['price_original'] > 0) {
    $discountPct = (int)round((1 - ((int)$product['price_sale'] / (int)$product['price_original'])) * 100);
}

$pageTitle = $product['name'];
require __DIR__ . '/includes/header.php';
?>

<style>
/* ===== 리뷰 작성 CTA / 모달 - 트렌디 버튼 스타일 ===== */
.pd-review-cta {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.btn-review-write {
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  color: #fff;
  border: none;
  padding: 12px 26px;
  border-radius: 999px;
  font-weight: 700;
  font-size: 15px;
  cursor: pointer;
  box-shadow: 0 6px 16px rgba(99, 102, 241, .35);
  transition: transform .15s ease, box-shadow .15s ease;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.btn-review-write:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 22px rgba(99, 102, 241, .45);
}
.btn-review-write:active { transform: translateY(0); }
.pd-review-ddays { font-size: 13px; color: #64748b; }

.review-modal-overlay {
  position: fixed; inset: 0;
  background: rgba(15, 23, 42, .55);
  display: flex; align-items: center; justify-content: center;
  opacity: 0; visibility: hidden;
  transition: opacity .2s ease;
  z-index: 999;
}
.review-modal-overlay.active { opacity: 1; visibility: visible; }
.review-modal-box {
  background: #fff;
  border-radius: 20px;
  padding: 32px;
  width: 92%;
  max-width: 440px;
  position: relative;
  transform: translateY(16px) scale(.97);
  transition: transform .2s ease;
  box-shadow: 0 24px 60px rgba(0,0,0,.25);
}
.review-modal-overlay.active .review-modal-box { transform: translateY(0) scale(1); }
.review-modal-close {
  position: absolute; top: 16px; right: 16px;
  background: none; border: none; font-size: 22px; color: #94a3b8; cursor: pointer;
}
.review-modal-title { font-size: 19px; font-weight: 800; margin-bottom: 4px; }
.review-modal-sub { font-size: 13px; color: #64748b; margin-bottom: 18px; }

.star-rating { display: flex; flex-direction: row-reverse; gap: 4px; margin-bottom: 16px; }
.star-rating input { display: none; }
.star-rating label { font-size: 30px; color: #e2e8f0; cursor: pointer; transition: color .12s, transform .12s; }
.star-rating input:checked ~ label,
.star-rating label:hover,
.star-rating label:hover ~ label { color: #fbbf24; }

.review-modal-box textarea {
  width: 100%;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 12px 14px;
  font-size: 14px;
  resize: vertical;
  margin-bottom: 18px;
  box-sizing: border-box;
}
.review-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
.btn-modal-cancel {
  background: #f1f5f9; color: #475569; border: none;
  padding: 10px 20px; border-radius: 999px; font-weight: 600; cursor: pointer;
}
.btn-modal-submit {
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  color: #fff; border: none;
  padding: 10px 24px; border-radius: 999px; font-weight: 700; cursor: pointer;
  box-shadow: 0 6px 16px rgba(99, 102, 241, .35);
  transition: transform .12s ease;
}
.btn-modal-submit:hover { transform: translateY(-1px); }

/* ===== [수정 3] 리뷰 아이템 레이아웃 + 삭제 버튼 ===== */
.pd-review-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 16px 0;
  border-bottom: 1px solid #f1f5f9;
}
.pd-review-item-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.pd-review-meta { display: flex; align-items: center; gap: 8px; }
.btn-review-delete {
  background: none;
  border: 1px solid #e2e8f0;
  color: #94a3b8;
  font-size: 12px;
  padding: 4px 10px;
  border-radius: 999px;
  cursor: pointer;
  transition: color .12s, border-color .12s;
}
.btn-review-delete:hover { color: #ef4444; border-color: #fca5a5; }
.pd-flash-msg {
  padding: 12px 16px;
  border-radius: 12px;
  margin-bottom: 16px;
  font-size: 14px;
}
.pd-flash-msg.success { background: #ecfdf5; color: #047857; }
.pd-flash-msg.error { background: #fef2f2; color: #b91c1c; }
</style>

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
    <?php if ($flashMsg): ?>
        <div class="pd-flash-msg <?= h($flashMsg['type'] ?? 'success') ?>">
            <?= h($flashMsg['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php
    $rvStmt = $pdo->prepare("
        SELECT r.id, r.user_id, r.rating, r.content, r.created_at, u.name AS user_name
        FROM tt_reviews r
        JOIN tt_users u ON u.id = r.user_id
        WHERE r.product_id = :pid
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    $rvStmt->execute(['pid' => $productId]);
    $productReviews = $rvStmt->fetchAll(PDO::FETCH_ASSOC);
    $currentUid = Auth::isLoggedIn() ? (int)Auth::currentUserId() : 0;

    /* 구매확정(confirmed_at) 후 7일 이내인지로 리뷰 작성 가능 여부를 판정한다. */
    $canWriteReview = false;
    $reviewDeadline = null;
    $reviewDaysLeft = 0;

    if (Auth::isLoggedIn()) {
        $eligStmt = $pdo->prepare("
            SELECT oi.id, o.confirmed_at
            FROM tt_order_items oi
            JOIN tt_orders o ON o.id = oi.order_id
            WHERE o.user_id = :uid
              AND oi.product_id = :pid
              AND o.confirmed_at IS NOT NULL
            ORDER BY o.confirmed_at DESC
            LIMIT 1
        ");
        $eligStmt->execute(['uid' => Auth::currentUserId(), 'pid' => $productId]);
        $eligibleOrderItem = $eligStmt->fetch(PDO::FETCH_ASSOC);

        $alreadyStmt = $pdo->prepare('SELECT id FROM tt_reviews WHERE user_id = :uid AND product_id = :pid LIMIT 1');
        $alreadyStmt->execute(['uid' => Auth::currentUserId(), 'pid' => $productId]);
        $alreadyReviewed = (bool)$alreadyStmt->fetch();

        if ($eligibleOrderItem && !$alreadyReviewed) {
            $confirmedAt = new DateTime($eligibleOrderItem['confirmed_at']);
            $deadline    = (clone $confirmedAt)->modify('+' . REVIEW_WRITE_WINDOW_DAYS . ' days');
            $now         = new DateTime();
            if ($now <= $deadline) {
                $canWriteReview = true;
                $reviewDeadline = $deadline;
                $diff = $now->diff($deadline);
                $reviewDaysLeft = max(1, (int)$diff->days + (($diff->h > 0 || $diff->i > 0) ? 1 : 0));
            }
        }
    }
    ?>

    <?php if ($canWriteReview): ?>
        <div class="pd-review-cta">
            <button type="button" class="btn-review-write" id="pdReviewWriteBtn">
                <span>✎</span> 리뷰 작성하기
            </button>
            <span class="pd-review-ddays">
                리뷰 작성 가능 기간: <strong>D-<?= $reviewDaysLeft ?></strong>
                (<?= h($reviewDeadline->format('Y.m.d')) ?>까지)
            </span>
        </div>
    <?php elseif (Auth::isLoggedIn()): ?>
        <p style="color:var(--gray4);">구매확정 후 7일 이내에만 리뷰를 작성할 수 있습니다. (마이페이지 &gt; 주문내역에서 구매확정을 먼저 진행해 주세요)</p>
    <?php endif; ?>

    <?php if (empty($productReviews)): ?>
        <p style="color:var(--gray4);">등록된 리뷰가 없습니다.</p>
    <?php else: ?>
        <div class="pd-review-list">
            <?php foreach ($productReviews as $rv): ?>
                <div class="pd-review-item">
                    <div class="pd-review-item-top">
                        <div class="pd-review-meta">
                            <span class="pd-review-stars"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?></span>
                            <span class="pd-review-user"><?= h(mb_substr($rv['user_name'], 0, 1) . str_repeat('*', max(0, mb_strlen($rv['user_name']) - 1))) ?></span>
                            <span class="pd-review-date"><?= h(date('Y.m.d', strtotime($rv['created_at']))) ?></span>
                        </div>

                        <?php if ($currentUid && $currentUid === (int)$rv['user_id']): ?>
                        <!-- [수정 4] 본인 리뷰에만 삭제 버튼 노출, return_to=product로 이 페이지로 되돌아옴 -->
                        <form method="post" action="<?= BASE_URL ?>/review-delete.php" onsubmit="return confirm('리뷰를 삭제하시겠습니까? 삭제 후에는 복구할 수 없습니다.');" style="margin:0;">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
                            <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                            <input type="hidden" name="return_to" value="product">
                            <button type="submit" class="btn-review-delete">삭제</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <p class="pd-review-content"><?= nl2br(h($rv['content'])) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</div>

<?php if ($canWriteReview): ?>
<!-- 리뷰 작성 모달 : 7일 창구 안에서는 버튼 클릭 시 언제든 열린다 -->
<div class="review-modal-overlay" id="reviewModalOverlay">
  <div class="review-modal-box">
    <button type="button" class="review-modal-close" id="reviewModalClose" aria-label="닫기">&times;</button>
    <h3 class="review-modal-title">리뷰 작성하기</h3>
    <p class="review-modal-sub">솔직한 사용 후기를 남겨주시면 다른 고객에게 큰 도움이 됩니다.</p>
    <form method="post" action="<?= BASE_URL ?>/review-submit.php" class="pd-review-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
        <!-- [수정 5] 상품 상세페이지에서 제출한 것이므로 제출 후 이 페이지로 되돌아온다 -->
        <input type="hidden" name="return_to" value="product">
        <div class="star-rating">
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>>
                <label for="star<?= $i ?>">★</label>
            <?php endfor; ?>
        </div>
        <textarea name="content" rows="4" maxlength="1000" placeholder="사용해 보신 솔직한 후기를 남겨주세요." required></textarea>
        <div class="review-modal-actions">
            <button type="button" class="btn-modal-cancel" id="reviewModalCancel">취소</button>
            <button type="submit" class="btn-modal-submit">등록하기</button>
        </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- 바로구매용 히든 폼: checkout.php로 페이지 이동시키는 용도, AJAX 아님 -->
<form method="post" action="<?= BASE_URL ?>/buy-now.php" id="buyNowForm" style="display:none;">
  <?= Csrf::field() ?>
  <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
  <input type="hidden" name="option_id" id="buyNowOptionId" value="">
  <input type="hidden" name="qty" id="buyNowQty" value="1">
</form>

<input type="hidden" id="csrfToken" value="<?= h($csrfToken) ?>">

<script>
const BASE_URL       = "<?= BASE_URL ?>";
const csrfToken      = document.getElementById('csrfToken').value;
const productId      = <?= (int)$productId ?>;
const hasOptions     = <?= !empty($options) ? 'true' : 'false' ?>;
const isLoggedIn     = <?= Auth::isLoggedIn() ? 'true' : 'false' ?>;
const autoOpenReview = <?= $autoOpenReview ? 'true' : 'false' ?>; // [수정 6]

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

async function postJson(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  const data = await res.json();
  return { status: res.status, data };
}

wishBtn.addEventListener('click', async function () {
  try {
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

  const optionIdPayload = (hasOptions && optionSelect && optionSelect.value !== '')
    ? parseInt(optionSelect.value, 10)
    : null;

  try {
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

  buyBtn.disabled = true;
  document.getElementById('buyNowForm').submit();
});

document.querySelectorAll('.pd-tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.pd-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.pd-tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.querySelector(`.pd-tab-panel[data-panel="${btn.dataset.tab}"]`).classList.add('active');
  });
});

/* ===== 리뷰 작성 모달 열기/닫기 ===== */
const reviewBtn     = document.getElementById('pdReviewWriteBtn');
const reviewOverlay = document.getElementById('reviewModalOverlay');
const reviewClose   = document.getElementById('reviewModalClose');
const reviewCancel  = document.getElementById('reviewModalCancel');

function openReviewModal() {
  reviewOverlay.classList.add('active');
  document.body.style.overflow = 'hidden';
}
function closeReviewModal() {
  reviewOverlay.classList.remove('active');
  document.body.style.overflow = '';
}

if (reviewBtn && reviewOverlay) {
  reviewBtn.addEventListener('click', openReviewModal);
  reviewClose?.addEventListener('click', closeReviewModal);
  reviewCancel?.addEventListener('click', closeReviewModal);
  reviewOverlay.addEventListener('click', (e) => {
    if (e.target === reviewOverlay) closeReviewModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && reviewOverlay.classList.contains('active')) closeReviewModal();
  });
}

/* ===== [수정 7] 마이페이지 구매확정 → 이 페이지로 리다이렉트 됐을 때 자동으로
   리뷰 탭 전환 + #review로 스크롤 + 모달 오픈까지 한 번에 처리 ===== */
if (autoOpenReview) {
  const reviewTabBtn = document.querySelector('.pd-tab-btn[data-tab="review"]');
  const reviewPanel  = document.querySelector('.pd-tab-panel[data-panel="review"]');

  document.querySelectorAll('.pd-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.pd-tab-panel').forEach(p => p.classList.remove('active'));
  reviewTabBtn?.classList.add('active');
  reviewPanel?.classList.add('active');

  document.getElementById('review')?.scrollIntoView({ behavior: 'smooth', block: 'start' });

  if (reviewOverlay) {
    // 스크롤 애니메이션과 겹치지 않도록 살짝 지연 후 오픈
    setTimeout(openReviewModal, 350);
  }
}

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
