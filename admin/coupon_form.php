<?php
// /admin/coupon_form.php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('coupons');
ensure_coupon_tables();

$pdo = Database::connection();
$id = (int)($_GET['id'] ?? 0);
$coupon = null;

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM tt_coupons WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $coupon = $stmt->fetch();
    if (!$coupon) {
        flash('admin_error', '쿠폰을 찾을 수 없습니다.');
        redirect('/admin/coupons.php');
    }
}

const_defined: if (!defined('COUPON_IMG_MAX_SIZE_MB')) define('COUPON_IMG_MAX_SIZE_MB', 5);

function admin_handle_coupon_image_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('이미지 업로드 중 오류가 발생했습니다.');

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) {
        throw new RuntimeException('jpg, png, webp, gif 형식의 이미지만 업로드할 수 있습니다.');
    }
    if ($file['size'] > COUPON_IMG_MAX_SIZE_MB * 1024 * 1024) {
        throw new RuntimeException('이미지 용량은 ' . COUPON_IMG_MAX_SIZE_MB . 'MB를 초과할 수 없습니다.');
    }

    $dir = UPLOAD_DIR . 'coupons/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'coupon_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        throw new RuntimeException('이미지 저장에 실패했습니다.');
    }
    return UPLOAD_URL . 'coupons/' . $filename;
}

$errors = [];

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/coupon_form.php' . ($id ? "?id={$id}" : ''));
    }

    $name              = trim($_POST['name'] ?? '');
    $description       = trim($_POST['description'] ?? '');
    $discountType      = ($_POST['discount_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
    $discountValue     = (int)($_POST['discount_value'] ?? 0);
    $maxDiscountAmount = trim($_POST['max_discount_amount'] ?? '') === '' ? null : (int)$_POST['max_discount_amount'];
    $minOrderAmount    = (int)($_POST['min_order_amount'] ?? 0);
    $validFrom         = trim($_POST['valid_from'] ?? '') !== '' ? $_POST['valid_from'] . ':00' : null;
    $validUntil        = trim($_POST['valid_until'] ?? '') !== '' ? $_POST['valid_until'] . ':00' : null;
    $totalLimit        = trim($_POST['total_limit'] ?? '') === '' ? null : (int)$_POST['total_limit'];
    $status            = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $removeImage       = isset($_POST['remove_image']);

    if ($name === '') $errors['name'] = '쿠폰명을 입력해주세요.';
    if ($discountValue <= 0) $errors['discount_value'] = '할인 값을 입력해주세요.';
    if ($discountType === 'percent' && $discountValue > 100) $errors['discount_value'] = '퍼센트 할인은 100을 초과할 수 없습니다.';
    if ($validFrom && $validUntil && strtotime($validFrom) > strtotime($validUntil)) {
        $errors['valid_until'] = '종료일이 시작일보다 빠를 수 없습니다.';
    }

    $imageUrl = $coupon['image_url'] ?? null;
    try {
        $uploaded = admin_handle_coupon_image_upload($_FILES['image'] ?? []);
        if ($uploaded) $imageUrl = $uploaded;
        elseif ($removeImage) $imageUrl = null;
    } catch (Throwable $e) {
        $errors['image'] = $e->getMessage();
    }

    if (empty($errors)) {
        $params = [
            'name' => $name, 'description' => $description, 'image_url' => $imageUrl,
            'discount_type' => $discountType, 'discount_value' => $discountValue,
            'max_discount_amount' => $maxDiscountAmount, 'min_order_amount' => $minOrderAmount,
            'valid_from' => $validFrom, 'valid_until' => $validUntil,
            'total_limit' => $totalLimit, 'status' => $status,
        ];

        if ($coupon) {
            $params['id'] = $coupon['id'];
            $pdo->prepare('
                UPDATE tt_coupons SET name=:name, description=:description, image_url=:image_url,
                    discount_type=:discount_type, discount_value=:discount_value,
                    max_discount_amount=:max_discount_amount, min_order_amount=:min_order_amount,
                    valid_from=:valid_from, valid_until=:valid_until, total_limit=:total_limit, status=:status
                WHERE id = :id
            ')->execute($params);
            flash('admin_success', '쿠폰이 수정되었습니다.');
        } else {
            $pdo->prepare('
                INSERT INTO tt_coupons (name, description, image_url, discount_type, discount_value,
                    max_discount_amount, min_order_amount, valid_from, valid_until, total_limit, status)
                VALUES (:name, :description, :image_url, :discount_type, :discount_value,
                    :max_discount_amount, :min_order_amount, :valid_from, :valid_until, :total_limit, :status)
            ')->execute($params);
            flash('admin_success', '쿠폰이 생성되었습니다.');
        }
        redirect('/admin/coupons.php');
    }
}

$pageTitle = $coupon ? '쿠폰 수정' : '새 쿠폰 만들기';
require __DIR__ . '/includes/header.php';

$v = fn(string $key, $default = '') => h((string)($_POST[$key] ?? $coupon[$key] ?? $default));
?>
<style>
.coupon-form-layout{display:grid;grid-template-columns:1fr 300px;gap:28px}
@media (max-width:900px){.coupon-form-layout{grid-template-columns:1fr}}
.coupon-preview-panel{position:sticky;top:20px;align-self:start}
.coupon-preview-card{
  border-radius:16px;overflow:hidden;box-shadow:0 10px 28px rgba(17,24,39,.12);
}
.coupon-preview-top{padding:20px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff}
.coupon-preview-img{width:100%;height:120px;object-fit:cover;border-radius:10px;margin-bottom:12px;background:rgba(255,255,255,.15);display:none}
.coupon-preview-name{font-size:16px;font-weight:800;min-height:22px}
.coupon-preview-discount{font-size:24px;font-weight:900;margin-top:6px}
.coupon-preview-bottom{background:#fff;padding:16px 20px;font-size:12.5px;color:var(--adm-text-sub);line-height:1.7}
</style>

<form method="post" enctype="multipart/form-data" class="coupon-form-layout">
  <?= Csrf::field() ?>
  <div class="admin-card">
    <h3 class="admin-form-section-title">쿠폰 기본 정보</h3>
    <div class="admin-form-grid">
      <div class="admin-form-row admin-form-row-wide <?= isset($errors['name']) ? 'has-error' : '' ?>">
        <label>쿠폰명 <span class="req">*</span></label>
        <input type="text" name="name" id="fName" value="<?= $v('name') ?>" placeholder="예: 신규가입 5,000원 할인 쿠폰">
        <?php if (isset($errors['name'])): ?><p class="field-error-msg"><?= h($errors['name']) ?></p><?php endif; ?>
      </div>

      <div class="admin-form-row admin-form-row-wide">
        <label>쿠폰 설명</label>
        <textarea name="description" id="fDesc" rows="2" placeholder="회원에게 보여줄 설명을 입력하세요"><?= $v('description') ?></textarea>
      </div>

      <div class="admin-form-row">
        <label>할인 방식 <span class="req">*</span></label>
        <select name="discount_type" id="fType">
          <option value="fixed" <?= ($coupon['discount_type'] ?? 'fixed') === 'fixed' ? 'selected' : '' ?>>정액 할인 (원)</option>
          <option value="percent" <?= ($coupon['discount_type'] ?? '') === 'percent' ? 'selected' : '' ?>>정률 할인 (%)</option>
        </select>
      </div>

      <div class="admin-form-row <?= isset($errors['discount_value']) ? 'has-error' : '' ?>">
        <label>할인 값 <span class="req">*</span></label>
        <input type="number" name="discount_value" id="fValue" min="1" value="<?= $v('discount_value', '0') ?>">
        <?php if (isset($errors['discount_value'])): ?><p class="field-error-msg"><?= h($errors['discount_value']) ?></p><?php endif; ?>
      </div>

      <div class="admin-form-row" id="fMaxDiscountRow">
        <label>최대 할인 금액 (정률 할인일 때만 적용)</label>
        <input type="number" name="max_discount_amount" id="fMax" min="0" value="<?= $v('max_discount_amount', '') ?>" placeholder="비워두면 제한 없음">
      </div>

      <div class="admin-form-row">
        <label>쿠폰 사용 최소 주문금액 <span class="req">*</span></label>
        <input type="number" name="min_order_amount" id="fMin" min="0" value="<?= $v('min_order_amount', '0') ?>" placeholder="예: 30000">
        <p class="admin-form-hint">해당 금액 이상 구매 시에만 쿠폰을 사용할 수 있습니다.</p>
      </div>

      <div class="admin-form-row">
        <label>발급 가능 수량</label>
        <input type="number" name="total_limit" min="1" value="<?= $v('total_limit', '') ?>" placeholder="비워두면 무제한">
      </div>

      <div class="admin-form-row">
        <label>유효기간 시작</label>
        <input type="datetime-local" name="valid_from" id="fFrom"
               value="<?= $coupon && $coupon['valid_from'] ? h(date('Y-m-d\TH:i', strtotime($coupon['valid_from']))) : '' ?>">
      </div>

      <div class="admin-form-row <?= isset($errors['valid_until']) ? 'has-error' : '' ?>">
        <label>유효기간 종료</label>
        <input type="datetime-local" name="valid_until" id="fUntil"
               value="<?= $coupon && $coupon['valid_until'] ? h(date('Y-m-d\TH:i', strtotime($coupon['valid_until']))) : '' ?>">
        <?php if (isset($errors['valid_until'])): ?><p class="field-error-msg"><?= h($errors['valid_until']) ?></p><?php endif; ?>
      </div>

      <div class="admin-form-row">
        <label>상태</label>
        <select name="status">
          <option value="active" <?= ($coupon['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>발송 활성화</option>
          <option value="inactive" <?= ($coupon['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>비활성화</option>
        </select>
      </div>
    </div>

    <h3 class="admin-form-section-title">쿠폰 이미지</h3>
    <div class="admin-form-row <?= isset($errors['image']) ? 'has-error' : '' ?>">
      <?php if (!empty($coupon['image_url'])): ?>
        <div class="admin-thumb-preview">
          <img src="<?= h($coupon['image_url']) ?>" alt="">
          <label class="admin-checkbox-inline"><input type="checkbox" name="remove_image" value="1"> 현재 이미지 삭제</label>
        </div>
      <?php endif; ?>
      <input type="file" name="image" id="fImage" accept="image/jpeg,image/png,image/webp,image/gif">
      <p class="admin-form-hint">JPG / PNG / WEBP / GIF, 최대 5MB. 업로드하지 않으면 기본 그라디언트 카드로 표시됩니다.</p>
      <?php if (isset($errors['image'])): ?><p class="field-error-msg"><?= h($errors['image']) ?></p><?php endif; ?>
    </div>

    <div class="admin-form-actions">
      <a href="<?= BASE_URL ?>/admin/coupons.php" class="btn-admin-cancel">취소</a>
      <button type="submit" class="btn-admin-primary"><?= $coupon ? '수정 완료' : '쿠폰 생성' ?></button>
    </div>
  </div>

  <div class="coupon-preview-panel">
    <p class="admin-mini-hint" style="margin-bottom:8px">실시간 미리보기</p>
    <div class="coupon-preview-card">
      <div class="coupon-preview-top">
        <img id="pvImg" class="coupon-preview-img" src="" alt="">
        <div class="coupon-preview-name" id="pvName">쿠폰명을 입력하세요</div>
        <div class="coupon-preview-discount" id="pvDiscount">0원 할인</div>
      </div>
      <div class="coupon-preview-bottom">
        <div>최소 주문금액: <b id="pvMin">0원</b></div>
        <div style="margin-top:4px">유효기간: <b id="pvPeriod">제한없음</b></div>
      </div>
    </div>
  </div>
</form>

<script>
const fName=document.getElementById('fName'), fType=document.getElementById('fType'),
      fValue=document.getElementById('fValue'), fMax=document.getElementById('fMax'),
      fMin=document.getElementById('fMin'), fFrom=document.getElementById('fFrom'),
      fUntil=document.getElementById('fUntil'), fImage=document.getElementById('fImage'),
      pvImg=document.getElementById('pvImg'), pvName=document.getElementById('pvName'),
      pvDiscount=document.getElementById('pvDiscount'), pvMin=document.getElementById('pvMin'),
      pvPeriod=document.getElementById('pvPeriod'), fMaxDiscountRow=document.getElementById('fMaxDiscountRow');

function fmt(n){ return Number(n||0).toLocaleString(); }

function renderPreview(){
  pvName.textContent = fName.value.trim() || '쿠폰명을 입력하세요';
  const val = parseInt(fValue.value || '0', 10);
  pvDiscount.textContent = fType.value === 'percent' ? val + '% 할인' : fmt(val) + '원 할인';
  pvMin.textContent = fmt(fMin.value) + '원';
  const from = fFrom.value ? fFrom.value.slice(0,10).replace(/-/g,'.') : '제한없음';
  const until = fUntil.value ? fUntil.value.slice(0,10).replace(/-/g,'.') : '제한없음';
  pvPeriod.textContent = from + ' ~ ' + until;
  fMaxDiscountRow.style.display = fType.value === 'percent' ? '' : 'none';
}

[fName, fType, fValue, fMax, fMin, fFrom, fUntil].forEach(el => el.addEventListener('input', renderPreview));
fType.addEventListener('change', renderPreview);

fImage.addEventListener('change', function(){
  const file = this.files[0];
  if (!file) return;
  pvImg.src = URL.createObjectURL(file);
  pvImg.style.display = 'block';
});

renderPreview();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
