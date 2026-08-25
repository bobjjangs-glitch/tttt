<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('shipping');
$pdo = Database::connection();

$defaultFee = (int) get_setting('shipping_fee_default', (string)SHIPPING_FEE_DEFAULT);
$freeMin    = (int) get_setting('shipping_free_min', (string)FREE_SHIPPING_MIN);

$errors = [];

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/shipping.php');
    }

    $feeInput = trim($_POST['shipping_fee_default'] ?? '');
    $minInput = trim($_POST['shipping_free_min'] ?? '');

    if ($feeInput === '' || !ctype_digit($feeInput)) {
        $errors['shipping_fee_default'] = '배송비는 0 이상의 숫자로 입력해주세요.';
    }
    if ($minInput === '' || !ctype_digit($minInput)) {
        $errors['shipping_free_min'] = '무료배송 기준 금액은 0 이상의 숫자로 입력해주세요.';
    }

    if (empty($errors)) {
        $newFee = (int)$feeInput;
        $newMin = (int)$minInput;
        try {
            set_setting('shipping_fee_default', (string)$newFee);
            set_setting('shipping_free_min', (string)$newMin);
            AdminAuth::log(
                (int)AdminAuth::currentAdminId(),
                'shipping_setting_update',
                "배송비 {$newFee}원 / 무료배송기준 {$newMin}원으로 변경"
            );
            flash('admin_success', '배송비 설정이 저장되었습니다.');
        } catch (Throwable $e) {
            error_log('[admin/shipping save] ' . $e->getMessage());
            flash('admin_error', '저장 중 오류가 발생했습니다: ' . $e->getMessage());
        }
        redirect('/admin/shipping.php');
    }

    $defaultFee = ctype_digit($feeInput) ? (int)$feeInput : $defaultFee;
    $freeMin    = ctype_digit($minInput) ? (int)$minInput : $freeMin;
}

$pageTitle = '배송비 설정';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card" style="max-width:640px">
    <h3 class="admin-form-section-title">기본 배송비 설정</h3>
    <p class="admin-form-hint" style="margin-bottom:18px">
        여기서 저장한 값은 <b>지금부터 새로 결제되는 주문</b>에만 적용됩니다.
        이미 완료된 주문의 배송비는 결제 시점 값 그대로 보존되니 안심하셔도 됩니다.
    </p>

    <form method="post">
        <?= Csrf::field() ?>

        <div class="admin-form-row <?= isset($errors['shipping_fee_default']) ? 'has-error' : '' ?>">
            <label>기본 배송비 (원) <span class="req">*</span></label>
            <input type="number" name="shipping_fee_default" min="0" step="100" value="<?= h((string)$defaultFee) ?>">
            <?php if (isset($errors['shipping_fee_default'])): ?>
                <p class="field-error-msg"><?= h($errors['shipping_fee_default']) ?></p>
            <?php endif; ?>
            <p class="admin-form-hint">주문 금액이 무료배송 기준에 못 미칠 때 부과되는 배송비입니다.</p>
        </div>

        <div class="admin-form-row <?= isset($errors['shipping_free_min']) ? 'has-error' : '' ?>">
            <label>무료배송 기준 금액 (원) <span class="req">*</span></label>
            <input type="number" name="shipping_free_min" min="0" step="1000" value="<?= h((string)$freeMin) ?>">
            <?php if (isset($errors['shipping_free_min'])): ?>
                <p class="field-error-msg"><?= h($errors['shipping_free_min']) ?></p>
            <?php endif; ?>
            <p class="admin-form-hint">이 금액 이상 구매 시 배송비가 무료로 적용됩니다. 무료배송 자체를 없애려면 999999999처럼 매우 큰 값을 넣어주세요.</p>
        </div>

        <div class="admin-form-row" style="background:#f9fafb;border-radius:10px;padding:14px 16px">
            <p style="font-size:13px;color:#374151;line-height:1.7">
                📦 현재 적용 중: <b><?= number_format($freeMin) ?>원</b> 이상 구매 시 무료배송,
                미달 시 <b><?= number_format($defaultFee) ?>원</b> 부과
            </p>
        </div>

        <div class="admin-form-actions">
            <button type="submit" class="btn-admin-primary">저장</button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
