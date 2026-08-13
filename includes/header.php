<?php
$cartCount = 0;
if (Auth::isLoggedIn()) {
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) AS cnt FROM tt_carts WHERE user_id = :uid');
    $stmt->execute(['uid' => Auth::currentUserId()]);
    $cartCount = (int)$stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? h($pageTitle)." - ".SITE_NAME : SITE_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/common.css">
<script src="https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
</head>
<body>

<div class="tt-utilbar">
  <div class="tt-utilbar-inner">
    <a href="<?= BASE_URL ?>/guide.php">이용안내</a>
    <a href="<?= BASE_URL ?>/cs-center.php">고객센터</a>
  </div>
</div>

<header class="tt-header">
  <div class="tt-header-inner">
    <!-- ★ 변경: 텍스트 로고 → 이미지 로고 -->
    <a href="<?= BASE_URL ?>/index.php" class="tt-logo">
      <img src="<?= BASE_URL ?>/img/logo.png" alt="<?= h(SITE_NAME) ?>" class="tt-logo-img">
    </a>

    <nav class="tt-nav">
      <a href="<?= BASE_URL ?>/product-list.php?cat=tire">타이어</a>
      <a href="<?= BASE_URL ?>/product-list.php?cat=engineoil">엔진오일</a>
      <a href="<?= BASE_URL ?>/product-list.php?cat=battery">배터리</a>
      <a href="<?= BASE_URL ?>/review.php">리뷰</a>
    </nav>

    <!-- 헤더 통합 검색바 -->
    <div class="tt-header-search" id="ttHeaderSearch">
      <select id="ttSearchType" class="tt-search-type">
        <option value="size">사이즈</option>
        <option value="name">상품명</option>
      </select>
      <input
        type="text"
        id="ttSearchInput"
        class="tt-search-input"
        placeholder="예) 225/45R18"
        inputmode="text"
        autocomplete="off">
      <button type="button" id="ttSearchBtn" class="tt-search-btn" aria-label="검색">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
          <circle cx="11" cy="11" r="7"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
      </button>
    </div>

    <div class="tt-header-actions">
      <a href="<?= BASE_URL ?>/cart.php" class="tt-cart-link">
        장바구니
        <span class="tt-cart-badge" id="ttCartBadge" style="<?= $cartCount > 0 ? '' : 'display:none' ?>"><?= $cartCount ?></span>
      </a>
      <?php if (Auth::isLoggedIn()): ?>
        <a href="<?= BASE_URL ?>/mypage.php">마이페이지</a>
        <a href="<?= BASE_URL ?>/logout.php">로그아웃</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/login.php">로그인</a>
      
      <?php endif; ?>
    </div>
  </div>
</header>

<script>
window.ttSetCartCount = function(count) {
  const badge = document.getElementById('ttCartBadge');
  if (!badge) return;
  if (count > 0) {
    badge.textContent = count;
    badge.style.display = '';
  } else {
    badge.style.display = 'none';
  }
};

(function(){
  const typeSelect = document.getElementById('ttSearchType');
  const input       = document.getElementById('ttSearchInput');
  const btn          = document.getElementById('ttSearchBtn');
  if (!typeSelect || !input || !btn) return;

  const placeholders = {
    size: '예) 225/45R18',
    name: '상품명을 입력하세요'
  };

  typeSelect.addEventListener('change', function () {
    input.placeholder = placeholders[typeSelect.value] || '검색어를 입력하세요';
  });

  function doSearch() {
    const keyword = input.value.trim();
    if (keyword === '') {
      input.focus();
      return;
    }
    const params = new URLSearchParams();
    params.set('cat', '1');

    if (typeSelect.value === 'size') {
      const digitsOnly = keyword.replace(/[^0-9]/g, '');
      params.set('size_input', digitsOnly);
    } else {
      params.set('name', keyword);
    }

    window.location.href = '<?= BASE_URL ?>/product-list.php?' + params.toString();
  }

  btn.addEventListener('click', doSearch);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      doSearch();
    }
  });
})();
</script>

<main class="tt-main">
