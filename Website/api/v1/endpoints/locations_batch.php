<?php
/**
 * POST /api/v1/locations/batch   (application/json)
 *
 * Body: { "points": [ { client_uid, lat, lng, accuracy, speed, heading, altitude,
 *                       battery, is_charging, is_mock, provider, recorded_at }, ... ] }
 *
 * The app buffers points in local SQLite and pushes them here, so this endpoint
 * must tolerate replays of a batch it already stored: client_uid is the
 * idempotency key and duplicates are ignored rather than treated as errors.
 * Points are processed in chronological order so fence crossings are detected in
 * the sequence they actually happened.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

attRequireMethod('POST');

$auth = attAuthenticate();
$settings = $auth['settings'];
// Every assigned area. A point inside any of them is on site.
$areas = $auth['geofences'] ?? array_filter([$auth['geofence'] ?? null]);

$points = attParam('points');
if (!is_array($points) || !$points) {
    attFail('NO_POINTS', 'No location points supplied.', 422);
}
if (count($points) > ATT_MAX_BATCH_POINTS) {
    attFail('BATCH_TOO_LARGE', 'Send at most ' . ATT_MAX_BATCH_POINTS . ' points per request.', 422);
}

// Sort by capture time; a retried batch can arrive out of order.
usort($points, function ($a, $b) {
    return strtotime($a['recorded_at'] ?? 'now') <=> strtotime($b['recorded_at'] ?? 'now');
});

$db = attDB();

// Resolved once: every point in a batch is minutes old at most, so they all share
// whichever shift the employee currently has open.
$sessionWorkDate = attSessionWorkDate($auth['att_employee_id'], $settings);

$insert = $db->prepare("
    INSERT INTO att_location_logs
        (att_employee_id, attendance_id, work_date, lat, lng, accuracy_m, altitude_m,
         speed_kmh, heading, battery_pct, is_charging, is_mock, real_lat, real_lng,
         inside_fence, matched_geofence_id, distance_from_fence_m, provider, recorded_at, client_uid)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE received_at = received_at
");

$stored = 0;
$skipped = 0;
$rejected = [];
$attendanceCache = [];
$onClockCache = [];
$exitEventIds = [];
$entryEventIds = [];
$lastInside = null;

/**
 * Consecutive credible outside fixes seen so far, carried across batches.
 *
 * Seeded from what is already stored so the run is not reset by a batch boundary —
 * otherwise an employee reporting one point per request could never accumulate the
 * confirmations needed and no trip would ever open.
 */
$allowMock = (int)($settings['allow_mock_location'] ?? 0) === 1;

// Home-area entry, noticed as the points come in. Configured per employee and
// office-only; nothing about it is ever sent back to the app.
$homeLat = isset($settings['home_lat']) && is_numeric($settings['home_lat']) ? (float)$settings['home_lat'] : null;
$homeLng = isset($settings['home_lng']) && is_numeric($settings['home_lng']) ? (float)$settings['home_lng'] : null;
$homeRadius = max(30, (int)($settings['home_radius_m'] ?? 150));
$homeEnteredAt = null;
// Set only when the matching audit entry is written this request. The WhatsApp
// messages key off these, never off their own count queries: the first version
// counted rows itself with an off-by-one against the logger's rule, and the message
// repeated on every upload for the rest of the rate-limit window.
$homeLogged = false;
$impossibleLogged = false;

// Simulated points from an account not permitted them. Counted rather than acted on
// per point: one upload may carry a dozen, and it is one act of spoofing.
$mockRejected = 0;

// The trip already open before this batch, if any. attRecordExit returns the open
// trip's id for every outside point that touches it — correct for the app's response,
// but a notification keyed on that re-announced the same departure on every upload
// while the person stayed outside. "New departure" means an id that was not the one
// already open when the batch began.
$openBefore = $db->prepare("
    SELECT id FROM att_geofence_events
    WHERE att_employee_id = ? AND entry_at IS NULL AND work_date = ?
    ORDER BY exit_at DESC LIMIT 1
");
$openBefore->execute([$auth['att_employee_id'], $sessionWorkDate]);
$openTripBefore = (int)$openBefore->fetchColumn();
$confirmNeeded = attOutsideConfirmPoints();
$outsideRun = 0;
$uncertainOutside = 0;

if ($confirmNeeded > 1) {
    $recent = $db->prepare("
        SELECT inside_fence, distance_from_fence_m, accuracy_m
        FROM att_location_logs
        WHERE att_employee_id = ?" . ($allowMock ? "" : " AND is_mock = 0") . "
        ORDER BY recorded_at DESC
        LIMIT ?
    ");
    $recent->bindValue(1, $auth['att_employee_id'], PDO::PARAM_INT);
    $recent->bindValue(2, $confirmNeeded - 1, PDO::PARAM_INT);
    $recent->execute();

    foreach ($recent->fetchAll(PDO::FETCH_ASSOC) as $prior) {
        $credible = (int)$prior['inside_fence'] === 0 &&
            attOutsideIsCredible($prior['distance_from_fence_m'], $prior['accuracy_m']);
        if (!$credible) {
            break; // The run is broken; start counting again from zero.
        }
        $outsideRun++;
    }
}

$db->beginTransaction();
try {
    foreach ($points as $index => $point) {
        $lat = $point['lat'] ?? null;
        $lng = $point['lng'] ?? null;

        if (!attValidCoords($lat, $lng)) {
            $rejected[] = ['index' => $index, 'reason' => 'invalid_coordinates'];
            continue;
        }

        $recordedAt = isset($point['recorded_at']) ? strtotime($point['recorded_at']) : false;
        if ($recordedAt === false) {
            $rejected[] = ['index' => $index, 'reason' => 'invalid_timestamp'];
            continue;
        }
        // A device clock running ahead would otherwise write future route points.
        if ($recordedAt > time() + 300) {
            $rejected[] = ['index' => $index, 'reason' => 'timestamp_in_future'];
            continue;
        }
        $recorded = date('Y-m-d H:i:s', $recordedAt);

        $workDate = attPointWorkDate($settings, $recordedAt, $sessionWorkDate);

        if (!array_key_exists($workDate, $attendanceCache)) {
            $row = attFindAttendance($auth['att_employee_id'], $workDate);
            $attendanceCache[$workDate] = $row ? (int)$row['id'] : null;
            // Whether they were actually on the clock for this date. A trip outside
            // the work area only means something between a check-in and a check-out:
            // on a day off, or before arriving, being elsewhere is not a departure
            // from anywhere and must not be raised for review.
            $onClockCache[$workDate] = (bool)($row && $row['check_in_at'] && !$row['check_out_at']);
        }
        $attendanceId = $attendanceCache[$workDate];
        $onClock = $onClockCache[$workDate];

        // A checkout closes the tracking session. Ignore late/replayed points before
        // inserting them, so neither queued device writes nor stale callbacks can
        // create route data after the employee has clocked out.
        if (!$onClock) {
            $skipped++;
            $rejected[] = ['index' => $index, 'reason' => 'shift_closed'];
            continue;
        }

        $evaluation = attEvaluateAreas($lat, $lng, $areas);
        $isMock = filter_var($point['is_mock'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $insert->execute([
            $auth['att_employee_id'],
            $attendanceId,
            $workDate,
            $lat,
            $lng,
            isset($point['accuracy']) && is_numeric($point['accuracy']) ? (float)$point['accuracy'] : null,
            isset($point['altitude']) && is_numeric($point['altitude']) ? (float)$point['altitude'] : null,
            isset($point['speed']) && is_numeric($point['speed']) ? (float)$point['speed'] : null,
            isset($point['heading']) && is_numeric($point['heading']) ? (float)$point['heading'] : null,
            isset($point['battery']) && is_numeric($point['battery']) ? (int)$point['battery'] : null,
            isset($point['is_charging']) ? (filter_var($point['is_charging'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : null,
            $isMock ? 1 : 0,
            // The phone's own account of where it really was while reporting a simulated
            // position. Accepted only alongside a mock point: a genuine fix carrying a
            // "real" position is a contradiction, and storing it would invite confusion.
            $isMock && is_numeric($point['real_lat'] ?? null) ? (float)$point['real_lat'] : null,
            $isMock && is_numeric($point['real_lng'] ?? null) ? (float)$point['real_lng'] : null,
            $evaluation['inside'] ? 1 : 0,
            // Which site, when inside one. Null when outside them all: the nearest area
            // is not somewhere they were, and storing it would read as though it were.
            $evaluation['inside'] ? $evaluation['geofence_id'] : null,
            $evaluation['distance_m'],
            $point['provider'] ?? null,
            $recorded,
            $point['client_uid'] ?? null,
        ]);

        if ($insert->rowCount() > 0) {
            $stored++;
        } else {
            // Already stored under this client_uid; do not re-run crossing logic
            // for it, or a replayed batch would double-count time outside.
            $skipped++;
            continue;
        }

        // A spoofed position must not open or close a trip unless this employee is
        // explicitly permitted simulated locations, which is how testing is done. The
        // point is still stored, flagged, and now reported — see below.
        if ($isMock && !$allowMock) {
            $mockRejected++;
            continue;
        }

        // Off the clock — a day off, before arrival, or already checked out. The point
        // is kept so the route still shows where the device was, but it can neither
        // open nor close a trip: there is no shift to have walked away from, and
        // raising one would ask the employee to justify their own time off.
        if (!$onClock) {
            $lastInside = $evaluation['inside'];
            continue;
        }

        // At their home during the shift. Only on-clock points reach here, so a person
        // at home before work or after check-out is never reported — being at home on
        // one's own time is nobody's business, and that line is what keeps this
        // defensible. First qualifying point of the batch wins; one entry is one event.
        if ($homeLat !== null && $homeLng !== null && $homeEnteredAt === null) {
            if (attHaversine($lat, $lng, $homeLat, $homeLng) <= $homeRadius) {
                $homeEnteredAt = $recorded;
            }
        }

        $episodePoint = ['lat' => $lat, 'lng' => $lng, 'recorded_at' => $recorded];

        if (!$evaluation['inside']) {
            $accuracy = isset($point['accuracy']) && is_numeric($point['accuracy'])
                ? (float)$point['accuracy']
                : null;

            if (!attOutsideIsCredible($evaluation['distance_m'], $accuracy)) {
                // Reported outside, but by less than the fix's own margin of error.
                // The point is still stored — the route map should show what was
                // actually measured — but it must not open a trip, and it breaks the
                // run so drift cannot accumulate into a confirmation.
                $uncertainOutside++;
                $outsideRun = 0;
                $lastInside = $evaluation['inside'];
                continue;
            }

            $outsideRun++;

            // The device's fast watch checks every ten seconds and applies the same
            // credibility rule as above, so by the time it reports a streak it has
            // already established that the departure is real. Accepting that count
            // means the trip opens seconds after the crossing instead of waiting for a
            // second *recorded* point, which is a whole recording interval away — the
            // reason the employee sat looking at a spinner for five minutes.
            //
            // Taken as the greater of the two rather than replacing the server's own
            // tally, so a device reporting nothing (an older build, or a fix that came
            // from the recording listener rather than the watch) still works exactly as
            // before, and a device cannot lower the bar by reporting a smaller number.
            $deviceStreak = isset($point['outside_streak']) && is_numeric($point['outside_streak'])
                ? max(0, (int)$point['outside_streak'])
                : 0;
            $outsideRun = max($outsideRun, $deviceStreak);

            // Below the confirmation threshold this is a candidate, not yet a trip.
            if ($outsideRun < $confirmNeeded) {
                $lastInside = $evaluation['inside'];
                continue;
            }

            $eventId = attRecordExit(
                (int)$auth['att_employee_id'], $attendanceId,
                $settings['geofence_id'], $workDate, $episodePoint, $evaluation['distance_m']
            );
            // Reported whenever a trip is touched, not only on the first outside
            // point of a run: the previous condition left the response claiming no
            // trip had opened when one plainly had, which makes this hard to debug.
            if ($eventId) {
                $exitEventIds[$eventId] = $eventId;
            }
        } else {
            $outsideRun = 0;

            if ($lastInside === false || $lastInside === null) {
                $eventId = attRecordEntry((int)$auth['att_employee_id'], $episodePoint);
                if ($eventId) {
                    $entryEventIds[$eventId] = $eventId;
                }
            }
        }

        $lastInside = $evaluation['inside'];
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    throw $e;
}

// Checked after the batch is committed, so it sees the points that just arrived.
// This is the check that survives a patched app: it is derived from stored data
// rather than reported by the device.
//
// Logged, no longer blocked. The auto-block punished before anyone had looked at the
// evidence, locked the person out of legitimately recording their day, and cost an
// admin an unblock each time — the operator chose visibility over enforcement. The
// detection itself is unchanged; what happens on the Security page is now the whole
// consequence. attSecurityBlock still exists for an admin who decides a case deserves
// it after reading the log.
$impossible = attDetectImpossibleTravel($auth['att_employee_id'], $sessionWorkDate);
if ($impossible) {
    // One entry per episode, not one per upload: the same historical jump is re-seen
    // by every batch for the rest of the day, and a log that repeats itself every
    // minute buries the finding it exists to surface.
    $recent = $db->prepare("
        SELECT COUNT(*) FROM att_audit_logs
        WHERE action = 'impossible_travel' AND entity = 'att_employee' AND entity_id = ?
          AND created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)
    ");
    $recent->execute([$auth['att_employee_id']]);

    if ((int)$recent->fetchColumn() === 0) {
        $impossibleLogged = true;
        attAudit('impossible_travel', 'att_employee', $auth['att_employee_id'], [
            'summary' => sprintf('%s km in %s seconds (%s km/h)',
                number_format($impossible['metres'] / 1000, 1),
                $impossible['seconds'], $impossible['kmh']),
            'from' => $impossible['from'],
            'to' => $impossible['to'],
        ], 'system', null);
    }
}

// Bounded to the session being worked now.
//
// Without the date bound an old departure that was never closed governed the present
// for ever. A trip left open two days ago, with a reason already given, was still
// reported as "currently outside, already explained" - and the device silences its own
// alarm on exactly that flag, so leaving the work area today made no sound at all. The
// answer to a question asked on Friday must not answer Monday's.
// One log entry per visit, not one per point: at a 1-minute interval an evening at
// home would otherwise write dozens of identical rows. Two hours of silence before it
// repeats — long enough to separate visits, short enough that a second trip home the
// same day is still its own entry.
if ($homeEnteredAt !== null) {
    $recentHome = $db->prepare("
        SELECT COUNT(*) FROM att_audit_logs
        WHERE action = 'home_area_entered' AND entity = 'att_employee' AND entity_id = ?
          AND created_at > DATE_SUB(NOW(), INTERVAL 120 MINUTE)
    ");
    $recentHome->execute([$auth['att_employee_id']]);
    if ((int)$recentHome->fetchColumn() === 0) {
        $homeLogged = true;
        attAudit('home_area_entered', 'att_employee', $auth['att_employee_id'], [
            'summary' => 'Entered their home area while on duty',
            'at' => $homeEnteredAt,
            'label' => $settings['home_label'] ?? null,
        ], 'system', null);
    }
}

// WhatsApp copies of what just happened, sent after everything is committed so a
// slow or dead connector can never lose a stored point. One message per event type
// per batch — the departure is one fact however many points confirmed it.
// Fake GPS on an account not permitted it. Reported once an hour: the app uploads
// every minute while a spoofing tool runs, and a message a minute would be noise —
// but complete silence was worse, because a cheat then looked like a quiet day.
$fakeGpsLogged = false;
if ($mockRejected > 0) {
    $recentFake = $db->prepare("
        SELECT COUNT(*) FROM att_audit_logs
        WHERE action = 'fake_gps_detected' AND entity = 'att_employee' AND entity_id = ?
          AND created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)
    ");
    $recentFake->execute([$auth['att_employee_id']]);
    if ((int)$recentFake->fetchColumn() === 0) {
        $fakeGpsLogged = true;
        attAudit('fake_gps_detected', 'att_employee', $auth['att_employee_id'], [
            'summary' => $mockRejected . ' simulated position' . ($mockRejected === 1 ? '' : 's')
                . ' rejected in one upload',
            'note' => 'Attendance and route recording refused while this continues.',
        ], 'system', null);
    }
}

$newDepartures = array_diff(array_keys($exitEventIds), [$openTripBefore]);

$waName = null;
if ($newDepartures || $homeLogged || $impossibleLogged || $fakeGpsLogged) {
    $who = $db->prepare("
        SELECT CONCAT(emp.first_name, ' ', emp.last_name) AS name, emp.employee_code
        FROM att_employees e JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        WHERE e.id = ? LIMIT 1
    ");
    $who->execute([$auth['att_employee_id']]);
    $waName = $who->fetch(PDO::FETCH_ASSOC);
}

if ($waName && $newDepartures) {
    attWaNotify('Left work area', [
        'Employee' => $waName['name'] . ' (' . $waName['employee_code'] . ')',
        'Time' => date('H:i'),
        'They will be asked for a reason in the app; review it under Outside Trips.',
    ]);
}

// One message per log entry, guaranteed by construction: the flag is set in the same
// place the audit row is written, so the two can never disagree.
if ($waName && $homeLogged) {
    {
        attWaNotify('At home on duty', [
            'Employee' => $waName['name'] . ' (' . $waName['employee_code'] . ')',
            'Since' => substr($homeEnteredAt, 11, 5),
            'Their position entered the home area recorded on their profile.',
        ]);
    }
}

if ($waName && $fakeGpsLogged) {
    attWaNotify('Fake GPS detected', [
        'Employee' => $waName['name'] . ' (' . $waName['employee_code'] . ')',
        'Time' => date('H:i'),
        'Their phone is reporting a simulated position. Attendance and route recording '
            . 'are refused while it continues, and the employee has been warned on their phone.',
        'Details are on the Security page.',
    ]);
}

if ($waName && $impossibleLogged) {
    {
        attWaNotify('Impossible movement — possible fake GPS', [
            'Employee' => $waName['name'] . ' (' . $waName['employee_code'] . ')',
            'Detected' => sprintf('%s km in %s seconds (%s km/h)',
                number_format($impossible['metres'] / 1000, 1),
                $impossible['seconds'], $impossible['kmh']),
            'Details are on the Security page.',
        ]);
    }
}

$openEvent = $db->prepare("
    SELECT id, exit_at, max_distance_m, reason_submitted_at FROM att_geofence_events
    WHERE att_employee_id = ? AND entry_at IS NULL AND work_date = ?
    ORDER BY exit_at DESC LIMIT 1
");
$openEvent->execute([$auth['att_employee_id'], $sessionWorkDate]);
$open = $openEvent->fetch(PDO::FETCH_ASSOC);

attOk([
    'stored' => $stored,
    'duplicates_ignored' => $skipped,
    'rejected' => $rejected,
    // Reported outside but within their own error margin, so not treated as a trip.
    // Surfaced so a site whose fences are too tight for its signal quality is
    // diagnosable rather than merely puzzling.
    'uncertain_outside' => $uncertainOutside,
    'outside_run' => $outsideRun,
    'outside_confirm_points' => $confirmNeeded,
    'exit_events' => array_values($exitEventIds),
    'entry_events' => array_values($entryEventIds),
    // The app shows a banner and asks for a reason while this is not null.
    'currently_outside' => $open ? [
        'event_id' => (int)$open['id'],
        'since' => $open['exit_at'],
        'distance_m' => (int)$open['max_distance_m'],
        // The device silences its own alarm on this. Without it, a service that had
        // been restarted re-warned an employee who had already explained the trip,
        // because nothing in the device's own state records that the question was
        // answered — the answer lives here.
        'has_reason' => $open['reason_submitted_at'] !== null,
    ] : null,
    'tracking_active_now' => attTrackingActive($auth['att_employee_id'], $settings),
    // Learned here as well as on /poll: a device mid-upload should not wait a whole
    // poll cycle before speeding up for an admin who is watching it now.
    'live_seconds' => attFollowState($auth['att_employee_id'])['seconds'],
    'tracking_interval_min' => (int)$settings['tracking_interval_min'],
    'server_time' => date('c'),
]);
