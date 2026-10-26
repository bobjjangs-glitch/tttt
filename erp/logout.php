<?php
require __DIR__ . '/inc/bootstrap.php';
if (is_post()) {
    csrf_check();
    if (!empty($_SESSION['uid'])) {
        audit('logout', 'user', (int)$_SESSION['uid']);
    }
    $_SESSION = [];
    session_destroy();
}
redirect('login.php');
