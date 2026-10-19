<?php
require_once __DIR__ . '/core/bootstrap.php';
// signup.php는 더 이상 별도 페이지가 아님 — login.php의 탭으로 통합됨
redirect(BASE_URL . '/login.php#signup-personal');
