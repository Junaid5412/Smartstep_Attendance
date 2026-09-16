<?php
/**
 * Attendance domain logic shared by the API and the admin panel: shift maths,
 * photo storage, the daily register row, and geofence exit/entry episodes.
 */

/**
 * Which work date a timestamp belongs to.
 *
 * For an overnight shift (e.g. 20:00-05:00) the hours after midnight belong to the
 * previous calendar day, otherwise a night shift would be split across two rows.
 */
function attWorkDate($settings, $timestamp = null) {
    $timestamp = $timestamp ?: time();

    if (!empty($settings['overnight_shift'])) {
        $shiftEnd = strtotime(date('Y-m-d', $timestamp) . ' ' . $settings['shift_end']);
        if ($timestamp <= $shiftEnd) {
            return date('Y-m-d', strtotime('-1 day', $timestamp));
        }
    }
    return date('Y-m-d', $timestamp);
}

/**
 * How long after the scheduled end a shift stays open for check-out, in hours.
 *
 * Overtime is normal, so the day cannot simply close on the clock. It also cannot
 * stay open forever, or one forgotten check-out would block every future day.
 */
function attMaxOvertimeHours($settings) {
    $value = (int)($settings['max_overtime_hours'] ?? 6);
    return max(0, min(18, $value));
}

/**
 * The work date the employee's app is currently acting on.
 *
 * attWorkDate() answers "which day does this instant belong to", which is what the
 * route log needs. It is the wrong question for the Check In / Check Out button.
 * By the clock alone an overnight shift rolls to the next date at its end time and
 * a day shift at midnight — and at that moment anyone still on site had their open
 * row abandoned and was offered a fresh Check In, so their check-out was written
 * against the following day and the shift they actually worked stayed open forever.
 *
 * So a shift that has been checked into and not checked out of keeps the session on
 * its own date until it is closed, or until overtime runs out.
 *
 * Note that the deadline only governs whether a *later* date may take over. While
 * the natural date is still the open one — a day-shift employee at 23:00 whose
 * overtime allowance lapsed at 22:30 — check-out stays available. Being generous
 * about closing a shift is harmless; refusing to let someone clock off is not.
 */
function attSessionWorkDate($attEmployeeId, $settings, $timestamp = null) {
    $timestamp = $timestamp ?: time();
    $natural = attWorkDate($settings, $timestamp);

    $stmt = attDB()->prepare("
        SELECT id, work_date FROM att_attendance
        WHERE att_employee_id = ? AND work_date < ?
          AND check_in_at IS NOT NULL AND check_out_at IS NULL
        ORDER BY work_date DESC LIMIT 1
    ");
    $stmt->execute([$attEmployeeId, $natural]);
    $open = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$open) {
        return $natural;
    }

    $window = attShiftWindow($settings, $open['work_date']);
    $deadline = $window['end'] + attMaxOvertimeHours($settings) * 3600;

    if ($timestamp <= $deadline) {
        return $open['work_date'];
    }

    attMarkMissingCheckout((int)$open['id']);
    return $natural;
}

/**
 * Mark a date, or a range of dates, as leave or a holiday for one employee.
 *
 * Creates the register row when there is none, because the whole point is to record
 * an authorised absence for a day nobody checked in on. A day that was actually
 * worked is left alone: overwriting a real check-in with "on leave" would destroy
 * measured hours, and if the record is wrong that is a correction for a person to
 * make deliberately, not a side effect of marking leave.
 *
 * @param string $status 'leave' or 'holiday'
 * @return array{marked:int,skipped:int,skipped_dates:array}
 */
function attMarkLeave($attEmployeeId, $fromDate, $toDate, $status, $note, $adminUserId) {
    $status = in_array($status, ['leave', 'holiday'], true) ? $status : 'leave';

    $db = attDB();
    // employee_id is null for a monitor, so its absence no longer means "no such
    // account" — the account's own existence is what has to be checked.
    $employee = $db->prepare("SELECT id, employee_id FROM att_employees WHERE id = ? LIMIT 1");
    $employee->execute([$attEmployeeId]);
    $account = $employee->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        return ['marked' => 0, 'skipped' => 0, 'skipped_dates' => [], 'error' => 'No such employee.'];
    }
    $erpId = $account['employee_id'];

    $cursor = strtotime($fromDate);
    $last = strtotime($toDate ?: $fromDate);
    if ($cursor === false || $last === false || $last < $cursor) {
        return ['marked' => 0, 'skipped' => 0, 'skipped_dates' => [], 'error' => 'Invalid date range.'];
    }
    // A guard against a typo turning into thousands of rows.
    if (($last - $cursor) > 366 * 86400) {
        return ['marked' => 0, 'skipped' => 0, 'skipped_dates' => [], 'error' => 'Range must be a year or less.'];
    }

    $marked = 0;
    $skipped = [];

    $db->beginTransaction();
    try {
        while ($cursor <= $last) {
            $date = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);

            $existing = attFindAttendance($attEmployeeId, $date);

            if ($existing && $existing['check_in_at']) {
                $skipped[] = $date;
                continue;
            }

            if ($existing) {
                $db->prepare("
                    UPDATE att_attendance SET status = ?, admin_note = ?, edited_by = ?, source = 'manual'
                    WHERE id = ?
                ")->execute([$status, $note ?: null, $adminUserId, $existing['id']]);
            } else {
                $db->prepare("
                    INSERT INTO att_attendance
                        (att_employee_id, employee_id, work_date, status, admin_note, edited_by, source)
                    VALUES (?, ?, ?, ?, ?, ?, 'manual')
                ")->execute([$attEmployeeId, $erpId, $date, $status, $note ?: null, $adminUserId]);
            }
            $marked++;
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    attAudit('leave_marked', 'att_employee', (int)$attEmployeeId, [
        'from' => $fromDate, 'to' => $toDate, 'status' => $status,
        'note' => $note, 'marked' => $marked, 'skipped' => $skipped,
    ], 'admin', $adminUserId);

    return ['marked' => $marked, 'skipped' => count($skipped), 'skipped_dates' => $skipped];
}

/**
 * Is this date an authorised absence — leave or a holiday?
 *
 * Used to keep leave out of the absence tally and out of the app's expectations: a
 * day granted as leave is not a shortfall, and the app must not treat it as a shift.
 */
function attIsLeaveDay($attEmployeeId, $workDate) {
    $stmt = attDB()->prepare("
        SELECT status FROM att_attendance
        WHERE att_employee_id = ? AND work_date = ? LIMIT 1
    ");
    $stmt->execute([$attEmployeeId, $workDate]);
    return in_array($stmt->fetchColumn(), ['leave', 'holiday'], true);
}

/**
 * Flag a shift nobody ever checked out of.
 *
 * No check-out time is invented for it. A fabricated one would flow straight into
 * worked hours and payroll as though it had been measured; leaving worked_minutes
 * empty and the status visible forces the correction to be made by a person who
 * knows what actually happened.
 */
function attMarkMissingCheckout($attendanceId) {
    $db = attDB();

    $stmt = $db->prepare("SELECT att_employee_id, work_date FROM att_attendance WHERE id = ? LIMIT 1");
    $stmt->execute([$attendanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $db->prepare("
        UPDATE att_attendance SET status = 'missing-checkout'
        WHERE id = ? AND check_out_at IS NULL AND check_in_at IS NOT NULL
    ")->execute([$attendanceId]);

    if (!$row) {
        return;
    }

    // Abandon any trip still open on that shift.
    //
    // A trip is a departure from a shift, so it cannot outlive the shift. Left open it
    // followed the employee into every later day — the app went on telling them "you
    // are outside your work area" on their day off, about a shift that ended long ago,
    // which is the single most confusing thing this system can do.
    //
    // No duration is invented: nobody knows when they actually got back, and a fake
    // figure would flow into outside_minutes as though it had been measured.
    $db->prepare("
        UPDATE att_geofence_events
        SET review_status = CASE WHEN review_status = 'pending' THEN 'pending' ELSE review_status END,
            entry_at = exit_at
        WHERE att_employee_id = ? AND work_date = ? AND entry_at IS NULL
    ")->execute([$row['att_employee_id'], $row['work_date']]);
}

/** Shift window for a work date as unix timestamps. */
function attShiftWindow($settings, $workDate, $sessionNo = 1) {
    // A part-time monitor's afternoon run has its own hours. Without this, lateness for
    // the second shift was measured against the morning's start — someone arriving at
    // 12:00 for a 12:00 shift was recorded as four hours late.
    //
    // Falling back to the account's own times when the second pair is not configured is
    // deliberate: an unconfigured split shift then behaves exactly as it did before,
    // rather than measuring against midnight.
    $startTime = $settings['shift_start'];
    $endTime = $settings['shift_end'];

    if ((int)$sessionNo >= 2 && !empty($settings['shift2_start']) && !empty($settings['shift2_end'])) {
        $startTime = $settings['shift2_start'];
        $endTime = $settings['shift2_end'];
    }

    $start = strtotime($workDate . ' ' . $startTime);
    $end = strtotime($workDate . ' ' . $endTime);

    // The overnight flag describes the account's main shift. A second shift that simply
    // ends after it starts is an ordinary afternoon and must not be pushed a day out.
    $wrapsMidnight = $end <= $start;
    $overnight = !empty($settings['overnight_shift']) && (int)$sessionNo < 2;

    if ($overnight || $wrapsMidnight) {
        $end = strtotime('+1 day', $end);
    }
    return ['start' => $start, 'end' => $end];
}

/**
 * The window covering every shift of the day — earliest start to latest end.
 *
 * Tracking and the "is this a work day" question care about the whole day, not one shift.
 * For a single-shift account this is exactly attShiftWindow().
 */
function attDayWindow($settings, $workDate) {
    $first = attShiftWindow($settings, $workDate, 1);

    if ((int)($settings['sessions_per_day'] ?? 1) < 2
        || empty($settings['shift2_start']) || empty($settings['shift2_end'])) {
        return $first;
    }

    $second = attShiftWindow($settings, $workDate, 2);
    return [
        'start' => min($first['start'], $second['start']),
        'end' => max($first['end'], $second['end']),
    ];
}

/** Is the work date one of the employee's configured working days? */
function attIsWorkDay($settings, $workDate) {
    $days = array_filter(array_map('intval', explode(',', (string)$settings['work_days'])));
    if (!$days) {
        return true;
    }
    return in_array((int)date('N', strtotime($workDate)), $days, true);
}

/**
 * Is the clock currently inside the tracking window? Tracking runs across the
 * whole assigned shift (not just between check-in and check-out), with a small
 * lead-in so an employee arriving early is already being recorded.
 */
function attWithinShift($settings, $timestamp = null, $leadMinutes = 30) {
    $timestamp = $timestamp ?: time();
    $workDate = attWorkDate($settings, $timestamp);

    if (!attIsWorkDay($settings, $workDate)) {
        return false;
    }

    // Each shift is its own window. Deliberately not the span from the first start to
    // the last end: a part-time monitor is off duty between the morning and afternoon
    // runs, and recording their location through those hours would be tracking someone
    // on their own time.
    $sessions = (int)($settings['sessions_per_day'] ?? 1);
    for ($sessionNo = 1; $sessionNo <= max(1, $sessions); $sessionNo++) {
        $window = attShiftWindow($settings, $workDate, $sessionNo);
        if ($timestamp >= ($window['start'] - $leadMinutes * 60)
            && $timestamp <= ($window['end'] + $leadMinutes * 60)) {
            return true;
        }
    }
    return false;
}

/**
 * Which shift a single recorded position belongs to.
 *
 * By the clock, a point captured at 01:00 during overtime on a day shift belongs to
 * the new date — which would detach it from the shift being worked and leave the
 * overtime hours missing from that shift's route, exactly where an admin looks.
 * When the session is pinned to a still-open earlier shift, points inside that
 * shift's overtime window are attributed to it.
 */
function attPointWorkDate($settings, $recordedAt, $sessionWorkDate = null) {
    $natural = attWorkDate($settings, $recordedAt);

    if ($sessionWorkDate === null || $sessionWorkDate >= $natural) {
        return $natural;
    }

    $window = attDayWindow($settings, $sessionWorkDate);
    $deadline = $window['end'] + attMaxOvertimeHours($settings) * 3600;

    if ($recordedAt >= $window['start'] && $recordedAt <= $deadline) {
        return $sessionWorkDate;
    }
    return $natural;
}

/**
 * Should the device be recording right now?
 *
 * attWithinShift() alone stops half an hour after the scheduled end, which would
 * leave the route of anyone working overtime simply missing — the hours least
 * covered by a roster are the ones an admin most wants to see. An open shift keeps
 * recording until it is checked out of, within the overtime allowance.
 */
function attTrackingActive($attEmployeeId, $settings, $timestamp = null) {
    $timestamp = $timestamp ?: time();

    // Authorised leave outranks the roster. The day may be a working day on paper,
    // but an employee granted leave is not expected at work and should not be
    // followed around on it.
    if (attIsLeaveDay($attEmployeeId, attWorkDate($settings, $timestamp))) {
        return false;
    }

    if (attWithinShift($settings, $timestamp)) {
        return true;
    }

    $workDate = attSessionWorkDate($attEmployeeId, $settings, $timestamp);
    $row = attFindAttendance($attEmployeeId, $workDate);
    if (!$row || !$row['check_in_at'] || $row['check_out_at']) {
        return false;
    }

    $window = attShiftWindow($settings, $workDate, (int)($row['session_no'] ?? 1));
    return $timestamp <= $window['end'] + attMaxOvertimeHours($settings) * 3600;
}

/** Fetch today's register row for an employee, or null. */
function attFindAttendance($attEmployeeId, $workDate, $sessionNo = null) {
    if ($sessionNo !== null) {
        $stmt = attDB()->prepare("
            SELECT * FROM att_attendance
            WHERE att_employee_id = ? AND work_date = ? AND session_no = ? LIMIT 1
        ");
        $stmt->execute([$attEmployeeId, $workDate, (int)$sessionNo]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // No session asked for: the latest one, which for a single-session day is the only
    // row and for a split shift is the one still in play. Callers that pre-date split
    // shifts therefore keep behaving sensibly rather than seeing a stale morning.
    $stmt = attDB()->prepare("
        SELECT * FROM att_attendance
        WHERE att_employee_id = ? AND work_date = ?
        ORDER BY session_no DESC LIMIT 1
    ");
    $stmt->execute([$attEmployeeId, $workDate]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * How many shifts this account works in a day, and which one is next.
 *
 * @return array{allowed:int,rows:array,open:?array,next:?int}
 *   allowed — sessions permitted per day (1 for full time, 2 for a split shift)
 *   open    — the session checked into and not out of, if any
 *   next    — the session number a check-in would create, or null when the day is done
 */
function attSessionState($attEmployeeId, $workDate, $settings) {
    $allowed = max(1, min(4, (int)($settings['sessions_per_day'] ?? 1)));

    $stmt = attDB()->prepare("
        SELECT * FROM att_attendance
        WHERE att_employee_id = ? AND work_date = ?
        ORDER BY session_no ASC
    ");
    $stmt->execute([$attEmployeeId, $workDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $open = null;
    $highest = 0;
    foreach ($rows as $row) {
        $highest = max($highest, (int)$row['session_no']);
        if ($row['check_in_at'] && !$row['check_out_at']) {
            $open = $row;
        }
    }

    // A row with no check-in at all is a leave or holiday marker, not a worked session,
    // so it must not consume one of the day's shifts.
    $used = 0;
    foreach ($rows as $row) {
        if ($row['check_in_at']) {
            $used++;
        }
    }

    $next = null;
    if (!$open && $used < $allowed) {
        $next = max($highest + 1, 1);
        // Reuse an empty marker row's number rather than skipping past it, so a day
        // marked as leave and then actually worked does not start at session 2.
        foreach ($rows as $row) {
            if (!$row['check_in_at']) {
                $next = (int)$row['session_no'];
                break;
            }
        }
    }

    return ['allowed' => $allowed, 'rows' => $rows, 'open' => $open, 'next' => $next];
}

/**
 * Store a check-in/out selfie under uploads/attendance/YYYY/MM/<employee>/.
 *
 * @param array $file  an entry from $_FILES
 * @return string      path relative to the uploads directory
 */
function attStorePhoto(array $file, $attEmployeeId, $kind) {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        attFail('PHOTO_INVALID', 'Photo upload failed. Please retake the picture.', 422);
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        attFail('PHOTO_INVALID', 'Photo upload failed (error ' . $file['error'] . ').', 422);
    }
    if ($file['size'] > ATT_PHOTO_MAX_BYTES) {
        attFail('PHOTO_TOO_LARGE', 'Photo is larger than the ' . round(ATT_PHOTO_MAX_BYTES / 1048576) . 'MB limit.', 422);
    }

    // Trust the file's actual contents, not the client-supplied MIME type.
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? null;
    if (!$mime || !isset(ATT_PHOTO_TYPES[$mime])) {
        attFail('PHOTO_TYPE', 'Only JPG, PNG or WebP images are accepted.', 422);
    }

    $relativeDir = 'attendance/' . date('Y/m') . '/' . $attEmployeeId;
    $absoluteDir = ATT_UPLOAD_PATH . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        attFail('PHOTO_STORE', 'Could not store the photo on the server.', 500);
    }

    $filename = $kind . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . ATT_PHOTO_TYPES[$mime];
    $target = $absoluteDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        attFail('PHOTO_STORE', 'Could not store the photo on the server.', 500);
    }
    @chmod($target, 0644);

    return $relativeDir . '/' . $filename;
}

/**
 * Validate a position report against the employee's rules.
 * Returns the geofence evaluation; fails the request when a rule is broken.
 */
function attValidatePosition($auth, $lat, $lng, $accuracy, $isMock, $strict = true) {
    $settings = $auth['settings'];

    if (!attValidCoords($lat, $lng)) {
        attFail('LOCATION_INVALID', 'No valid GPS position was received. Move outdoors and try again.', 422);
    }

    if ($strict && $isMock && (int)$settings['allow_mock_location'] !== 1) {
        attFail('MOCK_LOCATION', 'A mock location app was detected. Turn it off to continue.', 422);
    }

    $maxAccuracy = (int)$settings['max_accuracy_m'];
    if ($strict && $maxAccuracy > 0 && $accuracy !== null && (float)$accuracy > $maxAccuracy) {
        attFail(
            'LOCATION_ACCURACY',
            'GPS accuracy is ' . round((float)$accuracy) . 'm; ' . $maxAccuracy . 'm or better is required. Wait for a stronger signal.',
            422,
            ['accuracy_m' => (float)$accuracy, 'required_m' => $maxAccuracy]
        );
    }

    // Judged against every assigned area, not just the primary one: somebody rostered
    // across two offices is at work in either, and being told to explain their
    // presence at their own second site was simply wrong.
    $areas = $auth['geofences'] ?? array_filter([$auth['geofence'] ?? null]);
    $evaluation = attEvaluateAreas($lat, $lng, $areas);

    if ($strict && !$evaluation['inside'] && (int)$settings['enforce_geofence'] === 1) {
        // Names the nearest area, and says how many were checked, so "outside your
        // work area" cannot be mistaken for "we only looked at one of them".
        $where = $evaluation['geofence_name'] ?? ($auth['geofence']['name'] ?? 'area');
        attFail(
            'OUTSIDE_GEOFENCE',
            'You are ' . $evaluation['distance_m'] . 'm outside your ' .
                (count($areas) > 1 ? 'nearest work area (' : 'assigned work area (') .
                $where . ').',
            422,
            [
                'distance_m' => $evaluation['distance_m'],
                'nearest_geofence_id' => $evaluation['geofence_id'],
                'areas_checked' => count($areas),
            ]
        );
    }

    return $evaluation;
}

/**
 * Is this fix good enough to assert the employee is genuinely outside the fence?
 *
 * A fix reported 30 m outside with ±100 m accuracy says nothing: the true position
 * could easily be well inside. Requiring the distance to exceed the fix's own error
 * margin is what separates "left the site" from "GPS drifted", and it is the single
 * most important guard on this feature — a false trip asks an employee to explain a
 * journey they never made.
 *
 * A fix with no accuracy figure is trusted, since there is nothing to judge it by.
 */
function attOutsideIsCredible($distanceM, $accuracyM) {
    if ($accuracyM === null || $accuracyM <= 0) {
        return true;
    }
    return (float)$distanceM >= (float)$accuracyM;
}

/** How many consecutive credible outside fixes are needed to open a trip. */
function attOutsideConfirmPoints() {
    $value = (int)(attSettings()['outside_confirm_points'] ?? 12);
    return max(1, min(36, $value));
}

/**
 * Open a geofence-exit episode, or extend the one already open.
 *
 * Called for every location ping outside the fence, so the "furthest point"
 * columns end up describing where the employee actually went, not just where
 * they crossed the boundary.
 */
function attRecordExit($attEmployeeId, $attendanceId, $geofenceId, $workDate, $point, $distance) {
    $db = attDB();

    $stmt = $db->prepare("
        SELECT id, max_distance_m FROM att_geofence_events
        WHERE att_employee_id = ? AND entry_at IS NULL
        ORDER BY exit_at DESC LIMIT 1
    ");
    $stmt->execute([$attEmployeeId]);
    $open = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($open) {
        if ((int)$distance > (int)$open['max_distance_m']) {
            $db->prepare("
                UPDATE att_geofence_events
                SET max_distance_m = ?, farthest_lat = ?, farthest_lng = ?
                WHERE id = ?
            ")->execute([$distance, $point['lat'], $point['lng'], $open['id']]);
        }
        return (int)$open['id'];
    }

    $db->prepare("
        INSERT INTO att_geofence_events
            (att_employee_id, attendance_id, geofence_id, work_date, exit_at, exit_lat, exit_lng,
             max_distance_m, farthest_lat, farthest_lng)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $attEmployeeId, $attendanceId, $geofenceId, $workDate,
        $point['recorded_at'], $point['lat'], $point['lng'],
        $distance, $point['lat'], $point['lng'],
    ]);

    return (int)$db->lastInsertId();
}

/** Close the open exit episode on re-entry and add its duration to the day's total. */
function attRecordEntry($attEmployeeId, $point) {
    $db = attDB();

    $stmt = $db->prepare("
        SELECT id, attendance_id, exit_at FROM att_geofence_events
        WHERE att_employee_id = ? AND entry_at IS NULL
        ORDER BY exit_at DESC LIMIT 1
    ");
    $stmt->execute([$attEmployeeId]);
    $open = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$open) {
        return null;
    }

    $minutes = max(0, (int)round((strtotime($point['recorded_at']) - strtotime($open['exit_at'])) / 60));

    $db->prepare("
        UPDATE att_geofence_events
        SET entry_at = ?, entry_lat = ?, entry_lng = ?, duration_min = ?
        WHERE id = ?
    ")->execute([$point['recorded_at'], $point['lat'], $point['lng'], $minutes, $open['id']]);

    if ($open['attendance_id']) {
        $db->prepare("UPDATE att_attendance SET outside_minutes = outside_minutes + ? WHERE id = ?")
           ->execute([$minutes, $open['attendance_id']]);
    }

    return (int)$open['id'];
}

/**
 * Recalculate the derived columns of a register row: worked time, lateness, early
 * leave, and the resulting status.
 */
function attFinaliseDay($attendanceId, $settings) {
    $db = attDB();

    $stmt = $db->prepare("SELECT * FROM att_attendance WHERE id = ? LIMIT 1");
    $stmt->execute([$attendanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['check_in_at']) {
        return;
    }

    // The shift this row belongs to, not the account's first one. This is where the bug
    // bit hardest: an afternoon session measured against a 08:00 start recorded a monitor
    // arriving on time as four hours late, and that figure flows into payroll.
    $window = attShiftWindow($settings, $row['work_date'], (int)($row['session_no'] ?? 1));
    $checkIn = strtotime($row['check_in_at']);
    $grace = (int)$settings['late_grace_min'] * 60;

    $lateMinutes = max(0, (int)round(($checkIn - ($window['start'] + $grace)) / 60));

    $workedMinutes = null;
    $earlyMinutes = 0;
    $overtimeMinutes = 0;
    $status = 'incomplete';

    if ($row['check_out_at']) {
        $checkOut = strtotime($row['check_out_at']);
        $workedMinutes = max(0, (int)round(($checkOut - $checkIn) / 60));
        $earlyMinutes = max(0, (int)round(($window['end'] - $checkOut) / 60));

        $shiftMinutes = max(1, (int)round(($window['end'] - $window['start']) / 60));

        // Time spent outside the assigned area does not count towards the day — except
        // the part an admin explicitly credited when approving the trip. Approval used
        // to be a verdict with no consequence: the whole excursion still came off the
        // worked time, so a site visit an admin had signed off was paid exactly like an
        // unexplained absence. approved_minutes is the missing half of that decision.
        //
        // The credit is capped at each trip's real duration: approving "2 hours" of a
        // 40-minute trip credits 40 minutes, not a fictional 120.
        $creditStmt = $db->prepare("
            SELECT COALESCE(SUM(LEAST(approved_minutes, COALESCE(duration_min, approved_minutes))), 0)
            FROM att_geofence_events
            WHERE attendance_id = ? AND review_status = 'approved' AND approved_minutes IS NOT NULL
        ");
        $creditStmt->execute([$attendanceId]);
        $approvedCredit = (int)$creditStmt->fetchColumn();

        $chargeableOutside = max(0, (int)$row['outside_minutes'] - $approvedCredit);
        $effective = max(0, $workedMinutes - $chargeableOutside);

        // worked_minutes stores the time that counts, not the raw span. It used to store
        // check-out minus check-in while the comment above claimed outside time did not
        // count towards the day — the deduction only ever reached status and overtime,
        // and the register's "worked" column quietly included time the same page listed
        // as spent outside. The raw span is not lost: it is derivable from the check-in
        // and check-out stamps, which are both on this row.
        $workedMinutes = $effective;

        // Time worked beyond the assigned shift length. Measured against the length
        // of the shift rather than its end time, so someone who starts late and
        // leaves late is not credited with overtime they did not work. It is stored
        // rather than derived because the shift may be reassigned later, and the day
        // as worked should not change retroactively when that happens.
        $overtimeMinutes = max(0, $effective - $shiftMinutes);

        if ($effective < $shiftMinutes * 0.5) {
            $status = 'half-day';
        } else {
            $status = $lateMinutes > 0 ? 'late' : 'present';
        }
    } elseif ($row['status'] === 'missing-checkout') {
        // Already flagged as abandoned; do not quietly downgrade it to 'incomplete'
        // and hide it from the admin who needs to correct it.
        $status = 'missing-checkout';
    }

    $db->prepare("
        UPDATE att_attendance
        SET worked_minutes = ?, late_minutes = ?, early_leave_minutes = ?,
            overtime_minutes = ?, status = ?
        WHERE id = ?
    ")->execute([$workedMinutes, $lateMinutes, $earlyMinutes, $overtimeMinutes, $status, $attendanceId]);
}

/**
 * Delete one day's attendance record.
 *
 * This removes data that payroll may already have acted on, so the whole row is
 * written into the audit log as JSON before it goes. That is the only recovery
 * route — there is no undo — and it is what makes an accidental deletion
 * explainable afterwards rather than just a gap in the register.
 *
 * @param bool $alsoRoute Delete the day's route points and trips too. When false
 *                        they are kept but detached, because att_location_logs
 *                        .attendance_id has no foreign key and would otherwise be
 *                        left pointing at a row that no longer exists.
 * @return array{ok:bool,message:string,photos:int,points:int,trips:int}
 */
function attDeleteAttendance($attendanceId, $alsoRoute, $reason, $adminUserId) {
    $db = attDB();

    $stmt = $db->prepare("
        SELECT a.*, emp.employee_code,
               CONCAT(emp.first_name, ' ', emp.last_name) AS name
        FROM att_attendance a
        JOIN att_employees e ON e.id = a.att_employee_id
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$attendanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'message' => 'That attendance record no longer exists.',
                'photos' => 0, 'points' => 0, 'trips' => 0];
    }

    $points = 0;
    $trips = 0;

    $db->beginTransaction();
    try {
        if ($alsoRoute) {
            $del = $db->prepare("
                DELETE FROM att_location_logs WHERE att_employee_id = ? AND work_date = ?
            ");
            $del->execute([$row['att_employee_id'], $row['work_date']]);
            $points = $del->rowCount();

            $del = $db->prepare("
                DELETE FROM att_geofence_events WHERE att_employee_id = ? AND work_date = ?
            ");
            $del->execute([$row['att_employee_id'], $row['work_date']]);
            $trips = $del->rowCount();
        } else {
            // Detach rather than orphan.
            $db->prepare("UPDATE att_location_logs SET attendance_id = NULL WHERE attendance_id = ?")
               ->execute([$attendanceId]);
            $db->prepare("UPDATE att_geofence_events SET attendance_id = NULL WHERE attendance_id = ?")
               ->execute([$attendanceId]);
        }

        $db->prepare("DELETE FROM att_attendance WHERE id = ?")->execute([$attendanceId]);

        // Written inside the transaction so a record can never vanish without the
        // audit entry that explains it.
        $db->prepare("
            INSERT INTO att_audit_logs (actor_type, actor_id, action, entity, entity_id, details, ip_address)
            VALUES ('admin', ?, 'attendance_deleted', 'att_attendance', ?, ?, ?)
        ")->execute([
            $adminUserId,
            $attendanceId,
            json_encode([
                'reason' => $reason,
                'also_deleted_route' => (bool)$alsoRoute,
                'points_deleted' => $points,
                'trips_deleted' => $trips,
                'record' => $row,
            ], JSON_UNESCAPED_UNICODE),
            attClientIp(),
        ]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        return ['ok' => false, 'message' => 'Could not delete the record: ' . $e->getMessage(),
                'photos' => 0, 'points' => 0, 'trips' => 0];
    }

    // Files are removed only after the transaction commits — a rolled-back delete
    // must not leave the register pointing at photos that are already gone.
    $photos = 0;
    foreach ([$row['check_in_photo'], $row['check_out_photo']] as $photo) {
        if (attDeletePhotoFile($photo)) {
            $photos++;
        }
    }

    return [
        'ok' => true,
        'message' => 'Deleted ' . $row['name'] . ' — ' .
                     date('d M Y', strtotime($row['work_date'])) . '.',
        'photos' => $photos,
        'points' => $points,
        'trips' => $trips,
    ];
}

/**
 * Remove a stored photo, refusing anything that resolves outside the uploads
 * directory. The path comes from our own database, but a delete driven by a stored
 * string is exactly the place a traversal bug becomes destructive.
 */
function attDeletePhotoFile($relativePath) {
    if (!$relativePath) {
        return false;
    }

    $base = realpath(ATT_UPLOAD_PATH);
    $target = realpath(ATT_UPLOAD_PATH . '/' . ltrim($relativePath, '/'));

    if (!$base || !$target || strpos($target, $base) !== 0 || !is_file($target)) {
        return false;
    }

    return @unlink($target);
}

/**
 * A day with no attendance record, shaped exactly like a real one.
 *
 * Same keys as the populated rows so the app can render one list without
 * special-casing, and so a missing day is an explicit status rather than a gap the
 * employee has to interpret.
 */
function attBlankDay($workDate, $status) {
    return [
        'work_date' => $workDate,
        'check_in_at' => null,
        'check_out_at' => null,
        'worked_minutes' => null,
        'outside_minutes' => 0,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'overtime_minutes' => 0,
        'status' => $status,
        'geofence_name' => null,
        'trips_outside' => 0,
        'check_in_photo_url' => null,
        'check_out_photo_url' => null,
        'check_in_inside_fence' => null,
        'check_out_inside_fence' => null,
    ];
}

/** Public URL for a stored photo path. */
function attPhotoUrl($relativePath) {
    return $relativePath ? ATT_UPLOAD_URL . '/' . ltrim($relativePath, '/') : null;
}

/** Shape a geofence row for the app / map JS. */
function attGeofencePayload($geofence) {
    if (!$geofence) {
        return null;
    }
    return [
        'id' => (int)$geofence['id'],
        'name' => $geofence['name'],
        'type' => $geofence['type'],
        'center_lat' => $geofence['center_lat'] !== null ? (float)$geofence['center_lat'] : null,
        'center_lng' => $geofence['center_lng'] !== null ? (float)$geofence['center_lng'] : null,
        'radius_m' => $geofence['radius_m'] !== null ? (int)$geofence['radius_m'] : null,
        'polygon' => attPolygonPoints($geofence),
        'color' => $geofence['color'],
    ];
}

/**
 * Did this employee's recorded route pass through their home during a shift?
 *
 * Answered from the route already stored, not by tracking anything new: the points
 * exist because the employee is on shift, and this only asks a different question of
 * them. It is deliberately confined to the working day — nothing outside the shift
 * window is examined, because where somebody is on their own time is not the company's
 * business and collecting it would not be defensible.
 *
 * Reported to the office only. See attHomePayload's note on why it is not in the app.
 *
 * @return array{visited:bool,minutes:int,first_at:?string,last_at:?string,points:int}
 */
function attHomeVisit($attEmployeeId, $workDate, $settings) {
    $lat = $settings['home_lat'] ?? null;
    $lng = $settings['home_lng'] ?? null;

    if ($lat === null || $lng === null) {
        return ['configured' => false, 'visited' => false, 'minutes' => 0,
                'first_at' => null, 'last_at' => null, 'points' => 0];
    }

    $radius = max(30, (int)($settings['home_radius_m'] ?? 150));

    // Only points belonging to the shift itself.
    $stmt = attDB()->prepare("
        SELECT recorded_at, lat, lng
        FROM att_location_logs
        WHERE att_employee_id = ? AND work_date = ?
        ORDER BY recorded_at ASC
    ");
    $stmt->execute([$attEmployeeId, $workDate]);

    $inside = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $point) {
        $distance = attHaversine((float)$point['lat'], (float)$point['lng'], (float)$lat, (float)$lng);
        if ($distance <= $radius) {
            $inside[] = $point['recorded_at'];
        }
    }

    if (!$inside) {
        return ['configured' => true, 'visited' => false, 'minutes' => 0,
                'first_at' => null, 'last_at' => null, 'points' => 0];
    }

    // Elapsed between the first and last point inside, which is a floor rather than an
    // exact figure: at a one-minute interval it under-reports by up to two minutes, and
    // guessing higher would overstate something an employee may be asked about.
    $first = $inside[0];
    $last = $inside[count($inside) - 1];

    return [
        'configured' => true,
        'visited' => true,
        'minutes' => max(0, (int)round((strtotime($last) - strtotime($first)) / 60)),
        'first_at' => $first,
        'last_at' => $last,
        'points' => count($inside),
    ];
}

/* ======================== security blocking ======================== */

/**
 * Fastest speed treated as physically possible between two recorded points, km/h.
 *
 * 300 km/h is far above anything achievable by road in Qatar, and well below what
 * spoofing produces — the test data showed 19 km covered in 55 seconds, about
 * 1,240 km/h. Set generously on purpose: a false block locks a real employee out of
 * their own attendance, which is a worse failure than missing one spoofer.
 */
const ATT_MAX_PLAUSIBLE_KMH = 300;

/** Below this the jump is too small to distinguish from a poor fix. */
const ATT_MIN_JUMP_METRES = 1000;

/**
 * Block an account on security grounds.
 *
 * Deliberately separate from is_active, which an admin uses to retire someone. Mixing
 * the two would make "why can this person not log in" unanswerable, and would let a
 * routine reactivation silently clear a security block nobody had reviewed.
 *
 * Blocking is recorded with its evidence, because the employee will ask why — and
 * because an accusation of faking location needs to be answerable with specifics.
 */
function attSecurityBlock($attEmployeeId, $reason, array $evidence = []) {
    $db = attDB();

    // Already blocked: keep the original reason and time. The first detection is the
    // one worth having; later ones are consequences of not being able to log in.
    $existing = $db->prepare("SELECT security_blocked_at FROM att_employees WHERE id = ? LIMIT 1");
    $existing->execute([$attEmployeeId]);
    if ($existing->fetchColumn() !== null) {
        return false;
    }

    $db->prepare("
        UPDATE att_employees
        SET security_blocked_at = NOW(), security_block_reason = ?
        WHERE id = ?
    ")->execute([mb_substr((string)$reason, 0, 255), $attEmployeeId]);

    // Every session on every device dies immediately, so a blocked account cannot keep
    // working from a token it already holds.
    $db->prepare("UPDATE att_tokens SET revoked_at = NOW() WHERE att_employee_id = ? AND revoked_at IS NULL")
       ->execute([$attEmployeeId]);

    attAudit('security_blocked', 'att_employee', (int)$attEmployeeId,
        array_merge(['reason' => $reason], $evidence), 'system', null);

    return true;
}

/**
 * Lift a security block. Requires a note — that is the point of the feature.
 *
 * @return array{ok:bool,message:string}
 */
function attSecurityUnblock($attEmployeeId, $note, $adminUserId) {
    $note = trim((string)$note);
    if ($note === '') {
        return ['ok' => false, 'message' => 'Write a note explaining why the block is being lifted.'];
    }

    attDB()->prepare("
        UPDATE att_employees
        SET security_blocked_at = NULL, security_block_reason = NULL,
            security_unblock_note = ?, security_unblocked_by = ?, security_unblocked_at = NOW()
        WHERE id = ?
    ")->execute([mb_substr($note, 0, 500), $adminUserId, $attEmployeeId]);

    attAudit('security_unblocked', 'att_employee', (int)$attEmployeeId,
        ['note' => $note], 'admin', $adminUserId);

    return ['ok' => true, 'message' => 'Block lifted. The employee can sign in again.'];
}

/**
 * Look for movement no human could have made, and block if found.
 *
 * This is the check that does not depend on trusting the handset. is_mock is reported
 * *by* the app, so a patched build simply stops reporting it; speed between two stored
 * points is derived from the data, and a spoofer cannot make 19 km in 55 seconds
 * plausible. Both checks run — this one is the backstop.
 *
 * Points flagged as inaccurate are skipped: a ±2 km network fix can look like a jump
 * without anybody having moved, and blocking on that would be a false accusation.
 *
 * @return array|null the breach details, or null when the movement is plausible
 */
function attDetectImpossibleTravel($attEmployeeId, $workDate) {
    // Points recorded before the last unblock are ignored.
    //
    // Without this the feature could not be recovered from. The offending pair of points
    // stays in att_location_logs for ever, this scans the whole work date on every batch,
    // and so the same jump re-blocked the account seconds after an admin cleared it: the
    // block revoked the tokens, the app reported "session timed out", and signing in
    // again said the account was blocked. An endless loop from one old pair of rows.
    //
    // An unblock is an administrator saying "I have looked at this and it is settled", so
    // the evidence behind it must stop counting. Anything that happens afterwards still
    // blocks, immediately.
    $cleared = attDB()->prepare(
        "SELECT security_unblocked_at FROM att_employees WHERE id = ? LIMIT 1"
    );
    $cleared->execute([$attEmployeeId]);
    $clearedAt = $cleared->fetchColumn();
    $clearedAt = ($clearedAt && $clearedAt !== '0000-00-00 00:00:00') ? $clearedAt : null;

    // Evidence that has already been reported is finished with. Without this the same
    // jump was found again by every upload for the rest of the day — the log entry was
    // rate-limited to one an hour, so it re-surfaced hourly, each time re-announcing a
    // teleport that had already been on the Security page since the first detection.
    $reported = attDB()->prepare("
        SELECT details FROM att_audit_logs
        WHERE action = 'impossible_travel' AND entity = 'att_employee' AND entity_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $reported->execute([$attEmployeeId]);
    $lastDetails = json_decode((string)$reported->fetchColumn(), true);
    $reportedUpTo = is_array($lastDetails) ? ($lastDetails['to'] ?? null) : null;

    $sql = "
        SELECT recorded_at, lat, lng, accuracy_m
        FROM att_location_logs
        WHERE att_employee_id = ? AND work_date = ?
          AND (accuracy_m IS NULL OR accuracy_m <= 100)
    ";
    $params = [$attEmployeeId, $workDate];

    if ($clearedAt !== null) {
        $sql .= " AND recorded_at > ?";
        $params[] = $clearedAt;
    }
    if ($reportedUpTo !== null) {
        $sql .= " AND recorded_at > ?";
        $params[] = $reportedUpTo;
    }

    // An employee who is permitted simulated locations is being tested with a fake-GPS
    // app, and a spoofing tool teleports by its nature. Judging those jumps as
    // "impossible movement" reports the operator's own testing to the operator. Real
    // spoofers do not have the permission, and a patched app that hides the mock flag
    // is still caught because its points arrive as genuine.
    $mockSetting = attDB()->prepare(
        "SELECT allow_mock_location FROM att_employee_settings WHERE att_employee_id = ? LIMIT 1"
    );
    $mockSetting->execute([$attEmployeeId]);
    if ((int)$mockSetting->fetchColumn() === 1) {
        $sql .= " AND is_mock = 0";
    }

    $sql .= " ORDER BY recorded_at ASC";

    $stmt = attDB()->prepare($sql);
    $stmt->execute($params);
    $points = $stmt->fetchAll(PDO::FETCH_ASSOC);

    for ($i = 1; $i < count($points); $i++) {
        $a = $points[$i - 1];
        $b = $points[$i];

        $seconds = strtotime($b['recorded_at']) - strtotime($a['recorded_at']);
        if ($seconds <= 0) {
            continue; // Same instant, or clock skew; speed is meaningless.
        }

        $metres = attHaversine((float)$a['lat'], (float)$a['lng'], (float)$b['lat'], (float)$b['lng']);
        if ($metres < ATT_MIN_JUMP_METRES) {
            continue;
        }

        $kmh = ($metres / $seconds) * 3.6;
        if ($kmh <= ATT_MAX_PLAUSIBLE_KMH) {
            continue;
        }

        return [
            'from' => $a['recorded_at'],
            'to' => $b['recorded_at'],
            'metres' => (int)round($metres),
            'seconds' => $seconds,
            'kmh' => (int)round($kmh),
        ];
    }
    return null;
}
