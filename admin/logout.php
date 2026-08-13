<?php
// /admin/logout.php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::logout();
redirect('/admin/login.php');
