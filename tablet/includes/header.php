<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$worker = tablet_require_worker();
$today = date('Y-m-d');
$pageTitle = $pageTitle ?? '작업일지';
$stateDate = $stateDate ?? $today;
$stateHash = $stateHash ?? '';
$isAdminPreview = ((int)($worker['id'] ?? 0)) === 0;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h($pageTitle) ?> — 태블릿 작업일지</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/tablet/assets/css/tablet.css">
</head>
<body class="tablet-body" data-date="<?= h($stateDate) ?>" <?= $stateHash ? ('data-state-hash="' . h($stateHash) . '"') : '' ?>>
<header class="tablet-header">
  <div class="tablet-header-left">
    <span class="tablet-logo"><?= h(defined('SITE_NAME') ? SITE_NAME : 'TIRETOP') ?></span>
    <span class="tablet-logo-sub">작업일지</span>
  </div>
  <nav class="tablet-header-nav">
    <a href="<?= BASE_URL ?>/tablet/index.php" class="<?= ($pageKey ?? '') === 'today' ? 'on' : '' ?>">📋 오늘의 작업</a>
    <a href="<?= BASE_URL ?>/tablet/reservation-board.php" class="<?= ($pageKey ?? '') === 'reserve' ? 'on' : '' ?>">🗓️ 예약판</a>
    <a href="<?= BASE_URL ?>/tablet/log-add.php" class="<?= ($pageKey ?? '') === 'add' ? 'on' : '' ?>">➕ 작업 등록</a>
  </nav>
  <div class="tablet-header-right">
    <div class="tablet-worker-chip">
      <span class="tablet-worker-dot"></span>
      <?= h($worker['name'] ?? '작업자') ?><?= $isAdminPreview ? ' (관리자)' : '' ?>
    </div>
    <form method="post" action="<?= BASE_URL ?>/tablet/logout.php" class="inline-form">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <button type="submit" class="tablet-btn tablet-btn-ghost tablet-btn-sm">로그아웃</button>
    </form>
  </div>
</header>
<main class="tablet-main">
