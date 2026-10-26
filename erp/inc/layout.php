<?php
// inc/layout.php - 공통 레이아웃 (미실행 코드: 업로드 전 서버 원본을 PC에 백업할 것)
// 전제: functions.php가 먼저 로드되어 있어야 함 (h, cfg, current_user, csrf_field, flash_render)

require_once __DIR__ . '/menu.php';

/**
 * 페이지 상단 출력.
 * $title  : 브라우저 제목
 * $active : 예전 상단 메뉴 호환용 (새 메뉴는 파일명과 쿼리로 자동 판정하므로 쓰지 않음)
 */
function page_header($title = '', $active = ''): void
{
    $u = function_exists('current_user') ? current_user() : null;
    $GLOBALS['ERP_SIDE_OPEN'] = (bool)$u;   // page_footer가 감싸는 div를 닫을지 판단
    ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h((string)$title) ?> - <?= h((string)cfg('app.name', 'ERP')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar no-print">
  <a class="brand" href="index.php"><?= h((string)cfg('app.name', 'ERP')) ?></a>
  <?php if ($u): ?>
  <form method="post" action="logout.php" class="userbox">
    <?= csrf_field() ?>
    <span><?= h((string)$u['name']) ?></span>
    <button class="btn btn-sm" type="submit">로그아웃</button>
  </form>
  <?php endif; ?>
</header>
<?php if ($u): ?>
<?= erp_render_nav() ?>
<div class="app-wrap">
<?= erp_render_side() ?>
<?php endif; ?>
<main class="container">
<?= flash_render() ?>
<?php
}

function page_footer(): void
{
    ?>
</main>
<?php if (!empty($GLOBALS['ERP_SIDE_OPEN'])): ?>
</div>
<?php endif; ?>
</body>
</html>
<?php
}
