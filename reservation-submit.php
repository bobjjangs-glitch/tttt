<?php
// reservation-submit.php — 방문 예약 접수 처리
require_once __DIR__ . '/core/bootstrap.php';
ensure_reservations_table();

if (!is_post()) {
    json_response(['success' => false, 'message' => '잘못된 요청입니다.']);
}

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    json_response(['success' => false, 'message' => '유효하지 않은 요청입니다. 페이지를 새로고침 후 다시 시도해주세요.']);
}

/* ===== [v41] 방문 예약은 일반회원만 — 사업자는 택배 주문(바로구매/장바구니)만 가능 ===== */
if (Auth::isLoggedIn() && Auth::isBusiness()) {
    json_response(['success' => false, 'message' => '매장 예약은 일반 회원만 이용할 수 있습니다. 사업자 회원은 택배배송으로 주문해 주세요.']);
}

$productId  = (int)($_POST['product_id'] ?? 0);
$optionId   = (int)($_POST['option_id'] ?? 0) ?: null;
$qty        = max(1, min(20, (int)($_POST['qty'] ?? 1)));
$visitorName = trim($_POST['visitor_name'] ?? '');
$visitorPhone = preg_replace('/[^0-9]/', '', $_POST['visitor_phone'] ?? '');
$visitDate  = trim($_POST['visit_date'] ?? '');
$visitTime  = trim($_POST['visit_time'] ?? '');
$visitType  = trim($_POST['visit_type'] ?? 'tire') ?: 'tire';
$carInfo    = trim($_POST['car_info'] ?? '');
$memo       = trim($_POST['memo'] ?? '');

/* 방문 구분(타이어/정비/기타) — 목록 밖 값이면 타이어 예약으로 취급 */
if (!array_key_exists($visitType, resv_visit_type_labels())) {
    $visitType = 'tire';
}

/* ===== 유효성 검사 ===== */
if ($productId <= 0) {
    json_response(['success' => false, 'message' => '상품 정보가 올바르지 않습니다.']);
}
if ($visitorName === '') {
    json_response(['success' => false, 'message' => '예약자 이름을 입력해주세요.']);
}
if (!preg_match('/^01[0-9]\d{7,8}$/', $visitorPhone)) {
    json_response(['success' => false, 'message' => '휴대폰 번호 형식이 올바르지 않습니다. (예: 010-1234-5678)']);
}
if ($visitDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) {
    json_response(['success' => false, 'message' => '방문 예정일을 선택해주세요.']);
}
// 과거 날짜 방지
if (strtotime($visitDate) < strtotime(date('Y-m-d'))) {
    json_response(['success' => false, 'message' => '방문 예정일은 오늘 이후로 선택해주세요.']);
}
// 30일 이후 방지
if (strtotime($visitDate) > strtotime('+30 days')) {
    json_response(['success' => false, 'message' => '매장 예약은 30일 이내만 가능합니다.']);
}
if ($visitTime === '') {
    json_response(['success' => false, 'message' => '방문 예정 시간을 선택해주세요.']);
}
if (!in_array(substr($visitTime, 0, 5), resv_time_slots(), true)) {
    json_response(['success' => false, 'message' => '방문 예정 시간이 올바르지 않습니다. 예약 양식에서 시간을 다시 선택해주세요.']);
}

/* ===== 시간대별 최대 인원 검사 (관리자 설정 · 취소/노쇼 제외 집계) ===== */
$slotRemaining = resv_slot_remaining($visitDate, substr($visitTime, 0, 5), $visitType);
if ($slotRemaining <= 0) {
    $typeLabel = resv_visit_type_labels()[$visitType];
    json_response(['success' => false, 'message' => "{$visitDate} {$visitTime} {$typeLabel} 시간대는 예약이 마감되었습니다 (시간대별 최대 " . get_resv_slot_max($visitType) . "명). 다른 시간대나 날짜를 선택해주세요."]);
}

/* ===== 상품 정보 스냅샷 ===== */
$pdo = Database::connection();
/* [FIX] 재고 검사를 위해 p.stock 추가 */
$stmt = $pdo->prepare('SELECT p.id, p.name, p.spec, p.price_sale, p.business_price, p.status, p.stock
                       FROM tt_products p WHERE p.id = :id LIMIT 1');
$stmt->execute(['id' => $productId]);
$product = $stmt->fetch();
if (!$product || $product['status'] !== 'active') {
    json_response(['success' => false, 'message' => '해당 상품은 현재 예약할 수 없습니다.']);
}

// 표시 가격 스냅샷 (회원 유형 반영)
$unitPrice = (int)$product['price_sale'];
if (Auth::isLoggedIn() && Auth::isBusiness()) {
    $bizPrice = (int)($product['business_price'] ?? 0);
    if ($bizPrice > 0) {
        $unitPrice = $bizPrice;
    } else {
        $unitPrice = (int)round($unitPrice * (1 - get_business_discount_pct() / 100));
    }
}

// 옵션 정보 + 가용 재고
// [FIX] reservation-pay.php / cart-add.php 와 같은 기준: 옵션이 있으면 옵션 재고, 없으면 상품 재고
$optionSnap = null;
$availStock = (int)$product['stock'];
if ($optionId) {
    $optStmt = $pdo->prepare('SELECT id, size, dot_code, stock_qty FROM tt_product_options WHERE id = :id AND product_id = :pid LIMIT 1');
    $optStmt->execute(['id' => $optionId, 'pid' => $productId]);
    $opt = $optStmt->fetch();
    if ($opt) {
        $optionSnap = $opt['size'] ?: $opt['dot_code'];
        $optionId = (int)$opt['id'];
        $availStock = (int)$opt['stock_qty'];
    } else {
        $optionId = null;
    }
}

/* [FIX] 품절(재고 1 미만) 상품은 예약 접수 자체를 차단.
   예전에는 재고 검사가 없어서 품절 상품도 예약이 접수되고 태블릿 예약판까지 올라왔다. */
if ($availStock < 1) {
    json_response(['success' => false, 'message' => '품절된 상품은 예약할 수 없습니다. 상품 페이지에서 재고 요청을 이용해 주세요.']);
}
if ($availStock < $qty) {
    json_response(['success' => false, 'message' => '재고가 부족합니다. 남은 재고: ' . $availStock . '개']);
}

/* ===== 중복 예약 방지: 같은 전화번호로 같은 날 같은 상품 pending/confirm 예약이 있으면 차단 ===== */
$dupStmt = $pdo->prepare(
    "SELECT id FROM tt_reservations
     WHERE visitor_phone = :phone AND product_id = :pid AND visit_date = :vd
       AND status IN ('pending','confirmed') LIMIT 1"
);
$dupStmt->execute(['phone' => $visitorPhone, 'pid' => $productId, 'vd' => $visitDate]);
if ($dupStmt->fetch()) {
    json_response(['success' => false, 'message' => '이미 같은 날짜에 같은 상품의 예약이 접수되어 있습니다. 마이페이지에서 확인해주세요.']);
}

/* ===== 예약번호 생성 (RES-ymmdd-XXXX) ===== */
$resNo = 'RES-' . date('ymd') . '-';
for ($try = 0; $try < 5; $try++) {
    $candidate = $resNo . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $chk = $pdo->prepare('SELECT id FROM tt_reservations WHERE res_no = :no LIMIT 1');
    $chk->execute(['no' => $candidate]);
    if (!$chk->fetch()) { $resNo = $candidate; break; }
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO tt_reservations
            (res_no, user_id, product_id, option_id, product_name_snap, size_snap,
             qty, unit_price, visitor_name, visitor_phone, visit_date, visit_time,
             car_info, memo, visit_type, status, created_at)
         VALUES
            (:res_no, :uid, :pid, :oid, :pname, :size,
             :qty, :unit_price, :vname, :vphone, :vdate, :vtime,
             :car, :memo, :vtype, "pending", NOW())'
    );
    $stmt->execute([
        'res_no' => $resNo,
        'uid'    => Auth::isLoggedIn() ? Auth::currentUserId() : null,
        'pid'    => $productId,
        'oid'    => $optionId,
        'pname'  => $product['name'],
        'size'   => $optionSnap ?: ($product['spec'] ?? null),
        'qty'    => $qty,
        'unit_price' => $unitPrice,
        'vname'  => $visitorName,
        'vphone' => $visitorPhone,
        'vdate'  => $visitDate,
        'vtime'  => $visitTime,
        'car'    => $carInfo !== '' ? $carInfo : null,
        'memo'   => $memo !== '' ? $memo : null,
        'vtype'  => $visitType,
    ]);

    /* 차량번호가 있으면 차량 마스터에 자동 등록 (교체주기 관리용 — 교체일은 방문 후 확정) */
    $plate = extract_plate_no($carInfo);
    if ($plate !== '') {
        capture_vehicle([
            'plate_no'   => $plate,
            'user_id'    => Auth::isLoggedIn() ? Auth::currentUserId() : null,
            'owner_name' => $visitorName,
            'owner_phone'=> $visitorPhone,
            'car_model'  => car_model_from_info($carInfo),
        ]);
    }

    json_response([
        'success' => true,
        'message' => '예약이 접수되었습니다. 결제를 진행해주세요.',
        'res_no'  => $resNo,
        'redirect_url' => BASE_URL . '/reservation-pay.php?no=' . urlencode($resNo),
    ]);
} catch (Throwable $e) {
    error_log('[reservation-submit] ' . $e->getMessage());
    json_response(['success' => false, 'message' => '예약 접수 중 오류가 발생했습니다. 잠시 후 다시 시도해주세요.']);
}
<?php
// reservation-submit.php — 방문 예약 접수 처리
require_once __DIR__ . '/core/bootstrap.php';
ensure_reservations_table();

if (!is_post()) {
    json_response(['success' => false, 'message' => '잘못된 요청입니다.']);
}

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    json_response(['success' => false, 'message' => '유효하지 않은 요청입니다. 페이지를 새로고침 후 다시 시도해주세요.']);
}

/* ===== [v41] 방문 예약은 일반회원만 — 사업자는 택배 주문(바로구매/장바구니)만 가능 ===== */
if (Auth::isLoggedIn() && Auth::isBusiness()) {
    json_response(['success' => false, 'message' => '매장 예약은 일반 회원만 이용할 수 있습니다. 사업자 회원은 택배배송으로 주문해 주세요.']);
}

$productId  = (int)($_POST['product_id'] ?? 0);
$optionId   = (int)($_POST['option_id'] ?? 0) ?: null;
$qty        = max(1, min(20, (int)($_POST['qty'] ?? 1)));
$visitorName = trim($_POST['visitor_name'] ?? '');
$visitorPhone = preg_replace('/[^0-9]/', '', $_POST['visitor_phone'] ?? '');
$visitDate  = trim($_POST['visit_date'] ?? '');
$visitTime  = trim($_POST['visit_time'] ?? '');
$visitType  = trim($_POST['visit_type'] ?? 'tire') ?: 'tire';
$carInfo    = trim($_POST['car_info'] ?? '');
$memo       = trim($_POST['memo'] ?? '');

/* 방문 구분(타이어/정비/기타) — 목록 밖 값이면 타이어 예약으로 취급 */
if (!array_key_exists($visitType, resv_visit_type_labels())) {
    $visitType = 'tire';
}

/* ===== 유효성 검사 ===== */
if ($productId <= 0) {
    json_response(['success' => false, 'message' => '상품 정보가 올바르지 않습니다.']);
}
if ($visitorName === '') {
    json_response(['success' => false, 'message' => '예약자 이름을 입력해주세요.']);
}
if (!preg_match('/^01[0-9]\d{7,8}$/', $visitorPhone)) {
    json_response(['success' => false, 'message' => '휴대폰 번호 형식이 올바르지 않습니다. (예: 010-1234-5678)']);
}
if ($visitDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) {
    json_response(['success' => false, 'message' => '방문 예정일을 선택해주세요.']);
}
// 과거 날짜 방지
if (strtotime($visitDate) < strtotime(date('Y-m-d'))) {
    json_response(['success' => false, 'message' => '방문 예정일은 오늘 이후로 선택해주세요.']);
}
// 30일 이후 방지
if (strtotime($visitDate) > strtotime('+30 days')) {
    json_response(['success' => false, 'message' => '매장 예약은 30일 이내만 가능합니다.']);
}
if ($visitTime === '') {
    json_response(['success' => false, 'message' => '방문 예정 시간을 선택해주세요.']);
}
if (!in_array(substr($visitTime, 0, 5), resv_time_slots(), true)) {
    json_response(['success' => false, 'message' => '방문 예정 시간이 올바르지 않습니다. 예약 양식에서 시간을 다시 선택해주세요.']);
}

/* ===== 시간대별 최대 인원 검사 (관리자 설정 · 취소/노쇼 제외 집계) ===== */
$slotRemaining = resv_slot_remaining($visitDate, substr($visitTime, 0, 5), $visitType);
if ($slotRemaining <= 0) {
    $typeLabel = resv_visit_type_labels()[$visitType];
    json_response(['success' => false, 'message' => "{$visitDate} {$visitTime} {$typeLabel} 시간대는 예약이 마감되었습니다 (시간대별 최대 " . get_resv_slot_max($visitType) . "명). 다른 시간대나 날짜를 선택해주세요."]);
}

/* ===== 상품 정보 스냅샷 ===== */
$pdo = Database::connection();
/* [FIX] 재고 검사를 위해 p.stock 추가 */
$stmt = $pdo->prepare('SELECT p.id, p.name, p.spec, p.price_sale, p.business_price, p.status, p.stock
                       FROM tt_products p WHERE p.id = :id LIMIT 1');
$stmt->execute(['id' => $productId]);
$product = $stmt->fetch();
if (!$product || $product['status'] !== 'active') {
    json_response(['success' => false, 'message' => '해당 상품은 현재 예약할 수 없습니다.']);
}

// 표시 가격 스냅샷 (회원 유형 반영)
$unitPrice = (int)$product['price_sale'];
if (Auth::isLoggedIn() && Auth::isBusiness()) {
    $bizPrice = (int)($product['business_price'] ?? 0);
    if ($bizPrice > 0) {
        $unitPrice = $bizPrice;
    } else {
        $unitPrice = (int)round($unitPrice * (1 - get_business_discount_pct() / 100));
    }
}

// 옵션 정보 + 가용 재고
// [FIX] reservation-pay.php / cart-add.php 와 같은 기준: 옵션이 있으면 옵션 재고, 없으면 상품 재고
$optionSnap = null;
$availStock = (int)$product['stock'];
if ($optionId) {
    $optStmt = $pdo->prepare('SELECT id, size, dot_code, stock_qty FROM tt_product_options WHERE id = :id AND product_id = :pid LIMIT 1');
    $optStmt->execute(['id' => $optionId, 'pid' => $productId]);
    $opt = $optStmt->fetch();
    if ($opt) {
        $optionSnap = $opt['size'] ?: $opt['dot_code'];
        $optionId = (int)$opt['id'];
        $availStock = (int)$opt['stock_qty'];
    } else {
        $optionId = null;
    }
}

/* [FIX] 품절(재고 1 미만) 상품은 예약 접수 자체를 차단.
   예전에는 재고 검사가 없어서 품절 상품도 예약이 접수되고 태블릿 예약판까지 올라왔다. */
if ($availStock < 1) {
    json_response(['success' => false, 'message' => '품절된 상품은 예약할 수 없습니다. 상품 페이지에서 재고 요청을 이용해 주세요.']);
}
if ($availStock < $qty) {
    json_response(['success' => false, 'message' => '재고가 부족합니다. 남은 재고: ' . $availStock . '개']);
}

/* ===== 중복 예약 방지: 같은 전화번호로 같은 날 같은 상품 pending/confirm 예약이 있으면 차단 ===== */
$dupStmt = $pdo->prepare(
    "SELECT id FROM tt_reservations
     WHERE visitor_phone = :phone AND product_id = :pid AND visit_date = :vd
       AND status IN ('pending','confirmed') LIMIT 1"
);
$dupStmt->execute(['phone' => $visitorPhone, 'pid' => $productId, 'vd' => $visitDate]);
if ($dupStmt->fetch()) {
    json_response(['success' => false, 'message' => '이미 같은 날짜에 같은 상품의 예약이 접수되어 있습니다. 마이페이지에서 확인해주세요.']);
}

/* ===== 예약번호 생성 (RES-ymmdd-XXXX) ===== */
$resNo = 'RES-' . date('ymd') . '-';
for ($try = 0; $try < 5; $try++) {
    $candidate = $resNo . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $chk = $pdo->prepare('SELECT id FROM tt_reservations WHERE res_no = :no LIMIT 1');
    $chk->execute(['no' => $candidate]);
    if (!$chk->fetch()) { $resNo = $candidate; break; }
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO tt_reservations
            (res_no, user_id, product_id, option_id, product_name_snap, size_snap,
             qty, unit_price, visitor_name, visitor_phone, visit_date, visit_time,
             car_info, memo, visit_type, status, created_at)
         VALUES
            (:res_no, :uid, :pid, :oid, :pname, :size,
             :qty, :unit_price, :vname, :vphone, :vdate, :vtime,
             :car, :memo, :vtype, "pending", NOW())'
    );
    $stmt->execute([
        'res_no' => $resNo,
        'uid'    => Auth::isLoggedIn() ? Auth::currentUserId() : null,
        'pid'    => $productId,
        'oid'    => $optionId,
        'pname'  => $product['name'],
        'size'   => $optionSnap ?: ($product['spec'] ?? null),
        'qty'    => $qty,
        'unit_price' => $unitPrice,
        'vname'  => $visitorName,
        'vphone' => $visitorPhone,
        'vdate'  => $visitDate,
        'vtime'  => $visitTime,
        'car'    => $carInfo !== '' ? $carInfo : null,
        'memo'   => $memo !== '' ? $memo : null,
        'vtype'  => $visitType,
    ]);

    /* 차량번호가 있으면 차량 마스터에 자동 등록 (교체주기 관리용 — 교체일은 방문 후 확정) */
    $plate = extract_plate_no($carInfo);
    if ($plate !== '') {
        capture_vehicle([
            'plate_no'   => $plate,
            'user_id'    => Auth::isLoggedIn() ? Auth::currentUserId() : null,
            'owner_name' => $visitorName,
            'owner_phone'=> $visitorPhone,
            'car_model'  => car_model_from_info($carInfo),
        ]);
    }

    json_response([
        'success' => true,
        'message' => '예약이 접수되었습니다. 결제를 진행해주세요.',
        'res_no'  => $resNo,
        'redirect_url' => BASE_URL . '/reservation-pay.php?no=' . urlencode($resNo),
    ]);
} catch (Throwable $e) {
    error_log('[reservation-submit] ' . $e->getMessage());
    json_response(['success' => false, 'message' => '예약 접수 중 오류가 발생했습니다. 잠시 후 다시 시도해주세요.']);
}
