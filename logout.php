<?php
// /logout.php
require_once __DIR__ . '/core/bootstrap.php';
Auth::logout();
redirect(BASE_URL . '/index.php');
