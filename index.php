<?php

require_once __DIR__ . '/includes/config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . 'pages/dashboard.php');
} else {
    header('Location: ' . APP_URL . 'auth/login.php');
}
exit;
