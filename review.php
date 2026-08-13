<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

$pdo = Database::connection();

// ── 페이지네이션 ──
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 12;

// ── 별점 필터 ──
$ratingFilter = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$where  = [];
$params = [];
if ($ratingFilter >= 1 && $ratingFilter <= 5) {
    $where[] = 'r.rating = :rating';
    $params[':rating'] = $ratingFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── 전체 개수 → 총 페이지 계산 ──
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_reviews r $whereSql");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

// ── 목록 조회 (최신순) — 실제 tt_reviews 컬럼: id, product_id, user_id, rating, content, created_at ──
$sql = "SELECT r.id, r.rating, r.content, r.created_at,
               p.id AS product_id, p.name AS product_name, p.thumbnail_url,
               u.name AS user_name
        FROM tt_reviews r
        JOIN tt_products p ON p.id = r.product_id
        JOIN tt_users u ON u.id = r.user_id
        $whereSql
        ORDER BY r.created_at DESC
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── 전체 통계 (평균 별점) ──
$stat = $pdo->query("SELECT COUNT(*) AS cnt, AVG(rating) AS avg_rating FROM tt_reviews")
             ->fetch(PDO::FETCH_ASSOC);

/**
 * 작성자 이름 마스킹 — 개인정보 노출 방지를 위해 실명을 그대로 노출하지 않는다.
 * 예: "홍길동" -> "홍*동", "이몽" -> "이*"
 */
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
?>

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
    <?php foreach ($reviews as $rv): ?>
      <div class="rv-card">
        <a href="<?= BASE_URL ?>/product-detail.php?id=<?= (int)$rv['product_id'] ?>" class="rv-card-thumb">
          <?php if (!empty($rv['thumbnail_url'])): ?>
            <img src="<?= h($rv['thumbnail_url']) ?>" alt="<?= h($rv['product_name']) ?>">
          <?php else: ?>
            <span class="ph">🛞</span>
          <?php endif; ?>
        </a>
        <div class="rv-card-body">
          <a href="<?= BASE_URL ?>/product-detail.php?id=<?= (int)$rv['product_id'] ?>" class="rv-card-product">
            <?= h($rv['product_name']) ?>
          </a>
          <div class="rv-card-meta">
            <span class="rv-stars"><?= starHtml((int)$rv['rating']) ?></span>
            <span class="rv-user"><?= h(maskUserName($rv['user_name'])) ?></span>
            <span class="rv-date"><?= h(date('Y.m.d', strtotime($rv['created_at']))) ?></span>
          </div>
          <p class="rv-content"><?= nl2br(h($rv['content'])) ?></p>
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

<?php require __DIR__ . '/includes/footer.php'; ?>
