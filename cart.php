<?php
// /cart.php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

if (!Auth::isLoggedIn()) {
    flash('error', '로그인이 필요합니다.');
    redirect('/login.php');
}

$pdo = Database::connection();
$uid = Auth::currentUserId();

// ---------- 수량 변경 처리 (폼 fallback, JS 없이도 동작) ----------
if (is_post() && ($_POST['action'] ?? '') === 'update_qty') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error', '유효하지 않은 요청입니다.');
        redirect('/cart.php');
    }
    $cartId = (int)($_POST['cart_id'] ?? 0);
    $qty = (int)($_POST['qty'] ?? 1);
    if ($qty < 1) $qty = 1;
    if ($qty > 99) $qty = 99;

    $chk = $pdo->prepare('SELECT id FROM tt_carts WHERE id = :id AND user_id = :uid');
    $chk->execute(['id' => $cartId, 'uid' => $uid]);
    if ($chk->fetch()) {
        $pdo->prepare('UPDATE tt_carts SET qty = :qty WHERE id = :id AND user_id = :uid')
            ->execute(['qty' => $qty, 'id' => $cartId, 'uid' => $uid]);
    }
    redirect('/cart.php');
}

// ---------- 개별 삭제 처리 ----------
if (is_post() && ($_POST['action'] ?? '') === 'remove_item') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error', '유효하지 않은 요청입니다.');
        redirect('/cart.php');
    }
    $cartId = (int)($_POST['cart_id'] ?? 0);
    $pdo->prepare('DELETE FROM tt_carts WHERE id = :id AND user_id = :uid')
        ->execute(['id' => $cartId, 'uid' => $uid]);
    flash('success', '상품이 삭제되었습니다.');
    redirect('/cart.php');
}

// ---------- 선택 삭제 처리 ----------
if (is_post() && ($_POST['action'] ?? '') === 'remove_selected') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error', '유효하지 않은 요청입니다.');
        redirect('/cart.php');
    }
    $ids = array_filter(array_map('intval', $_POST['cart_ids'] ?? []));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM tt_carts WHERE user_id = ? AND id IN ({$placeholders})";
        $del = $pdo->prepare($sql);
        $del->execute(array_merge([$uid], $ids));
        flash('success', '선택한 상품이 삭제되었습니다.');
    }
    redirect('/cart.php');
}

// ---------- 장바구니 목록 조회 ----------
$stmt = $pdo->prepare('
    SELECT
        c.id AS cart_id, c.qty,
        p.id AS product_id, p.name, p.thumbnail_url, p.price_sale, p.price_original, p.stock AS product_stock,
        b.name AS brand_name,
        o.id AS option_id, o.size, o.extra_price, o.stock_qty AS option_stock
    FROM tt_carts c
    JOIN tt_products p ON p.id = c.product_id
    JOIN tt_brands b ON b.id = p.brand_id
    LEFT JOIN tt_product_options o ON o.id = c.option_id
    WHERE c.user_id = :uid
    ORDER BY c.id DESC
');
$stmt->execute(['uid' => $uid]);
$items = $stmt->fetchAll();

// ---------- 합계 계산 및 재고 부족 항목 표시 ----------
$subtotal = 0;
foreach ($items as &$it) {
    $unitPrice = (int)$it['price_sale'] + (int)($it['extra_price'] ?? 0);
    $it['unit_price'] = $unitPrice;
    $it['line_total'] = $unitPrice * (int)$it['qty'];
    $subtotal += $it['line_total'];

    $availableStock = $it['option_id'] ? (int)$it['option_stock'] : (int)$it['product_stock'];
    $it['stock_shortage'] = $availableStock < (int)$it['qty'];
}
unset($it);

$shipFee = ($subtotal > 0 && $subtotal >= FREE_SHIPPING_MIN) ? 0 : ($subtotal > 0 ? SHIPPING_FEE_DEFAULT : 0);
$hasShortage = (bool)array_filter($items, fn($i) => $i['stock_shortage']);

$successMsg = flash('success');
$errorMsg = flash('error');

$pageTitle = '장바구니';
require __DIR__ . '/includes/header.php';
?>

<div class="cart-wrap">
  <h1 class="cart-title">장바구니</h1>

  <?php if ($successMsg): ?>
    <p class="error-msg" style="background:#f0fdf4;color:#15803d"><?= h($successMsg) ?></p>
  <?php endif; ?>
  <?php if ($errorMsg): ?>
    <p class="error-msg"><?= h($errorMsg) ?></p>
  <?php endif; ?>

  <?php if (!$items): ?>
    <div class="cart-empty">
      <div class="icon">🛒</div>
      <p>장바구니에 담긴 상품이 없습니다.</p>
      <a href="<?= BASE_URL ?>/product-list.php?cat=tire" class="btn-primary">타이어 보러가기</a>
    </div>
  <?php else: ?>

    <!--
      ★ 수정 포인트 1:
      #cartForm은 이제 .cart-list를 감싸지 않는다.
      선택삭제 액션을 위한 hidden 필드 + 버튼만 담는 "독립된 얇은 폼"으로 분리했다.
      과거에는 이 폼이 .cart-list 전체를 감싸고 있어서, 내부의 수량변경/개별삭제
      <form>들과 중첩되어 브라우저가 태그를 조기 종료시키는 문제가 있었다.
    -->
    <form method="post" action="<?= BASE_URL ?>/cart.php" id="cartForm">
      <input type="hidden" name="action" value="remove_selected">
      <?= Csrf::field() ?>

      <div class="cart-select-bar">
        <label>
          <input type="checkbox" id="checkAll">
          전체선택 (<?= count($items) ?>)
        </label>
        <button type="submit" class="cart-remove-selected" onclick="return confirm('선택한 상품을 삭제하시겠습니까?')">선택삭제</button>
      </div>
    </form>

    <!--
      ★ 수정 포인트 2:
      .cart-list는 이제 #cartForm 밖에 위치한다.
      각 상품의 체크박스에는 form="cartForm" 속성을 붙여서,
      DOM 트리상 위치와 무관하게 #cartForm이 제출될 때 이 값들이 함께 전송된다.
      (HTML5 표준 기능이며 모든 최신 브라우저에서 정상 동작한다.)
      이렇게 하면 각 상품 안의 수량변경 폼 / 개별삭제 폼도 더 이상 어떤 폼에도
      중첩되지 않으므로, 태그 조기 종료 문제 자체가 원천적으로 사라진다.
    -->
    <div class="cart-list">
      <?php foreach ($items as $it): ?>
        <div class="cart-item <?= $it['stock_shortage'] ? 'stock-warn' : '' ?>">
          <input type="checkbox" name="cart_ids[]" value="<?= (int)$it['cart_id'] ?>" class="cart-item-check" form="cartForm">

          <a href="<?= BASE_URL ?>/product-detail.php?id=<?= (int)$it['product_id'] ?>" class="cart-item-img">
            <?php if ($it['thumbnail_url']): ?>
              <img src="<?= h($it['thumbnail_url']) ?>" alt="<?= h($it['name']) ?>">
            <?php else: ?>
              <span class="ph">🛞</span>
            <?php endif; ?>
          </a>

          <div class="cart-item-info">
            <div class="cart-item-brand"><?= h($it['brand_name']) ?></div>
            <a href="<?= BASE_URL ?>/product-detail.php?id=<?= (int)$it['product_id'] ?>" class="cart-item-name">
              <?= h($it['name']) ?>
            </a>
            <?php if ($it['size']): ?>
              <div class="cart-item-option">규격: <?= h($it['size']) ?></div>
            <?php endif; ?>
            <?php if ($it['stock_shortage']): ?>
              <div class="cart-item-stockwarn">⚠ 재고가 부족합니다. 수량을 조정해주세요.</div>
            <?php endif; ?>
          </div>

          <form method="post" action="<?= BASE_URL ?>/cart.php" class="cart-qty-form">
            <input type="hidden" name="action" value="update_qty">
            <input type="hidden" name="cart_id" value="<?= (int)$it['cart_id'] ?>">
            <?= Csrf::field() ?>
            <div class="cart-item-qty">
              <button type="button" class="qty-minus">−</button>
              <input type="number" name="qty" value="<?= (int)$it['qty'] ?>" min="1" max="99" class="qty-input" data-cart-id="<?= (int)$it['cart_id'] ?>">
              <button type="button" class="qty-plus">+</button>
            </div>
          </form>

          <div class="cart-item-price">
            <span class="now" data-line-total="<?= (int)$it['cart_id'] ?>"><?= format_price((int)$it['line_total']) ?></span>
            <?php if ((int)$it['price_original'] > (int)$it['price_sale']): ?>
              <span class="orig"><?= format_price((int)$it['price_original']) ?></span>
            <?php endif; ?>
          </div>

          <form method="post" action="<?= BASE_URL ?>/cart.php">
            <input type="hidden" name="action" value="remove_item">
            <input type="hidden" name="cart_id" value="<?= (int)$it['cart_id'] ?>">
            <?= Csrf::field() ?>
            <button type="submit" class="cart-item-remove" title="삭제">✕</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="cart-summary">
      <div class="cart-summary-row">
        <span>총 상품금액</span><span><?= format_price($subtotal) ?></span>
      </div>
      <div class="cart-summary-row">
        <span>배송비</span><span><?= $shipFee === 0 ? '무료' : format_price($shipFee) ?></span>
      </div>
      <div class="cart-summary-row total">
        <span>결제예정금액</span><strong><?= format_price($subtotal + $shipFee) ?></strong>
      </div>

      <?php if ($hasShortage): ?>
        <a href="#" class="cart-checkout-btn disabled">재고 부족 상품이 있어 주문할 수 없습니다</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/checkout.php" class="cart-checkout-btn">주문하기</a>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>

<script>
(function(){
  // 전체선택 체크박스 — 셀렉터/로직은 기존과 동일하다.
  // #checkAll이 #cartForm 안에 있고, .cart-item-check들이 form 밖에 있어도
  // 여기서는 단순히 checked 상태만 동기화하는 것이라 문제 없다.
  const checkAll = document.getElementById('checkAll');
  const itemChecks = document.querySelectorAll('.cart-item-check');
  checkAll?.addEventListener('change', function(){
    itemChecks.forEach(c => c.checked = this.checked);
  });

  // 수량 스테퍼: +/- 클릭 시 즉시 폼 제출 (서버 재계산 방식으로 단순/안전하게 처리)
  document.querySelectorAll('.cart-item-qty').forEach(function(box){
    const input = box.querySelector('.qty-input');
    const minus = box.querySelector('.qty-minus');
    const plus  = box.querySelector('.qty-plus');

    minus.addEventListener('click', function(){
      let v = parseInt(input.value, 10) || 1;
      if (v > 1) {
        input.value = v - 1;
        input.closest('form').submit();
      }
    });
    plus.addEventListener('click', function(){
      let v = parseInt(input.value, 10) || 1;
      if (v < 99) {
        input.value = v + 1;
        input.closest('form').submit();
      }
    });
    // 직접 입력 후 변경(blur) 시에도 반영
    input.addEventListener('change', function(){
      let v = parseInt(this.value, 10) || 1;
      if (v < 1) v = 1;
      if (v > 99) v = 99;
      this.value = v;
      this.closest('form').submit();
    });
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
