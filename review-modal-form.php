<?php
declare(strict_types=1);

/**
 * includes/review-modal-form.php
 * 리뷰 작성 모달 — product-detail.php / mypage.php 공용 템플릿
 *
 * [사용법] include 하기 전에 아래 변수를 반드시 정의할 것.
 *   $reviewReturnTo       : 'product' | 'mypage'
 *   $reviewFixedProductId : 상품이 고정된 페이지(product-detail)면 int,
 *                           여러 상품 공용 모달(mypage)이면 null
 *
 * 아래 변수는 두 페이지 모두 include 전에 준비되어 있어야 하며,
 * 없으면 빈 배열로 안전하게 처리된다.
 *   $reviewOptionTags, $reviewVisitTypes, $reviewExtraServices, $activeStores
 */
$reviewReturnTo       = $reviewReturnTo ?? 'product';
$reviewFixedProductId = $reviewFixedProductId ?? null;
$reviewOptionTags     = $reviewOptionTags ?? [];
$reviewVisitTypes     = $reviewVisitTypes ?? [];
$reviewExtraServices  = $reviewExtraServices ?? [];
$activeStores         = $activeStores ?? [];
?>
<div class="review-modal-overlay" id="reviewModalOverlay">
  <div class="review-modal-box">
    <button type="button" class="review-modal-close" id="reviewModalClose" aria-label="닫기">&times;</button>
    <h3 class="review-modal-title" id="reviewModalTitle">후기 작성하기</h3>
    <p class="review-modal-sub">솔직한 상품 후기를 남겨주시면 다른 고객님들께 큰 도움이 됩니다.</p>

    <form method="post" action="<?= BASE_URL ?>/review-submit.php" class="tt-review-form" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="<?= h($reviewReturnTo) ?>">
        <input type="hidden" name="product_id" id="reviewModalProductId"
               value="<?= $reviewFixedProductId !== null ? (int)$reviewFixedProductId : '' ?>">

        <div class="star-rating">
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <input type="radio" id="reviewStar<?= $i ?>" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>>
                <label for="reviewStar<?= $i ?>">★</label>
            <?php endfor; ?>
        </div>

        <?php if (!empty($reviewOptionTags)): ?>
        <div class="rv-modal-field-label">어떤 점이 좋았나요? (선택, 여러개 선택 가능)</div>
        <div class="rv-chip-group">
            <?php foreach ($reviewOptionTags as $idx => $tagLabel): ?>
                <input type="checkbox" class="rv-chip-input" id="reviewTag<?= $idx ?>" name="option_tags[]" value="<?= h($tagLabel) ?>">
                <label class="rv-chip-label" for="reviewTag<?= $idx ?>"><?= h($tagLabel) ?></label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="rv-modal-field-label">방문 형태</div>
        <div class="rv-visit-toggle-row">
            <?php foreach ($reviewVisitTypes as $vKey => $vLabel): ?>
                <input type="radio" class="rv-chip-input" id="reviewVisit_<?= h($vKey) ?>" name="visit_type" value="<?= h($vKey) ?>" <?= $vKey === 'store' ? 'checked' : '' ?>>
                <label class="rv-chip-label" for="reviewVisit_<?= h($vKey) ?>"><?= h($vLabel) ?></label>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($activeStores)): ?>
        <div class="rv-store-select-wrap" id="reviewStoreSelectWrap">
            <div class="rv-modal-field-label">방문 매장</div>
            <select name="store_id" id="reviewStoreSelect">
                <?php foreach ($activeStores as $st): ?>
                    <option value="<?= (int)$st['id'] ?>"><?= h($st['name']) ?> (<?= h($st['address']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="rv-vehicle-input-wrap">
            <div class="rv-modal-field-label">탑승 차량 (선택)</div>
            <input type="text" name="vehicle_model" maxlength="60" placeholder="예) 소나타 ES">
        </div>

        <?php if (!empty($reviewExtraServices)): ?>
        <div class="rv-modal-field-label">추가로 진행한 서비스 (선택, 여러개 선택 가능)</div>
        <div class="rv-chip-group">
            <?php foreach ($reviewExtraServices as $eIdx => $eLabel): ?>
                <input type="checkbox" class="rv-chip-input" id="reviewExtra<?= $eIdx ?>" name="extra_service[]" value="<?= h($eLabel) ?>">
                <label class="rv-chip-label" for="reviewExtra<?= $eIdx ?>"><?= h($eLabel) ?></label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <textarea name="content" rows="4" maxlength="1000" placeholder="상품에 대한 솔직한 후기를 남겨주세요." required></textarea>

        <div class="rv-photo-upload-wrap">
            <div class="rv-modal-field-label">사진 첨부 (선택, 최대 3장 / 각각 5MB 이하)</div>
            <input type="file" name="photos[]" id="reviewPhotoInput" accept="image/jpeg,image/png,image/webp" multiple>
            <div class="rv-photo-preview" id="reviewPhotoPreview"></div>
        </div>

        <div class="review-modal-actions">
            <button type="button" class="btn-modal-cancel" id="reviewModalCancel">취소</button>
            <button type="submit" class="btn-modal-submit">등록하기</button>
        </div>
    </form>
  </div>
</div>

<script>
(function () {
  const overlay = document.getElementById('reviewModalOverlay');
  if (!overlay) return;

  const closeBtn       = document.getElementById('reviewModalClose');
  const cancelBtn      = document.getElementById('reviewModalCancel');
  const titleEl        = document.getElementById('reviewModalTitle');
  const productIdInput = document.getElementById('reviewModalProductId');
  const storeWrap      = document.getElementById('reviewStoreSelectWrap');
  const photoInput     = document.getElementById('reviewPhotoInput');
  const photoPreview   = document.getElementById('reviewPhotoPreview');
  const reviewForm     = overlay.querySelector('.tt-review-form');

  /* [공용] 모달 열기/닫기 — product-detail.php, mypage.php 양쪽에서 window.openReviewModal() 로 호출 */
  window.openReviewModal = function (productId, productName) {
    if (productId !== undefined && productId !== null && productIdInput) {
      productIdInput.value = productId;
    }
    if (titleEl) {
      titleEl.textContent = productName ? (productName + ' 후기 작성하기') : '후기 작성하기';
    }
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
  };
  window.closeReviewModal = function () {
    overlay.classList.remove('active');
    document.body.style.overflow = '';
  };

  closeBtn?.addEventListener('click', window.closeReviewModal);
  cancelBtn?.addEventListener('click', window.closeReviewModal);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) window.closeReviewModal(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && overlay.classList.contains('active')) window.closeReviewModal();
  });

  /* [공용] data-product-id / data-product-name 을 가진 트리거 버튼은 모두 자동으로 연결된다.
     (마이페이지의 "구매 후기 작성" 카드 버튼들이 여기 해당) */
  document.querySelectorAll('.js-open-review-modal').forEach((btn) => {
    btn.addEventListener('click', () => {
      window.openReviewModal(btn.dataset.productId, btn.dataset.productName);
    });
  });

  /* 방문형태(매장방문/택배수령)에 따라 매장 선택 노출/숨김 */
  if (storeWrap) {
    const syncStoreWrap = () => {
      const checked = document.querySelector('input[name="visit_type"]:checked');
      storeWrap.style.display = (checked && checked.value === 'store') ? '' : 'none';
    };
    document.querySelectorAll('input[name="visit_type"]').forEach(r => r.addEventListener('change', syncStoreWrap));
    syncStoreWrap();
  }

  /* 리뷰 사진 미리보기 (최대 3장) */
  photoInput?.addEventListener('change', () => {
    if (!photoPreview) return;
    photoPreview.innerHTML = '';
    Array.from(photoInput.files || []).slice(0, 3).forEach((file) => {
      const reader = new FileReader();
      reader.onload = (e) => {
        const img = document.createElement('img');
        img.src = e.target.result;
        photoPreview.appendChild(img);
      };
      reader.readAsDataURL(file);
    });
  });

  /* [NEW] AJAX 제출 — 성공/실패 메시지를 즉시 alert 로 보여주고, 성공 시 새 리뷰 위치로 이동 */
  reviewForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = reviewForm.querySelector('.btn-modal-submit');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const res = await fetch(reviewForm.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(reviewForm)
      });
      const data = await res.json();
      alert(data.message || (data.success ? '리뷰가 등록되었습니다.' : '리뷰 등록 중 오류가 발생했습니다.'));
      if (data.success && data.redirect_url) {
        location.href = data.redirect_url;
      } else if (submitBtn) {
        submitBtn.disabled = false;
      }
    } catch (err) {
      alert('네트워크 오류로 요청에 실패했습니다. 잠시 후 다시 시도해 주세요.');
      if (submitBtn) submitBtn.disabled = false;
    }
  });
})();
</script>
