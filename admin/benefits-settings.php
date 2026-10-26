<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('coupons'); // 쿠폰/혜택 그룹
ensure_coupon_tables(); // is_signup_pack 컬럼 자동 보강

$pageTitle = '혜택 설정';

/* ===== 저장 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/benefits-settings.php');
    }
    if (($_POST['action'] ?? '') !== 'save_benefits') {
        flash('admin_error', '알 수 없는 요청입니다.');
        redirect('/admin/benefits-settings.php');
    }

    $warranty = trim($_POST['benefit_warranty_text'] ?? '');
    $packText = trim($_POST['benefit_signup_pack_text'] ?? '');

    set_setting('benefit_warranty_text', $warranty);
    set_setting('benefit_signup_pack_text', $packText);

    for ($i = 1; $i <= 3; $i++) {
        set_setting("benefit_row_{$i}_label", trim($_POST["benefit_row_{$i}_label"] ?? ''));
        set_setting("benefit_row_{$i}_text", trim($_POST["benefit_row_{$i}_text"] ?? ''));
    }

    AdminAuth::log((int)AdminAuth::currentAdminId(), 'benefit_settings', '상품 상세 혜택 안내 설정 저장');
    flash('admin_success', '혜택 안내 설정을 저장했습니다. 상품 상세 페이지 구매패널에 바로 반영됩니다.');
    redirect('/admin/benefits-settings.php');
}

/* ===== 현재값 로드 ===== */
$warrantyText = get_setting('benefit_warranty_text', '정품 보증 · 무상 밸런스/장착 안내');
$packText = get_setting('benefit_signup_pack_text', '첫 가입 & 구매 시 쿠폰팩 증정');
$rows = [];
for ($i = 1; $i <= 3; $i++) {
    $rows[$i] = [
        'label' => get_setting("benefit_row_{$i}_label", $i === 1 ? '카드할인' : ''),
        'text'  => get_setting("benefit_row_{$i}_text", $i === 1 ? '카드사 무이자 할부 혜택 (결제 시 적용)' : ''),
    ];
}

/* 신규 가입 쿠폰팩 현황 (쿠폰 관리에서 '가입 쿠폰팩' 체크한 쿠폰) */
$packCoupons = [];
try {
    $stmt = Database::connection()->prepare(
        "SELECT id, name, discount_type, discount_value, status, valid_until
         FROM tt_coupons WHERE is_signup_pack = 1 ORDER BY id ASC");
    $stmt->execute();
    $packCoupons = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $packCoupons = [];
}

require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">🎟️ 혜택 설정 (상품 상세 · 구매패널)</h2>
      <p class="admin-list-sub">상품 상세 페이지 구매패널에 표시되는 혜택 안내 문구를 관리합니다 — 보증 배너 · 첫 가입 쿠폰팩 안내 · 카드할인/프로모션 줄.</p>
    </div>
  </div>

  <form method="post" style="margin-top:16px">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save_benefits">

    <div style="margin-bottom:22px">
      <label style="font-size:13px;font-weight:800;color:#334155;display:block;margin-bottom:6px">💎 보증 배너 문구 <span style="color:#94a3b8;font-weight:600">(네이비 배너 — 이용안내 페이지로 연결)</span></label>
      <input type="text" name="benefit_warranty_text" value="<?= h($warrantyText) ?>" maxlength="60" style="width:100%;max-width:520px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13.5px;font-weight:700">
    </div>

    <div style="margin-bottom:22px">
      <label style="font-size:13px;font-weight:800;color:#334155;display:block;margin-bottom:6px">🎁 첫 가입 쿠폰팩 안내 문구 <span style="color:#94a3b8;font-weight:600">(연보라 띠 배너)</span></label>
      <input type="text" name="benefit_signup_pack_text" value="<?= h($packText) ?>" maxlength="60" style="width:100%;max-width:520px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13.5px;font-weight:700">
      <p style="font-size:12px;color:#64748b;margin-top:6px;line-height:1.6">
        쿠폰 관리에서 <b>"가입 쿠폰팩 포함"</b>에 체크한 쿠폰이 신규 가입 회원에게 자동 지급됩니다 (가입 즉시 쿠폰함에 저장).<br>
        현재 가입팩 쿠폰: <?= count($packCoupons) > 0
            ? implode(' · ', array_map(function ($c) {
                return h($c['name']) . ' (' . coupon_discount_label($c) . ($c['status'] === 'active' ? '' : ' · 비활성') . ')';
            }, $packCoupons))
            : '없음 — 쿠폰 관리에서 쿠폰 생성/수정 시 체크해주세요' ?>
      </p>
    </div>

    <div style="margin-bottom:22px">
      <label style="font-size:13px;font-weight:800;color:#334155;display:block;margin-bottom:6px">💳 카드할인 / 프로모션 안내 줄 <span style="color:#94a3b8;font-weight:600">(최대 3줄 — 빈 줄은 상세 페이지에서 숨겨짐)</span></label>
      <?php foreach ($rows as $i => $r): ?>
      <div style="display:flex;gap:10px;margin-bottom:8px;flex-wrap:wrap">
        <input type="text" name="benefit_row_<?= $i ?>_label" value="<?= h($r['label']) ?>" placeholder="예: 카드할인 / 프로모션" maxlength="20" style="width:150px;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:700">
        <input type="text" name="benefit_row_<?= $i ?>_text" value="<?= h($r['text']) ?>" placeholder="예: ○○카드 3,000원 즉시 할인 (50만원 이상 결제 시)" maxlength="80" style="flex:1;min-width:260px;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
      </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:10px;align-items:center">
      <button type="submit" class="btn-admin-primary" style="padding:10px 22px;font-size:13.5px">💾 저장</button>
      <a href="<?= BASE_URL ?>/product-detail.php?id=1" target="_blank" class="btn-admin-secondary" style="padding:10px 16px;font-size:13px;text-decoration:none">상세 페이지에서 확인 ↗</a>
    </div>
  </form>
</div>

<style>
.admin-card input:focus{outline:none;border-color:#818cf8;box-shadow:0 0 0 3px rgba(99,102,241,.12);}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
