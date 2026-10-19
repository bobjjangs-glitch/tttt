<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
ensure_review_extra_columns();

$pdo = Database::connection();

$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

$ratingFilter = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$where  = [];
$params = [];
if ($ratingFilter >= 1 && $ratingFilter <= 5) {
    $where[] = 'r.rating = :rating';
    $params[':rating'] = $ratingFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_reviews r $whereSql");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$sql = "SELECT r.id, r.rating, r.content, r.option_tags, r.created_at,
               r.vehicle_model, r.visit_type, r.extra_service, r.helpful_count, r.store_id,
               p.id AS product_id, p.name AS product_name, p.thumbnail_url, p.spec,
               p.width_mm, p.aspect_ratio, p.rim_diameter,
               b.name AS brand_name,
               u.name AS user_name,
               s.name AS store_name, s.address AS store_address
        FROM tt_reviews r
        JOIN tt_products p ON p.id = r.product_id
        LEFT JOIN tt_brands b ON b.id = p.brand_id
        JOIN tt_users u ON u.id = r.user_id
        LEFT JOIN tt_stores s ON s.id = r.store_id
        $whereSql
        ORDER BY r.created_at DESC
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

$photosByReview = [];
if (!empty($reviews)) {
    $ids = array_column($reviews, 'id');
    $ph  = $pdo->prepare('SELECT review_id, image_url FROM tt_review_photos WHERE review_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sort_order ASC');
    $ph->execute($ids);
    foreach ($ph->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $photosByReview[(int)$row['review_id']][] = $row['image_url'];
    }
}

$helpfulByMe = [];
if (Auth::isLoggedIn() && !empty($reviews)) {
    $ids = array_column($reviews, 'id');
    $hf  = $pdo->prepare('SELECT review_id FROM tt_review_helpful WHERE user_id = ? AND review_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $hf->execute(array_merge([(int)Auth::currentUserId()], $ids));
    $helpfulByMe = array_flip($hf->fetchAll(PDO::FETCH_COLUMN));
}

$stat = $pdo->query("SELECT COUNT(*) AS cnt, AVG(rating) AS avg_rating FROM tt_reviews")
             ->fetch(PDO::FETCH_ASSOC);

function maskUserName(string $name): string {
    $len = mb_strlen($name);
    if ($len <= 1) return $name;
    if ($len === 2) return mb_substr($name, 0, 1) . '*';
    return mb_substr($name, 0, 1) . str_repeat('*', $len - 2) . mb_substr($name, -1, 1);
}

function starHtml(int $rating): string {
    $rating = max(0, min(5, $rating));
    $html = '';
    for ($i = 1; $i <= 5; $i++) { $html .= $i <= $rating ? '★' : '☆'; }
    return $html;
}

$pageTitle = '고객 리뷰';
require __DIR__ . '/includes/header.php';
$csrfToken = Csrf::token();
?>

<style>
.rv-wrap{max-width:1100px;margin:0 auto;padding:24px 20px 60px;}
.rv-head{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:8px;}
.rv-title{font-size:22px;font-weight:800;color:#0f172a;margin:0;}
.rv-summary{font-size:13px;color:#64748b;margin:0;}
.rv-filter-bar{display:flex;justify-content:flex-end;margin-bottom:14px;}
.rv-filter-bar select{padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;background:#fff;color:#334155;}
.rv-event-banner{display:flex;align-items:center;justify-content:space-between;background:linear-gradient(120deg,#1e2a5e,#3b3f8f);border-radius:16px;padding:20px 26px;margin-bottom:22px;color:#fff;}
.rv-event-banner .txt strong{display:block;font-size:16px;font-weight:800;margin-bottom:4px;}
.rv-event-banner .txt span{font-size:13px;opacity:.85;}
.rv-event-banner .badge{background:rgba(255,255,255,.15);border-radius:999px;padding:8px 16px;font-size:13px;font-weight:700;white-space:nowrap;}
.rv-list{display:flex;flex-direction:column;gap:14px;}
.rv-card{display:flex;gap:20px;border:1px solid #eef1f6;border-radius:16px;padding:20px 22px;background:#fff;}
.rv-card-main{flex:1;min-width:0;}
.rv-card-meta{display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;}
.rv-card-brand{font-size:13px;font-weight:800;color:#0f172a;}
.rv-card-vehicle{font-size:12px;color:#64748b;font-weight:600;}
.rv-stars{color:#fbbf24;font-size:13px;letter-spacing:1px;}
.rv-date{font-size:12px;color:#94a3b8;}
.rv-hot-badge{background:#fee2e2;color:#dc2626;font-size:11px;font-weight:800;border-radius:999px;padding:3px 9px;}
.rv-content{font-size:14px;color:#334155;line-height:1.6;margin:8px 0 10px;word-break:break-word;}
.rv-tag-row{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;}
.rv-tag-chip{background:#eef2ff;color:#4f46e5;border-radius:999px;padding:4px 12px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
.rv-photo-row{display:flex;gap:8px;margin-bottom:10px;}
.rv-photo-row img{width:72px;height:72px;object-fit:cover;border-radius:10px;border:1px solid #eef1f6;cursor:pointer;}
.rv-service-row{font-size:12px;color:#64748b;margin-bottom:6px;display:flex;flex-wrap:wrap;gap:10px;}
.rv-service-row b{color:#334155;font-weight:700;}
.rv-helpful-btn{display:inline-flex;align-items:center;gap:6px;border:1px solid #e2e8f0;background:#fff;border-radius:999px;padding:6px 14px;font-size:12px;color:#475569;cursor:pointer;}
.rv-helpful-btn.active{border-color:#4f46e5;color:#4f46e5;background:#eef2ff;}
.rv-product-box{width:200px;flex-shrink:0;border:1px solid #eef1f6;border-radius:14px;padding:16px;display:flex;flex-direction:column;align-items:center;gap:8px;text-align:center;}
.rv-product-thumb{width:64px;height:64px;border-radius:12px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;overflow:hidden;}
.rv-product-thumb img{width:100%;height:100%;object-fit:cover;}
.rv-product-brand{font-size:12px;color:#94a3b8;font-weight:700;}
.rv-product-name{font-size:13px;font-weight:800;color:#0f172a;}
.rv-product-spec{font-size:12px;color:#64748b;}
.rv-product-store{font-size:11px;color:#94a3b8;line-height:1.4;}
.rv-product-btn{margin-top:4px;background:#0f172a;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;text-decoration:none;}
.rv-empty{padding:60px 0;text-align:center;color:#94a3b8;font-size:14px;}
.rv-pagination{display:flex;justify-content:center;gap:6px;margin-top:28px;}
.rv-page-btn{padding:8px 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#334155;text-decoration:none;}
.rv-page-btn.active{background:#0f172a;color:#fff;border-color:#0f172a;}
@media (max-width:640px){.rv-card{flex-direction:column;} .rv-product-box{width:100%;flex-direction:row;text-align:left;}}
</style>

<main class="tt-main">
<div class="rv-wrap">
  <div class="rv-head">
    <h1 class="rv-title">고객 리뷰</h1>
    <p class="rv-summary">
      총 <strong><?= number_format($stat['cnt'] ?? 0) ?></strong>개의 리뷰
      <?php if (!empty($stat['avg_rating'])): ?>
        · 평균 <strong><?= number_format((float)$stat['avg_rating'], 1) ?></strong>점
      <?php endif; ?>
    </p>
  </div>

  <div class="rv-event-banner">
    <div class="txt">
      <strong>구매 리뷰 쓰고 14,000P 받자!</strong>
      <span>타이어픽 리뷰 이벤트</span>
    </div>
    <span class="badge">🎁 이벤트 자세히 보기</span>
  </div>

  <form method="get" class="rv-filter-bar">
    <select name="rating" onchange="this.form.submit()">
      <option value="0" <?= $ratingFilter === 0 ? 'selected' : '' ?>>전체 별점</option>
      <?php for ($i = 5; $i >= 1; $i--): ?>
        <option value="<?= $i ?>" <?= $ratingFilter === $i ? 'selected' : '' ?>><?= $i ?>점</option>
      <?php endfor; ?>
    </select>
  </form>

  <?php if (empty($reviews)): ?>
    <div class="rv-empty"><p>등록된 리뷰가 없습니다.</p></div>
  <?php else: ?>
  <div class="rv-list">
    <?php foreach ($reviews as $rv):
        $sizeLabel = '';
        if (!empty($rv['width_mm']) && !empty($rv['aspect_ratio']) && !empty($rv['rim_diameter'])) {
            $sizeLabel = $rv['width_mm'] . '/' . $rv['aspect_ratio'] . 'R' . $rv['rim_diameter'];
        } elseif (!empty($rv['spec'])) {
            $sizeLabel = $rv['spec'];
        }
        $tags        = review_parse_option_tags($rv['option_tags'] ?? null);
        $extraSvc    = review_parse_option_tags($rv['extra_service'] ?? null);
        $photos      = $photosByReview[(int)$rv['id']] ?? [];
        $helpfulCnt  = (int)$rv['helpful_count'];
        $iMarkedHelpful = isset($helpfulByMe[(int)$rv['id']]);
        $isHot       = is_review_hot($helpfulCnt, (int)$rv['rating'], count($photos));
    ?>
      <div class="rv-card">
        <div class="rv-card-main">
          <div class="rv-card-meta">
            <span class="rv-card-brand"><?= h($rv['brand_name'] ?? '') ?> · <?= h(maskUserName($rv['user_name'])) ?></span>
            <?php if (!empty($rv['vehicle_model'])): ?>
              <span class="rv-card-vehicle"><?= h($rv['vehicle_model']) ?></span>
            <?php endif; ?>
            <span class="rv-stars"><?= starHtml((int)$rv['rating']) ?></span>
            <span class="rv-date"><?= h(date('y.m.d', strtotime($rv['created_at']))) ?></span>
            <?php if ($isHot): ?><span class="rv-hot-badge">HOT</span><?php endif; ?>
          </div>

          <?php if (!empty($tags)): ?>
            <div class="rv-tag-row">
              <?php foreach ($tags as $t): ?>
                <span class="rv-tag-chip">✅ <?= h($t) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <p class="rv-content"><?= nl2br(h($rv['content'])) ?></p>

          <?php if (!empty($photos)): ?>
            <div class="rv-photo-row">
              <?php foreach ($photos as $url): ?>
                <img src="<?= h($url) ?>" alt="리뷰 사진" loading="lazy" onclick="window.open(this.src)">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if (!empty($rv['visit_type']) || !empty($extraSvc)): ?>
            <div class="rv-service-row">
              <?php if (!empty($rv['visit_type'])): ?><span><b>방문방식</b> <?= h($rv['visit_type']) ?></span><?php endif; ?>
              <?php if (!empty($extraSvc)): ?><span><b>추가서비스</b> <?= h(implode(', ', $extraSvc)) ?> 추가</span><?php endif; ?>
            </div>
          <?php endif; ?>

          <button type="button" class="rv-helpful-btn <?= $iMarkedHelpful ? 'active' : '' ?>"
                  data-review-id="<?= (int)$rv['id'] ?>" onclick="rvToggleHelpful(this)">
            👍 도움이 돼요 <span class="rv-helpful-count"><?= $helpfulCnt ?></span>
          </button>
        </div>

        <div class="rv-product-box">
          <div class="rv-product-thumb">
            <?php if (!empty($rv['thumbnail_url'])): ?>
              <img src="<?= h($rv['thumbnail_url']) ?>" alt="<?= h($rv['product_name']) ?>">
            <?php else: ?>
              <span>🛞</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($rv['brand_name'])): ?><span class="rv-product-brand"><?= h($rv['brand_name']) ?></span><?php endif; ?>
          <span class="rv-product-name"><?= h($rv['product_name']) ?></span>
          <?php if ($sizeLabel !== ''): ?><span class="rv-product-spec"><?= h($sizeLabel) ?></span><?php endif; ?>
          <?php if (!empty($rv['store_name'])): ?>
            <span class="rv-product-store"><?= h($rv['store_name']) ?><br><?= h($rv['store_address'] ?? '') ?></span>
          <?php endif; ?>
          <a class="rv-product-btn" href="<?= BASE_URL ?>/product-detail.php?id=<?= (int)$rv['product_id'] ?>">상품 보기</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="rv-pagination">
      <?php
        $qs = $ratingFilter ? '&rating=' . $ratingFilter : '';
        $startPage = max(1, $page - 2);
        $endPage   = min($totalPages, $page + 2);
      ?>
      <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?><?= $qs ?>" class="rv-page-btn">이전</a><?php endif; ?>
      <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
        <a href="?page=<?= $p ?><?= $qs ?>" class="rv-page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1 ?><?= $qs ?>" class="rv-page-btn">다음</a><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
</main>

<input type="hidden" id="rvCsrfToken" value="<?= h($csrfToken) ?>">
<script>
const rvIsLoggedIn = <?= Auth::isLoggedIn() ? 'true' : 'false' ?>;
async function rvToggleHelpful(btn) {
  if (!rvIsLoggedIn) {
    alert('로그인이 필요합니다.');
    location.href = '<?= BASE_URL ?>/login.php';
    return;
  }
  const reviewId = btn.dataset.reviewId;
  const token = document.getElementById('rvCsrfToken').value;
  btn.disabled = true;
  try {
    const res = await fetch('<?= BASE_URL ?>/review-helpful.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ review_id: reviewId, csrf_token: token })
    });
    const data = await res.json();
    if (!data.success) {
      alert(data.message || '처리 중 오류가 발생했습니다.');
      return;
    }
    btn.classList.toggle('active', data.data.helpful);
    btn.querySelector('.rv-helpful-count').textContent = data.data.count;
  } catch (e) {
    alert('네트워크 오류가 발생했습니다.');
  } finally {
    btn.disabled = false;
  }
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
