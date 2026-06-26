<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . 'pages/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        $db = getDb();
        $user = $db->fetchOne(
            "SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1",
            [$username, $username]
        );

        if ($user && $user['status'] === 'active' && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_county_id'] = $user['county_id'];
            $_SESSION['user_association_id'] = $user['association_id'];

            if ($user['county_id']) {
                $county = $db->fetchOne("SELECT name, group_label FROM counties WHERE id = ?", [$user['county_id']]);
                $_SESSION['user_group_label'] = $county ? $county['group_label'] : null;
                $_SESSION['user_county_name'] = $county ? $county['name'] : null;
            } else {
                $_SESSION['user_group_label'] = null;
                $_SESSION['user_county_name'] = null;
            }

            logActivity('login', 'User logged in');

            $redirect = $_SESSION['redirect_after_login'] ?? APP_URL . 'pages/dashboard.php';
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = 'Invalid credentials or account inactive.';
        }
    }
}

$pageTitle = 'Login';
?>
<?php include __DIR__ . '/../templates/header.php'; ?>

<div class="login-card">
    <div class="card-header">
        <img src="<?= APP_URL ?>assets/images/ncsm.png" alt="Logo" style="height:150px;width:150px;object-fit:contain;border-radius:50%;" class="mb-2">
        <h4 class="mb-1"><?= APP_NAME ?></h4>
        <p class="mb-0 small opacity-75">Sign in to your account</p>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= $error ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Username or Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" name="username" class="form-control" placeholder="Enter username or email" required autofocus>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="password" class="form-control" placeholder="Enter password" required>
                    <button class="btn btn-outline-secondary" type="button" data-toggle="password-visibility data-target="#password">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                </button>
            </div>
        </form>

    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
