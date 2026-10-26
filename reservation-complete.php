<?php
// reservation-complete.php — 예약 결제 완료
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
ensure_reservations_table();

$pdo = Database::connection();
$resNo = trim($_GET['no'] ?? '');

if ($resNo === '') redirect('/index.php');

$stmt = $pdo->prepare('SELECT * FROM tt_reservations WHERE res_no = :no LIMIT 1');
$stmt->execute(['no' => $resNo]);
$reservation = $stmt->fetch();

if (!$reservation) redirect('/index.php');
if (Auth::isLoggedIn() && $reservation['user_id'] !== null && (int)$reservation['user_id'] !== Auth::currentUserId()) {
    redirect('/index.php');
}

/* [v47] 결제수단 표시 — 주문과 공통 코드 + v47 이전 예약의 구 코드(bank/virtual) 함께 지원 */
$payMethodLabels = [
    'bank_transfer'   => '무통장입금',
    'virtual_account' => '가상계좌',
    'card'            => '신용·체크카드',
    'naver_pay'       => '네이버페이',
    'kakao_pay'       => '카카오페이',
    'bank'            => '무통장입금',
    'virtual'         => '가상계좌',
];
$payMethod = $reservation['payment_method'] ?? null;
$isPaid = ($reservation['payment_status'] ?? 'none') === 'paid';
$hasPayment = ($reservation['payment_status'] ?? 'none') !== 'none';

$subtotal = (int)$reservation['unit_price'] * (int)$reservation['qty'];
$discount = (int)($reservation['discount_amount'] ?? 0);
$finalAmount = (int)($reservation['final_amount'] ?? 0);
if ($finalAmount <= 0) $finalAmount = max(0, $subtotal - $discount);

$pageTitle = '예약 완료';
require __DIR__ . '/includes/header.php';
?>

<div class="rc-wrap">
  <div class="rc-icon"><?= $isPaid ? '✅' : '🗓️' ?></div>
  <h1 class="rc-title"><?= $isPaid ? '결제가 완료되었습니다' : '예약이 접수되었습니다' ?></h1>
  <p class="rc-resno"><?= h($reservation['res_no']) ?></p>

  <div class="rc-card">
    <div class="rc-row">
      <span>상품</span>
      <strong><?= h($reservation['product_name_snap']) ?></strong>
    </div>
    <div class="rc-row">
      <span>수량</span>
      <strong><?= (int)$reservation['qty'] ?>개</strong>
    </div>
    <div class="rc-row">
      <span>방문 예정일</span>
      <strong><?= h(date('Y년 m월 d일', strtotime($reservation['visit_date']))) ?> <?= h($reservation['visit_time']) ?></strong>
    </div>
    <?php if ($hasPayment): ?>
    <div class="rc-row">
      <span>상품 금액</span>
      <strong><?= number_format($subtotal) ?>원</strong>
    </div>
    <?php if ($discount > 0): ?>
    <div class="rc-row discount">
      <span>쿠폰 할인</span>
      <strong>-<?= number_format($discount) ?>원</strong>
    </div>
    <div class="rc-row total">
      <span>결제 금액 (<?= h($payMethodLabels[$payMethod] ?? $payMethod ?? '-') ?>)</span>
      <strong><?= number_format($finalAmount) ?>원</strong>
    </div>
    <?php else: ?>
    <div class="rc-row total">
      <span>결제 금액 (<?= h($payMethodLabels[$payMethod] ?? '-') ?>)</span>
      <strong><?= number_format($finalAmount) ?>원</strong>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($hasPayment && !$isPaid): ?>
  <!-- 무통장입금 / 가상계좌 안내 -->
  <div class="rc-bank-box">
    <h3>입금 계좌 안내</h3>
    <p class="rc-bank-account">
      <strong>농협 000-0000-0000-000</strong><br>
      예금주: 타이어몰
    </p>
    <p class="rc-bank-notice">
      <b><?= number_format($finalAmount) ?>원</b>을 24시간 이내 입금해주세요.<br>
      입금 확인 후 예약이 최종 확정되며, 담당자가 방문 일정을 확정해 드립니다.
    </p>
  </div>
  <?php endif; ?>

  <?php if (!$hasPayment): ?>
  <p class="rc-sub">영업일 1일 내 확인 연락을 드립니다.<br>담당자가 방문 일정을 확정해 드립니다.</p>
  <?php elseif ($isPaid): ?>
  <p class="rc-sub">결제가 완료되었습니다. 영업일 1일 내 담당자가<br>방문 일정을 확정해 드립니다.</p>
  <?php endif; ?>

  <div class="rc-actions">
    <a href="<?= BASE_URL ?>/mypage.php#myreservations" class="rc-btn-primary">내 예약 확인하기</a>
    <a href="<?= BASE_URL ?>/index.php" class="rc-btn-secondary">쇼핑 계속하기</a>
  </div>
</div>

<style>
.rc-wrap{max-width:440px;margin:0 auto;padding:48px 20px 80px;text-align:center;}
.rc-icon{font-size:48px;margin-bottom:12px;}
.rc-title{font-size:22px;font-weight:900;color:#0f172a;margin:0 0 8px;}
.rc-resno{font-size:18px;font-weight:800;color:#2563eb;letter-spacing:1px;margin:0 0 24px;}
.rc-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:8px 18px;text-align:left;margin-bottom:20px;}
.rc-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f1f5f9;font-size:13px;color:#64748b;}
.rc-row:last-child{border-bottom:none;}
.rc-row strong{font-weight:700;color:#0f172a;text-align:right;}
.rc-row.discount strong{color:#2563eb;}
.rc-row.total{border-top:1px dashed #e2e8f0;border-bottom:none;}
.rc-row.total span{font-weight:800;color:#0f172a;}
.rc-row.total strong{font-size:18px;font-weight:900;color:#2563eb;}
.rc-bank-box{background:#f0f9ff;border:1px solid #bae6fd;border-radius:14px;padding:20px;text-align:center;margin-bottom:16px;}
.rc-bank-box h3{font-size:14px;font-weight:800;color:#0c4a6e;margin:0 0 10px;}
.rc-bank-account{font-size:14px;color:#075985;line-height:1.7;margin:0 0 10px;}
.rc-bank-account strong{font-size:17px;font-weight:900;color:#0c4a6e;}
.rc-bank-notice{font-size:12px;color:#075985;line-height:1.7;margin:0;}
.rc-bank-notice b{color:#0369a1;}
.rc-sub{font-size:12px;color:#64748b;line-height:1.8;margin:0 0 24px;}
.rc-actions{display:flex;flex-direction:column;gap:8px;}
.rc-btn-primary{display:block;background:#0f172a;color:#fff;padding:14px;border-radius:12px;font-weight:800;font-size:14px;text-decoration:none;}
.rc-btn-primary:hover{background:#1e293b;}
.rc-btn-secondary{display:block;background:#f1f5f9;color:#334155;padding:13px;border-radius:12px;font-weight:700;font-size:13px;text-decoration:none;}
.rc-btn-secondary:hover{background:#e2e8f0;}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
