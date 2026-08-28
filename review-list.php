<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
ensure_review_extra_columns();

$pdo = Database::connection();

$serviceOptions = review_service_type_options();
$filterService  = $_GET['service'] ?? 'all';
if ($filterService !== 'all' && !in_array($filterService, $serviceOptions, true)) {
    $filterService = 'all';
}

$sort = ($_GET['sort'] ?? 'latest') === 'rating' ? 'rating' : 'latest';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

$whereSql = '';
$params   = [];
if ($filterService !== 'all') {
    $whereSql = 'WHERE r.service_type = :service';
    $params['service'] = $filterService;
}

$orderSql = $sort === 'rating'
    ? 'ORDER BY r.rating DESC, r.created_at DESC'
    : 'ORDER BY r.created_at DESC';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_reviews r $whereSql");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$listStmt = $pdo->prepare("
    SELECT r.id, r.rating, r.content, r.service_type, r.option_tags, r.created_at,
           p.id AS product_id, p.name AS product_name, p.thumbnail_url,
           b.name AS brand_name,
           u.name AS user_name
    FROM tt_reviews r
    LEFT JOIN tt_products p ON p.id = r.product_id
    LEFT JOIN tt_brands   b ON b.id = p.brand_id
    LEFT JOIN tt_users    u ON u.id = r.user_id
    $whereSql
    $orderSql
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) { $listStmt->bindValue(':' . $k, $v); }
$listStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$reviews = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// 리뷰 사진을 한 번에 조회 (N+1 쿼리 방지)
$photosByReview = [];
if (!empty($reviews)) {
    $ids = array_column($reviews, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $photoStmt = $pdo->prepare("SELECT review_id, image_url FROM tt_review_photos WHERE review_id IN ($in) ORDER BY sort_order ASC, id ASC");
    $photoStmt->execute($ids);
    foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $photosByReview[$row['review_id']][] = $row['image_url'];
    }
}

foreach ($reviews as &$rv) {
    $name = (string)($rv['user_name'] ?? '고객');
    $len  = mb_strlen($name);
    $rv['user_name_masked'] = $len <= 1 ? $name . '*' : mb_substr($name, 0, 1) . str_repeat('*', $len - 1);
    $rv['photos'] = $photosByReview[$rv['id']] ?? [];
    $rv['option_tag_list'] = array_filter(explode(',', (string)($rv['option_tags'] ?? '')));
}
unset($rv);

function rl_query_with(array $override): string
{
    $base = ['service' => $_GET['service'] ?? 'all', 'sort' => $_GET['sort'] ?? 'latest', 'page' => $_GET['page'] ?? 1];
    return http_build_query(array_merge($base, $override));
}

$pageTitle = '실구매자 리뷰';
require __DIR__ . '/includes/header.php';
?>
<style>
.rl-wrap{max-width:1200px;margin:0 auto;padding:40px 20px 80px;}
.rl-head{margin-bottom:24px;}
.rl-kicker{font-size:13px;font-weight:700;color:#0d9488;margin:0 0 6px;}
.rl-title{font-size:28px;font-weight:800;color:#0f172a;margin:0 0 6px;}
.rl-sub{font-size:14px;color:#94a3b8;margin:0;}
.rl-filter-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin:24px 0 8px;border-bottom:1px solid #e5e7eb;padding-bottom:14px;}
.rl-tabs{display:flex;gap:6px;flex-wrap:wrap;}
.rl-tab{font-size:13px;font-weight:700;color:#64748b;padding:8px 16px;border-radius:999px;border:1px solid #e2e8f0;transition:.15s;}
.rl-tab:hover{border-color:#a5b4fc;}
.rl-tab.active{background:#0f172a;color:#fff;border-color:#0f172a;}
.rl-sort{display:flex;gap:4px;}
.rl-sort-btn{font-size:13px;color:#94a3b8;padding:6px 12px;font-weight:600;}
.rl-sort-btn.active{color:#0f172a;font-weight:800;text-decoration:underline;}
.rl-count-info{font-size:13px;color:#94a3b8;margin:12px 0 20px;}
.rl-empty{color:#94a3b8;padding:60px 0;text-align:center;}
.rl-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
@media (max-width:900px){.rl-grid{grid-template-columns:repeat(2,1fr);}}
@media (max-width:600px){.rl-grid{grid-template-columns:1fr;}}
.rl-card{background:#fff;border-radius:16px;padding:18px;box-shadow:0 2px 10px rgba(15,23,42,.06);display:flex;flex-direction:column;gap:10px;}
.rl-card-top{display:flex;gap:10px;align-items:center;}
.rl-thumb{width:52px;height:52px;border-radius:10px;overflow:hidden;background:#f1f5f9;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
.rl-thumb img{width:100%;height:100%;object-fit:cover;}
.rl-card-info{min-width:0;}
.rl-brand{font-size:11px;font-weight:700;color:#6366f1;display:block;}
.rl-product-name{font-size:13px;font-weight:700;color:#0f172a;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.rl-stars{color:#fbbf24;font-size:13px;}
.rl-badge-row{display:flex;gap:6px;flex-wrap:wrap;}
.rl-badge{font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;}
.rl-badge-service{background:#eef2ff;color:#4338ca;}
.rl-badge-option{background:#f0fdf4;color:#15803d;}
.rl-content{font-size:14px;color:#334155;line-height:1.6;min-height:44px;}
.rl-photo-row{display:flex;gap:6px;}
.rl-photo-thumb{width:64px;height:64px;border-radius:10px;overflow:hidden;}
.rl-photo-thumb img{width:100%;height:100%;object-fit:cover;}
.rl-meta-row{display:flex;align-items:center;gap:6px;font-size:12px;color:#94a3b8;margin-top:auto;}
.rl-dot{color:#e2e8f0;}
.rl-pagination{display:flex;justify-content:center;gap:6px;margin-top:36px;}
.rl-page-btn{width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:13px;font-weight:700;color:#64748b;border:1px solid #e2e8f0;}
.rl-page-btn.active{background:#0f172a;color:#fff;border-color:#0f172a;}
</style>
<main class="tt-main">
<div class="rl-wrap">
  <div class="rl-head">
    <p class="rl-kicker">REAL REVIEW</p>
    <h1 class="rl-title">실시간 리뷰</h1>
    <p class="rl-sub">실제 구매자들이 남긴 솔직한 후기를 모두 확인해보세요</p>
  </div>

  <div class="rl-filter-row">
    <div class="rl-tabs">
      <a href="?<?= h(rl_query_with(['service' => 'all', 'page' => 1])) ?>" class="rl-tab <?= $filterService === 'all' ? 'active' : '' ?>">전체</a>
      <?php foreach ($serviceOptions as $opt): ?>
        <a href="?<?= h(rl_query_with(['service' => $opt, 'page' => 1])) ?>" class="rl-tab <?= $filterService === $opt ? 'active' : '' ?>"><?= h($opt) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="rl-sort">
      <a href="?<?= h(rl_query_with(['sort' => 'latest', 'page' => 1])) ?>" class="rl-sort-btn <?= $sort === 'latest' ? 'active' : '' ?>">최신순</a>
      <a href="?<?= h(rl_query_with(['sort' => 'rating', 'page' => 1])) ?>" class="rl-sort-btn <?= $sort === 'rating' ? 'active' : '' ?>">평점높은순</a>
    </div>
  </div>

  <p class="rl-count-info">총 <strong><?= number_format($totalCount) ?></strong>개의 리뷰</p>

  <?php if (empty($reviews)): ?>
    <p class="rl-empty">아직 등록된 리뷰가 없습니다.</p>
  <?php else: ?>
    <div class="rl-grid">
      <?php foreach ($reviews as $rv): ?>
        <div class="rl-card">
          <div class="rl-card-top">
            <a href="<?= BASE_URL ?>/product-detail.php?id=<?= (int)$rv['product_id'] ?>" class="rl-thumb">
              <?php if (!empty($rv['thumbnail_url'])): ?>
                <img src="<?= h($rv['thumbnail_url']) ?>" alt="<?= h($rv['product_name'] ?? '') ?>" loading="lazy">
              <?php else: ?>
                <span>🛞</span>
              <?php endif; ?>
            </a>
            <div class="rl-card-info">
              <?php if (!empty($rv['brand_name'])): ?><span class="rl-brand"><?= h($rv['brand_name']) ?></span><?php endif; ?>
              <a href="<?= BASE_URL ?>/product-detail.php?id=<?= (int)$rv['product_id'] ?>" class="rl-product-name"><?= h($rv['product_name'] ?? '판매종료 상품') ?></a>
              <div class="rl-stars"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?></div>
            </div>
          </div>

          <div class="rl-badge-row">
            <?php if (!empty($rv['service_type'])): ?><span class="rl-badge rl-badge-service"><?= h($rv['service_type']) ?></span><?php endif; ?>
            <?php foreach ($rv['option_tag_list'] as $tag): ?><span class="rl-badge rl-badge-option"><?= h($tag) ?></span><?php endforeach; ?>
          </div>

          <p class="rl-content"><?= h($rv['content']) ?></p>

          <?php if (!empty($rv['photos'])): ?>
            <div class="rl-photo-row">
              <?php foreach (array_slice($rv['photos'], 0, 3) as $photoUrl): ?>
                <div class="rl-photo-thumb"><img src="<?= h($photoUrl) ?>" alt="리뷰 사진" loading="lazy"></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="rl-meta-row">
            <span><?= h($rv['user_name_masked']) ?></span>
            <span class="rl-dot">·</span>
            <span><?= h(date('Y.m.d', strtotime($rv['created_at']))) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="rl-pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?<?= h(rl_query_with(['page' => $p])) ?>" class="rl-page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
