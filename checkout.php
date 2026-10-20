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
// [FIX] 옵션(DOT)이 있으면 옵션 자신의 price_sale이 실제 판매가다.
//       상품의 price_sale은 옵션이 없을 때만 쓰는 기본가이며,
//       extra_price는 실제로 사용되지 않는 레거시 컬럼이므로 가격 계산에서 제외한다.
if ($mode === 'buynow') {
    $buyNow = $_SESSION['buy_now'] ?? null;
    if (!$buyNow) {
        flash('error', '구매하실 상품 정보를 찾을 수 없습니다. 다시 시도해주세요.');
        redirect('/product-list.php');
    }

    $stmt = $pdo->prepare('
        SELECT :qty AS qty, p.id AS product_id, p.name, p.price_sale, p.stock AS product_stock,
               o.id AS option_id, o.size, o.price_sale AS option_price_sale, o.stock_qty AS option_stock
        FROM tt_products p
        LEFT JOIN tt_product_options o ON o.id = :option_id AND o.product_id = p.id
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
               o.id AS option_id, o.size, o.price_sale AS option_price_sale, o.stock_qty AS option_stock
        FROM tt_carts c
        JOIN tt_products p ON p.id = c.product_id
        LEFT JOIN tt_product_options o ON o.id = c.option_id
        WHERE c.user_id = :uid
    ');
    $stmt->execute(['uid' => $uid]);
    $items = $stmt->fetchAll();

    if (!$items) redirect('/cart.php');
}

/** [FIX] 옵션이 있으면 옵션의 price_sale, 없으면 상품의 price_sale을 실제 단가로 사용 */
function ck_unit_price(array $it): int {
    return $it['option_id']
        ? (int)$it['option_price_sale']
        : (int)$it['price_sale'];
}

$subtotalPreview = array_sum(array_map(fn($i) => ck_unit_price($i) * (int)$i['qty'], $items));

/* ===== [v46] 사업자 회원은 쿠폰 사용 불가 — 쿠폰 목록 조회/노출/적용 전부 제외 ===== */
$isBusinessUser = Auth::isBusiness();

/* ===== 사용 가능한 쿠폰 목록 조회 (사업자 제외 — 사용 안 함 + 기간 유효) ===== */
$availableCoupons = [];
if (!$isBusinessUser) {
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
}

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
    /* [v46] 사업자 회원은 쿠폰 적용 불가 — 쿠폰 id가 넘어와도 무시 */
    $userCouponId   = $isBusinessUser
        ? null
        : (trim($_POST['user_coupon_id'] ?? '') === '' ? null : (int)$_POST['user_coupon_id']);

    /* [v45→v46] 결제수단 선택 — 소비자·사업자 공통 5종(무통장/가상계좌/카드/네이버페이/카카오페이).
       PG 연결 전이라 어떤 수단을 골라도 주문은 '입금/확인 대기(pending)'로 접수되고
       결제수단이 주문에 기록된다. PG 연결 후 카드·간편결제는 PG 창으로 연결하면 된다. */
    $paymentMethod  = trim($_POST['payment_method'] ?? '') ?: 'bank_transfer';
    $paymentMethods = payment_method_labels();
    if (!isset($paymentMethods[$paymentMethod])) $paymentMethod = 'bank_transfer';

    /* [v46] 현금영수증 — 무통장입금/가상계좌(현금결제)일 때만 요청 가능
       income=소득공제(휴대폰 번호) / expense=지출증빙(사업자번호) */
    $cashReceiptType = trim($_POST['cash_receipt_type'] ?? 'none') ?: 'none';
    if (!in_array($cashReceiptType, ['none', 'income', 'expense'], true)) $cashReceiptType = 'none';
    if (!is_cash_payment_method($paymentMethod)) $cashReceiptType = 'none';
    $cashReceiptNo = preg_replace('/[^0-9\-]/', '', trim($_POST['cash_receipt_no'] ?? ''));
    if ($cashReceiptNo === '-' ) $cashReceiptNo = '';
    $cashReceiptNo = mb_substr($cashReceiptNo, 0, 30);
    if ($cashReceiptType === 'none') $cashReceiptNo = '';

    $_SESSION['_old'] = [
        'recipient_name'  => $recipientName,
        'recipient_phone' => $recipientPhone,
        'zipcode'         => $zipcode,
        'address1'        => $address1,
        'address2'        => $address2,
        'memo'            => $memo,
        'cash_receipt_type' => $cashReceiptType,
        'cash_receipt_no'   => $cashReceiptNo,
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

    /* [v46] 현금영수증 번호 검증 — 유형별 형식 확인 */
    if ($cashReceiptType === 'income' && !preg_match('/^01[0-9]-?\d{3,4}-?\d{4}$/', $cashReceiptNo)) {
        flash('error', '현금영수증 소득공제는 휴대폰 번호(예: 010-1234-5678)를 입력해주세요.');
        redirect('/checkout.php' . ($mode === 'buynow' ? '?mode=buynow' : ''));
    }
    if ($cashReceiptType === 'expense' && !preg_match('/^\d{10}$|^\d{12}$/', $cashReceiptNo)) {
        flash('error', '현금영수증 지출증빙은 사업자번호(10~12자리 숫자)를 입력해주세요.');
        redirect('/checkout.php' . ($mode === 'buynow' ? '?mode=buynow' : ''));
    }

    $recipientAddr = "({$zipcode}) {$address1}" . ($address2 !== '' ? " {$address2}" : '');

    // [FIX] get_setting()은 내부적으로 ensure_settings_table()을 호출해
    // CREATE TABLE IF NOT EXISTS(DDL)를 실행할 수 있다. DDL이 실행되는 순간
    // MySQL/MariaDB는 현재 트랜잭션을 암묵적으로 커밋해버리기 때문에,
    // 트랜잭션 안에서 이 함수를 호출하면 이후 catch 블록의 rollBack()이
    // "There is no active transaction" 예외를 던지며 500 오류로 튄다.
    // 반드시 beginTransaction() 이전에 값을 미리 가져와 둔다.
    $freeShippingMin    = (int) get_setting('shipping_free_min', (string)FREE_SHIPPING_MIN);
    $shippingFeeDefault = (int) get_setting('shipping_fee_default', (string)SHIPPING_FEE_DEFAULT);

    // [v31] 재고 이력 테이블 ensure 도 DDL이므로 트랜잭션 시작 전에 실행
    // (record_stock_movement 를 트랜잭션 안에서 호출하기 때문)
    ensure_stock_movement_tables();
    // [v31] 신규 설치 DB의 tt_orders 에 recipient_addr 등 운송장/구매확정 컬럼 보장
    ensure_order_tracking_columns();

    try {
        $pdo->beginTransaction();

        $total = 0;
        $orderItemsData = [];

        foreach ($items as $it) {
            if ($it['option_id']) {
                $lock = $pdo->prepare('SELECT stock_qty FROM tt_product_options WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $it['option_id']]);
                $row = $lock->fetch();
                if (!$row) {
                    throw new RuntimeException("재고가 없습니다. 재고 요청을 하시기 바랍니다. — {$it['name']}");
                }
                if ((int)$row['stock_qty'] <= 0) {
                    throw new RuntimeException("재고가 없습니다. 재고 요청을 하시기 바랍니다. — {$it['name']}");
                }
                if ((int)$row['stock_qty'] < (int)$it['qty']) {
                    throw new RuntimeException("재고가 부족합니다. — {$it['name']} (남은 재고 " . (int)$row['stock_qty'] . "개)");
                }
                $pdo->prepare('UPDATE tt_product_options SET stock_qty = stock_qty - :q WHERE id = :id')
                    ->execute(['q' => $it['qty'], 'id' => $it['option_id']]);
                /* 재고 입출고 이력 기록 (출고) */
                record_stock_movement((int)$it['product_id'], (int)$it['option_id'], 'out', -1 * (int)$it['qty'],
                    (int)$row['stock_qty'] - (int)$it['qty'], "주문 출고 — {$it['name']}", '주문자동');
            } else {
                $lock = $pdo->prepare('SELECT stock FROM tt_products WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $it['product_id']]);
                $row = $lock->fetch();
                if (!$row) {
                    throw new RuntimeException("재고가 없습니다. 재고 요청을 하시기 바랍니다. — {$it['name']}");
                }
                if ((int)$row['stock'] <= 0) {
                    throw new RuntimeException("재고가 없습니다. 재고 요청을 하시기 바랍니다. — {$it['name']}");
                }
                if ((int)$row['stock'] < (int)$it['qty']) {
                    throw new RuntimeException("재고가 부족합니다. — {$it['name']} (남은 재고 " . (int)$row['stock'] . "개)");
                }
                $pdo->prepare('UPDATE tt_products SET stock = stock - :q WHERE id = :id')
                    ->execute(['q' => $it['qty'], 'id' => $it['product_id']]);
                /* 재고 입출고 이력 기록 (출고) */
                record_stock_movement((int)$it['product_id'], null, 'out', -1 * (int)$it['qty'],
                    (int)$row['stock'] - (int)$it['qty'], "주문 출고 — {$it['name']}", '주문자동');
            }

            // [FIX] 옵션 자체 가격(price_sale)을 실제 단가로 사용 (247,000원 오표시 버그 수정)
            $unitPrice = ck_unit_price($it);
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

        /* ===== 쿠폰 재검증 및 할인 계산 (서버가 최종 권위, 클라이언트 값은 신뢰하지 않음) =====
           [FIX] 예전 코드는 'SELECT uc.id, uc.status, c.*, c.id AS coupon_id'처럼
           c.*로 컬럼을 통째로 가져왔는데, tt_coupons에도 id/status 컬럼이 있어서
           PDO가 뒤에 나온 값으로 앞의 값을 덮어썼다. 그 결과 uc.status(쿠폰 사용여부:
           unused/used)가 c.status(쿠폰 자체 활성여부: active)로 뒤바뀌어,
           쿠폰을 쓸 때마다 "이미 사용했거나 만료된 쿠폰입니다" 예외가 무조건
           발생했다. 이게 주문하기를 누르면 상품 페이지로 튕겨나가고, 트랜잭션이
           롤백되어 쿠폰도 그대로 남아있던 진짜 원인이었다.
           컬럼을 전부 명시적으로 나열하고 별칭을 붙여 충돌을 제거한다. */
        $discountAmount = 0;
        $validUserCouponId = null;

        if ($userCouponId !== null) {
            $ucStmt = $pdo->prepare('
                SELECT uc.id AS uc_id, uc.status AS uc_status,
                       c.id AS coupon_id, c.discount_type, c.discount_value,
                       c.max_discount_amount, c.min_order_amount, c.valid_until
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
            if ($ucRow['uc_status'] !== 'unused') {
                throw new RuntimeException('이미 사용했거나 만료된 쿠폰입니다.');
            }
            if ($ucRow['valid_until'] && strtotime($ucRow['valid_until']) < time()) {
                throw new RuntimeException('쿠폰 유효기간이 만료되었습니다.');
            }
            if ($total < (int)$ucRow['min_order_amount']) {
                throw new RuntimeException('쿠폰 사용을 위한 최소 주문금액(' . number_format((int)$ucRow['min_order_amount']) . '원)을 충족하지 않습니다.');
            }

            $discountAmount = calc_coupon_discount($ucRow, $total);
            $validUserCouponId = (int)$ucRow['uc_id'];
        }

        // [FIX] 설정값은 트랜잭션 시작 전에 이미 조회해 둔 변수를 그대로 사용한다.
        $shippingFee = $total >= $freeShippingMin ? 0 : $shippingFeeDefault;
        $payableAmount = max(0, $total + $shippingFee - $discountAmount);
        $orderNo = 'TT' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

        /* [v48] 주문 접수 시 결제상태 저장 — 예약결제창(reservation-pay)과 동일 기준:
           카드·네이버페이·카카오페이(비현금)는 즉시 결제완료 처리,
           무통장입금·가상계좌(현금)는 입금 확인(관리자 결제확정) 후 결제완료.
           [v47]까지 주문은 payment_status를 아예 저장하지 않아(컬럼 기본값 unpaid 고정)
           마이페이지 세금계산서의 "결제완료 주문" 목록이 비어 있던 것이 이 건의 직접 원인. */
        $isCashPayAtOrder = is_cash_payment_method($paymentMethod);
        $initStatus     = $isCashPayAtOrder ? 'pending' : 'paid';
        $initPayStatus  = $isCashPayAtOrder ? 'unpaid'  : 'paid';

        $pdo->prepare('
            INSERT INTO tt_orders (order_no, user_id, total_amount, shipping_fee, discount_amount, user_coupon_id,
                                    recipient_name, recipient_phone, recipient_addr, memo, payment_method,
                                    cash_receipt_type, cash_receipt_no, payment_status, status)
            VALUES (:no, :uid, :total, :ship, :discount, :ucid, :rname, :rphone, :raddr, :memo, :pmethod,
                    :crtype, :crno, :pst, :st)
        ')->execute([
            'no' => $orderNo, 'uid' => $uid, 'total' => $payableAmount, 'ship' => $shippingFee,
            'discount' => $discountAmount, 'ucid' => $validUserCouponId,
            'rname' => $recipientName, 'rphone' => $recipientPhone,
            'raddr' => $recipientAddr, 'memo' => $memo, 'pmethod' => $paymentMethod,
            'crtype' => $cashReceiptType, 'crno' => ($cashReceiptNo !== '' ? $cashReceiptNo : null),
            'pst' => $initPayStatus, 'st' => $initStatus,
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

        /* [신규] 이카운트 동기화 큐 적재 — 주문번호가 확정된 '이 시점'에서만 가능하다.
           (재고 차감 루프 안에서는 아직 $orderId가 없어 주문과 연결할 수 없었다.)
           품목코드 미매핑 상품은 함수 내부에서 조용히 스킵되고, 함수 자체가
           없는 서버에서도 결제가 막히지 않도록 function_exists()로 방어한다. */
        if (function_exists('ecount_queue_stock_change')) {
            foreach ($orderItemsData as $d) {
                ecount_queue_stock_change(
                    (int)$d['product_id'],
                    -1 * (int)$d['qty'],
                    "주문 출고 — {$d['name_snap']} (주문번호 {$orderNo})",
                    $orderId
                );
            }
        }

        /* [v46→v48] 상태로그에 결제수단 + 결제상태 + 현금영수증 요청까지 기록.
           비현금(카드·간편결제)은 즉시 결제완료 처리, 현금수단은 입금 확인 후 확정. */
        $statusMemo = '주문 생성 (결제수단: ' . $paymentMethods[$paymentMethod] . ')';
        if (!$isCashPayAtOrder) {
            $statusMemo .= ' · 결제완료 처리';
        }
        if ($cashReceiptType !== 'none') {
            $statusMemo .= ' · 현금영수증 ' . ($cashReceiptType === 'income' ? '소득공제' : '지출증빙') . ' 요청 (' . $cashReceiptNo . ')'
                . ($cashReceiptType === 'income' ? ' — 입금 확인 시 자동 발행' : '');
        }
        $pdo->prepare('INSERT INTO tt_order_status_logs (order_id, status, memo) VALUES (:oid, :st, :pmemo)')
            ->execute(['oid' => $orderId, 'st' => $initStatus, 'pmemo' => $statusMemo]);

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
        // [FIX] rollBack() 호출 전, 실제로 활성 트랜잭션이 남아있는지 먼저 확인한다.
        // (DDL 암묵적 커밋 등으로 이미 트랜잭션이 끊겼는데 rollBack()을 또 부르면
        // "There is no active transaction" PDOException이 새로 발생해 그대로
        // 튀어오르면서 Apache 기본 500 화면이 뜬다.)
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
/* [배송비 설정 반영] 화면 미리보기도 동일하게 설정값 기준으로 계산 (GET 렌더링, 트랜잭션과 무관하므로 안전) */
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
/* [v45] 결제수단 선택 카드 */
.ck-pay-methods{display:flex;flex-direction:column;gap:10px;}
.ck-pay-method{display:flex;align-items:center;gap:12px;border:1.5px solid #e2e8f0;border-radius:12px;
  padding:13px 16px;cursor:pointer;transition:border-color .15s,background .15s;background:#fff;}
.ck-pay-method:hover{border-color:#93c5fd;}
.ck-pay-method input{position:absolute;opacity:0;pointer-events:none;}
.ck-pay-method:has(input:checked){border-color:#2563eb;background:#eff6ff;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
.ck-pay-icon{font-size:22px;line-height:1;}
.ck-pay-body{display:flex;flex-direction:column;gap:2px;}
.ck-pay-label{font-size:14px;font-weight:800;color:#0f172a;}
.ck-pay-sub{font-size:12px;color:#64748b;font-weight:600;}
.ck-pay-account{margin-top:14px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;padding:12px 14px;font-size:13px;color:#475569;}
.ck-pay-account p{margin:2px 0;}
.ck-pay-account .pay-account{font-weight:800;color:#0f172a;}
.ck-pay-note{margin-top:10px;font-size:12px;color:#94a3b8;}
/* [v46] 현금영수증 블록 */
.ck-cash-receipt{margin-top:14px;background:#f0fdfa;border:1px solid #99f6e4;border-radius:12px;padding:14px 16px;}
.ck-cr-title{font-size:13.5px;font-weight:800;color:#0f766e;margin:0 0 10px;}
.ck-cr-options{display:flex;gap:8px;flex-wrap:wrap;}
.ck-cr-opt{display:inline-flex;align-items:center;gap:6px;border:1.5px solid #ccfbf1;background:#fff;border-radius:10px;
  padding:8px 12px;cursor:pointer;font-size:13px;font-weight:700;color:#334155;transition:border-color .15s,background .15s;}
.ck-cr-opt:hover{border-color:#5eead4;}
.ck-cr-opt input{position:absolute;opacity:0;pointer-events:none;}
.ck-cr-opt:has(input:checked){border-color:#0d9488;background:#ecfdf5;box-shadow:0 0 0 3px rgba(13,148,136,.12);}
.ck-cr-opt small{font-weight:600;color:#64748b;font-size:11.5px;}
.ck-cr-number{margin-top:10px;}
.ck-cr-number input{width:100%;max-width:320px;border:1px solid #99f6e4;border-radius:10px;padding:10px 13px;
  font-size:14px;font-weight:700;background:#fff;letter-spacing:.3px;}
.ck-cr-hint{font-size:12px;color:#0f766e;font-weight:600;margin:6px 0 0;}
.ck-cr-note{font-size:11.5px;color:#94a3b8;margin:10px 0 0;}
@media(max-width:640px){.ck-pay-methods{flex-direction:column;}.ck-cr-options{flex-direction:column;}}
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
            <td><?= format_price(ck_unit_price($it) * (int)$it['qty']) ?></td>
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

    <?php /* [v46] 사업자 회원에게는 쿠폰 사용 섹션 자체를 노출하지 않음 */ ?>
    <?php if (!$isBusinessUser): ?>
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
    <?php endif; ?>

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

    <?php /* [v45→v46] 결제수단 선택 — 소비자·사업자 공통 5종(네이버페이·카카오페이 추가).
            PG 연결 전이라 현재는 어떤 수단을 골라도 주문이 '입금/확인 대기'로 접수되고,
            카드·간편결제는 PG 연결 후 즉시 결제로 전환된다. */ ?>
    <section class="checkout-section payment-notice">
      <h2>결제 방법</h2>
      <div class="ck-pay-methods">
        <label class="ck-pay-method">
          <input type="radio" name="payment_method" value="bank_transfer" checked>
          <span class="ck-pay-icon">🏦</span>
          <span class="ck-pay-body">
            <span class="ck-pay-label">무통장입금</span>
            <span class="ck-pay-sub">주문 후 계좌로 입금하시면 확인 후 배송됩니다</span>
          </span>
        </label>
        <label class="ck-pay-method">
          <input type="radio" name="payment_method" value="virtual_account">
          <span class="ck-pay-icon">💳</span>
          <span class="ck-pay-body">
            <span class="ck-pay-label">가상계좌</span>
            <span class="ck-pay-sub">전용 계좌로 입금 시 자동 결제 처리</span>
          </span>
        </label>
        <label class="ck-pay-method">
          <input type="radio" name="payment_method" value="card">
          <span class="ck-pay-icon">💠</span>
          <span class="ck-pay-body">
            <span class="ck-pay-label">신용·체크카드</span>
            <span class="ck-pay-sub">PG 연결 후 즉시 결제 — 연결 전에는 주문만 접수됩니다</span>
          </span>
        </label>
        <label class="ck-pay-method">
          <input type="radio" name="payment_method" value="naver_pay">
          <span class="ck-pay-icon">🟢</span>
          <span class="ck-pay-body">
            <span class="ck-pay-label">네이버페이</span>
            <span class="ck-pay-sub">네이버페이(포인트 결제) — PG 연결 후 즉시 결제</span>
          </span>
        </label>
        <label class="ck-pay-method">
          <input type="radio" name="payment_method" value="kakao_pay">
          <span class="ck-pay-icon">🟡</span>
          <span class="ck-pay-body">
            <span class="ck-pay-label">카카오페이</span>
            <span class="ck-pay-sub">카카오페이(간편결제) — PG 연결 후 즉시 결제</span>
          </span>
        </label>
      </div>
      <div class="ck-pay-account" id="ckPayAccount">
        <p><strong>무통장입금 안내</strong></p>
        <p>주문 완료 후 아래 계좌로 입금해주시면 관리자가 확인 후 상품을 준비합니다.</p>
        <p class="pay-account">국민은행 123456-04-123456 (예금주: 타이어탑)</p>
      </div>

      <?php /* [v46] 현금영수증 — 무통장입금·가상계좌(현금결제) 선택 시에만 노출 (JS로 토글) */ ?>
      <div class="ck-cash-receipt" id="ckCashReceipt" hidden>
        <p class="ck-cr-title">🧾 현금영수증 발행</p>
        <div class="ck-cr-options">
          <label class="ck-cr-opt">
            <input type="radio" name="cash_receipt_type" value="none" checked>
            <span>발행 안함</span>
          </label>
          <label class="ck-cr-opt">
            <input type="radio" name="cash_receipt_type" value="income">
            <span>소득공제 <small>(휴대폰 번호)</small></span>
          </label>
          <label class="ck-cr-opt">
            <input type="radio" name="cash_receipt_type" value="expense">
            <span>지출증빙 <small>(사업자번호)</small></span>
          </label>
        </div>
        <div class="ck-cr-number" id="ckCrNumber" hidden>
          <input type="text" name="cash_receipt_no" id="ckCrNoInput"
                 value="<?= h(old('cash_receipt_no')) ?>"
                 inputmode="numeric" placeholder="번호 입력" maxlength="14">
          <p class="ck-cr-hint" id="ckCrHint"></p>
        </div>
        <p class="ck-cr-note">현금영수증은 입금 확인 후 발행 처리됩니다. (마이페이지·주문내역에서 확인 가능)</p>
      </div>

      <p class="ck-pay-note">결제 수단은 주문 정보에 함께 저장되며, 마이페이지 주문내역에서 확인하실 수 있습니다.</p>
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
    const row = this.closest('[data-field-row]');
    if (row) row.classList.remove('has-error');
  });
});

/* ===== [v46] 결제수단 선택 — 수단별 안내 교체 + 현금영수증 블록 토글 ===== */
(function(){
  const accountBox = document.getElementById('ckPayAccount');
  const cashBox    = document.getElementById('ckCashReceipt');
  const crNumber   = document.getElementById('ckCrNumber');
  const crHint     = document.getElementById('ckCrHint');
  const crNoInput  = document.getElementById('ckCrNoInput');

  const ACCOUNT_HTML = {
    bank_transfer:
      '<p><strong>무통장입금 안내</strong></p>' +
      '<p>주문 완료 후 아래 계좌로 입금해주시면 관리자가 확인 후 상품을 준비합니다.</p>' +
      '<p class="pay-account">국민은행 123456-04-123456 (예금주: 타이어탑)</p>',
    virtual_account:
      '<p><strong>가상계좌 안내</strong></p>' +
      '<p>주문 완료 후 안내드리는 전용 계좌로 입금해주시면 자동 결제 처리됩니다.</p>' +
      '<p class="pay-account">국민은행 123456-04-123456 (예금주: 타이어탑)</p>',
    card:
      '<p><strong>카드결제 안내</strong></p>' +
      '<p>카드결제는 PG(결제대행사) 연결 후 즉시 결제로 전환됩니다.</p>' +
      '<p>연결 전에는 주문만 접수되며, 입금·결제는 별도 안내드립니다.</p>',
    naver_pay:
      '<p><strong>🟢 네이버페이 안내</strong></p>' +
      '<p>네이버페이 결제는 PG(결제대행사) 연결 후 즉시 결제로 전환됩니다.</p>' +
      '<p>연결 전에는 주문만 접수되며, 결제는 별도 안내드립니다.</p>',
    kakao_pay:
      '<p><strong>🟡 카카오페이 안내</strong></p>' +
      '<p>카카오페이 결제는 PG(결제대행사) 연결 후 즉시 결제로 전환됩니다.</p>' +
      '<p>연결 전에는 주문만 접수되며, 결제는 별도 안내드립니다.</p>'
  };
  const CASH_METHODS = ['bank_transfer', 'virtual_account'];

  function crHintFor(type) {
    if (type === 'income')  return '소득공제용 — 휴대폰 번호를 입력해주세요. (예: 010-1234-5678)';
    if (type === 'expense') return '지출증빙용 — 사업자번호를 입력해주세요. (10~12자리 숫자)';
    return '';
  }

  function syncCrNumber() {
    const checked = document.querySelector('input[name="cash_receipt_type"]:checked');
    const type = checked ? checked.value : 'none';
    if (crNumber) crNumber.hidden = (type === 'none');
    if (crHint) crHint.textContent = crHintFor(type);
    if (crNoInput) crNoInput.placeholder = type === 'income' ? '010-1234-5678' : (type === 'expense' ? '123-45-67890' : '번호 입력');
  }

  document.querySelectorAll('input[name="cash_receipt_type"]').forEach(function(radio){
    radio.addEventListener('change', syncCrNumber);
  });

  /* [v46] 검증 실패로 되돌아왔을 때 이전 선택 복원 */
  (function restoreCrOld(){
    const oldType = <?= json_encode(old('cash_receipt_type')) ?>;
    if (oldType === 'income' || oldType === 'expense') {
      const target = document.querySelector('input[name="cash_receipt_type"][value="' + oldType + '"]');
      if (target) { target.checked = true; }
    }
    syncCrNumber();
  })();

  function syncPayMethod() {
    const checked = document.querySelector('input[name="payment_method"]:checked');
    const method = checked ? checked.value : 'bank_transfer';
    if (accountBox) accountBox.innerHTML = ACCOUNT_HTML[method] || ACCOUNT_HTML.bank_transfer;
    const isCash = CASH_METHODS.indexOf(method) !== -1;
    if (cashBox) cashBox.hidden = !isCash;
    if (!isCash) {
      /* 현금이 아닌 수단으로 바뀌면 현금영수증 선택 초기화 */
      const none = document.querySelector('input[name="cash_receipt_type"][value="none"]');
      if (none) none.checked = true;
      syncCrNumber();
    }
  }

  document.querySelectorAll('input[name="payment_method"]').forEach(function(radio){
    radio.addEventListener('change', syncPayMethod);
  });

  syncPayMethod();
})();

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
