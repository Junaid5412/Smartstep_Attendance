<?php
/**
 * GET /api/v1/app-config
 *
 * Named app-config rather than config because the ERP's root .htaccess denies any
 * request whose last path segment is "config", "includes" or "database".
 *
 * The app calls this on launch and after every successful sync so a change made
 * in the admin panel (new geofence, different ping interval, new shift hours)
 * takes effect without the employee reinstalling or signing in again.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

$auth = attAuthenticate();
$settings = $auth['settings'];
$global = attSettings();

// Pinned to an open shift rather than the raw calendar day, so that tracking and the
// shift window the app draws its progress bar from both stay on the shift actually
// being worked when someone stays past their end time.
$workDate = attSessionWorkDate($auth['att_employee_id'], $settings);
// The shift the employee is in, which for a split shift is not always the first.
$configState = attSessionState($auth['att_employee_id'], $workDate, $settings);
$configSession = $configState['open']
    ? (int)$configState['open']['session_no']
    : ($configState['next'] !== null ? (int)$configState['next'] : 1);
$window = attShiftWindow($settings, $workDate, $configSession);

attOk([
    'settings' => [
        'shift_start' => substr($settings['shift_start'], 0, 5),
        'shift_end' => substr($settings['shift_end'], 0, 5),
        'sessions_per_day' => (int)($settings['sessions_per_day'] ?? 1),
        'shift2_start' => !empty($settings['shift2_start']) ? substr($settings['shift2_start'], 0, 5) : null,
        'shift2_end' => !empty($settings['shift2_end']) ? substr($settings['shift2_end'], 0, 5) : null,
        'session_no' => $configSession,
        'overnight_shift' => (bool)($settings['overnight_shift'] ?? false),
        'work_days' => array_map('intval', array_filter(explode(',', (string)($settings['work_days'] ?? '1,2,3,4,5,6')))),
        'tracking_interval_min' => (int)($settings['tracking_interval_min'] ?? 10),
        'late_grace_min' => (int)($settings['late_grace_min'] ?? 15),
        'require_photo' => (bool)($settings['require_photo'] ?? true),
        // How the phone decides a point is worth recording. Sent from here so the cadence
        // can be tuned centrally without building a new APK.
        'move_min_m' => (int)ATT_MOVE_MIN_M,
        'min_gap_seconds' => (int)ATT_MIN_GAP_SECONDS,
        // Whether the app permits screenshots and screen recording. Sent from the server
        // so it can be changed without building and distributing a new APK — the whole
        // reason it is a setting and not a constant.
        'allow_screen_capture' => (bool)(attSettings()['allow_screen_capture'] ?? 0),
        'enforce_geofence' => (bool)($settings['enforce_geofence'] ?? false),
        'enforce_geofence_checkout' => (bool)($settings['enforce_geofence_checkout'] ?? false),
        'max_accuracy_m' => (int)($settings['max_accuracy_m'] ?? 50),
        // The tolerance ring, sent so the device reaches the same inside/outside verdict
        // the server does. Two different answers to "am I inside?" is how an employee
        // gets an alarm the admin panel cannot explain.
        'geofence_buffer_m' => (int)attGeofenceBufferM(),
        'allow_mock_location' => (bool)($settings['allow_mock_location'] ?? false),
        'max_overtime_hours' => attMaxOvertimeHours($settings),
        // The personal live board's channel. Off-shift pings go to a separate
        // table the register never reads — see live_ping.php — so this changes
        // nothing about attendance, only whether the board stays live overnight.
        'live_board_enabled' => (bool)($global['live_board_enabled'] ?? 1),
        'live_ping_interval_min' => max(5, min(120, (int)($global['live_ping_interval_min'] ?? 15))),
        // Breach detection runs far faster than recording; 0 disables it.
        'geofence_watch_seconds' => (int)($global['geofence_watch_seconds'] ?? 0),
        'alert_sound' => (bool)($global['alert_sound'] ?? false),
        'command_poll_seconds' => attCommandPollSeconds(),
    ],
    // The primary area, kept for older installs of the app that only understand one.
    'geofence' => attGeofencePayload($auth['geofence']),
    // Every assigned area. The device judges "am I outside" against all of these, so
    // an employee working across two offices is not warned at the second one.
    'geofences' => array_values(array_filter(array_map(
        'attGeofencePayload',
        $auth['geofences'] ?? []
    ))),
    'today' => [
        'work_date' => $workDate,
        'is_work_day' => attIsWorkDay($settings, $workDate),
        // Authorised leave. The app says so and offers nothing to do, rather than
        // presenting a Check In button for a day the employee was given off.
        'is_leave' => attIsLeaveDay($auth['att_employee_id'], $workDate),
        'shift_start_at' => date('c', $window['start']),
        'shift_end_at' => date('c', $window['end']),
        'tracking_active_now' => attTrackingActive($auth['att_employee_id'], $settings),
    ],
    'map' => attMapPayload(),
    'branding' => attBranding(),
    'app' => [
        'min_version' => $global['min_app_version'] ?? null,
        'photo_max_kb' => (int)($global['photo_max_kb'] ?? 4096),
        'max_batch_points' => ATT_MAX_BATCH_POINTS,
    ],
    'server_time' => date('c'),
]);
