<?php
/**
 * GET /api/v1/attendance-history?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * The employee's own register for a date range (defaults to the current month),
 * with a summary the app shows at the top of the history screen.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

$auth = attAuthenticate();

$from = attParam('from', date('Y-m-01'));
$to = attParam('to', date('Y-m-d'));

foreach (['from' => $from, 'to' => $to] as $label => $value) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) {
        attFail('DATE_INVALID', "Parameter '$label' must be a YYYY-MM-DD date.", 422);
    }
}
if (strtotime($from) > strtotime($to)) {
    attFail('DATE_RANGE', "'from' must not be after 'to'.", 422);
}
// Cap the span so a client cannot ask for years of rows in one call.
if ((strtotime($to) - strtotime($from)) > 366 * 86400) {
    attFail('DATE_RANGE', 'Range must be one year or less.', 422);
}

$stmt = attDB()->prepare("
    SELECT a.work_date, a.check_in_at, a.check_out_at, a.worked_minutes, a.outside_minutes,
           a.late_minutes, a.early_leave_minutes, a.overtime_minutes, a.status,
           a.check_in_photo, a.check_out_photo,
           a.check_in_inside_fence, a.check_out_inside_fence,
           g.name AS geofence_name,
           (SELECT COUNT(*) FROM att_geofence_events e
             WHERE e.att_employee_id = a.att_employee_id AND e.work_date = a.work_date) AS trips_outside
    FROM att_attendance a
    LEFT JOIN att_geofences g ON g.id = a.geofence_id
    WHERE a.att_employee_id = ? AND a.work_date BETWEEN ? AND ?
    ORDER BY a.work_date DESC
");
$stmt->execute([$auth['att_employee_id'], $from, $to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Keyed by date so the calendar walk below can find a day's record in one step.
$byDate = [];
foreach ($rows as $row) {
    $byDate[$row['work_date']] = $row;
}

$settings = $auth['settings'];

$days = [];
$summary = ['present' => 0, 'late' => 0, 'half_day' => 0, 'incomplete' => 0,
            'absent' => 0, 'off_days' => 0, 'missing_checkout' => 0,
            'worked_minutes' => 0, 'outside_minutes' => 0, 'overtime_minutes' => 0];

/**
 * Walk every date in the range rather than only the dates that produced a record.
 *
 * A day with no check-in previously returned no row at all, so an absence was
 * invisible — the employee saw a gap and could not tell whether they had been
 * marked absent or the app had simply failed to record. Now the range is walked
 * day by day and the missing ones are labelled explicitly.
 *
 * The employee's own work_days decide which of those absences count: a scheduled
 * day off is reported as 'off' and deliberately excluded from the absent tally,
 * because being off on a rest day is not a shortfall.
 */
$cursor = strtotime($from);
$lastDay = min(strtotime($to), strtotime('today'));

while ($cursor <= $lastDay) {
    $date = date('Y-m-d', $cursor);
    $cursor = strtotime('+1 day', $cursor);

    if (isset($byDate[$date])) {
        continue; // Real records are emitted by the loop below.
    }

    if (!attIsWorkDay($settings, $date)) {
        $summary['off_days']++;
        $days[] = attBlankDay($date, 'off');
        continue;
    }

    // Today is only an absence once the shift has actually ended; before that the
    // employee may simply not have started yet.
    if ($date === date('Y-m-d')) {
        $window = attShiftWindow($settings, $date);
        if (time() < $window['end']) {
            $days[] = attBlankDay($date, 'pending');
            continue;
        }
    }

    $summary['absent']++;
    $days[] = attBlankDay($date, 'absent');
}

foreach ($rows as $row) {
    $summary['worked_minutes'] += (int)$row['worked_minutes'];
    $summary['outside_minutes'] += (int)$row['outside_minutes'];
    $summary['overtime_minutes'] += (int)$row['overtime_minutes'];
    switch ($row['status']) {
        case 'present': $summary['present']++; break;
        case 'late': $summary['late']++; break;
        case 'half-day': $summary['half_day']++; break;
        case 'incomplete': $summary['incomplete']++; break;
        // Counted separately from an in-progress day: the shift is over and nobody
        // closed it, so the hours are unknown rather than merely not final yet.
        case 'missing-checkout': $summary['missing_checkout']++; break;
    }

    $days[] = [
        'work_date' => $row['work_date'],
        'check_in_at' => $row['check_in_at'],
        'check_out_at' => $row['check_out_at'],
        'worked_minutes' => $row['worked_minutes'] !== null ? (int)$row['worked_minutes'] : null,
        'outside_minutes' => (int)$row['outside_minutes'],
        'late_minutes' => (int)$row['late_minutes'],
        'early_leave_minutes' => (int)$row['early_leave_minutes'],
        'overtime_minutes' => (int)$row['overtime_minutes'],
        'status' => $row['status'],
        'geofence_name' => $row['geofence_name'],
        'trips_outside' => (int)$row['trips_outside'],
        'check_in_photo_url' => attPhotoUrl($row['check_in_photo']),
        'check_out_photo_url' => attPhotoUrl($row['check_out_photo']),
        'check_in_inside_fence' => $row['check_in_inside_fence'] === null ? null : (bool)$row['check_in_inside_fence'],
        'check_out_inside_fence' => $row['check_out_inside_fence'] === null ? null : (bool)$row['check_out_inside_fence'],
    ];
}

// Newest first. The two loops above append in different orders, so the combined
// list has to be sorted rather than assumed.
usort($days, function ($a, $b) {
    return strcmp($b['work_date'], $a['work_date']);
});

attOk(['from' => $from, 'to' => $to, 'summary' => $summary, 'days' => $days]);
