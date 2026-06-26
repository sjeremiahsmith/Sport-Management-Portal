<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'sports_meet_portal');
define('DB_USER', 'root');
define('DB_PASS', '');

// Application configuration
define('APP_NAME', 'National County Sports Meet Portal');
define('APP_SHORT_NAME', 'National County Sports Meet Portal');
define('APP_URL', 'http://localhost/Sports%20Management%20Portal/');
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('PHOTO_PATH', UPLOAD_PATH . 'photos/');
define('DOCUMENT_PATH', UPLOAD_PATH . 'documents/');
define('MAX_PHOTO_SIZE', 2 * 1024 * 1024); // 2MB
define('ALLOWED_PHOTO_TYPES', ['image/jpeg', 'image/png', 'image/gif']);
define('MAX_DOCUMENT_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_DOCUMENT_TYPES', ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain', 'image/jpeg', 'image/png']);

// Timezone
date_default_timezone_set('Africa/Monrovia');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session after all ini settings are configured
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
