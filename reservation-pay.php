<?php
// reservation-pay.php — 예약 결제창 (쿠폰 적용 + 결제수단 선택)
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
ensure_reservations_table();
ensure_coupon_tables();

$pdo = Database::connection();
$resNo = trim($_GET['no'] ?? '');
$error = '';
$reservation = null;

if ($resNo === '') {
    redirect('/index.php');
}

// 예약 조회 (본인 예약만 — 비회원 예약은 결제 페이지 미지원, 로그인 유도)
$findStmt = $pdo->prepare('SELECT * FROM tt_reservations WHERE res_no = :no LIMIT 1');
$findStmt->execute(['no' => $resNo]);
$reservation = $findStmt->fetch();

if (!$reservation) {
    flash('error', '예약을 찾을 수 없습니다.');
    redirect('/index.php');
}
if ($reservation['payment_status'] === 'paid') {
    // 이미 결제 완료 → 완료 페이지로
    redirect('/reservation-complete.php?no=' . urlencode($resNo));
}
if (!Auth::isLoggedIn()) {
    flash('error', '예약 결제는 로그인이 필요합니다. 로그인 후 이 페이지를 다시 열어주세요. (예약번호: ' . $resNo . ')');
    redirect('/login.php');
}
if ($reservation['user_id'] !== null && (int)$reservation['user_id'] !== Auth::currentUserId()) {
    flash('error', '본인의 예약만 결제할 수 있습니다.');
    redirect('/index.php');
}
if (in_array($reservation['status'], ['canceled', 'noshow'], true)) {
    flash('error', '취소된 예약은 결제할 수 없습니다.');
    redirect('/mypage.php#myreservations');
}

$uid = Auth::currentUserId();

/* ===== 재고 확인 (재고 없으면 결제 진행 불가 — 소비자·사업자 공통) =====
   예약은 방문 후 결제 방식이라 재고를 미리 차감하지는 않지만,
   결제 시점에 상품/DOT옵션 재고가 없으면 결제를 진행할 수 없다. */
$resvStockQty = 0; // 이 예약에 적용되는 가용 재고
try {
    if (!empty($reservation['option_id'])) {
        $sStmt = $pdo->prepare('SELECT stock_qty FROM tt_product_options WHERE id = :id AND is_active = 1 LIMIT 1');
        $sStmt->execute(['id' => (int)$reservation['option_id']]);
        $resvStockQty = (int)($sStmt->fetchColumn() ?: 0);
    } else {
        $sStmt = $pdo->prepare('SELECT stock FROM tt_products WHERE id = :id LIMIT 1');
        $sStmt->execute(['id' => (int)$reservation['product_id']]);
        $resvStockQty = (int)($sStmt->fetchColumn() ?: 0);
    }
} catch (Throwable $e) {
    error_log('[reservation-pay stock] ' . $e->getMessage());
    $resvStockQty = 0;
}
$resvNeedQty = max(1, (int)$reservation['qty']);
$stockShortage = $resvStockQty < $resvNeedQty;

// 비회원 예약을 로그인 사용자에게 귀속 (같은 전화번호면 본인 예약으로 연결)
if ($reservation['user_id'] === null) {
    $pdo->prepare('UPDATE tt_reservations SET user_id = :uid WHERE id = :id')
        ->execute(['uid' => $uid, 'id' => $reservation['id']]);
    $reservation['user_id'] = $uid;
}

$subtotal = (int)$reservation['unit_price'] * (int)$reservation['qty'];

/* ===== 사용 가능한 쿠폰 목록 (checkout.php와 동일한 조건) ===== */
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

/* ===== 결제 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = '유효하지 않은 요청입니다. 다시 시도해주세요.';
    } else {
        $userCouponId = trim($_POST['user_coupon_id'] ?? '') === '' ? null : (int)$_POST['user_coupon_id'];
        /* [v47] 결제수단 — 주문(checkout)과 동일한 공통 5종(네이버페이·카카오페이 포함) */
        $paymentMethod = $_POST['payment_method'] ?? 'bank_transfer';
        if (!array_key_exists($paymentMethod, payment_method_labels())) $paymentMethod = 'bank_transfer';

        try {
            $pdo->beginTransaction();

            /* ===== 재고 재확인 (결제 직전 서버 차단 — 소비자·사업자 공통) ===== */
            if (!empty($reservation['option_id'])) {
                $sLock = $pdo->prepare('SELECT stock_qty FROM tt_product_options WHERE id = :id FOR UPDATE');
                $sLock->execute(['id' => (int)$reservation['option_id']]);
                $sAvail = (int)($sLock->fetchColumn() ?: 0);
            } else {
                $sLock = $pdo->prepare('SELECT stock FROM tt_products WHERE id = :id FOR UPDATE');
                $sLock->execute(['id' => (int)$reservation['product_id']]);
                $sAvail = (int)($sLock->fetchColumn() ?: 0);
            }
            if ($sAvail < $resvNeedQty) {
                throw new RuntimeException('재고가 없습니다. 재고 요청을 하시기 바랍니다. (현재 재고 ' . $sAvail . '개 / 필요 ' . $resvNeedQty . '개)');
            }

            $discountAmount = 0;
            $validUserCouponId = null;

            // 쿠폰 검증 + 할인 계산
            if ($userCouponId !== null) {
                $ucStmt = $pdo->prepare("
                    SELECT uc.id AS user_coupon_id, uc.status AS uc_status, c.id AS coupon_id,
                           c.name, c.discount_type, c.discount_value, c.max_discount_amount,
                           c.min_order_amount, c.valid_until
                    FROM tt_user_coupons uc
                    JOIN tt_coupons c ON c.id = uc.coupon_id
                    WHERE uc.id = :id AND uc.user_id = :uid
                      AND c.status = 'active'
                      AND (c.valid_until IS NULL OR c.valid_until >= NOW())
                    LIMIT 1
                    FOR UPDATE
                ");
                $ucStmt->execute(['id' => $userCouponId, 'uid' => $uid]);
                $ucRow = $ucStmt->fetch();

                if ($ucRow && $ucRow['uc_status'] === 'unused') {
                    $discountAmount = calc_coupon_discount($ucRow, $subtotal);
                    if ($discountAmount > 0) {
                        $validUserCouponId = (int)$ucRow['user_coupon_id'];
                    }
                }
            }

            $finalAmount = max(0, $subtotal - $discountAmount);
            /* [v47] 카드·간편결제(네이버페이/카카오페이)는 즉시 결제 완료 처리(기존 카드와 동일),
               무통장입금·가상계좌는 입금 확인 후 결제 확정 */
            $payStatus = is_cash_payment_method($paymentMethod) ? 'unpaid' : 'paid';

            // 예약에 결제 정보 저장
            $pdo->prepare('UPDATE tt_reservations
                           SET discount_amount = :dc, final_amount = :fa, user_coupon_id = :ucid,
                               payment_method = :pm, payment_status = :ps
                           WHERE id = :id')
                ->execute([
                    'dc' => $discountAmount, 'fa' => $finalAmount, 'ucid' => $validUserCouponId,
                    'pm' => $paymentMethod, 'ps' => $payStatus, 'id' => $reservation['id'],
                ]);

            // 쿠폰 사용 처리
            if ($validUserCouponId !== null) {
                $pdo->prepare("UPDATE tt_user_coupons SET status = 'used', used_at = NOW() WHERE id = :id")
                    ->execute(['id' => $validUserCouponId]);
            }

            $pdo->commit();
            /* [v51] 결제완료(비현금 즉시결제) 예약은 이카운트 ERP 판매입력 자동 전송 */
            if ($payStatus === 'paid') {
                ecount_on_paid('reservation', (int)$reservation['id']);
            }
            redirect('/reservation-complete.php?no=' . urlencode($resNo));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[reservation-pay] ' . $e->getMessage());
            // 재고 부족 등 안내 가능한 오류는 그대로 사용자에게 표시
            $error = ($e instanceof RuntimeException) ? $e->getMessage() : '결제 처리 중 오류가 발생했습니다. 다시 시도해주세요.';
        }
    }
}

$pageTitle = '예약 결제';
require __DIR__ . '/includes/header.php';
?>

<div class="rp-wrap">
  <div class="rp-steps">
    <span class="rp-step done">1. 예약 접수</span>
    <span class="rp-step active">2. 결제</span>
    <span class="rp-step">3. 완료</span>
  </div>

  <div class="rp-grid">
    <!-- 좌측: 예약 정보 -->
    <div class="rp-card">
      <h2 class="rp-card-title">예약 정보</h2>
      <div class="rp-resv-box">
        <div class="rp-resv-no"><?= h($reservation['res_no']) ?></div>
        <div class="rp-resv-product">
          <strong><?= h($reservation['product_name_snap']) ?></strong>
          <?php if ($reservation['size_snap']): ?><span><?= h($reservation['size_snap']) ?></span><?php endif; ?>
        </div>
        <div class="rp-resv-meta">
          <span>🛞 <?= (int)$reservation['qty'] ?>개</span>
          <span>📅 <?= h(date('Y.m.d', strtotime($reservation['visit_date']))) ?> <?= h($reservation['visit_time']) ?></span>
          <span>👤 <?= h($reservation['visitor_name']) ?></span>
          <?php if ($reservation['car_info']): ?><span>🚗 <?= h($reservation['car_info']) ?></span><?php endif; ?>
        </div>
      </div>

      <h2 class="rp-card-title">쿠폰 적용</h2>
      <?php if (empty($availableCoupons)): ?>
        <p class="rp-coupon-empty">사용 가능한 쿠폰이 없습니다.<br>마이페이지 쿠폰함에서 보유한 쿠폰을 확인할 수 있습니다.</p>
      <?php else: ?>
        <div class="rp-coupon-select">
          <label>사용할 쿠폰 선택</label>
          <select id="rpCouponSelect">
            <option value="" data-discount="0">쿠폰 미사용</option>
            <?php foreach ($availableCoupons as $cpn): ?>
              <?php
                // 서버 계산과 동일한 규칙으로 미리 할인액 계산해 data로 심음
                $previewDiscount = calc_coupon_discount($cpn, $subtotal);
                $disabled = $previewDiscount <= 0;
                $label = h($cpn['name']) . ' (-' . number_format($previewDiscount) . '원)';
                if ((int)$cpn['min_order_amount'] > $subtotal) $label .= ' / 최소 ' . number_format((int)$cpn['min_order_amount']) . '원 이상';
              ?>
              <option value="<?= (int)$cpn['user_coupon_id'] ?>"
                      data-discount="<?= $previewDiscount ?>"
                      data-min="<?= (int)$cpn['min_order_amount'] ?>"
                      <?= $disabled ? 'disabled' : '' ?>>
                <?= $label ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <h2 class="rp-card-title">결제 수단</h2>
      <?php /* [v47] 주문(checkout)과 동일한 공통 5종 — 네이버페이·카카오페이 추가 */ ?>
      <div class="rp-pay-methods">
        <label class="rp-pay-method">
          <input type="radio" name="payment_method" value="bank_transfer" checked>
          <span class="rp-pay-icon">🏦</span>
          <span class="rp-pay-label">무통장입금</span>
          <span class="rp-pay-sub">24시간 내 입금 시 자동 확정</span>
        </label>
        <label class="rp-pay-method">
          <input type="radio" name="payment_method" value="virtual_account">
          <span class="rp-pay-icon">💳</span>
          <span class="rp-pay-label">가상계좌</span>
          <span class="rp-pay-sub">전용 계좌로 입금 시 자동 결제</span>
        </label>
        <label class="rp-pay-method">
          <input type="radio" name="payment_method" value="card">
          <span class="rp-pay-icon">💠</span>
          <span class="rp-pay-label">신용·체크카드</span>
          <span class="rp-pay-sub">즉시 결제 완료</span>
        </label>
        <label class="rp-pay-method">
          <input type="radio" name="payment_method" value="naver_pay">
          <span class="rp-pay-icon">🟢</span>
          <span class="rp-pay-label">네이버페이</span>
          <span class="rp-pay-sub">네이버페이(포인트 결제) — PG 연결 후 즉시 결제</span>
        </label>
        <label class="rp-pay-method">
          <input type="radio" name="payment_method" value="kakao_pay">
          <span class="rp-pay-icon">🟡</span>
          <span class="rp-pay-label">카카오페이</span>
          <span class="rp-pay-sub">카카오페이(간편결제) — PG 연결 후 즉시 결제</span>
        </label>
      </div>
    </div>

    <!-- 우측: 결제 요약 -->
    <div class="rp-summary">
      <h2 class="rp-card-title">결제 금액</h2>
      <div class="rp-price-rows">
        <div class="rp-price-row">
          <span>상품 금액 (<?= (int)$reservation['qty'] ?>개 × <?= number_format((int)$reservation['unit_price']) ?>원)</span>
          <strong><?= number_format($subtotal) ?>원</strong>
        </div>
        <div class="rp-price-row discount" id="rpDiscountRow" style="display:none">
          <span>쿠폰 할인</span>
          <strong id="rpDiscountAmount">-0원</strong>
        </div>
        <div class="rp-price-row total">
          <span>결제하실 금액</span>
          <strong id="rpTotalAmount"><?= number_format($subtotal) ?>원</strong>
        </div>
      </div>

      <?php if ($error): ?><p class="rp-error"><?= h($error) ?></p><?php endif; ?>

      <?php if ($stockShortage): ?>
      <!-- ===== 재고 없음 — 결제 진행 불가 (소비자·사업자 공통) ===== -->
      <div class="rp-stock-block">
        <p class="rp-stock-title">📦 재고가 없습니다.</p>
        <p class="rp-stock-sub">재고 요청을 하시기 바랍니다 — 입고되면 결제하실 수 있습니다.<br>(현재 재고 <?= (int)$resvStockQty ?>개 / 필요 <?= (int)$resvNeedQty ?>개)</p>
        <a href="<?= BASE_URL ?>/product-detail.php?id=<?= (int)$reservation['product_id'] ?>" class="rp-stock-link">상품 페이지로 가서 재고 요청하기 →</a>
      </div>
      <button type="button" class="rp-pay-btn" disabled>재고가 없어 결제할 수 없습니다</button>
      <?php else: ?>
      <form method="post" id="rpPayForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="user_coupon_id" id="rpCouponId" value="">
        <input type="hidden" name="payment_method" id="rpPaymentMethod" value="bank_transfer">
        <button type="submit" class="rp-pay-btn" id="rpPayBtn">결제하기</button>
      </form>
      <a href="<?= BASE_URL ?>/mypage.php#myreservations" class="rp-mypage-link">나중에 결제하기 (마이페이지에서 결제)</a>
      <?php endif; ?>
      <p class="rp-note">무통장입금/가상계좌 선택 시 예약이 먼저 확정되며, 입금 확인 후 방문 일정이 최종 확정됩니다.</p>
    </div>
  </div>
</div>

<style>
.rp-wrap{max-width:960px;margin:0 auto;padding:28px 20px 80px;}
.rp-steps{display:flex;gap:8px;margin-bottom:24px;}
.rp-step{flex:1;text-align:center;font-size:13px;font-weight:700;color:#94a3b8;padding:10px;border-radius:10px;background:#f1f5f9;}
.rp-step.active{background:#0f172a;color:#fff;}
.rp-step.done{background:#dbeafe;color:#1d4ed8;}
.rp-grid{display:grid;grid-template-columns:1.5fr 1fr;gap:20px;align-items:start;}
.rp-card,.rp-summary{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;}
.rp-card-title{font-size:16px;font-weight:800;color:#0f172a;margin:0 0 14px;padding-bottom:10px;border-bottom:2px solid #0f172a;}
.rp-card-title:not(:first-child){margin-top:26px;}
.rp-resv-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;}
.rp-resv-no{font-size:11px;font-weight:800;color:#94a3b8;letter-spacing:.5px;margin-bottom:6px;}
.rp-resv-product strong{font-size:16px;font-weight:800;color:#0f172a;display:block;line-height:1.4;}
.rp-resv-product span{font-size:12px;color:#64748b;}
.rp-resv-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;font-size:12px;color:#475569;font-weight:600;}
.rp-coupon-empty{font-size:13px;color:#94a3b8;line-height:1.7;padding:14px;background:#f8fafc;border-radius:10px;margin:0;}
.rp-coupon-select label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;}
.rp-coupon-select select{width:100%;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;transition:border-color .15s;}
.rp-coupon-select select:focus{outline:none;border-color:#2563eb;}
.rp-pay-methods{display:flex;flex-direction:column;gap:8px;}
.rp-pay-method{display:flex;align-items:center;gap:12px;padding:14px 16px;border:1.5px solid #e2e8f0;border-radius:12px;cursor:pointer;transition:border-color .15s,background .15s;}
.rp-pay-method:hover{border-color:#cbd5e1;}
.rp-pay-method:has(input:checked){border-color:#2563eb;background:#eff6ff;}
.rp-pay-method input{display:none;}
.rp-pay-icon{font-size:20px;}
.rp-pay-label{font-size:14px;font-weight:800;color:#0f172a;}
.rp-pay-sub{font-size:11px;color:#64748b;margin-left:auto;}
.rp-price-rows{display:flex;flex-direction:column;gap:10px;margin-bottom:18px;}
.rp-price-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;color:#475569;}
.rp-price-row strong{font-weight:700;color:#0f172a;}
.rp-price-row.discount strong{color:#2563eb;}
.rp-price-row.total{padding-top:12px;border-top:1px dashed #e2e8f0;}
.rp-price-row.total span{font-size:13px;font-weight:800;color:#0f172a;}
.rp-price-row.total strong{font-size:22px;font-weight:900;color:#2563eb;}
.rp-error{background:#fef2f2;color:#b91c1c;font-size:13px;font-weight:600;padding:10px 14px;border-radius:8px;margin-bottom:12px;}
/* ===== 재고 없음 — 결제 차단 박스 ===== */
.rp-stock-block{background:#fef2f2;border:1.5px solid #fecaca;border-radius:12px;padding:16px;margin-bottom:14px;}
.rp-stock-title{margin:0 0 6px;font-size:15px;font-weight:800;color:#b91c1c;}
.rp-stock-sub{margin:0 0 10px;font-size:12.5px;color:#7f1d1d;line-height:1.7;}
.rp-stock-link{display:inline-block;font-size:13px;font-weight:800;color:#1d4ed8;text-decoration:none;border-bottom:2px solid #bfdbfe;padding-bottom:2px;}
.rp-stock-link:hover{border-bottom-color:#1d4ed8;}
.rp-pay-btn:disabled{opacity:.55;cursor:not-allowed;}
.rp-pay-btn{width:100%;height:50px;border:none;border-radius:12px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-size:16px;font-weight:800;cursor:pointer;box-shadow:0 6px 16px rgba(37,99,235,.3);transition:transform .12s,box-shadow .12s;}
.rp-pay-btn:hover{transform:translateY(-1px);}
.rp-pay-btn:disabled{opacity:.6;cursor:not-allowed;transform:none;}
.rp-mypage-link{display:block;text-align:center;font-size:12px;color:#64748b;margin-top:12px;text-decoration:none;}
.rp-mypage-link:hover{color:#2563eb;}
.rp-note{font-size:11px;color:#94a3b8;line-height:1.6;margin-top:14px;}
@media(max-width:768px){
  .rp-wrap{padding:20px 14px 70px;}
  .rp-grid{grid-template-columns:1fr;}
  .rp-step{font-size:11px;padding:8px 4px;}
  .rp-card,.rp-summary{padding:18px;}
  .rp-pay-sub{display:none;}
}
</style>

<script>
(function(){
  const couponSelect = document.getElementById('rpCouponSelect');
  const couponIdInput = document.getElementById('rpCouponId');
  const discountRow = document.getElementById('rpDiscountRow');
  const discountAmountEl = document.getElementById('rpDiscountAmount');
  const totalEl = document.getElementById('rpTotalAmount');
  const payMethodInput = document.getElementById('rpPaymentMethod');
  const payBtn = document.getElementById('rpPayBtn');
  const subtotal = <?= $subtotal ?>;

  // 쿠폰 선택 → 금액 재계산
  if (couponSelect) {
    couponSelect.addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      const discount = parseInt(opt.dataset.discount || '0', 10) || 0;
      couponIdInput.value = opt.value || '';
      if (discount > 0) {
        discountRow.style.display = 'flex';
        discountAmountEl.textContent = '-' + discount.toLocaleString('ko-KR') + '원';
      } else {
        discountRow.style.display = 'none';
      }
      totalEl.textContent = Math.max(0, subtotal - discount).toLocaleString('ko-KR') + '원';
    });
  }

  // 결제수단 → hidden input 동기화
  document.querySelectorAll('.rp-pay-method input[type=radio]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      payMethodInput.value = this.value;
    });
  });

  // 제출 버튼 상태
  document.getElementById('rpPayForm')?.addEventListener('submit', function () {
    payBtn.disabled = true;
    payBtn.textContent = '결제 처리 중...';
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
