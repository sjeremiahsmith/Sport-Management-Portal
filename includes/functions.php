<?php
require_once __DIR__ . '/db.php';

function getDb() {
    return Database::getInstance();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . APP_URL . 'auth/login.php');
        exit;
    }
}

function hasRole($roles) {
    if (!isLoggedIn()) return false;
    $roles = is_array($roles) ? $roles : [$roles];
    return in_array($_SESSION['user_role'], $roles);
}

function requireRole($roles) {
    requireLogin();
    if (!hasRole($roles)) {
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: ' . APP_URL . 'pages/dashboard.php');
        exit;
    }
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return getDb()->fetchOne(
        "SELECT u.*, c.name as county_name, s.name as sport_name, s.association_name
         FROM users u
         LEFT JOIN counties c ON u.county_id = c.id
         LEFT JOIN sports_disciplines s ON u.association_id = s.id
         WHERE u.id = ?",
        [$_SESSION['user_id']]
    );
}

function uploadPhoto($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) return ['success' => false, 'error' => 'Upload failed.'];

    if ($file['size'] > MAX_PHOTO_SIZE) return ['success' => false, 'error' => 'File too large. Max 2MB.'];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, ALLOWED_PHOTO_TYPES)) return ['success' => false, 'error' => 'Invalid file type. JPG, PNG, GIF only.'];

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('photo_') . '.' . $ext;
    $dest = PHOTO_PATH . $filename;

    if (!is_dir(PHOTO_PATH)) mkdir(PHOTO_PATH, 0755, true);

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => true, 'filename' => $filename];
    }
    return ['success' => false, 'error' => 'Failed to save file.'];
}

function uploadDocument($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) return ['success' => false, 'error' => 'Upload failed.'];
    if ($file['size'] > MAX_DOCUMENT_SIZE) return ['success' => false, 'error' => 'File too large. Max 10MB.'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, ALLOWED_DOCUMENT_TYPES)) return ['success' => false, 'error' => 'Invalid file type.'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('doc_') . '.' . $ext;
    $dest = DOCUMENT_PATH . $filename;
    if (!is_dir(DOCUMENT_PATH)) mkdir(DOCUMENT_PATH, 0755, true);
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => true, 'filename' => $filename, 'original_name' => $file['name'], 'mime' => $mime, 'size' => $file['size']];
    }
    return ['success' => false, 'error' => 'Failed to save file.'];
}

function logActivity($action, $description = '') {
    $db = getDb();
    $db->insert(
        "INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
        [$_SESSION['user_id'], $action, $description, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']
    );
}

function createNotification($userId, $title, $message, $type = 'info', $link = '') {
    return getDb()->insert(
        "INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)",
        [$userId, $title, $message, $type, $link]
    );
}

function getUnreadNotificationCount($userId) {
    return getDb()->fetchOne(
        "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0",
        [$userId]
    )['count'];
}

function getRecentNotifications($userId, $limit = 5) {
    return getDb()->fetchAll(
        "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
        [$userId, $limit]
    );
}

function getCountyGroupLabel($groupId) {
    $labels = ['A' => 'Group A', 'B' => 'Group B', 'C' => 'Group C', 'D' => 'Group D'];
    return $labels[$groupId] ?? 'Unknown';
}

function getGroupCounties($groupLabel) {
    return getDb()->fetchAll(
        "SELECT * FROM counties WHERE group_label = ? ORDER BY name",
        [$groupLabel]
    );
}

function getAllGroups() {
    $groups = [];
    foreach (['A', 'B', 'C', 'D'] as $label) {
        $groups[$label] = getGroupCounties($label);
    }
    return $groups;
}

function getPlayerCountByStatus($status = null, $sportId = null) {
    $sql = "SELECT COUNT(*) as count FROM players";
    $conditions = [];
    $params = [];
    if ($status) {
        $conditions[] = "status = ?";
        $params[] = $status;
    }
    if ($sportId) {
        $conditions[] = "sport_discipline_id = ?";
        $params[] = $sportId;
    }
    if ($conditions) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    return getDb()->fetchOne($sql, $params)['count'];
}

function getPlayerCountByCounty($countyId) {
    return getDb()->fetchOne(
        "SELECT COUNT(*) as count FROM players WHERE county_id = ?",
        [$countyId]
    )['count'];
}

function getPlayerCountBySport($sportId) {
    return getDb()->fetchOne(
        "SELECT COUNT(*) as count FROM players WHERE sport_discipline_id = ?",
        [$sportId]
    )['count'];
}

function getCounties() {
    return getDb()->fetchAll("SELECT * FROM counties ORDER BY group_label, name");
}

function getSports() {
    return getDb()->fetchAll("SELECT * FROM sports_disciplines WHERE status = 'active'");
}

function getAssociations() {
    return getDb()->fetchAll("SELECT DISTINCT association_name, association_code FROM sports_disciplines WHERE status = 'active'");
}

function formatDate($date, $format = 'M d, Y') {
    return date($format, strtotime($date));
}

function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . ' year(s) ago';
    if ($diff->m > 0) return $diff->m . ' month(s) ago';
    if ($diff->d > 0) return $diff->d . ' day(s) ago';
    if ($diff->h > 0) return $diff->h . ' hour(s) ago';
    if ($diff->i > 0) return $diff->i . ' minute(s) ago';
    return 'just now';
}

function getStatusBadge($status) {
    $map = [
        'draft' => 'secondary',
        'submitted' => 'info',
        'approved' => 'success',
        'rejected' => 'danger',
        'pending_review' => 'warning',
        'fit' => 'success',
        'unfit' => 'danger',
        'active' => 'success',
        'inactive' => 'secondary',
    ];
    $class = $map[$status] ?? 'secondary';
    return "<span class='badge bg-{$class}'>{$status}</span>";
}

function paginate($total, $page, $perPage = 20) {
    $totalPages = ceil($total / $perPage);
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    return [
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
        'offset' => $offset,
    ];
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function getPlayerPhotoUrl($photoPath) {
    if ($photoPath && file_exists(PHOTO_PATH . $photoPath)) {
        return APP_URL . 'uploads/photos/' . $photoPath;
    }
    return APP_URL . 'assets/images/default-avatar.svg';
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function setFlash($key, $message) {
    $_SESSION['flash'][$key] = $message;
}

function getCountyFlagUrl($countyName) {
    $map = [
        'Bomi' => 'Bomi.png',
        'Bong' => 'Bong.png',
        'Gbarpolu' => 'Gbarpolu.png',
        'Grand Bassa' => 'Grand Bassa.png',
        'Grand Cape Mount' => 'Grand Cape Mount.png',
        'Grand Gedeh' => 'Grand Gedeh.png',
        'Grand Kru' => 'Grand Kru.png',
        'Lofa' => 'Lofa.png',
        'Margibi' => 'Margibi.png',
        'Maryland' => 'Maryland.png',
        'Montserrado' => 'Montserrado.png',
        'Nimba' => 'Nimba.png',
        'River Cess' => 'Rivercess.jpg',
        'River Gee' => 'River Gee.png',
        'Sinoe' => 'Sinoe.png',
    ];
    $file = $map[$countyName] ?? null;
    if ($file && file_exists(__DIR__ . '/../assets/images/' . $file)) {
        return APP_URL . 'assets/images/' . $file;
    }
    return null;
}

function getFlash($key) {
    if (isset($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}
