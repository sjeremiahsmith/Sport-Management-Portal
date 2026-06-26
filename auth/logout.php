<?php

require_once __DIR__ . '/../includes/config.php';

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/functions.php';
    logActivity('logout', 'User logged out');
}

$_SESSION = [];
session_destroy();

header('Location: ' . APP_URL . 'auth/login.php');
exit;
