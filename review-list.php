<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
$pdo = Database::connection();
ensure_review_extra_columns($pdo);

$serviceOptions = review_service_type_options();
$filterService  = $_GET['service'] ?? '';
if ($filterService !== '' && !array_key_exists($filterService, $serviceOptions)) {
    $filterService = '';
}
$sort = ($_GET['sort'] ?? 'latest') === 'rating' ? 'rating' : 'latest';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($filterService !== '') {
    $where[] = 'r.service_type = :service';
    $params[':service'] = $filterService;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$orderSql = $sort === 'rating' ? 'ORDER BY r.rating DESC, r.created_at DESC' : 'ORDER BY r.created_at DESC';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_reviews r $whereSql");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$listStmt = $pdo->prepare("
    SELECT r.id, r.rating, r.content, r.service_type, r.option_tags, r.created_at,
           p.id AS product_id, p.name AS product_name, p.thumbnail_url,
           b.name AS brand_name, u.name AS user_name
    FROM tt_reviews r
    LEFT JOIN tt_products p ON p.id = r.product_id
    LEFT JOIN tt_brands b ON b.id = p.brand_id
    LEFT JOIN tt_users u ON u.id = r.user_id
    $whereSql
    $orderSql
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) { $listStmt->bindValue($k, $v); }
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$reviews = $listStmt->fetchAll(PDO::FETCH_ASSOC);

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
$optionOptions = review_option_tag_options();
require __DIR__ . '/includes/header.php';
?>
<div class="review-list-wrap">
  <h1 class="review-list-title">실구매자 리뷰</h1>

  <div class="review-filter-tabs">
    <a href="?service=&sort=<?= h($sort) ?>" class="review-tab <?= $filterService === '' ? 'active' : '' ?>">전체</a>
    <?php foreach ($serviceOptions as $key => $label): ?>
      <a href="?service=<?= h($key) ?>&sort=<?= h($sort) ?>" class="review-tab <?= $filterService === $key ? 'active' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="review-sort-bar">
    <a href="?service=<?= h($filterService) ?>&sort=latest" class="<?= $sort === 'latest' ? 'active' : '' ?>">최신순</a>
    <a href="?service=<?= h($filterService) ?>&sort=rating" class="<?= $sort === 'rating' ? 'active' : '' ?>">평점순</a>
    <span class="review-total-count">총 <?= number_format($totalCount) ?>건</span>
  </div>

  <?php if (empty($reviews)): ?>
    <p class="review-empty">등록된 후기가 없습니다.</p>
  <?php else: ?>
    <div class="review-card-grid">
      <?php foreach ($reviews as $rv): ?>
        <div class="review-card">
          <div class="review-card-top">
            <img src="<?= h($rv['thumbnail_url'] ?: BASE_URL . '/assets/img/placeholder.svg') ?>" alt="" class="review-card-thumb">
            <div class="review-card-product">
              <div class="review-card-brand"><?= h($rv['brand_name'] ?? '') ?></div>
              <div class="review-card-name"><?= h($rv['product_name'] ?? '판매종료 상품') ?></div>
            </div>
          </div>
          <div class="review-card-stars">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <span class="<?= $i <= (int)$rv['rating'] ? 'on' : '' ?>">★</span>
            <?php endfor; ?>
          </div>
          <div class="review-card-badges">
            <?php if (!empty($rv['service_type']) && isset($serviceOptions[$rv['service_type']])): ?>
              <span class="review-badge service"><?= h($serviceOptions[$rv['service_type']]) ?></span>
            <?php endif; ?>
            <?php foreach (explode(',', (string)$rv['option_tags']) as $tagKey): ?>
              <?php if (isset($optionOptions[$tagKey])): ?>
                <span class="review-badge option"><?= h($optionOptions[$tagKey]) ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <p class="review-card-content"><?= nl2br(h($rv['content'])) ?></p>
          <?php if (!empty($photosByReview[$rv['id']])): ?>
            <div class="review-card-photos">
              <?php foreach (array_slice($photosByReview[$rv['id']], 0, 3) as $url): ?>
                <img src="<?= h($url) ?>" alt="후기 사진" loading="lazy">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="review-card-meta">
            <span><?= h($rv['user_name'] ? mb_substr($rv['user_name'], 0, 1) . str_repeat('*', max(1, mb_strlen($rv['user_name']) - 1)) : '고객') ?></span>
            <span><?= h(date('Y.m.d', strtotime($rv['created_at']))) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="review-pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?service=<?= h($filterService) ?>&sort=<?= h($sort) ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<style>
.review-list-wrap{max-width:1080px;margin:0 auto;padding:32px 16px 60px;}
.review-list-title{font-size:24px;font-weight:700;margin-bottom:20px;}
.review-filter-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
.review-tab{padding:8px 16px;border:1px solid #ddd;border-radius:20px;font-size:14px;color:#555;text-decoration:none;}
.review-tab.active{background:#2563eb;color:#fff;border-color:#2563eb;}
.review-sort-bar{display:flex;align-items:center;gap:14px;margin-bottom:20px;font-size:14px;}
.review-sort-bar a{color:#999;text-decoration:none;}
.review-sort-bar a.active{color:#111;font-weight:700;}
.review-total-count{margin-left:auto;color:#999;}
.review-card-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
.review-card{border:1px solid #eee;border-radius:12px;padding:18px;}
.review-card-top{display:flex;gap:10px;align-items:center;margin-bottom:10px;}
.review-card-thumb{width:48px;height:48px;object-fit:cover;border-radius:8px;}
.review-card-brand{font-size:12px;color:#999;}
.review-card-name{font-size:14px;font-weight:600;}
.review-card-stars span{color:#ddd;font-size:15px;}
.review-card-stars span.on{color:#fbbf24;}
.review-card-badges{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0;}
.review-badge{font-size:11px;padding:3px 9px;border-radius:12px;}
.review-badge.service{background:#eef2ff;color:#4338ca;}
.review-badge.option{background:#f0fdf4;color:#15803d;}
.review-card-content{font-size:14px;color:#333;line-height:1.6;margin-bottom:10px;}
.review-card-photos{display:flex;gap:6px;margin-bottom:10px;}
.review-card-photos img{width:60px;height:60px;object-fit:cover;border-radius:6px;}
.review-card-meta{display:flex;justify-content:space-between;font-size:12px;color:#999;}
.review-pagination{display:flex;justify-content:center;gap:6px;margin-top:32px;}
.review-pagination a{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:6px;color:#555;text-decoration:none;}
.review-pagination a.active{background:#2563eb;color:#fff;}
@media (max-width:900px){.review-card-grid{grid-template-columns:repeat(2,1fr);}}
@media (max-width:600px){.review-card-grid{grid-template-columns:1fr;}}
</style>
<?php require __DIR__ . '/includes/footer.php'; ?>
