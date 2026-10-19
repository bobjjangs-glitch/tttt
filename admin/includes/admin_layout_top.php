<?php
// /admin/includes/admin_layout_top.php
require_once __DIR__ . '/../../core/bootstrap.php';
AdminAuth::requireLogin();
?>
<!DOCTYPE html>
<html lang="ko"><head><meta charset="UTF-8"><title>TIRETOP 관리자</title>
<link rel="stylesheet" href="/admin/assets/admin.css"></head>
<body>
<div class="admin-shell">
  <aside class="admin-sidebar">
    <h2>TIRETOP ADMIN</h2>
    <nav>
      <a href="/admin/dashboard.php">대시보드</a>
      <a href="/admin/products/list.php">상품관리</a>
      <a href="/admin/orders/list.php">주문관리</a>
      <a href="/admin/users/list.php">회원관리</a>
      <a href="/admin/banners/list.php">배너관리</a>
      <a href="/admin/notices/list.php">공지사항</a>
      <a href="/admin/logout.php">로그아웃</a>
    </nav>
  </aside>
  <main class="admin-main">
