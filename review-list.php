<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
$pdo = Database::connection();
ensure_review_extra_columns($pdo);

/* ---------- 서비스(카테고리) 탭 필터 ---------- */
$serviceOptions = review_service_type_options();
$filterService  = $_GET['service'] ?? '';
if ($filterService !== '' && !array_key_exists($filterService, $serviceOptions)) {
    $filterService = '';
}

/* ---------- 브랜드 사이드바 필터 ---------- */
$brandList   = $pdo->query("SELECT id, name FROM tt_brands WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$filterBrand = (int)($_GET['brand'] ?? 0);
if ($filterBrand > 0) {
    $validBrand = false;
    foreach ($brandList as $b) {
        if ((int)$b['id'] === $filterBrand) { $validBrand = true; break; }
    }
    if (!$validBrand) $filterBrand = 0;
}

/* ---------- 정렬 / 페이지네이션 ---------- */
$sort     = ($_GET['sort'] ?? 'latest') === 'rating' ? 'rating' : 'latest';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 10;
$offset   = ($page - 1) * $perPage;

/* ---------- WHERE 절 구성 ---------- */
$where  = [];
$params = [];
if ($filterService !== '') {
    $where[] = 'r.service_type = :service';
    $params[':service'] = $filterService;
}
if ($filterBrand > 0) {
    $where[] = 'p.brand_id = :brand';
    $params[':brand'] = $filterBrand;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$orderSql = $sort === 'rating' ? 'ORDER BY r.rating DESC, r.created_at DESC' : 'ORDER BY r.created_at DESC';

/* ---------- 전체 개수 ---------- */
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tt_reviews r
    LEFT JOIN tt_products p ON p.id = r.product_id
    $whereSql
");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

/* ---------- 리뷰 목록 (상품/브랜드/유저/매장 정보까지 조인) ---------- */
$listStmt = $pdo->prepare("
    SELECT r.id, r.rating, r.content, r.service_type, r.option_tags, r.visit_type,
           r.helpful_count, r.created_at,
           p.id AS product_id, p.name AS product_name, p.spec AS product_spec,
           p.thumbnail_url,
           b.name AS brand_name,
           u.name AS user_name,
           s.name AS store_name, s.address AS store_address
    FROM tt_reviews r
    LEFT JOIN tt_products p ON p.id = r.product_id
    LEFT JOIN tt_brands   b ON b.id = p.brand_id
    LEFT JOIN tt_users    u ON u.id = r.user_id
    LEFT JOIN tt_stores   s ON s.id = r.store_id
    $whereSql
    $orderSql
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) { $listStmt->bindValue($k, $v); }
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$reviews = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- 리뷰 사진 일괄 조회 ---------- */
$reviewIds = array_column($reviews, 'id');
$photosByReview = [];
if ($reviewIds) {
    $ph = implode(',', array_fill(0, count($reviewIds), '?'));
    $photoStmt = $pdo->prepare("SELECT review_id, image_url FROM tt_review_photos WHERE review_id IN ($ph) ORDER BY sort_order ASC");
    $photoStmt->execute($reviewIds);
    foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $photosByReview[$row['review_id']][] = $row['image_url'];
    }
}

$optionOptions   = review_option_tag_options();
$visitTypeLabels = review_visit_type_options();

/* querystring 유지 헬퍼 */
function rvl_qs(array $override = []): string {
    $params = array_merge($_GET, $override);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
}

$pageTitle = '리뷰 모아보기';
require __DIR__ . '/includes/header.php';
?>
<div class="rvl-wrap">

  <!-- ===================== 좌측 브랜드 사이드바 ===================== -->
  <aside class="rvl-sidebar">
    <div class="rvl-sidebar-title"><span class="rvl-sidebar-icon">🏷️</span> 브랜드</div>
    <a href="<?= rvl_qs(['brand' => null, 'page' => 1]) ?>"
       class="rvl-brand-item <?= $filterBrand === 0 ? 'active' : '' ?>">전체</a>
    <?php foreach ($brandList as $b): ?>
      <a href="<?= rvl_qs(['brand' => (int)$b['id'], 'page' => 1]) ?>"
         class="rvl-brand-item <?= $filterBrand === (int)$b['id'] ? 'active' : '' ?>">
        <span><?= h($b['name']) ?></span>
        <span class="rvl-brand-arrow">›</span>
      </a>
    <?php endforeach; ?>
  </aside>

  <!-- ===================== 우측 메인 컨텐츠 ===================== -->
  <div class="rvl-main">

    <!-- 서비스 탭 -->
    <div class="rvl-service-tabs">
      <a href="<?= rvl_qs(['service' => null, 'page' => 1]) ?>" class="rvl-tab <?= $filterService === '' ? 'active' : '' ?>">전체</a>
      <?php foreach ($serviceOptions as $key => $label): ?>
        <a href="<?= rvl_qs(['service' => $key, 'page' => 1]) ?>" class="rvl-tab <?= $filterService === $key ? 'active' : '' ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>

    <!-- 정렬바 -->
    <div class="rvl-sort-bar">
      <span class="rvl-sort-icon">⇅</span>
      <a href="<?= rvl_qs(['sort' => 'latest', 'page' => 1]) ?>" class="<?= $sort === 'latest' ? 'active' : '' ?>">최신순</a>
      <a href="<?= rvl_qs(['sort' => 'rating', 'page' => 1]) ?>" class="<?= $sort === 'rating' ? 'active' : '' ?>">평점순</a>
      <span class="rvl-total-count">총 <?= number_format($totalCount) ?>건</span>
    </div>

    <?php if (empty($reviews)): ?>
      <p class="rvl-empty">등록된 리뷰가 없습니다.</p>
    <?php else: ?>

      <?php foreach ($reviews as $idx => $rv):
          $rvTags = review_parse_option_tags($rv['option_tags'] ?? null);
          $isHot  = is_review_hot((int)$rv['helpful_count']);
          $detailUrl = BASE_URL . '/product-detail.php?id=' . (int)$rv['product_id'] . '&open_review=' . (int)$rv['id'] . '#review-' . (int)$rv['id'];
      ?>

        <!-- ===== 리뷰 카드(클릭 시 상세페이지 리뷰탭으로 이동) ===== -->
        <a href="<?= h($detailUrl) ?>" class="rvl-card" id="review-<?= (int)$rv['id'] ?>">

          <div class="rvl-card-left">
            <div class="rvl-card-top">
              <span class="rvl-user"><?= h(!empty($rv['user_name']) ? mb_substr($rv['user_name'], 0, 1) . str_repeat('*', max(1, mb_strlen($rv['user_name']) - 1)) : '고객') ?></span>
              <span class="rvl-dot">·</span>
              <span class="rvl-date"><?= h(date('y.m.d', strtotime($rv['created_at']))) ?></span>
              <?php if ($isHot): ?><span class="rvl-badge-hot">🔥 HOT</span><?php endif; ?>
            </div>

            <div class="rvl-stars">
              <?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?>
            </div>

            <p class="rvl-content"><?= nl2br(h($rv['content'])) ?></p>

            <?php if (!empty($rvTags)): ?>
              <div class="rvl-tag-row">
                <?php foreach ($rvTags as $i => $t): ?>
                  <span class="rvl-tag-chip rvl-chip-c<?= $i % 3 ?>">
                    <?= $i % 3 === 2 ? '●' : '✔' ?> <?= h($t) ?>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($photosByReview[$rv['id']])): ?>
              <div class="rvl-photo-row">
                <?php foreach (array_slice($photosByReview[$rv['id']], 0, 3) as $url): ?>
                  <img src="<?= h($url) ?>" alt="리뷰 사진" loading="lazy">
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div class="rvl-helpful-row" onclick="event.preventDefault();">
              <span class="rvl-helpful-btn">👍 도움이 돼요 <strong><?= (int)$rv['helpful_count'] ?></strong></span>
            </div>
          </div>

          <!-- ===== 우측 상품 미니박스 ===== -->
          <div class="rvl-card-right">
            <img class="rvl-product-thumb" src="<?= h($rv['thumbnail_url'] ?: BASE_URL . '/assets/img/placeholder.svg') ?>" alt="">
            <div class="rvl-product-brand"><?= h($rv['brand_name'] ?? '') ?></div>
            <div class="rvl-product-name"><?= h($rv['product_name'] ?? '삭제된 상품') ?></div>
            <?php if (!empty($rv['product_spec'])): ?>
              <div class="rvl-product-spec"><?= h($rv['product_spec']) ?></div>
            <?php endif; ?>

            <div class="rvl-product-info-list">
              <div class="rvl-info-row">🏬 매장방문<?= !empty($rv['store_name']) ? ' · ' . h($rv['store_name']) : '' ?></div>
              <div class="rvl-info-row">🔔 이벤트 안내</div>
              <?php if (!empty($rv['store_address'])): ?>
                <div class="rvl-info-row">📍 <?= h($rv['store_address']) ?></div>
              <?php endif; ?>
            </div>

            <span class="rvl-view-btn">상품 보기</span>
          </div>
        </a>

        <?php if ($idx === 0): ?>
        <!-- ===== 리뷰 이벤트 프로모션 배너 (첫 리뷰 다음 고정 삽입) ===== -->
        <a href="<?= BASE_URL ?>/guide.php" class="rvl-promo-banner">
          <div class="rvl-promo-text">
            <strong>구매 리뷰 쓰고 14,000P 받자!</strong>
            <span>타이어픽 리뷰 이벤트</span>
          </div>
          <span class="rvl-promo-arrow">＋</span>
        </a>
        <?php endif; ?>

      <?php endforeach; ?>

      <?php if ($totalPages > 1): ?>
        <div class="rvl-pagination">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="<?= rvl_qs(['page' => $p]) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<style>
/* ============================================================
   리뷰 모아보기 페이지 – 사이드바 + 리스트형 레이아웃
   ============================================================ */
.rvl-wrap{max-width:1200px;margin:0 auto;padding:28px 16px 60px;display:flex;gap:28px;align-items:flex-start;}

/* ---------- 좌측 사이드바 ---------- */
.rvl-sidebar{width:200px;flex-shrink:0;border-right:1px solid #eee;padding-right:16px;}
.rvl-sidebar-title{display:flex;align-items:center;gap:6px;font-size:15px;font-weight:700;margin-bottom:14px;}
.rvl-sidebar-icon{font-size:14px;}
.rvl-brand-item{display:flex;justify-content:space-between;align-items:center;padding:10px 4px;font-size:14px;color:#444;text-decoration:none;border-bottom:1px solid #f4f4f4;}
.rvl-brand-item.active{color:#2563eb;font-weight:700;}
.rvl-brand-arrow{color:#bbb;font-size:15px;}

/* ---------- 메인 영역 ---------- */
.rvl-main{flex:1;min-width:0;}

/* 서비스 탭 */
.rvl-service-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;border-bottom:1px solid #eee;padding-bottom:14px;}
.rvl-tab{padding:7px 14px;border-radius:18px;font-size:13px;color:#666;text-decoration:none;border:1px solid #e2e2e2;}
.rvl-tab.active{background:#111;color:#fff;border-color:#111;}

/* 정렬바 */
.rvl-sort-bar{display:flex;align-items:center;gap:12px;font-size:13px;color:#888;margin-bottom:16px;}
.rvl-sort-bar a{color:#999;text-decoration:none;}
.rvl-sort-bar a.active{color:#111;font-weight:700;}
.rvl-sort-icon{font-size:13px;}
.rvl-total-count{margin-left:auto;color:#aaa;}

.rvl-empty{color:#999;text-align:center;padding:60px 0;}

/* ---------- 리뷰 카드 ---------- */
.rvl-card{display:flex;gap:20px;padding:22px 0;border-bottom:1px solid #eee;text-decoration:none;color:inherit;transition:background .15s;}
.rvl-card:hover{background:#fafafa;}

.rvl-card-left{flex:1;min-width:0;}
.rvl-card-top{display:flex;align-items:center;gap:6px;font-size:12px;color:#999;margin-bottom:6px;}
.rvl-dot{color:#ccc;}
.rvl-badge-hot{margin-left:6px;background:#fef2f2;color:#ef4444;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:700;}

.rvl-stars{color:#fbbf24;font-size:15px;margin-bottom:8px;letter-spacing:1px;}

.rvl-content{font-size:14px;color:#333;line-height:1.6;margin:0 0 10px;}

.rvl-tag-row{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;}
.rvl-tag-chip{font-size:12px;padding:5px 12px;border-radius:16px;display:inline-flex;align-items:center;gap:4px;}
.rvl-chip-c0{background:#e6fbf5;color:#0f9d76;}
.rvl-chip-c1{background:#e6fbf5;color:#0f9d76;}
.rvl-chip-c2{background:#fdeef5;color:#d63384;}

.rvl-photo-row{display:flex;gap:8px;margin-bottom:10px;}
.rvl-photo-row img{width:64px;height:64px;object-fit:cover;border-radius:8px;}

.rvl-helpful-row{display:flex;}
.rvl-helpful-btn{font-size:12px;color:#888;border:1px solid #e2e2e2;padding:5px 12px;border-radius:14px;}

/* 우측 상품 미니박스 */
.rvl-card-right{width:220px;flex-shrink:0;border-left:1px solid #f0f0f0;padding-left:20px;display:flex;flex-direction:column;gap:4px;}
.rvl-product-thumb{width:56px;height:56px;object-fit:cover;border-radius:8px;margin-bottom:4px;}
.rvl-product-brand{font-size:11px;color:#999;}
.rvl-product-name{font-size:14px;font-weight:700;color:#111;}
.rvl-product-spec{font-size:12px;color:#777;margin-bottom:6px;}
.rvl-product-info-list{display:flex;flex-direction:column;gap:4px;margin-bottom:10px;}
.rvl-info-row{font-size:11.5px;color:#777;}
.rvl-view-btn{align-self:flex-start;font-size:12px;font-weight:700;color:#2563eb;border:1px solid #2563eb;padding:6px 14px;border-radius:8px;}

/* 프로모션 배너 */
.rvl-promo-banner{display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#1f2937,#111827);color:#fff;padding:18px 24px;border-radius:12px;text-decoration:none;margin:18px 0;}
.rvl-promo-text strong{display:block;font-size:15px;margin-bottom:4px;}
.rvl-promo-text span{font-size:12px;color:#cbd5e1;}
.rvl-promo-arrow{font-size:20px;color:#fff;}

/* 페이지네이션 */
.rvl-pagination{display:flex;justify-content:center;gap:6px;margin-top:32px;}
.rvl-pagination a{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:6px;color:#555;text-decoration:none;font-size:13px;}
.rvl-pagination a.active{background:#111;color:#fff;}

/* 반응형 */
@media (max-width:900px){
  .rvl-wrap{flex-direction:column;}
  .rvl-sidebar{width:100%;border-right:none;border-bottom:1px solid #eee;padding-right:0;padding-bottom:14px;display:flex;overflow-x:auto;gap:12px;}
  .rvl-brand-item{border-bottom:none;white-space:nowrap;}
  .rvl-card{flex-direction:column;}
  .rvl-card-right{width:100%;border-left:none;border-top:1px solid #f0f0f0;padding-left:0;padding-top:14px;flex-direction:row;flex-wrap:wrap;align-items:center;}
  .rvl-product-thumb{margin-bottom:0;}
}
</style>

<script>
/* 특정 리뷰로 진입했을 때(#review-xx) 살짝 하이라이트 처리 */
(function () {
  const hash = window.location.hash;
  if (!hash) return;
  const target = document.querySelector(hash);
  if (!target) return;
  target.style.background = '#fff7ed';
  setTimeout(() => { target.style.background = ''; }, 2000);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
