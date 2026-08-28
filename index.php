<?php
require_once __DIR__ . '/core/bootstrap.php';
$pdo = Database::connection();
ensure_promo_placement_column();
ensure_banner_size_columns(); // ← 방문 순서와 무관하게 target_w/target_h 컬럼 보장
ensure_brand_best_sections_table(); // ← [NEW] 브랜드별 베스트셀러 섹션 테이블 보장

$bestProducts = $pdo->query("
    SELECT p.*, b.name AS brand_name
    FROM tt_products p JOIN tt_brands b ON b.id = p.brand_id
    WHERE p.status = 'active' ORDER BY p.sales_count DESC LIMIT 8
")->fetchAll();

$newProducts = $pdo->query("
    SELECT p.*, b.name AS brand_name
    FROM tt_products p JOIN tt_brands b ON b.id = p.brand_id
    WHERE p.status = 'active' ORDER BY p.created_at DESC LIMIT 8
")->fetchAll();

// ── 메인 배너 (카드형, 배너별 target_w/target_h 사용) ──
$banners = $pdo->query("
    SELECT id, title, image_url, link_url, target_w, target_h FROM tt_banners
    WHERE is_active = 1 ORDER BY sort_order ASC, id ASC
")->fetchAll();

// ── 카테고리 아이콘 (관리자에서 이미지로 등록한 실데이터) ──
$homeCategoryIcons = $pdo->query("
    SELECT id, label, icon_image_url, link_url FROM tt_category_icons
    WHERE is_active = 1 ORDER BY sort_order ASC, id ASC
")->fetchAll();

// ── 카드형 프로모 배너: 그리드용 ──
$homePromoGrid = $pdo->query("
    SELECT id, title, description, cta_text, image_url, link_url FROM tt_promo_banners
    WHERE is_active = 1 AND placement = 'grid' ORDER BY sort_order ASC, id ASC
")->fetchAll();

// ── [NEW] BEST 섹션 바로 위 와이드 배너 ──
$bestTopBanners = $pdo->query("
    SELECT id, title, description, cta_text, image_url, link_url FROM tt_promo_banners
    WHERE is_active = 1 AND placement = 'best_top' ORDER BY sort_order ASC, id ASC
")->fetchAll();

// ── 섹션 제목 (관리자에서 즉시 수정) ──
$bestSectionTitle = get_setting('best_section_title', '가장 많이 팔린 타이어');
$bestSectionSub   = get_setting('best_section_sub', 'BEST');
$newSectionTitle  = get_setting('new_section_title', '신상품');
$newSectionSub    = get_setting('new_section_sub', 'NEW');

// ── [NEW] 브랜드별 베스트셀러 섹션 목록 (어드민 → admin/banners.php?tab=brandbest 에서 관리) ──
$brandBestSections = $pdo->query("
    SELECT s.*, b.name AS brand_name
    FROM tt_brand_best_sections s
    LEFT JOIN tt_brands b ON b.id = s.brand_id
    WHERE s.is_active = 1
    ORDER BY s.sort_order ASC, s.id ASC
")->fetchAll();

// ── [NEW] 메인화면 맨 하단 "실구매자 리뷰" 섹션용 데이터 (core/functions.php에 정의된 함수 사용) ──
$homeReviews = get_home_best_reviews($pdo, 10);

function renderProductCard(array $p): string {
    $discount = ($p['price_original'] > 0)
        ? round((1 - $p['price_sale'] / $p['price_original']) * 100)
        : 0;
    $img = $p['thumbnail_url'] ?: (BASE_URL . '/assets/img/placeholder.svg');

    $html  = '<a href="' . BASE_URL . '/product-detail.php?id=' . (int)$p['id'] . '" class="prod-card">';
    $html .= '<div class="prod-img-wrap">';
    if ($discount >= 30) {
        $html .= '<div class="prod-badge-wrap"><span class="prod-badge badge-sale">' . $discount . '%</span></div>';
    }
    $html .= '<img src="' . h($img) . '" alt="' . h($p['name']) . '" loading="lazy">';
    $html .= '<span class="prod-brand-badge">' . h($p['brand_name']) . '</span>';
    $html .= '</div>';
    $html .= '<div class="prod-body">';
    $html .= '<div class="prod-brand-name">' . h($p['brand_name']) . '</div>';
    $html .= '<div class="prod-title">' . h($p['name']) . '</div>';
    if (!empty($p['model'])) {
        $html .= '<div class="prod-model">' . h($p['model']) . '</div>';
    }
    $html .= '<div class="prod-rating"><span class="star">★</span> ' . number_format((float)$p['rating_avg'], 1)
            . ' <span>(' . (int)$p['review_count'] . ')</span></div>';
    $html .= '<div class="prod-price-area">';
    if ($discount > 0) {
        $html .= '<span class="prod-discount-pct">' . $discount . '%</span>';
    }
    $html .= '<span class="prod-price-now">' . format_price((int)$p['price_sale']) . '</span>';
    if ($discount > 0) {
        $html .= '<span class="prod-price-orig">' . format_price((int)$p['price_original']) . '</span>';
    }
    $html .= '</div></div></a>';
    return $html;
}

$pageTitle = '홈';
require __DIR__ . '/includes/header.php';
?>

<!-- ===== 메인 배너 (카드형 슬라이더 — 관리자에서 배너별로 지정한 target_w × target_h 비율을 그대로 반영) ===== -->
<?php if (!empty($banners)): ?>
<section class="banner-card-sec" aria-label="메인 배너">
  <div class="sec-inner">
    <div class="banner-card-wrap" id="bannerSlider" style="aspect-ratio: <?= (int)$banners[0]['target_w'] ?> / <?= (int)$banners[0]['target_h'] ?>;">
      <div class="banner-card-track">
        <?php foreach ($banners as $i => $bn): ?>
          <?php $isFirst = ($i === 0); ?>
          <div class="banner-card-slide <?= $isFirst ? 'active' : '' ?>"
               data-index="<?= $i ?>"
               data-tw="<?= (int)$bn['target_w'] ?>"
               data-th="<?= (int)$bn['target_h'] ?>">
            <?php if (!empty($bn['link_url'])): ?>
            <a href="<?= h($bn['link_url']) ?>" class="banner-card-link" aria-label="<?= h($bn['title']) ?>">
            <?php endif; ?>
              <img class="banner-card-img" src="<?= h($bn['image_url']) ?>" alt="<?= h($bn['title']) ?>" loading="<?= $isFirst ? 'eager' : 'lazy' ?>">
            <?php if (!empty($bn['link_url'])): ?>
            </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($banners) > 1): ?>
        <button type="button" class="banner-card-nav banner-card-nav-prev" id="bannerPrev" aria-label="이전 배너">‹</button>
        <button type="button" class="banner-card-nav banner-card-nav-next" id="bannerNext" aria-label="다음 배너">›</button>
        <div class="banner-card-dots" id="bannerDots">
          <?php foreach ($banners as $i => $bn): ?>
            <button type="button" class="banner-card-dot <?= $i === 0 ? 'active' : '' ?>" data-goto="<?= $i ?>" aria-label="<?= $i + 1 ?>번째 배너"></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ===== 카테고리 아이콘 바 ===== -->
<?php if (!empty($homeCategoryIcons)): ?>
<section class="category-icon-sec" aria-label="카테고리 아이콘 바로가기">
  <div class="sec-inner">
    <div class="category-icon-bar">
      <?php foreach ($homeCategoryIcons as $c): ?>
        <a class="category-icon-item" href="<?= h($c['link_url'] ?: '#') ?>">
          <span class="category-icon-img-wrap"><img src="<?= h($c['icon_image_url']) ?>" alt="<?= h($c['label']) ?>" loading="lazy"></span>
          <span class="category-icon-label"><?= h($c['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ===== 카드형 프로모 배너 그리드 ===== -->
<?php if (!empty($homePromoGrid)): ?>
<section class="promo-grid-sec" aria-label="프로모션 배너">
  <div class="sec-inner promo-grid-wrap">
    <div class="promo-grid" id="promoGrid">
      <?php foreach ($homePromoGrid as $pg):
        $hasText = trim((string)($pg['title'] ?? '')) !== '';
      ?>
        <a class="promo-card" href="<?= h($pg['link_url'] ?: '#') ?>" style="background-image:url('<?= h($pg['image_url']) ?>')">
          <?php if ($hasText): ?>
            <div class="promo-overlay"></div>
            <div class="promo-title"><?= h($pg['title']) ?></div>
            <?php if (!empty($pg['description'])): ?><div class="promo-desc"><?= h($pg['description']) ?></div><?php endif; ?>
            <span class="promo-cta"><?= h($pg['cta_text'] ?: '바로가기') ?> →</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if (count($homePromoGrid) > 1): ?>
      <button type="button" class="promo-scroll-nav promo-scroll-prev" id="promoPrev" aria-label="이전 배너">‹</button>
      <button type="button" class="promo-scroll-nav promo-scroll-next" id="promoNext" aria-label="다음 배너">›</button>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- ===== [NEW] BEST 섹션 바로 위 와이드 배너 ===== -->
<?php if (!empty($bestTopBanners)): ?>
<section class="besttop-banner-sec" aria-label="특가 배너">
  <div class="sec-inner">
    <?php foreach ($bestTopBanners as $bt):
      $btHasText = trim((string)($bt['title'] ?? '')) !== '';
    ?>
      <a class="besttop-banner-card" href="<?= h($bt['link_url'] ?: '#') ?>" style="background-image:url('<?= h($bt['image_url']) ?>')">
        <?php if ($btHasText): ?>
          <div class="besttop-banner-overlay"></div>
          <div class="besttop-banner-title"><?= h($bt['title']) ?></div>
          <?php if (!empty($bt['description'])): ?><div class="besttop-banner-desc"><?= h($bt['description']) ?></div><?php endif; ?>
          <span class="besttop-banner-cta"><?= h($bt['cta_text'] ?: '바로가기') ?> →</span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="rec-sec">
  <div class="sec-inner">
    <h2 class="sec-title"><?= h($bestSectionTitle) ?> <span class="sec-sub"><?= h($bestSectionSub) ?></span></h2>
    <?php if ($bestProducts): ?>
      <div class="prod-grid">
        <?php foreach ($bestProducts as $p): echo renderProductCard($p); endforeach; ?>
      </div>
    <?php else: ?>
      <p class="empty-msg">등록된 상품이 없습니다.</p>
    <?php endif; ?>
  </div>
</section>

<section class="rec-sec">
  <div class="sec-inner">
    <h2 class="sec-title"><?= h($newSectionTitle) ?> <span class="sec-sub"><?= h($newSectionSub) ?></span></h2>
    <?php if ($newProducts): ?>
      <div class="prod-grid">
        <?php foreach ($newProducts as $p): echo renderProductCard($p); endforeach; ?>
      </div>
    <?php else: ?>
      <p class="empty-msg">등록된 상품이 없습니다.</p>
    <?php endif; ?>
  </div>
</section>


<!-- ===== [NEW] 브랜드별 베스트셀러 섹션 (어드민에서 여러 개 생성 가능, 한 줄 최대 5개) ===== -->
<?php foreach ($brandBestSections as $bbs):
    $bbsProducts = get_brand_best_products(
        $pdo,
        $bbs['brand_id'] !== null ? (int)$bbs['brand_id'] : null,
        (int)$bbs['period_days'],
        (int)$bbs['display_limit']
    );
    if (!$bbsProducts) continue; // 해당 브랜드 상품이 없으면 섹션 자체를 숨김
    $viewAllUrl = $bbs['view_all_url'] ?: (BASE_URL . '/product-list.php' . ($bbs['brand_id'] ? ('?brand[]=' . (int)$bbs['brand_id']) : ''));
?>
<section class="rec-sec brand-best-sec">
  <div class="sec-inner">
    <div class="brand-best-head">
      <?php if (!empty($bbs['kicker_text'])): ?>
        <p class="brand-best-kicker"><?= h($bbs['kicker_text']) ?></p>
      <?php endif; ?>
      <div class="brand-best-title-row">
        <h2 class="brand-best-title"><?= h($bbs['section_title']) ?></h2>
        <a class="brand-best-viewall" href="<?= h($viewAllUrl) ?>"><?= h($bbs['view_all_text'] ?: '전체보기') ?> →</a>
      </div>
      <?php if (!empty($bbs['sub_text'])): ?>
        <p class="brand-best-sub"><?= h($bbs['sub_text']) ?></p>
      <?php endif; ?>
    </div>
    <div class="prod-grid">
      <?php foreach ($bbsProducts as $p): echo renderProductCard($p); endforeach; ?>
    </div>
  </div>
</section>
<?php endforeach; ?>

<section class="benefit-sec">
  <div class="benefit-grid">
    <div class="benefit-card">
      <div class="benefit-icon">💰</div>
      <div class="benefit-title">최저가 도전</div>
      <div class="benefit-desc">타이어 교체 비용 최저가로 도전합니다</div>
    </div>
    <div class="benefit-card">
      <div class="benefit-icon">🔧</div>
      <div class="benefit-title">맞춤 교체</div>
      <div class="benefit-desc">방문·출장·배송, 내 상황에 맞게 선택</div>
    </div>
    <div class="benefit-card">
      <div class="benefit-icon">🛡️</div>
      <div class="benefit-title">파손 보증</div>
      <div class="benefit-desc">보증 상품 파손 시 무상 교체</div>
    </div>
    <div class="benefit-card">
      <div class="benefit-icon">🎁</div>
      <div class="benefit-title">리뷰 적립</div>
      <div class="benefit-desc">구매 후 리뷰 작성하면 포인트 지급</div>
    </div>
  </div>
</section>

<!-- ===== [NEW] 메인화면 맨 하단 "실구매자 리뷰" 섹션 (타이어픽 스타일 참고, 기존 tt_reviews 그대로 사용) ===== -->
<?php if (!empty($homeReviews)): ?>
<section class="home-review-section" aria-label="실구매자 리뷰">
  <div class="sec-inner">
    <div class="home-review-head">
      <p class="home-review-kicker">고객 후기</p>
      <h2 class="home-review-title">실제 구매자들이 남긴 솔직한 리뷰</h2>
      <p class="home-review-sub">평점 4점 이상, 실제 구매 확인된 후기만 모았습니다.</p>
    </div>

    <div class="home-review-scroll" id="homeReviewScroll">
      <?php foreach ($homeReviews as $rv): ?>
        <div class="home-review-card">
          <div class="home-review-card-top">
            <div class="home-review-thumb">
              <?php if (!empty($rv['thumbnail_url'])): ?>
                <img src="<?= h($rv['thumbnail_url']) ?>" alt="<?= h($rv['product_name']) ?>" loading="lazy">
              <?php else: ?>
                <span class="home-review-thumb-ph">🛞</span>
              <?php endif; ?>
            </div>
            <div class="home-review-product-info">
              <?php if (!empty($rv['brand_name'])): ?>
                <span class="home-review-brand-chip"><?= h($rv['brand_name']) ?></span>
              <?php endif; ?>
              <p class="home-review-product-name"><?= h(mb_strimwidth($rv['product_name'], 0, 22, '…')) ?></p>
            </div>
          </div>

          <div class="home-review-stars">
            <?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?>
          </div>

          <p class="home-review-content"><?= h(mb_strimwidth($rv['content'], 0, 80, '…')) ?></p>

          <div class="home-review-meta">
            <span class="home-review-user"><?= h($rv['user_name_masked']) ?></span>
            <span class="home-review-dot">·</span>
            <span class="home-review-date"><?= h(date('Y.m.d', strtotime($rv['created_at']))) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<script>
(function(){
  const slider = document.getElementById('bannerSlider');
  if (!slider) return;
  const slides = Array.from(slider.querySelectorAll('.banner-card-slide'));
  const dots   = Array.from(document.querySelectorAll('#bannerDots .banner-card-dot'));
  const prevBtn = document.getElementById('bannerPrev');
  const nextBtn = document.getElementById('bannerNext');
  let current = 0;
  let timer = null;
  const AUTOPLAY_MS = 4000;

  function applyRatio(slide) {
    const tw = slide.dataset.tw || 1200;
    const th = slide.dataset.th || 400;
    slider.style.aspectRatio = tw + ' / ' + th;
  }

  function goTo(index) {
    slides[current].classList.remove('active');
    if (dots[current]) dots[current].classList.remove('active');
    current = (index + slides.length) % slides.length;
    slides[current].classList.add('active');
    if (dots[current]) dots[current].classList.add('active');
    applyRatio(slides[current]);
  }
  function next() { goTo(current + 1); }
  function prev() { goTo(current - 1); }
  function startAutoplay() { stopAutoplay(); timer = setInterval(next, AUTOPLAY_MS); }
  function stopAutoplay() { if (timer) clearInterval(timer); }

  if (nextBtn) nextBtn.addEventListener('click', () => { next(); startAutoplay(); });
  if (prevBtn) prevBtn.addEventListener('click', () => { prev(); startAutoplay(); });
  dots.forEach((dot, i) => dot.addEventListener('click', () => { goTo(i); startAutoplay(); }));
  slider.addEventListener('mouseenter', stopAutoplay);
  slider.addEventListener('mouseleave', startAutoplay);
  if (slides.length > 1) startAutoplay();
})();

(function(){
  const track = document.getElementById('promoGrid');
  if (!track) return;
  const prevBtn = document.getElementById('promoPrev');
  const nextBtn = document.getElementById('promoNext');
  function scrollByCard(dir) { track.scrollBy({ left: dir * Math.round(track.clientWidth * 0.9), behavior: 'smooth' }); }
  if (prevBtn) prevBtn.addEventListener('click', () => scrollByCard(-1));
  if (nextBtn) nextBtn.addEventListener('click', () => scrollByCard(1));
})();

(function(){
  // [NEW] 하단 리뷰 카드 가로 드래그 스크롤
  const scroller = document.getElementById('homeReviewScroll');
  if (!scroller) return;
  let isDown = false, startX = 0, scrollLeft = 0;

  scroller.addEventListener('mousedown', (e) => {
    isDown = true;
    scroller.classList.add('dragging');
    startX = e.pageX - scroller.offsetLeft;
    scrollLeft = scroller.scrollLeft;
  });
  ['mouseleave', 'mouseup'].forEach(evt =>
    scroller.addEventListener(evt, () => {
      isDown = false;
      scroller.classList.remove('dragging');
    })
  );
  scroller.addEventListener('mousemove', (e) => {
    if (!isDown) return;
    e.preventDefault();
    const x = e.pageX - scroller.offsetLeft;
    scroller.scrollLeft = scrollLeft - (x - startX) * 1.2;
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
