<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (is_post() && Csrf::verify($_POST['csrf_token'] ?? null)) {
    unset($_SESSION['tt_worker_id'], $_SESSION['tt_worker_name'], $_SESSION['tbl_login_worker']);
    session_regenerate_id(true);
}
redirect('/tablet/login.php');
