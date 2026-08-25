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
  <aside class="admin-sidebar">
    <div class="admin-logo"><?= h(defined('SITE_NAME') ? SITE_NAME : '타이어탑') ?> 관리자</div>
    <nav class="admin-nav">
      <a href="<?= BASE_URL ?>/admin/index.php" class="<?= admin_nav_active($currentScript, 'index.php') ?>">대시보드</a>

      <?php if (AdminAuth::can('orders')): ?>
      <a href="<?= BASE_URL ?>/admin/orders.php" class="<?= admin_nav_active($currentScript, 'orders.php') ?>">주문 관리</a>
      <?php endif; ?>

      <?php if (AdminAuth::can('shipping')): ?>
      <a href="<?= BASE_URL ?>/admin/shipping.php" class="<?= admin_nav_active($currentScript, 'shipping.php') ?>">🚚 배송비 설정</a>
      <?php endif; ?>

      <?php if (AdminAuth::can('products')): ?>
      <a href="<?= BASE_URL ?>/admin/products.php" class="<?= admin_nav_active($currentScript, 'products.php') ?>">상품 관리</a>
      <?php endif; ?>
      <?php if (AdminAuth::can('coupons')): ?>
<a href="<?= BASE_URL ?>/admin/coupons.php" class="<?= admin_nav_active($currentScript, 'coupons.php') ?>">🎟️ 쿠폰 관리</a>
<?php endif; ?>


      <?php if (AdminAuth::can('brands')): ?>
      <a href="<?= BASE_URL ?>/admin/brands.php" class="<?= admin_nav_active($currentScript, 'brands.php') ?>">브랜드 관리</a>
      <?php endif; ?>

      <?php if (AdminAuth::can('banners')): ?>
      <a href="<?= BASE_URL ?>/admin/banners.php" class="<?= admin_nav_active($currentScript, 'banners.php') ?>">홈 화면 관리 (배너·아이콘·프로모)</a>
      <?php endif; ?>
      <?php if (AdminAuth::can('banners')): ?>
<a href="<?= BASE_URL ?>/admin/popups.php" class="<?= admin_nav_active($currentScript, 'popups.php') ?>">팝업 광고 관리</a>
<?php endif; ?>


      <?php if (AdminAuth::can('reviews')): ?>
      <a href="<?= BASE_URL ?>/admin/reviews.php" class="<?= admin_nav_active($currentScript, 'reviews.php') ?>">리뷰 관리</a>
      <?php endif; ?>

      <?php if (AdminAuth::can('users')): ?>
      <a href="<?= BASE_URL ?>/admin/users.php" class="<?= admin_nav_active($currentScript, 'users.php') ?>">회원 관리</a>
      <?php endif; ?>

      <?php if ($isSuperAdmin): ?>
      <a href="<?= BASE_URL ?>/admin/admins.php" class="<?= admin_nav_active($currentScript, 'admins.php') ?>">관리자 계정</a>
      <?php endif; ?>
    </nav>

    <div class="admin-sidebar-footer">
      <div class="admin-user-info">
        <span class="admin-user-name"><?= h($adminName) ?></span>
        <span class="admin-role-badge admin-role-<?= h($adminRole) ?>"><?= h($adminRoleLabel) ?></span>
      </div>
      <a href="<?= BASE_URL ?>/admin/logout.php" class="admin-logout-link">로그아웃</a>
    </div>
  </aside>
  <div class="admin-content">
    <header class="admin-topbar">
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
