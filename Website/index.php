<?php
/**
 * Entry point for the attendance module.
 *
 * Nothing is served from this directory itself: a signed-in admin goes straight to
 * the dashboard, anyone else to the login page.
 */

require_once __DIR__ . '/config/config.php';
require_once ATT_PATH . '/includes/api.php';
require_once ATT_PATH . '/includes/admin_auth.php';

if (attAdmin()) {
    attRedirect(ATT_URL . '/admin/index.php');
}

attRedirect(ATT_URL . '/admin/login.php');
