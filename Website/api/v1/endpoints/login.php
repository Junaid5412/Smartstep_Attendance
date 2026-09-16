<?php
/**
 * POST /api/v1/login
 *
 * Body: login_code, password, device_uid, device_model, device_brand, os_version,
 *       app_version, platform, fcm_token
 *
 * On the first successful sign-in the device_uid is bound to the account. Any
 * other device is then refused with DEVICE_ALREADY_BOUND until an admin resets it.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

attRequireMethod('POST');

$fields = attRequire(['login_code', 'password', 'device_uid']);

$loginCode = trim($fields['login_code']);
$password = (string)$fields['password'];
$deviceUid = trim($fields['device_uid']);

if (strlen($deviceUid) < 8) {
    attFail('DEVICE_UID_INVALID', 'Device identifier is invalid. Please reinstall the app.', 422);
}

$stmt = attDB()->prepare("
    SELECT e.*, emp.employee_code, emp.first_name, emp.last_name, emp.position,
           emp.photo, emp.employment_status, d.department_name AS department
    FROM att_employees e
    -- LEFT, not inner. An inner join here meant that if the person record could not be
    -- matched (a missing view, an unpopulated column, a deleted HR row) the account row
    -- disappeared and the code below reported it as a wrong employee code or password.
    -- A schema problem must never be presented to an employee as a bad password: they
    -- retype it, become convinced they are locked out, and nobody looks at the database.
    LEFT JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
    LEFT JOIN hr_departments d ON d.id = emp.department_id
    WHERE e.login_code = ?
    LIMIT 1
");
$stmt->execute([$loginCode]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// The credentials are right but the person record behind the account is unreachable.
// Told apart from a bad password on purpose, and only *after* the password has been
// verified — so this cannot be used to discover which codes exist.
if ($employee && password_verify($password, $employee['password'])
    && $employee['employee_code'] === null) {
    attAudit('login_person_missing', 'att_employee', (int)$employee['id'],
        ['summary' => 'Login refused: no person record matched this account',
         'person_type' => $employee['person_type'] ?? null,
         'employee_id' => $employee['employee_id'] ?? null,
         'monitor_id' => $employee['monitor_id'] ?? null,
         'driver_id' => $employee['driver_id'] ?? null], 'system');
    attFail('PERSON_RECORD_MISSING',
        'Your password is correct, but your employee record could not be found. '
        . 'This is a problem on the server, not with your login — please tell your administrator.',
        409);
}

// One generic message for both a wrong code and a wrong password, so the response
// cannot be used to enumerate valid employee codes.
if (!$employee || !password_verify($password, $employee['password'])) {
    attAudit('login_failed', 'login_code', null, $loginCode, 'employee');
    attFail('INVALID_CREDENTIALS', 'Employee code or password is incorrect.', 401);
}

if ((int)$employee['is_active'] !== 1) {
    attFail('ACCOUNT_DISABLED', 'Your attendance account has been disabled. Contact HR.', 403);
}
if (in_array($employee['employment_status'], ['terminated', 'resigned'], true)) {
    attFail('ACCOUNT_DISABLED', 'Your employment record is no longer active.', 403);
}

$binding = attBindDevice((int)$employee['id'], $deviceUid, [
    'model' => attParam('device_model'),
    'brand' => attParam('device_brand'),
    'os_version' => attParam('os_version'),
    'app_version' => attParam('app_version'),
    'platform' => attParam('platform', 'android'),
    'fcm_token' => attParam('fcm_token'),
]);

// A fresh sign-in supersedes any older session on the same handset.
attRevokeDeviceTokens($binding['device_id']);
$token = attIssueToken((int)$employee['id'], $binding['device_id']);

attDB()->prepare("UPDATE att_employees SET last_login_at = NOW() WHERE id = ?")
       ->execute([$employee['id']]);

attAudit(
    $binding['first_bind'] ? 'device_bound' : 'login',
    'att_employee',
    (int)$employee['id'],
    ['device_uid' => $deviceUid, 'model' => attParam('device_model')],
    'employee',
    (int)$employee['id']
);

$settings = attEmployeeSettings((int)$employee['id']);
$geofences = attEmployeeGeofences((int)$employee['id'], $settings);
$geofence = $settings['geofence_id'] ? attGeofence($settings['geofence_id']) : ($geofences[0] ?? null);

attOk([
    'token' => $token,
    'device_bound_now' => $binding['first_bind'],
    'must_change_password' => (int)$employee['must_change_password'] === 1,
    'consent_required' => $employee['consent_accepted_at'] === null,
    'employee' => [
        'id' => (int)$employee['id'],
        // NULL for a monitor account — there is no hr_employees row. Cast only
        // when present; (int)NULL is 0, which looks like a real person id.
        'employee_id' => $employee['employee_id'] !== null ? (int)$employee['employee_id'] : null,
        'employee_code' => $employee['employee_code'],
        'name' => trim($employee['first_name'] . ' ' . $employee['last_name']),
        // hr_employees.position holds the designation (hr_designations), which is
        // a job title. Role is a separate concept living on the linked users row.
        'designation' => $employee['position'],
        'department' => $employee['department'],
        'photo_url' => $employee['photo'] ? ATT_ERP_URL . '/' . ltrim($employee['photo'], '/') : null,
    ],
    'settings' => [
        'shift_start' => substr($settings['shift_start'], 0, 5),
        'shift_end' => substr($settings['shift_end'], 0, 5),
        'sessions_per_day' => (int)($settings['sessions_per_day'] ?? 1),
        'shift2_start' => !empty($settings['shift2_start']) ? substr($settings['shift2_start'], 0, 5) : null,
        'shift2_end' => !empty($settings['shift2_end']) ? substr($settings['shift2_end'], 0, 5) : null,
        'overnight_shift' => (bool)($settings['overnight_shift'] ?? false),
        'work_days' => array_map('intval', array_filter(explode(',', (string)($settings['work_days'] ?? '1,2,3,4,5,6')))),
        'tracking_interval_min' => (int)($settings['tracking_interval_min'] ?? 10),
        'require_photo' => (bool)($settings['require_photo'] ?? true),
        'enforce_geofence' => (bool)($settings['enforce_geofence'] ?? false),
        'enforce_geofence_checkout' => (bool)($settings['enforce_geofence_checkout'] ?? false),
        'max_accuracy_m' => (int)($settings['max_accuracy_m'] ?? 50),
        'allow_mock_location' => (bool)($settings['allow_mock_location'] ?? false),
        'live_board_enabled' => (bool)(attSettings()['live_board_enabled'] ?? 1),
        'live_ping_interval_min' => max(5, min(120, (int)(attSettings()['live_ping_interval_min'] ?? 15))),
        'geofence_watch_seconds' => (int)(attSettings()['geofence_watch_seconds'] ?? 0),
        'alert_sound' => (bool)(attSettings()['alert_sound'] ?? false),
        'command_poll_seconds' => attCommandPollSeconds(),
    ],
    'geofence' => attGeofencePayload($geofence),
    'geofences' => array_values(array_filter(array_map('attGeofencePayload', $geofences))),
    'map' => attMapPayload(),
    'branding' => attBranding(),
    'server_time' => date('c'),
], 'Signed in.');
