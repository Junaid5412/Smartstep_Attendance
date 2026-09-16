<?php
/**
 * Attendance panel sign-in. Credentials are the ERP's own user accounts.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once ATT_PATH . '/includes/api.php';
require_once ATT_PATH . '/includes/admin_auth.php';

// Already signed in (here or in the ERP): go straight through.
if (attAdmin()) {
    attRedirect(ATT_URL . '/admin/index.php');
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    attCheckCsrf();

    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $result = attAdminLogin($username, $password);
        if ($result['success']) {
            $target = $_SESSION['att_redirect_after_login'] ?? null;
            unset($_SESSION['att_redirect_after_login']);
            // Only ever return to a path inside this module.
            $safe = ($target && strpos($target, '/Attendance/') !== false)
                ? $target
                : ATT_URL . '/admin/index.php';
            attRedirect($safe);
        }
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in · <?php echo e(ATT_NAME); ?></title>
<link rel="stylesheet" href="<?php echo ATT_ASSETS_URL; ?>/admin.css?v=<?php echo ATT_VERSION; ?>">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="brand">
            <span class="brand-mark">SST</span>
            <span class="brand-text" style="color:var(--ink)">Attendance</span>
        </div>
        <h1>Attendance Dashboard</h1>
        <p class="lead">Sign in with your SmartStep ERP account</p>

        <?php if ($error): ?>
            <div class="alert error" style="margin:0 0 16px"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
            <div class="field">
                <label for="username">Username or email</label>
                <input type="text" id="username" name="username" required autofocus
                       value="<?php echo e($_POST['username'] ?? ''); ?>">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn">Sign in</button>
        </form>

        <p class="login-note">
            Employees use the <strong>SST Attendance</strong> mobile app, not this page.
        </p>
    </div>
</div>
</body>
</html>
