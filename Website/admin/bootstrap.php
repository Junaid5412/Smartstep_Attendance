<?php
/**
 * Common bootstrap for every admin page: config, helpers, and the access gate.
 *
 * A page includes this first, sets $pageTitle, then includes layout_top.php.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once ATT_PATH . '/includes/api.php';
require_once ATT_PATH . '/includes/geo.php';
require_once ATT_PATH . '/includes/attendance.php';
// app_auth.php is the app-facing session layer, but it also owns the shared
// geofence/settings readers and attResetDevice(), which the panel needs.
require_once ATT_PATH . '/includes/app_auth.php';
require_once ATT_PATH . '/includes/admin_auth.php';
require_once ATT_PATH . '/includes/admin_data.php';
require_once ATT_PATH . '/includes/security_data.php';
require_once ATT_PATH . '/includes/maptiles.php';
require_once ATT_PATH . '/includes/branding.php';
require_once ATT_PATH . '/includes/commands.php';
require_once ATT_PATH . '/includes/mappack.php';
require_once ATT_PATH . '/includes/appupdate.php';
require_once ATT_PATH . '/includes/whatsapp.php';
require_once ATT_PATH . '/includes/monitor_enrol.php';
require_once ATT_PATH . '/includes/driver_enrol.php';
require_once ATT_PATH . '/includes/project_shifts.php';
require_once ATT_PATH . '/includes/account_delete.php';

$attAdmin = attRequireAdmin();
$attCanManage = attCanManage();

// Resolved once per request and handed to the page chrome, so every map in the
// panel draws from the same source.
$attTiles = attTileConfig();
