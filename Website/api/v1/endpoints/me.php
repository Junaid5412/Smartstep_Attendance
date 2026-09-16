<?php
/**
 * GET /api/v1/me
 *
 * Profile, the bound device, and today's attendance state — enough for the app's
 * home screen to decide whether to show a Check In or a Check Out button.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

$auth = attAuthenticate();
$settings = $auth['settings'];
// The shift the app should be acting on. Distinct from the calendar day, so that an
// employee still on site after their end time is offered Check Out rather than a
// Check In for tomorrow.
$workDate = attSessionWorkDate($auth['att_employee_id'], $settings);
$attendance = attFindAttendance($auth['att_employee_id'], $workDate);

// A part-time monitor's day is two shifts, so "have they checked in today" is no longer
// the question the buttons hang off. What the app needs to know is whether a shift is
// open right now and whether any remain.
$sessionState = attSessionState($auth['att_employee_id'], $workDate, $settings);

// The row the screen describes: the open shift if there is one, otherwise the most
// recent — which is what attFindAttendance already returns.
if ($sessionState['open']) {
    $attendance = $sessionState['open'];
}

// Mapped so an app that knows nothing about sessions still behaves correctly:
//   in a shift          -> checked in, not out    -> offers Check Out
//   between two shifts  -> neither                -> offers Check In again
//   day finished        -> both                   -> shows the day as done
// The old meaning is preserved exactly for a one-shift day, where next is null the
// moment the single session closes.
$dayComplete = $sessionState['open'] === null && $sessionState['next'] === null;
$shiftOpen = $sessionState['open'] !== null;

// Which shift's hours to show: the open one, else the one about to start, else the last.
// Resolved after the session state is known, so the app shows the hours of the shift
// the employee is actually in rather than always the morning's.
$displaySession = $sessionState['open']
    ? (int)$sessionState['open']['session_no']
    : ($sessionState['next'] !== null ? (int)$sessionState['next'] : (int)$sessionState['allowed']);
$shiftWindow = attShiftWindow($settings, $workDate, $displaySession);

// Minutes worked past the scheduled end, live, while the shift is still open. The
// stored overtime_minutes is only written at check-out, so without this the app
// could not tell someone working late that they are in overtime.
$runningOvertime = 0;
if ($attendance && $attendance['check_in_at'] && !$attendance['check_out_at']) {
    $runningOvertime = max(0, (int)round((time() - $shiftWindow['end']) / 60));
}

$deviceStmt = attDB()->prepare("
    SELECT device_model, device_brand, os_version, app_version, platform, bound_at, last_seen_at
    FROM att_devices WHERE id = ? LIMIT 1
");
$deviceStmt->execute([$auth['device_id']]);
$device = $deviceStmt->fetch(PDO::FETCH_ASSOC);

// An episode with no entry_at means the employee is outside the fence right now
// and still owes a reason for it.
// review_status and the reviewer's note are included because without them the app
// could only ever say "waiting for your administrator" — it had no way to know a
// decision had been made, so an approval or rejection was invisible to the employee
// who was asked to explain themselves.
$openEvent = attDB()->prepare("
    SELECT ev.id, ev.work_date, ev.exit_at, ev.max_distance_m, ev.category, ev.employee_reason,
           ev.review_status, ev.review_note, ev.reviewed_at,
           u.username AS reviewed_by_name
    FROM att_geofence_events ev
    LEFT JOIN users u ON u.id = ev.reviewed_by
    WHERE ev.att_employee_id = ? AND ev.entry_at IS NULL
    ORDER BY ev.exit_at DESC LIMIT 1
");
$openEvent->execute([$auth['att_employee_id']]);
$outside = $openEvent->fetch(PDO::FETCH_ASSOC);

// "You are outside your work area" is a statement about right now, so it is only
// reported while the employee is actually on the clock for the shift the trip belongs
// to. Without this a trip left open by a forgotten check-out kept announcing itself on
// every later day — including days off and authorised leave — about a shift that had
// already ended.
$onClockNow = $attendance && $attendance['check_in_at'] && !$attendance['check_out_at'];
if ($outside && (!$onClockNow || ($outside['work_date'] ?? null) !== $workDate)) {
    $outside = null;
}

$pendingReasons = attDB()->prepare("
    SELECT id, work_date, exit_at, entry_at, duration_min, max_distance_m
    FROM att_geofence_events
    WHERE att_employee_id = ? AND entry_at IS NOT NULL AND reason_submitted_at IS NULL
    ORDER BY exit_at DESC LIMIT 20
");
$pendingReasons->execute([$auth['att_employee_id']]);

/**
 * Decisions made recently on this employee's trips.
 *
 * An approval or rejection was previously visible only to the admin who made it.
 * The employee was required to explain a departure and then told nothing about the
 * outcome — while the same decision determines whether that time counts as worked
 * hours. Anything decided in the last two days is reported so the app can show it.
 */
$decided = attDB()->prepare("
    SELECT ev.id, ev.work_date, ev.exit_at, ev.duration_min, ev.max_distance_m,
           ev.category, ev.employee_reason, ev.review_status, ev.review_note,
           ev.reviewed_at, u.username AS reviewed_by_name
    FROM att_geofence_events ev
    LEFT JOIN users u ON u.id = ev.reviewed_by
    WHERE ev.att_employee_id = ?
      AND ev.review_status IN ('approved','rejected')
      AND ev.reviewed_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
      -- A departure still in progress is already shown by the banner, with its live
      -- distance; listing it here as well repeated the same verdict twice and, having
      -- no duration yet, reported it as 0 minutes away.
      AND ev.entry_at IS NOT NULL
    ORDER BY ev.reviewed_at DESC
    LIMIT 10
");
$decided->execute([$auth['att_employee_id']]);

attOk([
    'employee' => [
        'id' => (int)$auth['att_employee_id'],
        'employee_code' => $auth['employee_code'],
        'name' => trim($auth['first_name'] . ' ' . $auth['last_name']),
        'designation' => $auth['position'],
        'photo_url' => $auth['photo'] ? ATT_ERP_URL . '/' . ltrim($auth['photo'], '/') : null,
    ],
    'device' => $device,
    'consent_required' => $auth['consent_accepted_at'] === null,
    'must_change_password' => (int)$auth['must_change_password'] === 1,
    'today' => [
        'work_date' => $workDate,
        'checked_in' => $shiftOpen || $dayComplete,
        'checked_out' => $dayComplete,
        // The honest detail behind those two flags, for a build that wants to say
        // "Shift 2 of 2" rather than just "Check In".
        'sessions_per_day' => (int)$sessionState['allowed'],
        'session_no' => $displaySession,
        'sessions_done' => count(array_filter($sessionState['rows'], function ($row) {
            return $row['check_in_at'] && $row['check_out_at'];
        })),
        'check_in_at' => $attendance['check_in_at'] ?? null,
        'check_out_at' => $attendance['check_out_at'] ?? null,
        'check_in_photo_url' => attPhotoUrl($attendance['check_in_photo'] ?? null),
        'check_out_photo_url' => attPhotoUrl($attendance['check_out_photo'] ?? null),
        'worked_minutes' => $attendance['worked_minutes'] ?? null,
        'outside_minutes' => (int)($attendance['outside_minutes'] ?? 0),
        'overtime_minutes' => (int)($attendance['overtime_minutes'] ?? 0),
        'status' => $attendance['status'] ?? 'absent',
        'tracking_active_now' => attTrackingActive($auth['att_employee_id'], $settings),
        'shift_start' => date('Y-m-d H:i:s', $shiftWindow['start']),
        'shift_end' => date('Y-m-d H:i:s', $shiftWindow['end']),
        // Every session of the day, so a two-session day can show "Shift 1 done,
        // Shift 2 to go" instead of a single check-in/out pair that only ever
        // describes the latest row.
        'sessions' => array_map(function ($row) {
            return [
                'session_no' => (int)$row['session_no'],
                'check_in_at' => $row['check_in_at'],
                'check_out_at' => $row['check_out_at'],
                'status' => $row['status'],
            ];
        }, $sessionState['rows']),
        'open_session_no' => $sessionState['open'] ? (int)$sessionState['open']['session_no'] : null,
        'next_session_no' => $sessionState['next'] !== null ? (int)$sessionState['next'] : null,
        // True when this open shift began on an earlier date than today, so the app
        // can label it ("Shift of 6 Aug") instead of appearing to be a day behind.
        'is_previous_day' => $workDate !== attWorkDate($settings),
        'is_work_day' => attIsWorkDay($settings, $workDate),
        'is_leave' => attIsLeaveDay($auth['att_employee_id'], $workDate),
        'running_overtime_minutes' => $runningOvertime,
    ],
    'currently_outside' => $outside ?: null,
    'reasons_pending' => $pendingReasons->fetchAll(PDO::FETCH_ASSOC),
    'recent_decisions' => $decided->fetchAll(PDO::FETCH_ASSOC),
    'server_time' => date('c'),
]);
