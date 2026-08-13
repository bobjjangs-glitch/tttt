<?php
$cartCount = 0;
if (Auth::isLoggedIn()) {
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) AS cnt FROM tt_carts WHERE user_id = :uid');
    $stmt->execute(['uid' => Auth::currentUserId()]);
    $cartCount = (int)$stmt->fetchColumn();
}

/* ===== 팝업 광고 조회: 활성 + 노출 기간 내인 것만, 순서대로 최대 5개 =====
   [FIX] 비로그인 사용자는 위 if 블록을 타지 않아 $pdo가 없을 수 있으므로
   여기서 한 번 더 안전하게 커넥션을 확보한다. */
$pdo = $pdo ?? Database::connection();
$activePopups = [];
try {
    $ppStmt = $pdo->query("
        SELECT id, title, image_url, link_url, width, height, allow_today_close
        FROM tt_popups
        WHERE is_active = 1
          AND (start_at IS NULL OR start_at <= NOW())
          AND (end_at IS NULL OR end_at >= NOW())
        ORDER BY sort_order ASC, id DESC
        LIMIT 5
    ");
    $activePopups = $ppStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[popup fetch] ' . $e->getMessage());
    $activePopups = [];
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
  <?php if (!empty($activePopups)): ?>
<style>
.tt-popup-overlay-wrap { position: fixed; inset: 0; z-index: 2000; pointer-events: none; }
.tt-popup-box {
  position: absolute;
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 20px 50px rgba(0,0,0,.25);
  overflow: hidden;
  pointer-events: auto;
  animation: ttPopupIn .25s ease;
  max-width: 92vw;
  max-height: 88vh;
}
@keyframes ttPopupIn { from { opacity:0; transform: translateY(10px) scale(.97); } to { opacity:1; transform: translateY(0) scale(1); } }

/* [FIX] 링크 유무와 무관하게 이미지 영역 높이가 항상 "박스 전체 - 풋터 40px"로
   고정되도록 별도 래퍼(.tt-popup-img-wrap)를 둔다. 이전에는 <a>에만 이 높이를 줘서
   링크 없는 팝업의 img가 박스 전체 높이를 차지해 풋터를 밀어내고 잘리게 만들었다. */
.tt-popup-img-wrap { display:block; width:100%; height:calc(100% - 40px); overflow:hidden; }
.tt-popup-img-wrap a { display:block; width:100%; height:100%; }
.tt-popup-img-wrap img { display:block; width:100%; height:100%; object-fit: contain; background:#f8fafc; }

.tt-popup-footer {
  height: 40px; display:flex; align-items:center; justify-content:space-between;
  padding: 0 12px; background:#f8fafc; border-top:1px solid #e2e8f0; font-size:12px;
}
.tt-popup-today-btn { background:none; border:none; color:#475569; font-size:12px; cursor:pointer; }
.tt-popup-close-btn { background:none; border:none; color:#94a3b8; font-size:16px; cursor:pointer; font-weight:700; }
</style>

<div class="tt-popup-overlay-wrap" id="ttPopupOverlayWrap">
  <?php foreach ($activePopups as $i => $pp): ?>
  <div class="tt-popup-box"
       id="ttPopupBox<?= (int)$pp['id'] ?>"
       data-popup-id="<?= (int)$pp['id'] ?>"
       style="width:<?= (int)$pp['width'] ?>px; height:<?= (int)($pp['height'] + 40) ?>px;">
    <div class="tt-popup-img-wrap">
      <?php if (!empty($pp['link_url'])): ?>
        <a href="<?= h($pp['link_url']) ?>"><img src="<?= h($pp['image_url']) ?>" alt="<?= h($pp['title']) ?>"></a>
      <?php else: ?>
        <img src="<?= h($pp['image_url']) ?>" alt="<?= h($pp['title']) ?>">
      <?php endif; ?>
    </div>
    <div class="tt-popup-footer">
      <?php if ((int)$pp['allow_today_close'] === 1): ?>
        <button type="button" class="tt-popup-today-btn" data-hide-today="<?= (int)$pp['id'] ?>">오늘 하루 보지 않기</button>
      <?php else: ?>
        <span></span>
      <?php endif; ?>
      <button type="button" class="tt-popup-close-btn" data-close="<?= (int)$pp['id'] ?>" aria-label="닫기">&times;</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>


<script>
(function () {
  function getCookie(name) {
    const m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : null;
  }
  function setCookieUntilMidnight(name) {
    const now = new Date();
    const midnight = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 0, 0, 0);
    document.cookie = name + '=1; expires=' + midnight.toUTCString() + '; path=/';
  }

  const boxes = Array.from(document.querySelectorAll('.tt-popup-box'));
  let visibleIndex = 0;
  const OFFSET = 24;

  function layout() {
    let shown = 0;
    boxes.forEach((box) => {
      const id = box.dataset.popupId;
      if (getCookie('tt_popup_hide_' + id)) { box.style.display = 'none'; return; }
      box.style.display = '';
      box.style.left = (60 + shown * OFFSET) + 'px';
      box.style.top  = (60 + shown * OFFSET) + 'px';
      shown++;
    });
    if (shown === 0) {
      document.getElementById('ttPopupOverlayWrap')?.remove();
    }
  }
  layout();

  document.querySelectorAll('.tt-popup-close-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('ttPopupBox' + btn.dataset.close)?.remove();
      layout();
    });
  });
  document.querySelectorAll('[data-hide-today]').forEach(btn => {
    btn.addEventListener('click', () => {
      setCookieUntilMidnight('tt_popup_hide_' + btn.dataset.hideToday);
      document.getElementById('ttPopupBox' + btn.dataset.hideToday)?.remove();
      layout();
    });
  });
})();
</script>
<?php endif; ?>

