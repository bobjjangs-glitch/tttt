<?php
declare(strict_types=1);
require_once __DIR__ . '/../../core/bootstrap.php';
AdminAuth::requireLogin();

$currentScript = basename($_SERVER['SCRIPT_NAME']);
$adminName     = method_exists('AdminAuth', 'currentAdminName') ? AdminAuth::currentAdminName() : '관리자';
$adminRole     = method_exists('AdminAuth', 'currentRole') ? AdminAuth::currentRole() : 'cs';
$isSuperAdmin  = method_exists('AdminAuth', 'isSuper') ? AdminAuth::isSuper() : false;

$adminRoleLabels = [
    'super'   => '최고관리자',
    'product' => '상품/배너 담당',
    'order'   => '주문/재고 담당',
    'cs'      => '고객응대 담당',
];
$adminRoleLabel = $adminRoleLabels[$adminRole] ?? $adminRole;

// 현재 페이지가 어느 그룹에 속하는지 확인 (드롭다운 자동 펼침용)
$orderGroup = ['orders.php', 'business-orders.php', 'reservations.php', 'reservation-reminders.php'];
$taxGroup = ['tax-invoices.php', 'tax-invoice-faxes.php', 'tax-invoice-print.php', 'tax-invoice-export.php'];
$productGroup = ['products.php', 'brands.php', 'coupons.php', 'benefits-settings.php', 'reviews.php', 'popups.php', 'banners.php', 'bulk-upload.php', 'stock-movements.php', 'ecount-items.php'];
$memberGroup = ['users.php', 'business-approvals.php', 'inquiries.php', 'chatbot.php', 'vehicles.php'];
$settleGroup = ['daily-settlement.php', 'daily-settlement-print.php', 'monthly-settlement.php', 'monthly-settlement-print.php', 'card-fees.php', 'wholesale-sales.php', 'wholesale-sale-print.php'];
$tabletGroup = ['work-logs.php', 'workers.php'];

$activeGroup = '';
if (in_array($currentScript, $orderGroup, true)) $activeGroup = 'orders';
elseif (in_array($currentScript, $taxGroup, true)) $activeGroup = 'tax';
elseif (in_array($currentScript, $settleGroup, true)) $activeGroup = 'settle';
elseif (in_array($currentScript, $tabletGroup, true)) $activeGroup = 'tablet';
elseif (in_array($currentScript, $productGroup, true)) $activeGroup = 'products';
elseif (in_array($currentScript, $memberGroup, true)) $activeGroup = 'members';

function admin_nav_active(string $script, string $target): string {
    return $script === $target ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? h($pageTitle) . ' - 관리자' : '관리자' ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/common.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/admin/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-layout">
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-logo">
      <span class="admin-logo-text"><?= h(defined('SITE_NAME') ? SITE_NAME : '타이어탑') ?></span>
      <span class="admin-logo-sub">관리자</span>
    </div>
    <nav class="admin-nav">

      <!-- 대시보드 -->
      <a href="<?= BASE_URL ?>/admin/index.php" class="admin-nav-item <?= admin_nav_active($currentScript, 'index.php') ?>">
        <span class="admin-nav-icon">📊</span>
        <span>대시보드</span>
      </a>

      <!-- 주문 관리 (드롭다운) -->
      <?php if (AdminAuth::can('orders') || AdminAuth::can('business-orders') || AdminAuth::can('reservations') || AdminAuth::can('tax-invoices')): ?>
      <div class="admin-nav-group <?= $activeGroup === 'orders' ? 'open' : '' ?>" data-group="orders">
        <button type="button" class="admin-nav-toggle">
          <span class="admin-nav-icon">📦</span>
          <span>주문 관리</span>
          <span class="admin-nav-arrow">▾</span>
        </button>
        <div class="admin-nav-sub">
          <?php if (AdminAuth::can('orders')): ?>
          <a href="<?= BASE_URL ?>/admin/orders.php" class="<?= admin_nav_active($currentScript, 'orders.php') ?>">주문 관리</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('business-orders')): ?>
          <a href="<?= BASE_URL ?>/admin/business-orders.php" class="<?= admin_nav_active($currentScript, 'business-orders.php') ?>">사업자 주문 관리</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('reservations')): ?>
          <a href="<?= BASE_URL ?>/admin/reservations.php" class="<?= admin_nav_active($currentScript, 'reservations.php') ?>">🗓️ 예약 관리</a>
          <a href="<?= BASE_URL ?>/admin/reservation-reminders.php" class="<?= admin_nav_active($currentScript, 'reservation-reminders.php') ?>">📩 예약 리마인더</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('tax-invoices')): ?>
          <a href="<?= BASE_URL ?>/admin/tax-invoices.php" class="<?= admin_nav_active($currentScript, 'tax-invoices.php') ?>">🧾 세금계산서</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- 세금계산서 관리 (전용 섹션) -->
      <?php if (AdminAuth::can('tax-invoices')): ?>
      <div class="admin-nav-group <?= $activeGroup === 'tax' ? 'open' : '' ?>" data-group="tax">
        <button type="button" class="admin-nav-toggle">
          <span class="admin-nav-icon">🧾</span>
          <span>세금계산서 관리</span>
          <span class="admin-nav-arrow">▾</span>
        </button>
        <div class="admin-nav-sub">
          <a href="<?= BASE_URL ?>/admin/tax-invoices.php" class="<?= admin_nav_active($currentScript, 'tax-invoices.php') ?>">발행 관리 (인쇄·엑셀)</a>
          <a href="<?= BASE_URL ?>/admin/tax-invoice-faxes.php" class="<?= admin_nav_active($currentScript, 'tax-invoice-faxes.php') ?>">📠 팩스 발송 관리</a>
        </div>
      </div>
      <?php endif; ?>

      <!-- 정산 · 인센티브 (전용 섹션) [v42 → v43: 도매매출 관리 추가] -->
      <?php if (AdminAuth::can('daily-settlement') || AdminAuth::can('monthly-settlement') || AdminAuth::can('card-fees') || AdminAuth::can('wholesale-sales')): ?>
      <div class="admin-nav-group <?= $activeGroup === 'settle' ? 'open' : '' ?>" data-group="settle">
        <button type="button" class="admin-nav-toggle">
          <span class="admin-nav-icon">💼</span>
          <span>정산 · 인센티브</span>
          <span class="admin-nav-arrow">▾</span>
        </button>
        <div class="admin-nav-sub">
          <?php if (AdminAuth::can('daily-settlement')): ?>
          <a href="<?= BASE_URL ?>/admin/daily-settlement.php" class="<?= admin_nav_active($currentScript, 'daily-settlement.php') . ($currentScript === 'daily-settlement-print.php' ? ' active' : '') ?>">💰 일정산 마감</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('monthly-settlement')): ?>
          <a href="<?= BASE_URL ?>/admin/monthly-settlement.php" class="<?= admin_nav_active($currentScript, 'monthly-settlement.php') . ($currentScript === 'monthly-settlement-print.php' ? ' active' : '') ?>">📅 월간 정산 · 인센티브</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('wholesale-sales')): ?>
          <a href="<?= BASE_URL ?>/admin/wholesale-sales.php" class="<?= admin_nav_active($currentScript, 'wholesale-sales.php') . ($currentScript === 'wholesale-sale-print.php' ? ' active' : '') ?>">📦 도매매출 관리 (일반 도매)</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('card-fees')): ?>
          <a href="<?= BASE_URL ?>/admin/card-fees.php" class="<?= admin_nav_active($currentScript, 'card-fees.php') ?>">💳 카드 수수료 설정</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- 태블릿 · 작업일지 (전용 섹션) [v42 — 작업자/작업일지/사이트만] -->
      <?php if (AdminAuth::can('workers') || AdminAuth::can('work-logs')): ?>
      <div class="admin-nav-group <?= $activeGroup === 'tablet' ? 'open' : '' ?>" data-group="tablet">
        <button type="button" class="admin-nav-toggle">
          <span class="admin-nav-icon">🔧</span>
          <span>태블릿 · 작업일지</span>
          <span class="admin-nav-arrow">▾</span>
        </button>
        <div class="admin-nav-sub">
          <?php if (AdminAuth::can('workers')): ?>
          <a href="<?= BASE_URL ?>/admin/workers.php" class="<?= admin_nav_active($currentScript, 'workers.php') ?>">👷 작업자 관리</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('work-logs')): ?>
          <a href="<?= BASE_URL ?>/admin/work-logs.php" class="<?= admin_nav_active($currentScript, 'work-logs.php') ?>">📋 작업일지 관리</a>
          <?php endif; ?>
          <a href="<?= BASE_URL ?>/tablet/index.php" target="_blank">📱 태블릿 사이트 열기</a>
        </div>
      </div>
      <?php endif; ?>

      <!-- 상품 관리 (드롭다운) -->
      <?php if (AdminAuth::can('products') || AdminAuth::can('brands') || AdminAuth::can('coupons') || AdminAuth::can('reviews') || AdminAuth::can('banners') || AdminAuth::can('stock-movements') || AdminAuth::can('stock-requests')): ?>
      <div class="admin-nav-group <?= $activeGroup === 'products' ? 'open' : '' ?>" data-group="products">
        <button type="button" class="admin-nav-toggle">
          <span class="admin-nav-icon">🏷️</span>
          <span>상품 관리</span>
          <span class="admin-nav-arrow">▾</span>
        </button>
        <div class="admin-nav-sub">
          <?php if (AdminAuth::can('products')): ?>
          <a href="<?= BASE_URL ?>/admin/products.php" class="<?= admin_nav_active($currentScript, 'products.php') ?>">상품 관리</a>
          <a href="<?= BASE_URL ?>/admin/bulk-upload.php" class="<?= admin_nav_active($currentScript, 'bulk-upload.php') ?>">📤 일괄 업로드</a>
          <a href="<?= BASE_URL ?>/admin/ecount-items.php" class="<?= admin_nav_active($currentScript, 'ecount-items.php') ?>">📦 이카운트 품목 등록</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('stock-movements')): ?>
          <a href="<?= BASE_URL ?>/admin/stock-movements.php" class="<?= admin_nav_active($currentScript, 'stock-movements.php') ?>">📋 재고 입출고 이력</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('stock-requests')): ?>
          <a href="<?= BASE_URL ?>/admin/stock-requests.php" class="<?= admin_nav_active($currentScript, 'stock-requests.php') ?>">📥 재고 요청 관리</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('brands')): ?>
          <a href="<?= BASE_URL ?>/admin/brands.php" class="<?= admin_nav_active($currentScript, 'brands.php') ?>">브랜드 관리</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('coupons')): ?>
          <a href="<?= BASE_URL ?>/admin/coupons.php" class="<?= admin_nav_active($currentScript, 'coupons.php') ?>">쿠폰 관리</a>
          <a href="<?= BASE_URL ?>/admin/benefits-settings.php" class="<?= admin_nav_active($currentScript, 'benefits-settings.php') ?>">🎟️ 혜택 설정</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('reviews')): ?>
          <a href="<?= BASE_URL ?>/admin/reviews.php" class="<?= admin_nav_active($currentScript, 'reviews.php') ?>">리뷰 관리</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('banners')): ?>
          <a href="<?= BASE_URL ?>/admin/banners.php" class="<?= admin_nav_active($currentScript, 'banners.php') ?>">홈 화면 관리</a>
          <a href="<?= BASE_URL ?>/admin/popups.php" class="<?= admin_nav_active($currentScript, 'popups.php') ?>">팝업 광고 관리</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- 배송비 설정 -->
      <?php if (AdminAuth::can('shipping')): ?>
      <a href="<?= BASE_URL ?>/admin/shipping.php" class="admin-nav-item <?= admin_nav_active($currentScript, 'shipping.php') ?>">
        <span class="admin-nav-icon">🚚</span>
        <span>배송비 설정</span>
      </a>
      <?php endif; ?>

      <!-- 사업자가 설정 -->
      <?php if (AdminAuth::can('biz-pricing')): ?>
      <a href="<?= BASE_URL ?>/admin/biz-pricing.php" class="admin-nav-item <?= admin_nav_active($currentScript, 'biz-pricing.php') ?>">
        <span class="admin-nav-icon">🏢</span>
        <span>사업자가 설정</span>
      </a>
      <?php endif; ?>

      <!-- 회원 관리 (드롭다운) -->
      <?php if (AdminAuth::can('users') || AdminAuth::can('vehicles') || AdminAuth::can('business-approvals') || AdminAuth::can('inquiries') || AdminAuth::can('chatbot')): ?>
      <div class="admin-nav-group <?= $activeGroup === 'members' ? 'open' : '' ?>" data-group="members">
        <button type="button" class="admin-nav-toggle">
          <span class="admin-nav-icon">👥</span>
          <span>회원 관리</span>
          <span class="admin-nav-arrow">▾</span>
        </button>
        <div class="admin-nav-sub">
          <?php if (AdminAuth::can('users')): ?>
          <a href="<?= BASE_URL ?>/admin/users.php" class="<?= admin_nav_active($currentScript, 'users.php') ?>">회원 관리</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('vehicles')): ?>
          <a href="<?= BASE_URL ?>/admin/vehicles.php" class="<?= admin_nav_active($currentScript, 'vehicles.php') ?>">🚗 차량 교체주기 관리</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('business-approvals')): ?>
          <a href="<?= BASE_URL ?>/admin/business-approvals.php" class="<?= admin_nav_active($currentScript, 'business-approvals.php') ?>">사업자 승인</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('inquiries')): ?>
          <a href="<?= BASE_URL ?>/admin/inquiries.php" class="<?= admin_nav_active($currentScript, 'inquiries.php') ?>">1:1 문의</a>
          <?php endif; ?>
          <?php if (AdminAuth::can('chatbot')): ?>
          <a href="<?= BASE_URL ?>/admin/chatbot.php" class="<?= admin_nav_active($currentScript, 'chatbot.php') ?>">💬 AI 챗봇 관리</a>
          <?php endif; ?>
         </div>
       </div>
       <?php endif; ?>

       <!-- 연동 · API 관리 (드롭다운) -->
       <?php if ($isSuperAdmin): ?>
       <div class="admin-nav-group <?= in_array($currentScript, ['api-settings.php', 'sns-settings.php'], true) ? 'open' : '' ?>">
         <button type="button" class="admin-nav-toggle">
           <span class="admin-nav-icon">🔌</span>
           <span>연동 · API 관리</span>
           <span class="admin-nav-arrow">▾</span>
         </button>
         <div class="admin-nav-sub">
           <a href="<?= BASE_URL ?>/admin/api-settings.php" class="<?= admin_nav_active($currentScript, 'api-settings.php') ?>">🔗 외부 연동 관리</a>
           <a href="<?= BASE_URL ?>/admin/sns-settings.php" class="<?= admin_nav_active($currentScript, 'sns-settings.php') ?>">💬 SNS 관리</a>
         </div>
       </div>
       <?php endif; ?>

       <!-- 관리자 계정 -->
       <?php if ($isSuperAdmin): ?>
       <a href="<?= BASE_URL ?>/admin/admins.php" class="admin-nav-item <?= admin_nav_active($currentScript, 'admins.php') ?>">
         <span class="admin-nav-icon">⚙️</span>
         <span>관리자 계정</span>
       </a>
       <?php endif; ?>

    </nav>

    <div class="admin-sidebar-footer">
      <div class="admin-user-info">
        <div class="admin-user-avatar"><?= h(mb_substr($adminName, 0, 1)) ?></div>
        <div class="admin-user-detail">
          <span class="admin-user-name"><?= h($adminName) ?></span>
          <span class="admin-role-badge admin-role-<?= h($adminRole) ?>"><?= h($adminRoleLabel) ?></span>
        </div>
      </div>
      <a href="<?= BASE_URL ?>/admin/logout.php" class="admin-logout-link">로그아웃</a>
    </div>
  </aside>

  <!-- 사이드바 토글 오버레이 (모바일) -->
  <div class="admin-sidebar-overlay" id="adminSidebarOverlay"></div>

  <div class="admin-content">
    <header class="admin-topbar">
      <button type="button" class="admin-sidebar-toggle" id="adminSidebarToggle" aria-label="메뉴">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <h1 class="admin-page-title"><?= isset($pageTitle) ? h($pageTitle) : '' ?></h1>
    </header>
    <main class="admin-main">
      <?php $flashError = flash('admin_error'); ?>
      <?php if ($flashError): ?>
        <div class="admin-alert admin-alert-error"><?= h($flashError) ?></div>
      <?php endif; ?>
      <?php $flashSuccess = flash('admin_success'); ?>
      <?php if ($flashSuccess): ?>
        <div class="admin-alert admin-alert-success"><?= h($flashSuccess) ?></div>
      <?php endif; ?>

<script>
/* 드롭다운 토글 */
(function(){
  document.querySelectorAll('.admin-nav-toggle').forEach(function(toggle){
    toggle.addEventListener('click', function(){
      var group = this.closest('.admin-nav-group');
      group.classList.toggle('open');
    });
  });

  /* 모바일 사이드바 토글 */
  var sidebarToggle = document.getElementById('adminSidebarToggle');
  var sidebar = document.getElementById('adminSidebar');
  var overlay = document.getElementById('adminSidebarOverlay');
  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', function(){
      sidebar.classList.toggle('mobile-open');
      if (overlay) overlay.classList.toggle('show');
    });
  }
  if (overlay) {
    overlay.addEventListener('click', function(){
      sidebar.classList.remove('mobile-open');
      overlay.classList.remove('show');
    });
  }
})();
</script>
