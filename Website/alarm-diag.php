<?php
/**
 * Why is the outside-area alarm not sounding? Temporary — delete after use.
 *
 * Read-only. Prints exactly what the phone is told, and every condition the alarm
 * depends on, in the order the device checks them. The first FAIL is the answer.
 *
 * Every gate below is deliberate — no alarm off shift, none before check-in, none for a
 * simulated position unless that employee is permitted one — so "nothing is broken" is a
 * real possible outcome, and this says which gate stopped it rather than leaving it a
 * guess.
 */

require_once __DIR__ . '/admin/bootstrap.php';

// Same access as the rest of the panel: this exposes shift patterns and settings.
attRequireAdmin();
if (!attCanManage()) {
    http_response_code(403);
    exit('Manager access required.');
}

header('Content-Type: text/plain; charset=utf-8');

$now = new DateTime('now');
echo "SST Attendance — alarm diagnosis\n";
echo "Server time: " . $now->format('D d M Y H:i:s') . " (" . date_default_timezone_get() . ")\n";
echo str_repeat('=', 74) . "\n\n";

/* ---------------- global settings ---------------- */

$settings = attSettings();
$watch = (int)($settings['geofence_watch_seconds'] ?? 0);
$buffer = (int)attGeofenceBufferM();

echo "GLOBAL SETTINGS\n";
printf("  Breach watch interval : %s\n", $watch > 0 ? $watch . 's' : '0  <-- FAIL: watching is DISABLED for everyone');
printf("  Alert sound           : %s\n", !empty($settings['alert_sound']) ? 'on' : 'off  <-- notification posts silently');
printf("  Tolerance outside area: %d m  (must be more than this far out before it counts)\n", $buffer);
echo "\n";

/* ---------------- per employee ---------------- */

$employees = attDB()->query("
    SELECT e.id, e.is_active, e.security_blocked_at,
           emp.employee_code, CONCAT(emp.first_name, ' ', emp.last_name) AS name,
           s.shift_start, s.shift_end, s.overnight_shift, s.work_days,
           s.allow_mock_location, s.geofence_id, s.max_accuracy_m
    FROM att_employees e
    JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
    LEFT JOIN att_employee_settings s ON s.att_employee_id = e.id
    WHERE e.is_active = 1
    ORDER BY emp.first_name
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($employees as $row) {
    $id = (int)$row['id'];
    $settingsRow = [
        'shift_start' => $row['shift_start'] ?: '08:00:00',
        'shift_end' => $row['shift_end'] ?: '17:00:00',
        'overnight_shift' => $row['overnight_shift'],
        'work_days' => $row['work_days'] ?: '1,2,3,4,5,6',
    ];

    $workDate = attSessionWorkDate($id, $settingsRow);
    $attendance = attFindAttendance($id, $workDate);
    $areas = attEmployeeGeofences($id, $settingsRow);

    $withinShift = attWithinShift($settingsRow);
    $checkedIn = $attendance && $attendance['check_in_at'] && !$attendance['check_out_at'];

    printf("%s (%s)\n", $row['name'], $row['employee_code']);
    printf("  Shift            : %s - %s  days [%s]\n",
        substr($settingsRow['shift_start'], 0, 5), substr($settingsRow['shift_end'], 0, 5),
        $settingsRow['work_days']);

    // The device checks these in this order, so the first FAIL is the reason.
    $gates = [];
    $gates[] = ['Watch enabled globally', $watch > 0, 'geofence_watch_seconds is 0 in Settings'];
    $gates[] = ['Account not blocked', empty($row['security_blocked_at']),
        'blocked ' . $row['security_blocked_at'] . ' — the app is signed out, nothing runs'];
    $gates[] = ['Work area assigned', !empty($areas),
        'no work area, so there is nothing to be outside of'];
    $gates[] = ['Within working hours now', $withinShift,
        'outside their shift or a non-working day — the alarm is silent by design'];
    $gates[] = ['Checked in (and not out)', $checkedIn,
        $attendance
            ? ($attendance['check_out_at'] ? 'already checked out today' : 'no check-in recorded today')
            : 'no attendance record today'];

    $failed = null;
    foreach ($gates as $g) {
        printf("  %-18s %s\n", $g[0], $g[1] ? 'ok' : 'FAIL  <-- ' . $g[2]);
        if (!$g[1] && $failed === null) {
            $failed = $g[0];
        }
    }

    printf("  Mock locations   : %s\n", !empty($row['allow_mock_location'])
        ? 'permitted (fake-GPS testing will work)'
        : 'NOT permitted — a simulated position is ignored, so fake-GPS testing raises no alarm');

    // A departure still open for today keeps the app quiet if it was already explained.
    $open = attDB()->prepare("
        SELECT id, work_date, exit_at, reason_submitted_at
        FROM att_geofence_events
        WHERE att_employee_id = ? AND entry_at IS NULL
        ORDER BY exit_at DESC LIMIT 1
    ");
    $open->execute([$id]);
    $openTrip = $open->fetch(PDO::FETCH_ASSOC);
    if ($openTrip) {
        $stale = $openTrip['work_date'] !== $workDate;
        printf("  Open departure   : #%d from %s%s%s\n",
            $openTrip['id'], $openTrip['work_date'],
            $openTrip['reason_submitted_at'] ? ' (reason already given)' : ' (no reason yet)',
            $stale ? '  <-- from another day; harmless now, but worth closing' : '');
    }

    // Where the phone last reported from, and whether that is inside.
    $last = attDB()->prepare("
        SELECT lat, lng, accuracy_m, inside_fence, distance_from_fence_m, is_mock, provider, recorded_at
        FROM att_location_logs WHERE att_employee_id = ?
        ORDER BY recorded_at DESC LIMIT 1
    ");
    $last->execute([$id]);
    $point = $last->fetch(PDO::FETCH_ASSOC);

    if (!$point) {
        echo "  Last position    : none ever recorded\n";
    } else {
        $age = (int)round((time() - strtotime($point['recorded_at'])) / 60);
        printf("  Last position    : %s (%d min ago) via %s +/-%sm%s\n",
            $point['recorded_at'], $age, $point['provider'] ?: '?',
            $point['accuracy_m'] !== null ? round($point['accuracy_m']) : '?',
            $point['is_mock'] ? '  [SIMULATED]' : '');
        printf("                     %s\n", $point['inside_fence']
            ? 'inside the work area — nothing to alarm about'
            : 'OUTSIDE by ' . (int)$point['distance_from_fence_m'] . ' m'
              . ((int)$point['distance_from_fence_m'] <= $buffer
                 ? '  <-- within the ' . $buffer . ' m tolerance, so treated as on site'
                 : ''));
        if ($point['is_mock'] && empty($row['allow_mock_location'])) {
            echo "                     <-- FAIL: simulated position and mock is not permitted\n";
        }
    }

    echo "  VERDICT          : " . ($failed === null
        ? "all gates pass — the alarm should fire when more than {$buffer} m outside\n"
        : "blocked at \"{$failed}\"\n");
    echo "\n";
}

echo "Delete this file when you are done.\n";
