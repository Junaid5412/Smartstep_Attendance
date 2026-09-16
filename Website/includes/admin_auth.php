<?php
/**
 * Admin-side authentication for the attendance panel.
 *
 * Credentials are the ERP's own `users` accounts, so nobody needs a second
 * password. If an ERP session is already open in the same browser it is adopted
 * silently (single sign-on); otherwise the panel's own login page authenticates
 * against the same table and starts an attendance session.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['att_csrf'])) {
    $_SESSION['att_csrf'] = bin2hex(random_bytes(32));
}

/**
 * Access is by ERP permission, not by role name.
 *
 * This used to be two hard-coded lists of roles — six could open the panel, four could
 * change things — so the only way to give or take away access was to edit this file.
 * Now it reads the same permissions table as the rest of the ERP, and access is granted
 * from the roles screen like everything else.
 *
 * Super admin bypasses the check, matching hasPermission() in includes/rbac.php.
 */
const ATT_PERMISSION_VIEW = 'attendance_panel.view';
const ATT_PERMISSION_MANAGE = 'attendance_panel.manage';

/**
 * Permission names granted to a user through their role.
 *
 * Mirrors getUserPermissions() in includes/rbac.php rather than calling it: the panel
 * runs on its own bootstrap and does not load the ERP's rbac.php, and reaching across
 * for one function would tie the two include orders together.
 */
function attUserPermissions($userId) {
    static $cache = [];

    $userId = (int)$userId;
    if (!$userId) {
        return [];
    }
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $stmt = attDB()->prepare("
        SELECT p.permission_name
        FROM permissions p
        JOIN role_permissions rp ON rp.permission_id = p.id
        JOIN users u ON u.role_id = rp.role_id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);

    return $cache[$userId] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Does the signed-in admin hold this permission? */
function attHasPermission($permission) {
    $admin = attAdmin();
    if (!$admin) {
        return false;
    }
    if ($admin['role_name'] === 'super_admin') {
        return true;
    }
    return in_array($permission, attUserPermissions($admin['id']), true);
}

/** May they open the panel at all? */
function attCanView() {
    return attHasPermission(ATT_PERMISSION_VIEW);
}

function attCsrfToken() {
    return $_SESSION['att_csrf'];
}

/** Validate a posted CSRF token, aborting the request when it does not match. */
function attCheckCsrf() {
    $posted = $_POST['csrf'] ?? '';
    if (!is_string($posted) || !hash_equals($_SESSION['att_csrf'], $posted)) {
        // 400, not 419: the latter is a Laravel convention, not a real HTTP status,
        // and Apache turns it into a 500.
        http_response_code(400);
        exit('Session expired or the request could not be verified. Reload the page and try again.');
    }
}

/**
 * The signed-in admin, or null.
 *
 * An ERP session (`user_id`) is trusted on sight — it was set by the ERP's own
 * login — and is copied into the attendance session keys on first use so later
 * requests do not need to re-query.
 */
function attAdmin() {
    static $admin = null;
    if ($admin !== null) {
        return $admin ?: null;
    }

    $userId = $_SESSION['att_admin_id'] ?? $_SESSION['user_id'] ?? null;
    if (!$userId) {
        $admin = false;
        return null;
    }

    $stmt = attDB()->prepare("
        SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.avatar,
               r.role_name, r.role_display_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Account deactivated or deleted while the session was still open.
        unset($_SESSION['att_admin_id']);
        $admin = false;
        return null;
    }

    $_SESSION['att_admin_id'] = (int)$row['id'];
    $_SESSION['att_admin_role'] = $row['role_name'];

    $admin = $row;
    return $admin;
}

function attIsSuperAdmin() {
    $admin = attAdmin();
    return $admin && $admin['role_name'] === 'super_admin';
}

/** Can the current admin change data, or only look at it? */
function attCanManage() {
    $admin = attAdmin();
    if (!$admin) {
        return false;
    }
    return attHasPermission(ATT_PERMISSION_MANAGE);
}

/** Send read-only roles away from a page that writes. */
function attRequireManage() {
    if (!attCanManage()) {
        attFlash('error', 'Your role does not allow changes to attendance settings.');
        attRedirect('index.php');
    }
}

/** Gate every admin page. Remembers where the user was going. */
function attRequireAdmin() {
    $admin = attAdmin();

    if (!$admin) {
        $_SESSION['att_redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        attRedirect(ATT_URL . '/admin/login.php');
    }

    if (!attCanView()) {
        http_response_code(403);
        exit('Your role (' . htmlspecialchars($admin['role_display_name']) .
             ') does not have access to the attendance dashboard. An administrator can ' .
             'grant it from Roles &amp; Permissions.');
    }

    return $admin;
}

/** Verify ERP credentials and open an attendance session. */
function attAdminLogin($username, $password) {
    $stmt = attDB()->prepare("
        SELECT u.id, u.password, u.is_active, r.role_name, r.role_display_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE (u.username = ? OR u.email = ?)
        LIMIT 1
    ");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Same message for an unknown username and a wrong password.
    if (!$user || !password_verify($password, $user['password'])) {
        attAudit('admin_login_failed', 'users', null, $username, 'admin');
        return ['success' => false, 'message' => 'Username or password is incorrect.'];
    }
    if ((int)$user['is_active'] !== 1) {
        return ['success' => false, 'message' => 'This account has been deactivated.'];
    }
    $mayView = $user['role_name'] === 'super_admin'
        || in_array(ATT_PERMISSION_VIEW, attUserPermissions($user['id']), true);
    if (!$mayView) {
        // Checked here as well as in attRequireAdmin: without it the login succeeds, a
        // session opens, and the refusal only appears on the next page load.
        return ['success' => false, 'message' =>
            'Your role (' . $user['role_display_name'] . ') does not have access to the ' .
            'attendance dashboard.'];
    }

    // Fresh session id on privilege change, so a fixated id cannot be reused.
    session_regenerate_id(true);
    $_SESSION['att_admin_id'] = (int)$user['id'];
    $_SESSION['att_admin_role'] = $user['role_name'];

    attDB()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    attAudit('admin_login', 'users', (int)$user['id'], null, 'admin', (int)$user['id']);

    return ['success' => true];
}

/**
 * End the attendance session. The ERP session is left alone unless it belongs to
 * the same person, so logging out here does not silently sign someone out of the
 * rest of the ERP in another tab.
 */
function attAdminLogout() {
    $adminId = $_SESSION['att_admin_id'] ?? null;
    unset($_SESSION['att_admin_id'], $_SESSION['att_admin_role'], $_SESSION['att_redirect_after_login']);

    if ($adminId) {
        attAudit('admin_logout', 'users', (int)$adminId, null, 'admin', (int)$adminId);
    }
}

function attRedirect($url) {
    header('Location: ' . $url);
    exit;
}

/** One-shot message shown after a redirect. */
function attFlash($type, $message = null) {
    if ($message === null) {
        $flash = $_SESSION['att_flash'] ?? null;
        unset($_SESSION['att_flash']);
        return $flash;
    }
    $_SESSION['att_flash'] = ['type' => $type, 'message' => $message];
    return null;
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
