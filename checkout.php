<?php
// /checkout.php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
ensure_coupon_tables();

if (!Auth::isLoggedIn()) redirect('/login.php');
$pdo = Database::connection();
$uid = Auth::currentUserId();

// ── 주문 모드 결정: cart(장바구니 주문) / buynow(바로구매) ─────────────
if (is_post()) {
    $mode = (($_POST['order_mode'] ?? 'cart') === 'buynow') ? 'buynow' : 'cart';
} else {
    $mode = (($_GET['mode'] ?? 'cart') === 'buynow') ? 'buynow' : 'cart';
}

// ── 주문 대상 아이템 조회 ───────────────────────────────────────────
if ($mode === 'buynow') {
    $buyNow = $_SESSION['buy_now'] ?? null;
    if (!$buyNow) {
        flash('error', '구매하실 상품 정보를 찾을 수 없습니다. 다시 시도해주세요.');
        redirect('/product-list.php');
    }

    $stmt = $pdo->prepare('
        SELECT :qty AS qty, p.id AS product_id, p.name, p.price_sale, p.stock AS product_stock,
               o.id AS option_id, o.size, o.extra_price, o.stock_qty AS option_stock
        FROM tt_products p
        LEFT JOIN tt_product_options o ON o.id = :option_id
        WHERE p.id = :pid AND p.status = "active"
    ');
    $stmt->execute([
        'qty'       => $buyNow['qty'],
        'option_id' => $buyNow['option_id'],
        'pid'       => $buyNow['product_id'],
    ]);
    $items = $stmt->fetchAll();

    if (!$items) {
        unset($_SESSION['buy_now']);
        flash('error', '상품 정보가 존재하지 않거나 판매가 종료되었습니다.');
        redirect('/product-list.php');
    }
} else {
    $stmt = $pdo->prepare('
        SELECT c.id AS cart_id, c.qty, p.id AS product_id, p.name, p.price_sale, p.stock AS product_stock,
               o.id AS option_id, o.size, o.extra_price, o.stock_qty AS option_stock
        FROM tt_carts c
        JOIN tt_products p ON p.id = c.product_id
        LEFT JOIN tt_product_options o ON o.id = c.option_id
        WHERE c.user_id = :uid
    ');
    $stmt->execute(['uid' => $uid]);
    $items = $stmt->fetchAll();

    if (!$items) redirect('/cart.php');
}

$subtotalPreview = array_sum(array_map(fn($i) => ((int)$i['price_sale'] + (int)($i['extra_price'] ?? 0)) * (int)$i['qty'], $items));

/* ===== [NEW] 사용 가능한 쿠폰 목록 조회 (사용 안 함 + 기간 유효 + 최소금액 이하는 제외하지 않고 전부 보여준 뒤 JS로 비활성 처리) ===== */
$couponListStmt = $pdo->prepare("
    SELECT uc.id AS user_coupon_id, c.id AS coupon_id, c.name, c.discount_type, c.discount_value,
           c.max_discount_amount, c.min_order_amount, c.valid_until
    FROM tt_user_coupons uc
    JOIN tt_coupons c ON c.id = uc.coupon_id
    WHERE uc.user_id = :uid AND uc.status = 'unused'
      AND (c.valid_until IS NULL OR c.valid_until >= NOW())
      AND c.status = 'active'
    ORDER BY c.min_order_amount ASC
");
$couponListStmt->execute(['uid' => $uid]);
$availableCoupons = $couponListStmt->fetchAll(PDO::FETCH_ASSOC);

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error', '유효하지 않은 요청입니다.');
        redirect('/checkout.php' . ($mode === 'buynow' ? '?mode=buynow' : ''));
    }

    $recipientName  = trim($_POST['recipient_name'] ?? '');
    $recipientPhone = trim($_POST['recipient_phone'] ?? '');
    $zipcode        = trim($_POST['zipcode'] ?? '');
    $address1       = trim($_POST['address1'] ?? '');
    $address2       = trim($_POST['address2'] ?? '');
    $memo           = trim($_POST['memo'] ?? '');
    $userCouponId   = trim($_POST['user_coupon_id'] ?? '') === '' ? null : (int)$_POST['user_coupon_id'];

    $_SESSION['_old'] = [
        'recipient_name'  => $recipientName,
        'recipient_phone' => $recipientPhone,
        'zipcode'         => $zipcode,
        'address1'        => $address1,
        'address2'        => $address2,
        'memo'            => $memo,
    ];

    $v = new Validator([
        'recipient_name'  => $recipientName,
        'recipient_phone' => $recipientPhone,
        'zipcode'         => $zipcode,
        'address1'        => $address1,
    ]);
    $v->require('recipient_name', '받는분 성명')
      ->require('recipient_phone', '연락처')->phone('recipient_phone')
      ->require('zipcode', '우편번호')
      ->require('address1', '배송지 주소');

    if ($v->fails()) {
        flash('errors', json_encode($v->errors(), JSON_UNESCAPED_UNICODE));
        redirect('/checkout.php' . ($mode === 'buynow' ? '?mode=buynow' : ''));
    }

    $recipientAddr = "({$zipcode}) {$address1}" . ($address2 !== '' ? " {$address2}" : '');

    try {
        $pdo->beginTransaction();

        $total = 0;
        $orderItemsData = [];

        foreach ($items as $it) {
            if ($it['option_id']) {
                $lock = $pdo->prepare('SELECT stock_qty FROM tt_product_options WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $it['option_id']]);
                $row = $lock->fetch();
                if (!$row || (int)$row['stock_qty'] < (int)$it['qty']) {
                    throw new RuntimeException("재고 부족: {$it['name']}");
                }
                $pdo->prepare('UPDATE tt_product_options SET stock_qty = stock_qty - :q WHERE id = :id')
                    ->execute(['q' => $it['qty'], 'id' => $it['option_id']]);
            } else {
                $lock = $pdo->prepare('SELECT stock FROM tt_products WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $it['product_id']]);
                $row = $lock->fetch();
                if (!$row || (int)$row['stock'] < (int)$it['qty']) {
                    throw new RuntimeException("재고 부족: {$it['name']}");
                }
                $pdo->prepare('UPDATE tt_products SET stock = stock - :q WHERE id = :id')
                    ->execute(['q' => $it['qty'], 'id' => $it['product_id']]);
            }

            $unitPrice = (int)$it['price_sale'] + (int)($it['extra_price'] ?? 0);
            $subtotal  = $unitPrice * (int)$it['qty'];
            $total += $subtotal;

            $nameSnap = $it['name'];
            if (!empty($it['size'])) {
                $nameSnap .= ' (' . $it['size'] . ')';
            }

            $orderItemsData[] = [
                'product_id' => $it['product_id'],
                'option_id'  => $it['option_id'],
                'name_snap'  => $nameSnap,
                'price_snap' => $unitPrice,
                'qty'        => $it['qty'],
            ];

            $pdo->prepare('UPDATE tt_products SET sales_count = sales_count + :q WHERE id = :id')
                ->execute(['q' => $it['qty'], 'id' => $it['product_id']]);
        }

        /* ===== [NEW] 쿠폰 재검증 및 할인 계산 (서버가 최종 권위, 클라이언트 값은 신뢰하지 않음) ===== */
        $discountAmount = 0;
        $validUserCouponId = null;

        if ($userCouponId !== null) {
            $ucStmt = $pdo->prepare('
                SELECT uc.id, uc.status, c.* , c.id AS coupon_id
                FROM tt_user_coupons uc
                JOIN tt_coupons c ON c.id = uc.coupon_id
                WHERE uc.id = :ucid AND uc.user_id = :uid
                FOR UPDATE
            ');
            $ucStmt->execute(['ucid' => $userCouponId, 'uid' => $uid]);
            $ucRow = $ucStmt->fetch();

            if (!$ucRow) {
                throw new RuntimeException('선택한 쿠폰을 찾을 수 없습니다.');
            }
            if ($ucRow['status'] !== 'unused') {
                throw new RuntimeException('이미 사용했거나 만료된 쿠폰입니다.');
            }
            if ($ucRow['status'] !== 'active' && $ucRow['status'] !== 'unused') {
                // no-op, status checked above
            }
            if ($ucRow['valid_until'] && strtotime($ucRow['valid_until']) < time()) {
                throw new RuntimeException('쿠폰 유효기간이 만료되었습니다.');
            }
            if ($total < (int)$ucRow['min_order_amount']) {
                throw new RuntimeException('쿠폰 사용을 위한 최소 주문금액(' . number_format((int)$ucRow['min_order_amount']) . '원)을 충족하지 않습니다.');
            }

            $discountAmount = calc_coupon_discount($ucRow, $total);
            $validUserCouponId = (int)$ucRow['id'];
        }

        /* [배송비 설정 반영] 관리자 설정값(tt_site_settings)을 우선 사용, 없으면 상수값을 기본값으로 사용 */
        $freeShippingMin    = (int) get_setting('shipping_free_min', (string)FREE_SHIPPING_MIN);
        $shippingFeeDefault = (int) get_setting('shipping_fee_default', (string)SHIPPING_FEE_DEFAULT);
        $shippingFee = $total >= $freeShippingMin ? 0 : $shippingFeeDefault;
        $payableAmount = max(0, $total + $shippingFee - $discountAmount);
        $orderNo = 'TT' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

        $pdo->prepare('
            INSERT INTO tt_orders (order_no, user_id, total_amount, shipping_fee, discount_amount, user_coupon_id,
                                    recipient_name, recipient_phone, recipient_addr, memo, status)
            VALUES (:no, :uid, :total, :ship, :discount, :ucid, :rname, :rphone, :raddr, :memo, "pending")
        ')->execute([
            'no' => $orderNo, 'uid' => $uid, 'total' => $payableAmount, 'ship' => $shippingFee,
            'discount' => $discountAmount, 'ucid' => $validUserCouponId,
            'rname' => $recipientName, 'rphone' => $recipientPhone,
            'raddr' => $recipientAddr, 'memo' => $memo,
        ]);
        $orderId = (int)$pdo->lastInsertId();

        if ($validUserCouponId !== null) {
            $pdo->prepare("UPDATE tt_user_coupons SET status = 'used', used_at = NOW(), order_id = :oid WHERE id = :id")
                ->execute(['oid' => $orderId, 'id' => $validUserCouponId]);
        }

        $itemStmt = $pdo->prepare('
            INSERT INTO tt_order_items (order_id, product_id, option_id, product_name, price, qty)
            VALUES (:oid, :pid, :optid, :name, :price, :qty)
        ');
        foreach ($orderItemsData as $d) {
            $itemStmt->execute([
                'oid' => $orderId, 'pid' => $d['product_id'], 'optid' => $d['option_id'],
                'name' => $d['name_snap'], 'price' => $d['price_snap'], 'qty' => $d['qty'],
            ]);
        }

        $pdo->prepare('INSERT INTO tt_order_status_logs (order_id, status, memo) VALUES (:oid, "pending", "주문 생성")')
            ->execute(['oid' => $orderId]);

        if ($mode === 'cart') {
            $pdo->prepare('DELETE FROM tt_carts WHERE user_id = :uid')->execute(['uid' => $uid]);
        }

        $pdo->commit();
        unset($_SESSION['_old']);
        if ($mode === 'buynow') {
            unset($_SESSION['buy_now']);
        }
        redirect('/order-complete.php?no=' . $orderNo);

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[CHECKOUT FAIL] ' . $e->getMessage());
        $userMsg = ($e instanceof RuntimeException) ? $e->getMessage() : '주문 처리 중 문제가 발생했습니다. 다시 시도해주세요.';
        flash('error', $userMsg);
        if ($mode === 'buynow') {
            $backProductId = $items[0]['product_id'] ?? null;
            unset($_SESSION['buy_now']);
            redirect($backProductId ? '/product-detail.php?id=' . $backProductId : '/product-list.php');
        }
        redirect('/cart.php');
    }
}

$pageTitle = '주문/결제';
require __DIR__ . '/includes/header.php';
$subtotal = $subtotalPreview;
/* [배송비 설정 반영] 화면 미리보기도 동일하게 설정값 기준으로 계산 */
$freeShippingMinDisp    = (int) get_setting('shipping_free_min', (string)FREE_SHIPPING_MIN);
$shippingFeeDefaultDisp = (int) get_setting('shipping_fee_default', (string)SHIPPING_FEE_DEFAULT);
$shipFee = $subtotal >= $freeShippingMinDisp ? 0 : $shippingFeeDefaultDisp;
$fieldErrors = json_decode(flash('errors') ?? '{}', true) ?: [];
?>
<style>
.coupon-select-box{
  background:linear-gradient(135deg,#f5f3ff,#eef2ff); border:1px solid #ddd6fe;
  border-radius:14px; padding:18px 20px; margin-top:6px;
}
.coupon-select-box label{display:block;font-size:13px;font-weight:800;color:#4f46e5;margin-bottom:10px}
.coupon-select-box select{
  width:100%; border:1px solid #ddd6fe; border-radius:10px; padding:11px 14px;
  font-size:14px; background:#fff; font-weight:600;
}
.coupon-select-empty{font-size:12.5px;color:#94a3b8;margin-top:4px}
.coupon-discount-line{color:#6366f1 !important;font-weight:800}
</style>

<div class="checkout-wrap">
  <h1 class="checkout-title">주문/결제</h1>

  <?php if ($mode === 'buynow'): ?>
    <p class="checkout-mode-badge" style="color:var(--primary);font-weight:600;margin-bottom:12px;">바로구매</p>
  <?php endif; ?>

  <?php if ($err = flash('error')): ?>
    <div class="error-msg"><?= h($err) ?></div>
  <?php endif; ?>

  <section class="checkout-section">
    <h2>주문 상품</h2>
    <table class="order-table">
      <thead><tr><th>상품</th><th>수량</th><th>금액</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= h($it['name']) ?> <?= $it['size'] ? h($it['size']) : '' ?></td>
            <td><?= (int)$it['qty'] ?>개</td>
            <td><?= format_price(((int)$it['price_sale'] + (int)($it['extra_price'] ?? 0)) * (int)$it['qty']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="checkout-total-row"><span>총 상품금액</span><strong id="ckSubtotalDisp"><?= format_price($subtotal) ?></strong></div>
    <div class="checkout-total-row"><span>배송비</span><strong><?= $shipFee === 0 ? '무료' : format_price($shipFee) ?></strong></div>
    <div class="checkout-total-row" id="ckDiscountRow" style="display:none">
      <span>쿠폰 할인</span><strong class="coupon-discount-line" id="ckDiscountDisp">-0원</strong>
    </div>
  </section>

  <form method="post" action="<?= BASE_URL ?>/checkout.php" id="checkoutForm" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="order_mode" value="<?= h($mode) ?>">

    <section class="checkout-section">
      <h2>🎟️ 쿠폰 사용</h2>
      <div class="coupon-select-box">
        <label>보유 쿠폰</label>
        <?php if ($availableCoupons): ?>
          <select name="user_coupon_id" id="couponSelect" data-subtotal="<?= (int)$subtotal ?>">
            <option value="">쿠폰을 선택하세요</option>
            <?php foreach ($availableCoupons as $ac): ?>
              <?php
                $label = $ac['discount_type'] === 'percent'
                    ? ($ac['name'] . ' (' . (int)$ac['discount_value'] . '% / ' . number_format((int)$ac['min_order_amount']) . '원 이상)')
                    : ($ac['name'] . ' (' . number_format((int)$ac['discount_value']) . '원 / ' . number_format((int)$ac['min_order_amount']) . '원 이상)');
              ?>
              <option value="<?= (int)$ac['user_coupon_id'] ?>"
                      data-type="<?= h($ac['discount_type']) ?>"
                      data-value="<?= (int)$ac['discount_value'] ?>"
                      data-max="<?= (int)($ac['max_discount_amount'] ?? 0) ?>"
                      data-min="<?= (int)$ac['min_order_amount'] ?>">
                <?= h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <p class="coupon-select-empty">사용 가능한 쿠폰이 없습니다. 마이페이지 &gt; 쿠폰함에서 확인해보세요.</p>
        <?php endif; ?>
      </div>
    </section>

    <section class="checkout-section">
      <h2>배송 정보</h2>

      <div class="mp-form-row <?= isset($fieldErrors['recipient_name']) ? 'has-error' : '' ?>" data-field-row="recipient_name">
        <label>받는분 <span style="color:var(--primary)">*</span></label>
        <input type="text" name="recipient_name" id="fRecipientName" value="<?= h(old('recipient_name')) ?>" placeholder="받으실 분의 성명을 입력해주세요">
        <div class="field-error"><?= h($fieldErrors['recipient_name'] ?? '받는분 성명을 입력해주세요.') ?></div>
      </div>

      <div class="mp-form-row <?= isset($fieldErrors['recipient_phone']) ? 'has-error' : '' ?>" data-field-row="recipient_phone">
        <label>연락처 <span style="color:var(--primary)">*</span></label>
        <input type="text" name="recipient_phone" id="fRecipientPhone" value="<?= h(old('recipient_phone')) ?>" placeholder="010-1234-5678">
        <div class="field-error"><?= h($fieldErrors['recipient_phone'] ?? '올바른 휴대폰 번호를 입력해주세요.') ?></div>
      </div>

      <div class="mp-form-row <?= isset($fieldErrors['zipcode']) ? 'has-error' : '' ?>" data-field-row="zipcode">
        <label>우편번호 <span style="color:var(--primary)">*</span></label>
        <div class="addr-search-row">
          <input type="text" name="zipcode" id="zipcode" value="<?= h(old('zipcode')) ?>" readonly placeholder="주소 검색을 눌러주세요">
          <button type="button" id="btnAddrSearch" class="btn-addr-search">주소 검색</button>
        </div>
        <div class="field-error"><?= h($fieldErrors['zipcode'] ?? '주소 검색을 눌러 우편번호를 입력해주세요.') ?></div>
      </div>

      <div class="mp-form-row <?= isset($fieldErrors['address1']) ? 'has-error' : '' ?>" data-field-row="address1">
        <label>주소 <span style="color:var(--primary)">*</span></label>
        <input type="text" name="address1" id="address1" value="<?= h(old('address1')) ?>" readonly placeholder="주소 검색을 눌러주세요">
        <div class="field-error"><?= h($fieldErrors['address1'] ?? '배송지 주소를 입력해주세요.') ?></div>
      </div>

      <div class="mp-form-row">
        <label>상세주소</label>
        <input type="text" name="address2" id="address2" value="<?= h(old('address2')) ?>" placeholder="동/호수 등 상세주소를 입력해주세요" autocomplete="off">
      </div>

      <div class="mp-form-row">
        <label>배송메모</label>
        <input type="text" name="memo" value="<?= h(old('memo')) ?>" placeholder="예: 부재 시 경비실에 맡겨주세요">
      </div>
    </section>

    <section class="checkout-section payment-notice">
      <h2>결제 방법</h2>
      <p><strong>무통장입금 안내</strong></p>
      <p>주문 완료 후 아래 계좌로 입금해주시면 관리자가 확인 후 상품을 준비합니다.</p>
      <p class="pay-account">국민은행 123456-04-123456 (예금주: 타이어탑)</p>
    </section>

    <button type="submit" class="btn-primary-lg checkout-submit" id="checkoutSubmitBtn">
      <span id="ckFinalAmount"><?= format_price($subtotal + $shipFee) ?></span> 주문 완료하기
    </button>
  </form>
</div>

<script src="https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
<script>
document.getElementById('btnAddrSearch')?.addEventListener('click', function () {
  if (typeof daum === 'undefined' || !daum.Postcode) {
    alert('주소 검색 서비스를 불러올 수 없습니다. 잠시 후 다시 시도해주세요.');
    return;
  }
  new daum.Postcode({
    oncomplete: function (data) {
      const addr = data.roadAddress || data.jibunAddress;
      document.getElementById('zipcode').value = data.zonecode;
      document.getElementById('address1').value = addr;
      clearFieldError('zipcode');
      clearFieldError('address1');
      document.getElementById('address2').focus();
    }
  }).open();
});

function setFieldError(fieldName, message) {
  const row = document.querySelector('[data-field-row="' + fieldName + '"]');
  if (!row) return;
  row.classList.add('has-error');
  const errEl = row.querySelector('.field-error');
  if (errEl && message) errEl.textContent = message;
}
function clearFieldError(fieldName) {
  const row = document.querySelector('[data-field-row="' + fieldName + '"]');
  if (row) row.classList.remove('has-error');
}
function clearAllFieldErrors() {
  document.querySelectorAll('[data-field-row]').forEach(row => row.classList.remove('has-error'));
}

const PHONE_REGEX = /^01[0-9]-?\d{3,4}-?\d{4}$/;

document.getElementById('checkoutForm')?.addEventListener('submit', function (e) {
  clearAllFieldErrors();

  const name    = document.getElementById('fRecipientName').value.trim();
  const phone   = document.getElementById('fRecipientPhone').value.trim();
  const zipcode = document.getElementById('zipcode').value.trim();
  const address1= document.getElementById('address1').value.trim();

  let firstInvalid = null;
  let hasError = false;

  if (!name) {
    setFieldError('recipient_name', '받는분 성명을 입력해주세요.');
    hasError = true; firstInvalid = firstInvalid || 'fRecipientName';
  }
  if (!phone) {
    setFieldError('recipient_phone', '연락처를 입력해주세요.');
    hasError = true; firstInvalid = firstInvalid || 'fRecipientPhone';
  } else if (!PHONE_REGEX.test(phone)) {
    setFieldError('recipient_phone', '올바른 휴대폰 번호 형식이 아닙니다. (예: 010-1234-5678)');
    hasError = true; firstInvalid = firstInvalid || 'fRecipientPhone';
  }
  if (!zipcode || !address1) {
    setFieldError('zipcode', '주소 검색을 눌러 배송지를 입력해주세요.');
    setFieldError('address1', '주소 검색을 눌러 배송지를 입력해주세요.');
    hasError = true; firstInvalid = firstInvalid || 'zipcode';
  }

  if (hasError) {
    e.preventDefault();
    const target = document.getElementById(firstInvalid);
    target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (!target?.hasAttribute('readonly')) target?.focus();
    return false;
  }
});

['fRecipientName', 'fRecipientPhone'].forEach(function (id) {
  document.getElementById(id)?.addEventListener('input', function () {
    const row = this.closest('[data-field-row"]');
    if (row) row.classList.remove('has-error');
  });
});

/* ===== [NEW] 쿠폰 선택 시 실시간 할인 계산 (화면 표시용, 최종 금액은 서버가 재계산) ===== */
(function(){
  const select = document.getElementById('couponSelect');
  if (!select) return;

  const subtotal   = parseInt(select.dataset.subtotal || '0', 10);
  const shipFeeRaw  = <?= (int)$shipFee ?>;
  const discountRow = document.getElementById('ckDiscountRow');
  const discountDisp= document.getElementById('ckDiscountDisp');
  const finalDisp    = document.getElementById('ckFinalAmount');

  function fmt(n){ return Number(n).toLocaleString() + '원'; }

  function recalc(){
    const opt = select.options[select.selectedIndex];
    if (!opt || !opt.value) {
      discountRow.style.display = 'none';
      finalDisp.textContent = fmt(subtotal + shipFeeRaw);
      return;
    }
    const type = opt.dataset.type;
    const value = parseInt(opt.dataset.value || '0', 10);
    const max   = parseInt(opt.dataset.max || '0', 10);
    const min   = parseInt(opt.dataset.min || '0', 10);

    if (subtotal < min) {
      alert('이 쿠폰은 ' + min.toLocaleString() + '원 이상 구매 시 사용할 수 있습니다.');
      select.value = '';
      discountRow.style.display = 'none';
      finalDisp.textContent = fmt(subtotal + shipFeeRaw);
      return;
    }

    let discount = type === 'percent' ? Math.floor(subtotal * (value / 100)) : value;
    if (type === 'percent' && max > 0) discount = Math.min(discount, max);
    discount = Math.min(discount, subtotal);

    discountRow.style.display = '';
    discountDisp.textContent = '-' + fmt(discount);
    finalDisp.textContent = fmt(Math.max(0, subtotal + shipFeeRaw - discount));
  }

  select.addEventListener('change', recalc);
  recalc();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
