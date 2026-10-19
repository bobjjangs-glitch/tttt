<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('biz-pricing');
ensure_price_tier_columns();
$pdo = Database::connection();

$bizDiscountPct = (int) get_setting('business_discount_pct', '5');
$bizDiscountType = get_setting('business_discount_type', 'percent');
if ($bizDiscountType !== 'fixed') $bizDiscountType = 'percent';
$bizDiscountAmount = (int) get_setting('business_discount_amount', '0');

$errors = [];

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/biz-pricing.php');
    }

    $bizTypeInput = $_POST['business_discount_type'] ?? 'percent';
    $bizPctInput  = trim($_POST['business_discount_pct'] ?? '0');
    $bizAmtInput  = preg_replace('/[^0-9]/', '', $_POST['business_discount_amount'] ?? '0');

    $newBizType = ($bizTypeInput === 'fixed') ? 'fixed' : 'percent';

    if ($newBizType === 'percent' && ($bizPctInput === '' || !ctype_digit($bizPctInput))) {
        $errors['business_discount_pct'] = '할인율은 0 이상의 숫자로 입력해주세요.';
    }
    if ($newBizType === 'fixed' && $bizAmtInput === '') {
        $errors['business_discount_amount'] = '할인 금액을 입력해주세요.';
    }

    if (empty($errors)) {
        $newBiz = max(0, min(50, (int)$bizPctInput));
        $newBizAmt = ($newBizType === 'fixed') ? max(0, (int)$bizAmtInput) : 0;
        try {
            set_setting('business_discount_pct', (string)$newBiz);
            set_setting('business_discount_type', $newBizType);
            set_setting('business_discount_amount', (string)$newBizAmt);
            $dcDesc = $newBizType === 'fixed' ? "{$newBizAmt}원" : "{$newBiz}%";
            AdminAuth::log(
                (int)AdminAuth::currentAdminId(),
                'biz_pricing_update',
                "사업자 우대가 설정 변경 → {$dcDesc} 할인"
            );
            flash('admin_success', '사업자가 설정이 저장되었습니다.');
        } catch (Throwable $e) {
            error_log('[admin/biz-pricing save] ' . $e->getMessage());
            flash('admin_error', '저장 중 오류가 발생했습니다: ' . $e->getMessage());
        }
        redirect('/admin/biz-pricing.php');
    }

    $bizDiscountPct = ctype_digit($bizPctInput) ? (int)$bizPctInput : $bizDiscountPct;
    $bizDiscountAmount = (int)$bizAmtInput;
    $bizDiscountType = ($bizTypeInput === 'fixed') ? 'fixed' : 'percent';
}

/* 적용 사례 미리보기용 상품 샘플 (실제 상품 3개) */
$sampleProducts = [];
try {
    $sampleProducts = $pdo->query(
        "SELECT name, price_sale, business_price FROM tt_products WHERE status='active' AND price_sale > 0 ORDER BY sales_count DESC, id DESC LIMIT 3"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$pageTitle = '사업자가 설정';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card" style="max-width:720px">
    <h2>🏢 사업자가 설정</h2>
    <p class="admin-form-hint" style="margin-bottom:18px">
        사업자 회원에게 적용되는 추가 할인입니다. 상품별로 개별 사업자가격(business_price)이 설정되어 있으면 그 가격이 최우선 적용되고,<br>
        설정되지 않은 상품은 판매가(price_sale)에서 아래 할인이 적용되어 표시됩니다. <b>% 할인과 금액(원) 할인 중 선택</b>할 수 있습니다.
    </p>

    <?php if (!empty($errors)): ?>
    <div class="admin-alert admin-alert-error">
        <?php foreach ($errors as $emsg): ?><?= h($emsg) ?><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post">
        <?= Csrf::field() ?>

        <div class="admin-form-row">
            <label>할인 방식 <span class="req">*</span></label>
            <div class="biz-dc-type-row" id="bizDcTypeRow">
                <label class="biz-dc-type <?= $bizDiscountType === 'percent' ? 'on' : '' ?>">
                    <input type="radio" name="business_discount_type" value="percent" <?= $bizDiscountType === 'percent' ? 'checked' : '' ?>>
                    <strong>% 할인</strong><span>판매가에서 N% 깎아서 표시</span>
                </label>
                <label class="biz-dc-type <?= $bizDiscountType === 'fixed' ? 'on' : '' ?>">
                    <input type="radio" name="business_discount_type" value="fixed" <?= $bizDiscountType === 'fixed' ? 'checked' : '' ?>>
                    <strong>금액 할인</strong><span>판매가에서 N원 깎아서 표시</span>
                </label>
            </div>
        </div>

        <div class="admin-form-row" id="bizPctRow" style="<?= $bizDiscountType === 'percent' ? '' : 'display:none' ?>">
            <label>할인율 (%)</label>
            <input type="number" name="business_discount_pct" min="0" max="50" step="1" value="<?= h((string)$bizDiscountPct) ?>" id="bizPctInput">
            <p class="admin-form-hint">0~50% 사이에서 설정 가능. 기본값 5%.</p>
        </div>

        <div class="admin-form-row" id="bizAmtRow" style="<?= $bizDiscountType === 'fixed' ? '' : 'display:none' ?>">
            <label>할인 금액 (원)</label>
            <input type="number" name="business_discount_amount" min="0" step="100" value="<?= h((string)$bizDiscountAmount) ?>" id="bizAmtInput">
            <p class="admin-form-hint">판매가에서 이 금액만큼 깎아서 표시합니다. (예: 5,000원 → 모든 상품 5,000원 할인)</p>
        </div>

        <!-- 실시간 미리보기 -->
        <div class="biz-dc-preview" id="bizDcPreview">
            <h4>💡 사업자가 실시간 미리보기</h4>
            <p class="biz-dc-preview-label">판매가를 입력하면 사업자 회원에게 표시되는 가격을 실시간으로 확인할 수 있습니다.</p>
            <div class="biz-dc-preview-input-row">
                <input type="number" id="bizSamplePrice" value="100000" min="0" step="1000">
                <span>원 (판매가)</span>
            </div>
            <div class="biz-dc-preview-result" id="bizPreviewResult"></div>
        </div>

        <!-- 실제 상품 적용 예시 -->
        <?php if (!empty($sampleProducts)): ?>
        <div class="biz-sample-products">
            <h4>📦 실제 상품 적용 예시 (현재 설정 기준)</h4>
            <table class="admin-table" style="margin-top:8px;">
                <thead><tr><th>상품</th><th>일반 회원가</th><th>사업자 회원가</th></tr></thead>
                <tbody>
                <?php foreach ($sampleProducts as $sp):
                    $bizPrice = get_display_price((int)$sp['price_sale'], (int)$sp['business_price'], 'business'); ?>
                <tr>
                    <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($sp['name']) ?></td>
                    <td class="mono"><?= number_format((int)$sp['price_sale']) ?>원</td>
                    <td class="mono" style="color:#1d4ed8;font-weight:800;"><?= number_format($bizPrice) ?>원</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="admin-form-row" style="background:#fef3c7;border-radius:10px;padding:14px 16px;margin-top:18px">
            <p style="font-size:13px;color:#92400e;line-height:1.7">
                🏢 현재 적용 중: <b><?= $bizDiscountType === 'fixed' ? number_format($bizDiscountAmount) . '원' : $bizDiscountPct . '%' ?></b> 할인
                (상품별 개별 사업자가격이 있으면 그 가격이 우선)
            </p>
        </div>

        <div class="admin-form-actions">
            <button type="submit" class="btn-admin-primary">저장</button>
        </div>
    </form>
</div>

<style>
.biz-dc-type-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px;}
.biz-dc-type{display:flex;flex-direction:column;gap:4px;border:2px solid #e2e8f0;border-radius:12px;padding:14px 16px;cursor:pointer;transition:border-color .15s,background .15s;}
.biz-dc-type input{display:none;}
.biz-dc-type strong{font-size:14px;font-weight:800;color:#0f172a;}
.biz-dc-type span{font-size:11.5px;color:#64748b;}
.biz-dc-type.on{border-color:#2563eb;background:#eff6ff;}
.biz-dc-type.on strong{color:#1d4ed8;}
.biz-dc-preview{background:#0f172a;border-radius:12px;padding:18px 20px;margin:18px 0 8px;}
.biz-dc-preview h4{font-size:13px;font-weight:800;color:#fff;margin:0 0 8px;}
.biz-dc-preview-label{font-size:11px;color:#94a3b8;margin:0 0 10px;line-height:1.6;}
.biz-dc-preview-input-row{display:flex;align-items:center;gap:8px;margin-bottom:12px;}
.biz-dc-preview-input-row input{width:140px;padding:9px 12px;border:1.5px solid #334155;border-radius:8px;background:#1e293b;color:#fff;font-size:14px;font-weight:700;outline:none;}
.biz-dc-preview-input-row input:focus{border-color:#2563eb;}
.biz-dc-preview-input-row span{font-size:12px;color:#94a3b8;font-weight:600;}
.biz-dc-preview-result{display:flex;align-items:center;justify-content:space-between;gap:10px;background:#1e293b;border-radius:10px;padding:12px 16px;flex-wrap:wrap;}
.biz-dc-preview-result .pv-row{display:flex;align-items:center;gap:10px;font-size:12px;color:#94a3b8;font-weight:600;}
.biz-dc-preview-result .pv-row strong{font-size:15px;font-weight:900;}
.pv-original{color:#64748b;text-decoration:line-through;}
.pv-arrow{color:#475569;}
.pv-final strong{color:#60a5fa;}
.pv-dc{background:#1d4ed8;color:#fff;font-size:10px;font-weight:800;padding:3px 8px;border-radius:999px;}
.biz-sample-products{margin-top:18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;}
.biz-sample-products h4{font-size:13px;font-weight:800;color:#0f172a;margin:0;}
@media(max-width:640px){.biz-dc-type-row{grid-template-columns:1fr;}.biz-dc-preview-result{justify-content:flex-start;}}
</style>

<script>
(function(){
  var typeRow = document.getElementById('bizDcTypeRow');
  var pctRow = document.getElementById('bizPctRow');
  var amtRow = document.getElementById('bizAmtRow');
  var pctInput = document.getElementById('bizPctInput');
  var amtInput = document.getElementById('bizAmtInput');
  var sampleInput = document.getElementById('bizSamplePrice');
  var resultEl = document.getElementById('bizPreviewResult');
  if (!typeRow || !resultEl) return;

  function currentType(){
    var checked = typeRow.querySelector('input[type=radio]:checked');
    return checked ? checked.value : 'percent';
  }
  function refreshTypeUI(){
    var t = currentType();
    pctRow.style.display = (t === 'percent') ? '' : 'none';
    amtRow.style.display = (t === 'fixed') ? '' : 'none';
    typeRow.querySelectorAll('.biz-dc-type').forEach(function(el){
      el.classList.toggle('on', el.querySelector('input').checked);
    });
    updatePreview();
  }
  function updatePreview(){
    var t = currentType();
    var price = parseInt(sampleInput.value, 10) || 0;
    var dc = 0, dcLabel = '';
    if (t === 'percent') {
      var pct = Math.max(0, Math.min(50, parseInt(pctInput.value, 10) || 0));
      dc = Math.round(price * pct / 100);
      dcLabel = pct + '% 할인';
    } else {
      dc = Math.max(0, parseInt(amtInput.value, 10) || 0);
      if (dc > price) dc = price;
      dcLabel = dc.toLocaleString('ko-KR') + '원 할인';
    }
    var final = Math.max(0, price - dc);
    resultEl.innerHTML =
      '<span class="pv-row pv-original"><strong>' + price.toLocaleString('ko-KR') + '원</strong> 판매가</span>' +
      '<span class="pv-arrow">→ 사업자 회원에게 →</span>' +
      '<span class="pv-row pv-final"><strong>' + final.toLocaleString('ko-KR') + '원</strong> 사업자가</span>' +
      '<span class="pv-dc">' + dcLabel + ' (-' + dc.toLocaleString('ko-KR') + '원)</span>';
  }
  typeRow.addEventListener('change', refreshTypeUI);
  [pctInput, amtInput, sampleInput].forEach(function(inp){
    inp.addEventListener('input', updatePreview);
  });
  refreshTypeUI();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
