<?php
/**
 * POST /api/v1/check-out   (multipart/form-data)
 *
 * Fields: lat, lng, accuracy, is_mock, address, photo (file)
 *
 * Check-out closes any still-open geofence exit episode first, so the day's
 * outside_minutes total is complete before the worked-time maths runs.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

attRequireMethod('POST');

$auth = attAuthenticate();
$settings = $auth['settings'];

$fields = attRequire(['lat', 'lng']);
$lat = (float)$fields['lat'];
$lng = (float)$fields['lng'];
$accuracy = is_numeric(attParam('accuracy')) ? (float)attParam('accuracy') : null;
$isMock = filter_var(attParam('is_mock', false), FILTER_VALIDATE_BOOLEAN);

// The shift being closed, which is not necessarily the one the calendar is on: an
// employee who works past their end time is still closing the shift they opened.
$workDate = attSessionWorkDate($auth['att_employee_id'], $settings);
$attendance = attFindAttendance($auth['att_employee_id'], $workDate);

if (!$attendance || !$attendance['check_in_at']) {
    attFail('NOT_CHECKED_IN', 'You have not checked in today.', 409);
}
if ($attendance['check_out_at']) {
    attFail('ALREADY_CHECKED_OUT', 'You already checked out at ' .
        date('H:i', strtotime($attendance['check_out_at'])) . '.', 409);
}

// Position is recorded but by default does not block a check-out: refusing to let
// someone clock off because they are away from site would only create false
// "still working" records. The flag on the row is what the admin reviews.
//
// Enforcement is opt-in per employee. It is checked here rather than through
// attValidatePosition's strict mode, because strict mode also rejects poor accuracy
// and mock positions — and neither should ever strand somebody unable to clock off.
$evaluation = attValidatePosition($auth, $lat, $lng, $accuracy, $isMock, false);

if (!$evaluation['inside'] && (int)($settings['enforce_geofence_checkout'] ?? 0) === 1) {
    attFail('OUTSIDE_GEOFENCE', 'You must be inside your work area to check out. You are ' .
        number_format($evaluation['distance_m']) . ' m away.', 422, [
            'distance_m' => (int)$evaluation['distance_m'],
        ]);
}

$photoPath = null;
if (isset($_FILES['photo'])) {
    $photoPath = attStorePhoto($_FILES['photo'], (int)$auth['att_employee_id'], 'out');
} elseif ((int)$settings['require_photo'] === 1) {
    attFail('PHOTO_REQUIRED', 'A photo is required to check out. Please take a picture.', 422);
}

$now = date('Y-m-d H:i:s');
$point = ['lat' => $lat, 'lng' => $lng, 'recorded_at' => $now];

$db = attDB();
$db->beginTransaction();
try {
    if ($evaluation['inside']) {
        attRecordEntry($auth['att_employee_id'], $point);
    } else {
        // Still outside at knock-off time: close the episode here so its duration
        // is not left open and counted into the following day.
        $open = $db->prepare("
            SELECT id, exit_at FROM att_geofence_events
            WHERE att_employee_id = ? AND entry_at IS NULL ORDER BY exit_at DESC LIMIT 1
        ");
        $open->execute([$auth['att_employee_id']]);
        if ($row = $open->fetch(PDO::FETCH_ASSOC)) {
            $minutes = max(0, (int)round((strtotime($now) - strtotime($row['exit_at'])) / 60));
            $db->prepare("
                UPDATE att_geofence_events
                SET entry_at = ?, entry_lat = ?, entry_lng = ?, duration_min = ?
                WHERE id = ?
            ")->execute([$now, $lat, $lng, $minutes, $row['id']]);
            $db->prepare("UPDATE att_attendance SET outside_minutes = outside_minutes + ? WHERE id = ?")
               ->execute([$minutes, $attendance['id']]);
        }
    }

    $db->prepare("
        UPDATE att_attendance
        SET check_out_at = ?, check_out_lat = ?, check_out_lng = ?, check_out_accuracy_m = ?,
            check_out_address = ?, check_out_photo = ?, check_out_inside_fence = ?,
            check_out_device_id = ?
        WHERE id = ?
    ")->execute([
        $now, $lat, $lng, $accuracy, attParam('address'), $photoPath,
        $evaluation['inside'] ? 1 : 0, $auth['device_id'], $attendance['id'],
    ]);

    $db->prepare("
        INSERT INTO att_location_logs
            (att_employee_id, attendance_id, work_date, lat, lng, accuracy_m,
             is_mock, inside_fence, distance_from_fence_m, provider, recorded_at, client_uid)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'checkout', ?, ?)
    ")->execute([
        $auth['att_employee_id'], $attendance['id'], $workDate, $lat, $lng, $accuracy,
        $isMock ? 1 : 0, $evaluation['inside'] ? 1 : 0, $evaluation['distance_m'],
        $now, 'out-' . $attendance['id'],
    ]);

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    throw $e;
}

attFinaliseDay((int)$attendance['id'], $settings);

$final = attFindAttendance($auth['att_employee_id'], $workDate);

attAudit('check_out', 'att_attendance', (int)$attendance['id'], [
    'lat' => $lat, 'lng' => $lng, 'inside' => $evaluation['inside'],
], 'employee', (int)$auth['att_employee_id']);

attOk([
    'attendance_id' => (int)$attendance['id'],
    'work_date' => $workDate,
    'check_out_at' => $now,
    'inside_geofence' => $evaluation['inside'],
    'worked_minutes' => (int)$final['worked_minutes'],
    'outside_minutes' => (int)$final['outside_minutes'],
    'status' => $final['status'],
    'photo_url' => attPhotoUrl($photoPath),
], 'Checked out at ' . date('H:i', strtotime($now)) . '.');
