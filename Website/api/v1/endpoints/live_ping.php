<?php
/**
 * POST /api/v1/live-ping   (JSON)
 *
 * Body: lat, lng, accuracy (optional), battery (optional), is_mock, recorded_at (optional)
 *
 * The personal live board's channel. One row per employee, always the latest —
 * nothing here touches att_location_logs, so the register, routes, trips and
 * worked hours are unaffected by design.
 *
 * This is a visibility ping, not attendance evidence: no geofence verdict, no
 * mock blocking, no accuracy refusal. The mock flag is stored with it so the
 * board shows honestly what it was given.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

attRequireMethod('POST');

$auth = attAuthenticate();

if ((int)(attSettings()['live_board_enabled'] ?? 1) !== 1) {
    attOk(['ignored' => true], 'Live board is switched off.');
}

$fields = attRequire(['lat', 'lng']);
$lat = (float)$fields['lat'];
$lng = (float)$fields['lng'];
$accuracy = is_numeric(attParam('accuracy')) ? (float)attParam('accuracy') : null;
$battery = is_numeric(attParam('battery')) ? (int)attParam('battery') : null;
$isMock = filter_var(attParam('is_mock', false), FILTER_VALIDATE_BOOLEAN);

if (!attValidCoords($lat, $lng)) {
    attFail('LOCATION_INVALID', 'No valid GPS position was received.', 422);
}

$recordedAt = attParam('recorded_at');
if (!$recordedAt || strtotime($recordedAt) === false) {
    $recordedAt = date('Y-m-d H:i:s');
}

attDB()->prepare("
    INSERT INTO att_live_pings
        (att_employee_id, lat, lng, accuracy_m, battery_pct, is_mock, recorded_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        lat = VALUES(lat), lng = VALUES(lng),
        accuracy_m = VALUES(accuracy_m), battery_pct = VALUES(battery_pct),
        is_mock = VALUES(is_mock), recorded_at = VALUES(recorded_at)
")->execute([
    (int)$auth['att_employee_id'], $lat, $lng, $accuracy, $battery,
    $isMock ? 1 : 0, $recordedAt,
]);

attOk(['recorded_at' => $recordedAt], 'Live position updated.');
