<?php
// tax-invoice.php — 세금계산서 발행 (사업자 회원 전용)
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
ensure_tax_invoice_table();
ensure_tax_invoice_fax_schema();
ensure_reservations_table();
/* [v47] 결제완료 주문 조회에 tt_orders.payment_status가 필요 — 운영 DB에 컬럼이 없으면
   여기서 자동 보강한다(이 컬럼이 없어 500 '내부서버 오류'가 발생했던 것의 직접 원인). */
ensure_order_tracking_columns();

if (!Auth::isLoggedIn()) {
    flash('error', '로그인이 필요합니다.');
    redirect('/login.php');
}
if (!Auth::isBusiness()) {
    flash('error', '세금계산서 발행은 사업자 회원만 이용할 수 있습니다.');
    redirect('/index.php');
}

$pdo = Database::connection();
$uid = Auth::currentUserId();

// 사업자 정보 로드 (프로필에서 자동 채움)
$userStmt = $pdo->prepare('SELECT name, phone, email, biz_number, biz_name, biz_owner_name, biz_address FROM tt_users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $uid]);
$user = $userStmt->fetch();
if (!$user) redirect('/mypage.php');

/* ===== 발행 가능한 대상: 결제완료 주문 + 결제완료 예약 =====
   [v47] 운영 DB의 스키마 드리프트로 컬럼이 없어도 500 대신 안내로 표시(방어).
   [v48] 결제완료 판정 확장 — payment_status가 'paid'인 주문뿐 아니라,
   어드민에서 결제확정(상태 결제완료~배송완료)된 legacy 주문(status만 paid 계열)도
   발행 대상으로 인정한다. v47까지 주문 접수 시 payment_status를 저장하지 않아
   "결제완료 건이 없다"고 뜨던 것이 이 건의 직접 원인. */
$schemaError = null;
$paidOrders = [];
try {
    $paidOrdersStmt = $pdo->prepare(
        "SELECT id, order_no, total_amount, status, payment_status, created_at
         FROM tt_orders
         WHERE user_id = :uid AND status NOT IN ('cancelled','canceled','refunded')
           AND (payment_status = 'paid' OR status IN ('paid','preparing','shipped','done','installed','completed'))
         ORDER BY created_at DESC LIMIT 30"
    );
    $paidOrdersStmt->execute(['uid' => $uid]);
    $paidOrders = $paidOrdersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $schemaError = '주문 내역을 불러오지 못했습니다. 잠시 후 다시 시도해주세요.';
    error_log('[tax-invoice][orders] ' . $e->getMessage());
    SchemaGuard::invalidate();
}

$paidReservations = [];
try {
    $paidReservationsStmt = $pdo->prepare(
        "SELECT id, res_no, final_amount, visit_date, status, payment_status, created_at
         FROM tt_reservations
         WHERE user_id = :uid AND status NOT IN ('canceled','noshow')
           AND payment_status = 'paid'
         ORDER BY created_at DESC LIMIT 30"
    );
    $paidReservationsStmt->execute(['uid' => $uid]);
    $paidReservations = $paidReservationsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $schemaError = $schemaError ?: '예약 내역을 불러오지 못했습니다. 잠시 후 다시 시도해주세요.';
    error_log('[tax-invoice][reservations] ' . $e->getMessage());
    SchemaGuard::invalidate();
}

/* 이미 요청된 대상 중복 방지 — [v47] 스키마 오류 시에도 500 없이 진행 */
$existing = [];
try {
    $exStmt = $pdo->prepare("SELECT target_type, target_id FROM tt_tax_invoices WHERE user_id = :uid AND status IN ('pending','issued')");
    $exStmt->execute(['uid' => $uid]);
    while ($row = $exStmt->fetch(PDO::FETCH_ASSOC)) {
        $existing[$row['target_type'] . '-' . $row['target_id']] = true;
    }
} catch (Throwable $e) {
    error_log('[tax-invoice][existing] ' . $e->getMessage());
    SchemaGuard::invalidate();
}

/* ===== 요청 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error', '유효하지 않은 요청입니다.');
        redirect('/tax-invoice.php');
    }

    $rawType = $_POST['target_type'] ?? '';
    $targetId = (int)($_POST['target_id'] ?? 0);
    // JS 없이 제출된 경우 "order:3" 형태도 처리
    if ($targetId <= 0 && strpos($rawType, ':') !== false) {
        [$rawType, $tid] = explode(':', $rawType, 2);
        $targetId = (int)$tid;
    }
    $targetType = $rawType;
    $bizNumber  = preg_replace('/[^0-9]/', '', $_POST['buyer_biz_number'] ?? '');
    $bizName    = trim($_POST['buyer_biz_name'] ?? '');
    $ceo        = trim($_POST['buyer_ceo'] ?? '');
    $address    = trim($_POST['buyer_address'] ?? '');
    $uptae      = trim($_POST['buyer_uptae'] ?? '');
    $jongmok    = trim($_POST['buyer_jongmok'] ?? '');
    $email      = trim($_POST['contact_email'] ?? '');
    $phone      = preg_replace('/[^0-9]/', '', $_POST['contact_phone'] ?? '');
    $faxNumber  = normalize_fax_number((string)($_POST['fax_number'] ?? ''));
    $memo       = trim($_POST['memo'] ?? '');

    $errors = [];
    if (!in_array($targetType, ['order', 'reservation'], true) || $targetId <= 0) {
        $errors[] = '발행 대상을 선택해주세요.';
    }
    if (!preg_match('/^\d{10}$/', $bizNumber)) $errors[] = '사업자등록번호는 숫자 10자리로 입력해주세요.';
    if ($bizName === '') $errors[] = '상호명을 입력해주세요.';
    if ($ceo === '') $errors[] = '대표자명을 입력해주세요.';
    if ($address === '') $errors[] = '사업장 주소를 입력해주세요.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = '이메일 형식이 올바르지 않습니다.';
    if ($faxNumber !== '' && !preg_match('/^\d{8,11}$/', $faxNumber)) $errors[] = '팩스번호는 지역번호 포함 숫자로 입력해주세요. (예: 02-1234-5678)';

    // 대상 검증 + 금액 확정
    $amount = 0; $targetNo = null;
    if (!$errors) {
        if ($targetType === 'order') {
            $stmt = $pdo->prepare("SELECT order_no, total_amount FROM tt_orders WHERE id = :id AND user_id = :uid AND status NOT IN ('cancelled','canceled','refunded') AND (payment_status = 'paid' OR status IN ('paid','preparing','shipped','done','installed','completed')) LIMIT 1");
            $stmt->execute(['id' => $targetId, 'uid' => $uid]);
            $o = $stmt->fetch();
            if ($o) { $amount = (int)$o['total_amount']; $targetNo = $o['order_no']; }
            else $errors[] = '선택한 주문을 찾을 수 없거나 결제가 완료되지 않았습니다.';
        } else {
            $stmt = $pdo->prepare("SELECT res_no, final_amount FROM tt_reservations WHERE id = :id AND user_id = :uid AND payment_status = 'paid' AND status NOT IN ('canceled','noshow') LIMIT 1");
            $stmt->execute(['id' => $targetId, 'uid' => $uid]);
            $r = $stmt->fetch();
            if ($r) { $amount = (int)$r['final_amount']; $targetNo = $r['res_no']; }
            else $errors[] = '선택한 예약을 찾을 수 없거나 결제가 완료되지 않았습니다.';
        }
    }

    // 중복 요청 방지
    if (!$errors && isset($existing[$targetType . '-' . $targetId])) {
        $errors[] = '이미 이 대상으로 접수된 세금계산서 요청이 있습니다.';
    }

    if ($errors) {
        flash('error', implode('<br>', $errors));
        redirect('/tax-invoice.php');
    }

    // 요청번호 생성
    $reqNo = 'TI-' . date('ymd') . '-';
    for ($try = 0; $try < 5; $try++) {
        $candidate = $reqNo . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $chk = $pdo->prepare('SELECT id FROM tt_tax_invoices WHERE req_no = :no LIMIT 1');
        $chk->execute(['no' => $candidate]);
        if (!$chk->fetch()) { $reqNo = $candidate; break; }
    }

    try {
        $pdo->prepare('INSERT INTO tt_tax_invoices
            (req_no, user_id, target_type, target_id, target_no, amount,
             buyer_biz_number, buyer_biz_name, buyer_ceo, buyer_address, buyer_uptae, buyer_jongmok,
             contact_email, contact_phone, fax_number, memo, status)
            VALUES
            (:req_no, :uid, :ttype, :tid, :tno, :amount,
             :bnum, :bname, :bceo, :baddr, :buptae, :bjongmok,
             :email, :phone, :fax, :memo, "pending")'
        )->execute([
            'req_no' => $reqNo, 'uid' => $uid, 'ttype' => $targetType, 'tid' => $targetId,
            'tno' => $targetNo, 'amount' => $amount,
            'bnum' => $bizNumber, 'bname' => $bizName, 'bceo' => $ceo, 'baddr' => $address,
            'buptae' => $uptae !== '' ? $uptae : null, 'bjongmok' => $jongmok !== '' ? $jongmok : null,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'fax' => $faxNumber !== '' ? $faxNumber : null,
            'memo' => $memo !== '' ? $memo : null,
        ]);
        flash('success', '세금계산서 발행 요청이 접수되었습니다. (' . $reqNo . ') 영업일 1~2일 내 처리됩니다.');
    } catch (Throwable $e) {
        error_log('[tax-invoice] ' . $e->getMessage());
        flash('error', '요청 접수 중 오류가 발생했습니다. 다시 시도해주세요.');
    }
    redirect('/tax-invoice.php');
}

/* ===== 내 요청 내역 — [v47] 스키마 오류 시 500 대신 빈 목록로 진행 ===== */
$myRequests = [];
try {
    $myRequestsStmt = $pdo->prepare('SELECT * FROM tt_tax_invoices WHERE user_id = :uid ORDER BY created_at DESC LIMIT 20');
    $myRequestsStmt->execute(['uid' => $uid]);
    $myRequests = $myRequestsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[tax-invoice][myRequests] ' . $e->getMessage());
    SchemaGuard::invalidate();
}

$statusLabels = ['pending' => '발행대기', 'issued' => '발행완료', 'rejected' => '반려'];
$statusColors = ['pending' => '#f59e0b', 'issued' => '#16a34a', 'rejected' => '#dc2626'];

$pageTitle = '세금계산서 발행';
require __DIR__ . '/includes/header.php';
?>

<div class="ti-wrap">
  <div class="ti-head">
    <h1>🧾 세금계산서 발행</h1>
    <p>결제가 완료된 주문·예약에 대해 세금계산서 발행을 요청할 수 있습니다. (사업자 회원 전용)</p>
  </div>

  <?php if ($err = flash('error')): ?><p class="error-msg"><?= $err ?></p><?php endif; ?>
  <?php if ($ok = flash('success')): ?><p class="error-msg" style="background:#f0fdf4;color:#15803d"><?= $ok ?></p><?php endif; ?>
  <?php if ($schemaError): ?><p class="error-msg"><?= h($schemaError) ?></p><?php endif; ?>

  <div class="ti-grid">
    <!-- ===== 요청 폼 ===== -->
    <div class="ti-card">
      <h2 class="ti-card-title">발행 요청</h2>

      <?php if (empty($paidOrders) && empty($paidReservations)): ?>
      <div class="ti-empty">
        발행 가능한 결제완료 건이 없습니다.<br>
        <span class="ti-empty-sub">주문·예약 결제 완료 후 세금계산서 발행을 요청할 수 있습니다.</span>
      </div>
      <?php else: ?>
      <form method="post" class="ti-form">
        <?= Csrf::field() ?>

        <div class="ti-form-row">
          <label>발행 대상 선택 <span class="req">*</span></label>
          <select name="target_type" id="tiTargetType" required>
            <option value="">대상 선택</option>
            <?php if (!empty($paidOrders)): ?>
            <optgroup label="주문">
              <?php foreach ($paidOrders as $o):
                  $dup = isset($existing['order-' . $o['id']]); ?>
              <option value="order:<?= (int)$o['id'] ?>" data-amount="<?= (int)$o['total_amount'] ?>" <?= $dup ? 'disabled' : '' ?>>
                [<?= h($o['order_no']) ?>] <?= number_format((int)$o['total_amount']) ?>원 <?= $dup ? '(요청됨)' : '' ?> · <?= h(date('m/d', strtotime($o['created_at']))) ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if (!empty($paidReservations)): ?>
            <optgroup label="매장 예약">
              <?php foreach ($paidReservations as $r):
                  $dup = isset($existing['reservation-' . $r['id']]); ?>
              <option value="reservation:<?= (int)$r['id'] ?>" data-amount="<?= (int)$r['final_amount'] ?>" <?= $dup ? 'disabled' : '' ?>>
                [<?= h($r['res_no']) ?>] <?= number_format((int)$r['final_amount']) ?>원 <?= $dup ? '(요청됨)' : '' ?> · 방문 <?= h(date('m/d', strtotime($r['visit_date']))) ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
          <input type="hidden" name="target_id" id="tiTargetId" value="">
          <p class="ti-hint" id="tiAmountHint"></p>
        </div>

        <div class="ti-section-title">공급받는자 (사업자 정보)</div>

        <div class="ti-form-row ti-2col">
          <div>
            <label>사업자등록번호 <span class="req">*</span></label>
            <input type="text" name="buyer_biz_number" id="tiBizNumber" value="<?= h($user['biz_number'] ?? '') ?>" placeholder="000-00-00000" maxlength="12" required>
          </div>
          <div>
            <label>상호명 <span class="req">*</span></label>
            <input type="text" name="buyer_biz_name" value="<?= h($user['biz_name'] ?? '') ?>" maxlength="100" required>
          </div>
        </div>
        <div class="ti-form-row ti-2col">
          <div>
            <label>대표자명 <span class="req">*</span></label>
            <input type="text" name="buyer_ceo" value="<?= h($user['biz_owner_name'] ?? '') ?>" maxlength="50" required>
          </div>
          <div>
            <label>사업장 주소 <span class="req">*</span></label>
            <input type="text" name="buyer_address" value="<?= h($user['biz_address'] ?? '') ?>" maxlength="255" required>
          </div>
        </div>
        <div class="ti-form-row ti-2col">
          <div>
            <label>업태</label>
            <input type="text" name="buyer_uptae" placeholder="예) 소매업" maxlength="50">
          </div>
          <div>
            <label>종목</label>
            <input type="text" name="buyer_jongmok" placeholder="예) 자동차 정비" maxlength="50">
          </div>
        </div>

        <div class="ti-section-title">담당자 연락처</div>

        <div class="ti-form-row ti-2col">
          <div>
            <label>수신 이메일</label>
            <input type="email" name="contact_email" placeholder="example@email.com" maxlength="191">
          </div>
          <div>
            <label>연락처</label>
            <input type="tel" name="contact_phone" value="<?= h($user['phone'] ?? '') ?>" placeholder="010-1234-5678" maxlength="13">
          </div>
        </div>

        <div class="ti-form-row">
          <label>팩스 수신번호 (팩스로 받기를 원하는 경우)</label>
          <input type="tel" name="fax_number" id="tiFaxNumber" placeholder="02-1234-5678" maxlength="13">
          <p class="ti-hint">입력하시면 발행 완료 시 세금계산서를 팩스로도 발송해드립니다. (선택)</p>
        </div>

        <div class="ti-form-row">
          <label>요청사항</label>
          <textarea name="memo" rows="2" maxlength="300" placeholder="예) 10일자로 발행해주세요 등"></textarea>
        </div>

        <button type="submit" class="ti-submit-btn">세금계산서 발행 요청</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- ===== 요청 내역 ===== -->
    <div class="ti-card">
      <h2 class="ti-card-title">발행 요청 내역 <span class="ti-count"><?= count($myRequests) ?>건</span></h2>
      <?php if (empty($myRequests)): ?>
      <div class="ti-empty">접수된 요청이 없습니다.</div>
      <?php else: ?>
      <div class="ti-list">
        <?php foreach ($myRequests as $req): ?>
        <div class="ti-item">
          <div class="ti-item-head">
            <span class="ti-item-no"><?= h($req['req_no']) ?></span>
            <span class="ti-item-status" style="color:<?= $statusColors[$req['status']] ?>;background:<?= $statusColors[$req['status']] ?>18">
              <?= h($statusLabels[$req['status']] ?? $req['status']) ?>
            </span>
            <?php if (($req['fax_status'] ?? 'none') === 'sent'): ?>
            <span class="ti-item-status" style="color:#2563eb;background:#2563eb18">📠 팩스 발송완료</span>
            <?php elseif (($req['fax_status'] ?? 'none') === 'failed'): ?>
            <span class="ti-item-status" style="color:#dc2626;background:#dc262618">📠 팩스 발송실패</span>
            <?php endif; ?>
          </div>
          <div class="ti-item-body">
            <span><?= $req['target_type'] === 'order' ? '📦 주문' : '🗓️ 예약' ?> <?= h((string)($req['target_no'] ?? '')) ?></span>
            <strong><?= number_format((int)$req['amount']) ?>원</strong>
          </div>
          <?php if ($req['target_type'] === 'order'): ?>
          <a class="ti-item-link" href="<?= BASE_URL ?>/mypage.php#orders">주문 상세 보기</a>
          <?php else: ?>
          <a class="ti-item-link" href="<?= BASE_URL ?>/mypage.php#myreservations">예약 상세 보기</a>
          <?php endif; ?>
          <?php if ($req['status'] === 'issued'): ?>
          <a class="ti-item-link ti-print-link" href="<?= BASE_URL ?>/tax-invoice-view.php?id=<?= (int)$req['id'] ?>" target="_blank">🖨️ 세금계산서 인쇄하기</a>
          <?php endif; ?>
          <?php if (!empty($req['admin_memo'])): ?>
          <p class="ti-item-admin">✏️ <?= h($req['admin_memo']) ?></p>
          <?php endif; ?>
          <span class="ti-item-date"><?= h(date('Y.m.d', strtotime($req['created_at']))) ?><?php if ($req['issued_at']): ?> · 발행 <?= h(date('Y.m.d', strtotime($req['issued_at']))) ?><?php endif; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
.ti-wrap{max-width:1000px;margin:0 auto;padding:32px 20px 80px;}
.ti-head{margin-bottom:24px;}
.ti-head h1{font-size:24px;font-weight:900;color:#0f172a;margin:0 0 6px;}
.ti-head p{font-size:13px;color:#64748b;margin:0;}
.ti-grid{display:grid;grid-template-columns:1.4fr 1fr;gap:20px;align-items:start;}
.ti-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;}
.ti-card-title{font-size:16px;font-weight:800;color:#0f172a;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #0f172a;}
.ti-count{font-size:12px;color:#94a3b8;font-weight:600;margin-left:6px;}
.ti-empty{text-align:center;color:#94a3b8;font-size:13px;padding:40px 0;line-height:1.8;}
.ti-empty-sub{font-size:11px;color:#cbd5e1;}
.ti-section-title{font-size:13px;font-weight:800;color:#334155;margin:18px 0 10px;padding-top:12px;border-top:1px dashed #e2e8f0;}
.ti-section-title:first-of-type{margin-top:14px;}
.ti-form-row{margin-bottom:12px;}
.ti-form-row label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;}
.ti-form-row .req{color:#ef4444;}
.ti-form-row input,.ti-form-row select,.ti-form-row textarea{
  width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;
  font-size:14px;font-family:inherit;transition:border-color .15s,box-shadow .15s;background:#fff;
}
.ti-form-row input:focus,.ti-form-row select:focus,.ti-form-row textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.ti-2col{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.ti-hint{font-size:11px;color:#2563eb;font-weight:700;margin-top:4px;min-height:14px;}
.ti-submit-btn{
  width:100%;margin-top:8px;height:50px;border:none;border-radius:12px;
  background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;
  font-size:15px;font-weight:800;cursor:pointer;
  box-shadow:0 6px 16px rgba(37,99,235,.3);transition:transform .12s,box-shadow .12s;
}
.ti-submit-btn:hover{transform:translateY(-1px);}
.ti-list{display:flex;flex-direction:column;gap:10px;}
.ti-item{border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;transition:border-color .15s;}
.ti-item:hover{border-color:#cbd5e1;}
.ti-item-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;}
.ti-item-no{font-size:11px;font-weight:800;color:#94a3b8;letter-spacing:.3px;}
.ti-item-status{font-size:11px;font-weight:800;padding:3px 10px;border-radius:999px;}
.ti-item-body{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:13px;color:#475569;}
.ti-item-body strong{font-size:15px;font-weight:900;color:#0f172a;}
.ti-item-link{display:inline-block;font-size:11px;color:#2563eb;margin-top:6px;text-decoration:none;font-weight:700;}
.ti-item-link:hover{text-decoration:underline;}
.ti-print-link{display:inline-block;margin-top:8px;padding:8px 14px;background:linear-gradient(135deg,#1e293b,#0f172a);color:#fff !important;border-radius:10px;font-size:12px;font-weight:800;text-decoration:none !important;box-shadow:0 4px 10px rgba(15,23,42,.25);}
.ti-print-link:hover{transform:translateY(-1px);text-decoration:none !important;}
.ti-item-admin{font-size:11px;color:#475569;background:#f8fafc;border-radius:8px;padding:6px 10px;margin:8px 0 0;}
.ti-item-date{display:block;font-size:11px;color:#94a3b8;margin-top:8px;}
@media(max-width:768px){
  .ti-wrap{padding:20px 14px 70px;}
  .ti-grid{grid-template-columns:1fr;}
  .ti-card{padding:18px;}
  .ti-2col{grid-template-columns:1fr;}
}
</style>

<script>
(function(){
  var typeSel = document.getElementById('tiTargetType');
  var idInput = document.getElementById('tiTargetId');
  var hint = document.getElementById('tiAmountHint');
  var bizInput = document.getElementById('tiBizNumber');
  if (!typeSel) return;

  typeSel.addEventListener('change', function(){
    var val = this.value; // "order:3" 형식
    if (val.indexOf(':') > -1) {
      var parts = val.split(':');
      // select의 value를 type만으로 재설정 — name은 target_type
      idInput.value = parts[1];
      this.value = parts[0];
      var opt = null;
      for (var i = 0; i < this.options.length; i++) {
        if (this.options[i].value === val) { opt = this.options[i]; break; }
      }
      if (hint && opt) {
        hint.textContent = '발행 금액: ' + (parseInt(opt.dataset.amount || '0', 10) || 0).toLocaleString('ko-KR') + '원';
      }
    }
  });

  // 사업자번호 자동 하이픈 (000-00-00000)
  if (bizInput) {
    bizInput.addEventListener('input', function(){
      var v = this.value.replace(/[^0-9]/g, '').slice(0, 10);
      if (v.length < 3) this.value = v;
      else if (v.length < 5) this.value = v.slice(0,3) + '-' + v.slice(3);
      else this.value = v.slice(0,3) + '-' + v.slice(3,5) + '-' + v.slice(5);
    });
  }

  // 팩스번호 자동 하이픈 (02-1234-5678 / 031-1234-5678)
  var faxInput = document.getElementById('tiFaxNumber');
  if (faxInput) {
    faxInput.addEventListener('input', function(){
      var v = this.value.replace(/[^0-9]/g, '').slice(0, 11);
      if (v.length < 3) this.value = v;
      else if (v.indexOf('02') === 0) {
        this.value = v.length <= 2 ? v
          : v.length <= 5 ? v.slice(0,2) + '-' + v.slice(2)
          : v.length <= 6 ? v.slice(0,2) + '-' + v.slice(2,5) + '-' + v.slice(5)  /* 9자리 */
          : v.slice(0,2) + '-' + v.slice(2,6) + '-' + v.slice(6);                  /* 10자리 */
      } else {
        this.value = v.length <= 3 ? v
          : v.length <= 6 ? v.slice(0,3) + '-' + v.slice(3)
          : v.length <= 7 ? v.slice(0,3) + '-' + v.slice(3,6) + '-' + v.slice(6)  /* 10자리 */
          : v.slice(0,3) + '-' + v.slice(3,7) + '-' + v.slice(7);                  /* 11자리 */
      }
    });
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
