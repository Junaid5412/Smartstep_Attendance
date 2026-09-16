<?php
/**
 * POST /api/v1/heartbeat
 * Body: app_version, battery, is_charging, permissions{}, tracking_running
 *
 * The tracking service pings this on its own schedule. It exists so the admin
 * panel can tell "employee genuinely stationary" apart from "tracking service was
 * killed by the OS" — a silent heartbeat is itself a finding.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

attRequireMethod('POST');

$auth = attAuthenticate();
$settings = $auth['settings'];

attDB()->prepare("
    UPDATE att_devices
    SET last_seen_at = NOW(),
        app_version = COALESCE(?, app_version),
        fcm_token = COALESCE(?, fcm_token)
    WHERE id = ?
")->execute([attParam('app_version'), attParam('fcm_token'), $auth['device_id']]);

$permissions = attParam('permissions');
if (is_array($permissions)) {
    // Recorded as an audit entry rather than a column: what matters is the moment
    // a permission was revoked, and the audit trail already carries timestamps.
    $missing = array_keys(array_filter($permissions, function ($granted) {
        return !filter_var($granted, FILTER_VALIDATE_BOOLEAN);
    }));
    if ($missing) {
        attAudit('permissions_missing', 'att_employee', (int)$auth['att_employee_id'],
            ['missing' => $missing], 'employee', (int)$auth['att_employee_id']);
    }
}

attOk([
    'tracking_active_now' => attTrackingActive($auth['att_employee_id'], $settings),
    'tracking_interval_min' => (int)$settings['tracking_interval_min'],
    'config_changed' => true,   // the app re-reads /config whenever this is true
    'server_time' => date('c'),
]);
