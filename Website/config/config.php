<?php
/**
 * SST Attendance - module configuration.
 *
 * The module lives inside the sstqa install and reuses its database credentials
 * and timezone handling, so there is a single source of truth for both. Only the
 * paths, URLs and attendance-specific constants are defined here.
 */

define('ATT_NAME', 'SST Attendance');
define('ATT_VERSION', '1.0.1');

// Root of the sstqa install, i.e. two levels above this file's directory.
define('ATT_ERP_PATH', dirname(dirname(dirname(__DIR__))));
define('ATT_PATH', dirname(__DIR__));

require_once ATT_ERP_PATH . '/config/database.php';

// Live-server overrides. Loaded FIRST on purpose: PHP keeps the first
// definition of a constant, so a file loaded after the defaults below could
// never override them — which is exactly how a live server ended up stuck on
// the localhost URLs with unstyled pages.
$attLocalConfig = __DIR__ . '/config.local.php';
if (file_exists($attLocalConfig)) {
    require_once $attLocalConfig;
}

// Base URLs. Override in config.local.php on the live server.
if (!defined('ATT_ERP_URL')) {
    define('ATT_ERP_URL', 'http://localhost/sstqa');
}
if (!defined('ATT_URL')) {
    define('ATT_URL', ATT_ERP_URL . '/Attendance/Website');
}
define('ATT_API_URL', ATT_URL . '/api/v1');
define('ATT_UPLOAD_PATH', ATT_PATH . '/uploads');
define('ATT_UPLOAD_URL', ATT_URL . '/uploads');
define('ATT_ASSETS_URL', ATT_URL . '/assets');

// Secret used to sign app bearer tokens. MUST be replaced on the live server;
// changing it invalidates every issued token, which is the intended kill switch.
if (!defined('ATT_TOKEN_SECRET')) {
    define('ATT_TOKEN_SECRET', 'change-me-sst-attendance-token-secret');
}

// Upload rules for check-in / check-out selfies.
define('ATT_PHOTO_MAX_BYTES', 4 * 1024 * 1024);
define('ATT_PHOTO_TYPES', ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp']);

// Largest number of route points the app may push in one batch request.
define('ATT_MAX_BATCH_POINTS', 200);

// A location ping is treated as belonging to the same episode as the previous
// one only if it arrived within this many minutes; larger gaps mean the service
// was killed and the route is drawn as a break rather than a straight line.
define('ATT_ROUTE_GAP_MIN', 30);

// Fixes less precise than this are left out of the drawn route line, though they are still
// stored and still shown as faint dots. A ±200 m fix — which the ingest path deliberately
// still accepts, so that an indoor check-in is never refused — says almost nothing about
// where somebody was, and joining it to its neighbours produces most of the visible
// scatter. Display-side only: nothing is deleted and no measurement is discarded.
// 50, not 75. A fix reporting +/-72 m was passing the old limit and landing a stationary
// employee on the far side of a ring road: these are wifi and cell-derived positions, and
// their stated accuracy is optimistic. 50 m matches the ceiling the app now applies before
// a fix may contribute a movement point, so the two ends agree on what is plottable.
define('ATT_ROUTE_MAX_ACCURACY_M', 50);

// The fastest anyone plausibly travels, in km/h. A point that could only be reached by
// exceeding this — and only by detouring out and straight back — is a spike rather than a
// journey, and is left out of the drawn line. Generous on purpose: this is meant to catch
// positions that are physically impossible, not to police driving.
define('ATT_ROUTE_MAX_KMH', 150);

// How the phone decides a point is worth recording, sent down in the app's config.
//
// The service already acquires a fix every few seconds for the geofence watch and throws
// most of them away. Recording the ones that represent real movement is what turns a route
// from straight chords between distant points into something that follows a road, and it
// costs almost no extra battery because the fixes are already being taken.
//
// A point is recorded when it is at least this far from the last one recorded...
define('ATT_MOVE_MIN_M', 25);
// ...and never more often than this, which is what bounds the stored volume.
define('ATT_MIN_GAP_SECONDS', 20);

date_default_timezone_set('Asia/Qatar');

/**
 * Shared PDO handle for the module, with MySQL's session timezone aligned to
 * PHP's so DATETIME values written and read back agree on a UTC host.
 */
function attDB() {
    static $db = null;
    if ($db === null) {
        $db = getDBConnection();
        applyDatabaseTimezone($db, date_default_timezone_get());
    }
    return $db;
}

/**
 * Module settings row, cached per request.
 *
 * The cache lives in a static so a page that touches maps, geofences and the app
 * config does not re-read the same row repeatedly. Pass true (or call
 * attSettingsRefresh) after writing to it — the Google tile session token is
 * written mid-request and later code must see the new value.
 */
/**
 * Does a column exist? Answered once per request and remembered.
 *
 * Used by the settings forms so a server whose PHP is newer than its migrations degrades
 * instead of dying. Uploading files and running SQL are two separate acts, and the gap
 * between them was producing a bare HTTP 500 on Save with nothing to explain it — the
 * worst possible way to find out a migration is outstanding.
 */
function attColumnExists($table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;

    if (!array_key_exists($key, $cache)) {
        try {
            $stmt = attDB()->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            $cache[$key] = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            // Cannot tell: assume absent, which only ever means a field is left alone.
            $cache[$key] = false;
        }
    }

    return $cache[$key];
}

function attSettings($refresh = false) {
    static $settings = null;
    if ($settings === null || $refresh) {
        $stmt = attDB()->query("SELECT * FROM att_settings WHERE id = 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    return $settings;
}

function attSettingsRefresh() {
    return attSettings(true);
}
