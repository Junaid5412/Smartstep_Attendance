<?php
/**
 * SST Attendance - app API v1 front controller.
 *
 * Routes /api/v1/<endpoint> to endpoints/<endpoint>.php. Every endpoint file
 * receives the bootstrapped environment (config, db, helpers) already loaded and
 * answers through attOk()/attFail().
 */

require_once __DIR__ . '/../../config/config.php';
require_once ATT_PATH . '/includes/api.php';
require_once ATT_PATH . '/includes/geo.php';
require_once ATT_PATH . '/includes/app_auth.php';
require_once ATT_PATH . '/includes/attendance.php';
require_once ATT_PATH . '/includes/maptiles.php';
require_once ATT_PATH . '/includes/branding.php';
require_once ATT_PATH . '/includes/commands.php';
require_once ATT_PATH . '/includes/whatsapp.php';
require_once ATT_PATH . '/includes/mappack.php';
require_once ATT_PATH . '/includes/appupdate.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-App-Token, X-Device-Uid');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// The route comes from the rewrite rule, with a query-string fallback so the API
// still works on a host where mod_rewrite is unavailable (/api/v1/?route=login).
$route = $_GET['route'] ?? '';
$route = trim(preg_replace('#[^a-z0-9/_-]#i', '', strtolower($route)), '/');

if ($route === '') {
    attOk([
        'name' => ATT_NAME,
        'api_version' => 'v1',
        'app_version' => ATT_VERSION,
        'server_time' => date('c'),
    ], 'SST Attendance API is running.');
}

// check-in, locations/batch and change-password all map onto flat filenames:
// endpoints/check_in.php, endpoints/locations_batch.php, endpoints/change_password.php
$endpointFile = __DIR__ . '/endpoints/' . str_replace(['/', '-'], '_', $route) . '.php';

// realpath comparison keeps a crafted route from escaping the endpoints folder.
$endpointsDir = realpath(__DIR__ . '/endpoints');
$resolved = realpath($endpointFile);

if (!$resolved || !$endpointsDir || strpos($resolved, $endpointsDir) !== 0) {
    attFail('NOT_FOUND', 'Unknown endpoint: ' . $route, 404);
}

try {
    require $resolved;
} catch (PDOException $e) {
    error_log('[SST Attendance] DB error on ' . $route . ': ' . $e->getMessage());
    attFail('SERVER_ERROR', 'A server error occurred. Please try again.', 500);
} catch (Throwable $e) {
    error_log('[SST Attendance] Error on ' . $route . ': ' . $e->getMessage());
    attFail('SERVER_ERROR', 'A server error occurred. Please try again.', 500);
}

// An endpoint that finishes without answering is a bug; say so rather than
// returning an empty body the app cannot decode.
attFail('NO_RESPONSE', 'Endpoint produced no response.', 500);
