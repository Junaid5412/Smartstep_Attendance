<?php
/**
 * POST /api/v1/check-in   (multipart/form-data)
 *
 * Fields: lat, lng, accuracy, is_mock, address, recorded_at (optional), photo (file)
 *
 * The selfie is compulsory whenever the employee's settings say so, and the
 * position must satisfy the accuracy, mock-location and geofence rules before the
 * register row is written.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

attRequireMethod('POST');

$auth = attAuthenticate();
$settings = $auth['settings'];

if ($auth['consent_accepted_at'] === null) {
    attFail('CONSENT_REQUIRED', 'Please accept the location tracking notice first.', 403);
}

$fields = attRequire(['lat', 'lng']);
$lat = (float)$fields['lat'];
$lng = (float)$fields['lng'];
$accuracy = is_numeric(attParam('accuracy')) ? (float)attParam('accuracy') : null;
$isMock = filter_var(attParam('is_mock', false), FILTER_VALIDATE_BOOLEAN);

// A shift still open from an earlier date must be closed before a new one starts,
// or the employee ends up with two open days and neither has worked hours. This
// also clears any shift whose overtime allowance has run out, so a single forgotten
// check-out cannot lock someone out of checking in ever again.
$workDate = attWorkDate($settings);
$session = attSessionWorkDate($auth['att_employee_id'], $settings);

if ($session !== $workDate) {
    attFail('CHECKOUT_PENDING',
        'Your shift for ' . date('j M', strtotime($session)) . ' is still open. ' .
        'Please check out of it first.', 409, ['open_work_date' => $session]);
}

if (!attIsWorkDay($settings, $workDate)) {
    attFail('NOT_WORK_DAY', 'Today is not one of your scheduled working days.', 422);
}

// A part-time monitor works a split shift — a morning run and an afternoon one — so a
// day may hold more than one session. The guard is therefore not "has this person checked
// in today" but "is a session open, and are there any left".
$sessionState = attSessionState($auth['att_employee_id'], $workDate, $settings);

if ($sessionState['open']) {
    attFail('ALREADY_CHECKED_IN', 'You already checked in at ' .
        date('H:i', strtotime($sessionState['open']['check_in_at'])) . '.', 409, [
            'check_in_at' => $sessionState['open']['check_in_at'],
            'session_no' => (int)$sessionState['open']['session_no'],
        ]);
}

if ($sessionState['next'] === null) {
    // Every shift for the day is finished. Said plainly, with the count, because for a
    // split shift "you have already finished today" is otherwise indistinguishable from
    // a bug to someone who has checked out only once.
    attFail('DAY_COMPLETE', $sessionState['allowed'] > 1
        ? "Both of today's shifts are already recorded."
        : "You have already completed today's shift.", 409, [
            'sessions_per_day' => $sessionState['allowed'],
        ]);
}

$sessionNo = (int)$sessionState['next'];

// An empty row for this session may exist as a leave or holiday marker, in which case
// check-in fills it in rather than colliding with it.
$existing = attFindAttendance($auth['att_employee_id'], $workDate, $sessionNo);

$evaluation = attValidatePosition($auth, $lat, $lng, $accuracy, $isMock, true);

$requirePhoto = (int)$settings['require_photo'] === 1;
$photoPath = null;
if (isset($_FILES['photo'])) {
    $photoPath = attStorePhoto($_FILES['photo'], (int)$auth['att_employee_id'], 'in');
} elseif ($requirePhoto) {
    attFail('PHOTO_REQUIRED', 'A photo is required to check in. Please take a picture.', 422);
}

// The device clock is only trusted for ordering within a batch; the register uses
// server time so an employee cannot back-date a check-in by changing their clock.
$now = date('Y-m-d H:i:s');

$db = attDB();
$db->beginTransaction();
try {
    if ($existing) {
        $db->prepare("
            UPDATE att_attendance
            SET geofence_id = ?, check_in_at = ?, check_in_lat = ?, check_in_lng = ?,
                check_in_accuracy_m = ?, check_in_address = ?, check_in_photo = ?,
                check_in_inside_fence = ?, check_in_device_id = ?, source = 'app'
            WHERE id = ?
        ")->execute([
            $evaluation['inside'] ? $evaluation['geofence_id'] : $settings['geofence_id'], $now, $lat, $lng, $accuracy,
            attParam('address'), $photoPath, $evaluation['inside'] ? 1 : 0,
            $auth['device_id'], $existing['id'],
        ]);
        $attendanceId = (int)$existing['id'];
    } else {
        $db->prepare("
            INSERT INTO att_attendance
                (att_employee_id, employee_id, work_date, session_no, geofence_id,
                 check_in_at, check_in_lat, check_in_lng, check_in_accuracy_m,
                 check_in_address, check_in_photo, check_in_inside_fence, check_in_device_id,
                 status, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'incomplete', 'app')
        ")->execute([
            $auth['att_employee_id'], $auth['employee_id'], $workDate, $sessionNo,
            $evaluation['inside'] ? $evaluation['geofence_id'] : $settings['geofence_id'],
            $now, $lat, $lng, $accuracy,
            attParam('address'), $photoPath, $evaluation['inside'] ? 1 : 0, $auth['device_id'],
        ]);
        $attendanceId = (int)$db->lastInsertId();
    }

    // The check-in point is also the first point of the day's route.
    $db->prepare("
        INSERT INTO att_location_logs
            (att_employee_id, attendance_id, work_date, lat, lng, accuracy_m,
             is_mock, inside_fence, distance_from_fence_m, provider, recorded_at, client_uid)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'checkin', ?, ?)
    ")->execute([
        $auth['att_employee_id'], $attendanceId, $workDate, $lat, $lng, $accuracy,
        $isMock ? 1 : 0, $evaluation['inside'] ? 1 : 0, $evaluation['distance_m'],
        $now, 'in-' . $attendanceId,
    ]);

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    throw $e;
}

attFinaliseDay($attendanceId, $settings);
attAudit('check_in', 'att_attendance', $attendanceId, [
    'lat' => $lat, 'lng' => $lng, 'inside' => $evaluation['inside'],
], 'employee', (int)$auth['att_employee_id']);

attOk([
    'attendance_id' => $attendanceId,
    'work_date' => $workDate,
    'check_in_at' => $now,
    'inside_geofence' => $evaluation['inside'],
    'distance_from_fence_m' => $evaluation['distance_m'],
    'photo_url' => attPhotoUrl($photoPath),
    'tracking_interval_min' => (int)$settings['tracking_interval_min'],
], 'Checked in at ' . date('H:i', strtotime($now)) . '.');
