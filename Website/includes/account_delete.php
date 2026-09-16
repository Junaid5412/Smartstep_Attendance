<?php
/**
 * Deleting an attendance account and everything recorded under it.
 *
 * This removes the account from the attendance system ONLY. The person's record in the
 * main SSTQA software — hr_employees for staff, monitors for monitors — is not touched, so
 * they remain an employee and can be enrolled again afterwards with a clean slate.
 *
 * It is irreversible. Nine tables cascade from att_employees (attendance rows, routes,
 * trips, devices, tokens, settings, area assignments, live follows, commands), and three
 * things do not and are handled here by hand: device resets, the audit entries keyed to
 * the account, and the check-in photos on disk.
 */

/**
 * Count what a deletion would destroy, so the confirmation can say it out loud.
 *
 * Shown before rather than after, because "12,481 location points" is the number that
 * makes somebody check they picked the right person.
 */
function attAccountFootprint($attEmployeeId) {
    $db = attDB();
    $id = (int)$attEmployeeId;

    $counts = [];
    $tables = [
        'attendance days' => 'att_attendance',
        'location points' => 'att_location_logs',
        'outside trips' => 'att_geofence_events',
        'devices' => 'att_devices',
        'device resets' => 'att_device_resets',
    ];

    foreach ($tables as $label => $table) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM $table WHERE att_employee_id = ?");
        $stmt->execute([$id]);
        $counts[$label] = (int)$stmt->fetchColumn();
    }

    // Trip-level audit entries are keyed by event id, not by the account, so they have to
    // be found through the trips themselves.
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM att_audit_logs
        WHERE (entity = 'att_employee' AND entity_id = ?)
           OR (entity = 'att_geofence_event' AND entity_id IN
               (SELECT id FROM att_geofence_events WHERE att_employee_id = ?))
           OR (entity = 'att_attendance' AND entity_id IN
               (SELECT id FROM att_attendance WHERE att_employee_id = ?))
    ");
    $stmt->execute([$id, $id, $id]);
    $counts['log entries'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM att_attendance
        WHERE att_employee_id = ? AND (check_in_photo IS NOT NULL OR check_out_photo IS NOT NULL)
    ");
    $stmt->execute([$id]);
    $counts['photos'] = (int)$stmt->fetchColumn();

    return $counts;
}

/**
 * Delete the account, its records, its logs and its photos.
 *
 * @param string $reason  recorded on the one audit entry that survives
 * @return array{ok:bool,message:string,removed:array}
 */
function attDeleteAccount($attEmployeeId, $reason, $adminUserId) {
    $db = attDB();
    $id = (int)$attEmployeeId;

    $account = $db->prepare("
        SELECT e.id, e.person_type, e.login_code, emp.employee_code,
               CONCAT(emp.first_name, ' ', emp.last_name) AS name
        FROM att_employees e
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        WHERE e.id = ? LIMIT 1
    ");
    $account->execute([$id]);
    $row = $account->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'message' => 'That account no longer exists.', 'removed' => []];
    }

    $removed = attAccountFootprint($id);

    // Photo paths are collected before the rows go, because after the cascade there is
    // nothing left to say which files belonged to this person. Deleting the files first
    // would be worse: a failure part-way would leave rows pointing at missing images.
    $photos = [];
    $stmt = $db->prepare("
        SELECT check_in_photo, check_out_photo FROM att_attendance WHERE att_employee_id = ?
    ");
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $day) {
        foreach ([$day['check_in_photo'], $day['check_out_photo']] as $path) {
            if ($path) {
                $photos[] = $path;
            }
        }
    }

    try {
        $db->beginTransaction();

        // Audit entries first, while the trips and days they point at still exist to be
        // found. The order matters: after the cascade those subqueries return nothing.
        $db->prepare("
            DELETE FROM att_audit_logs
            WHERE (entity = 'att_employee' AND entity_id = ?)
               OR (entity = 'att_geofence_event' AND entity_id IN
                   (SELECT id FROM att_geofence_events WHERE att_employee_id = ?))
               OR (entity = 'att_attendance' AND entity_id IN
                   (SELECT id FROM att_attendance WHERE att_employee_id = ?))
        ")->execute([$id, $id, $id]);

        // No cascade on this one, and it must go before the account row or the delete
        // leaves rows pointing at an account that is gone.
        $db->prepare("DELETE FROM att_device_resets WHERE att_employee_id = ?")->execute([$id]);

        // Everything else follows the account out through ON DELETE CASCADE.
        $db->prepare("DELETE FROM att_employees WHERE id = ?")->execute([$id]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok' => false, 'message' => 'Nothing was deleted: ' . $e->getMessage(), 'removed' => []];
    }

    // Files last, outside the transaction, because a filesystem cannot be rolled back.
    // If this half fails the rows are already gone, which is the right way round: an
    // orphaned image is a tidiness problem, a row pointing at a deleted file is a bug.
    $filesDeleted = 0;
    $base = realpath(ATT_UPLOAD_PATH);
    foreach ($photos as $relative) {
        $target = realpath(ATT_UPLOAD_PATH . '/' . ltrim($relative, '/'));
        // Confined to the uploads directory, so a crafted path in the database cannot
        // reach anything else on the disk.
        if ($target && $base && strpos($target, $base) === 0 && is_file($target)) {
            if (@unlink($target)) {
                $filesDeleted++;
            }
        }
    }
    $removed['photo files deleted'] = $filesDeleted;

    // One entry survives, deliberately. The request was to clear this account's logs, and
    // that is done — but a system where an account can vanish leaving no trace at all is
    // one where nobody can answer "who removed this person, and when". This single row
    // protects the administrator who did it as much as anyone.
    attAudit('account_deleted', 'att_employee', null, [
        'summary' => 'Attendance account deleted for ' . $row['name'] . ' (' . $row['employee_code'] . ')',
        'person_type' => $row['person_type'],
        'login_code' => $row['login_code'],
        'reason' => $reason,
        'removed' => $removed,
        'note' => 'The person remains in the main SSTQA system and can be enrolled again.',
    ], 'admin', $adminUserId);

    return [
        'ok' => true,
        'removed' => $removed,
        'message' => $row['name'] . ' removed from the attendance system. '
            . 'They are still in SSTQA and can be added again.',
    ];
}

/**
 * How much route data one employee has on one day.
 *
 * Counted before offering to remove it, so the confirmation states a number rather than
 * asking somebody to trust a button.
 */
function attRouteFootprint($attEmployeeId, $workDate) {
    $db = attDB();

    $points = $db->prepare("
        SELECT COUNT(*) FROM att_location_logs WHERE att_employee_id = ? AND work_date = ?
    ");
    $points->execute([(int)$attEmployeeId, $workDate]);

    $trips = $db->prepare("
        SELECT COUNT(*) FROM att_geofence_events WHERE att_employee_id = ? AND work_date = ?
    ");
    $trips->execute([(int)$attEmployeeId, $workDate]);

    return [
        'points' => (int)$points->fetchColumn(),
        'trips' => (int)$trips->fetchColumn(),
    ];
}

/**
 * Delete one day's recorded route for one employee, so a test can be repeated.
 *
 * Deliberately narrow. It removes the GPS trail and, optionally, the outside-area trips
 * derived from it — the things that get in the way of testing route recording again. It does
 * NOT touch att_attendance: check-in and check-out times, worked hours and photos are the
 * attendance record itself, and losing an afternoon's measured hours to clear a map is not a
 * trade anybody would knowingly make. Someone who genuinely wants to reset a day's
 * attendance can edit the register, where the consequences are visible.
 *
 * @param bool $includeTrips also remove the day's outside-area trips and their reasons
 * @return array{ok:bool,message:string,removed:array}
 */
function attClearRoute($attEmployeeId, $workDate, $includeTrips, $adminUserId) {
    $db = attDB();
    $id = (int)$attEmployeeId;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$workDate)) {
        return ['ok' => false, 'message' => 'That is not a valid date.', 'removed' => []];
    }

    $who = $db->prepare("
        SELECT emp.employee_code, CONCAT(emp.first_name, ' ', emp.last_name) AS name
        FROM att_employees e
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        WHERE e.id = ? LIMIT 1
    ");
    $who->execute([$id]);
    $person = $who->fetch(PDO::FETCH_ASSOC);

    if (!$person) {
        return ['ok' => false, 'message' => 'No such employee.', 'removed' => []];
    }

    $before = attRouteFootprint($id, $workDate);
    $removed = ['points' => 0, 'trips' => 0];

    try {
        $db->beginTransaction();

        // Trip-level audit entries first, while the trips they point at still exist to be
        // found — after the delete those ids resolve to nothing and the entries are orphaned.
        if ($includeTrips) {
            $db->prepare("
                DELETE FROM att_audit_logs
                WHERE entity = 'att_geofence_event' AND entity_id IN (
                    SELECT id FROM att_geofence_events WHERE att_employee_id = ? AND work_date = ?
                )
            ")->execute([$id, $workDate]);

            $trips = $db->prepare("
                DELETE FROM att_geofence_events WHERE att_employee_id = ? AND work_date = ?
            ");
            $trips->execute([$id, $workDate]);
            $removed['trips'] = $trips->rowCount();
        }

        $points = $db->prepare("
            DELETE FROM att_location_logs WHERE att_employee_id = ? AND work_date = ?
        ");
        $points->execute([$id, $workDate]);
        $removed['points'] = $points->rowCount();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok' => false, 'message' => 'Nothing was deleted: ' . $e->getMessage(), 'removed' => []];
    }

    attAudit('route_cleared', 'att_employee', $id, [
        'summary' => 'Route data cleared for ' . $person['name'] . ' (' . $person['employee_code']
            . ') on ' . $workDate,
        'work_date' => $workDate,
        'removed' => $removed,
        'note' => 'Attendance times, hours and photos were not touched.',
    ], 'admin', $adminUserId);

    $parts = [number_format($removed['points']) . ' location point'
        . ($removed['points'] === 1 ? '' : 's')];
    if ($includeTrips) {
        $parts[] = number_format($removed['trips']) . ' outside trip'
            . ($removed['trips'] === 1 ? '' : 's');
    }

    return [
        'ok' => true,
        'removed' => $removed,
        'message' => 'Cleared ' . implode(' and ', $parts) . ' for '
            . date('j M', strtotime($workDate)) . '. Check-in and check-out were kept.',
    ];
}

/**
 * Delete specific recorded positions, chosen on the map.
 *
 * Takes explicit ids rather than the drawn shape. The admin has just seen exactly which
 * points were highlighted; deleting by id means what disappears is precisely what they were
 * shown, with no chance of the server re-deriving the geometry slightly differently and
 * removing something else. The shape stays a drawing tool and never becomes a query.
 *
 * Two things are enforced here rather than trusted from the browser:
 *
 *  - every id must belong to this employee on this date, so a tampered request cannot reach
 *    another person's route;
 *  - the most recent position is never deleted. It is what the live map and the "last seen"
 *    column read, and erasing it makes an employee look as though they have stopped
 *    reporting. Keeping one point is also what makes this safe to use freely.
 *
 * @return array{ok:bool,message:string,deleted:int,kept_latest:bool}
 */
function attDeleteRoutePoints($attEmployeeId, $workDate, array $ids, $adminUserId) {
    $db = attDB();
    $id = (int)$attEmployeeId;

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return ['ok' => false, 'message' => 'No points were selected.', 'deleted' => 0,
                'kept_latest' => false];
    }
    // A drawn area could in principle cover a whole day; the cap is only here so a single
    // request cannot be made arbitrarily large.
    if (count($ids) > 20000) {
        return ['ok' => false, 'message' => 'Too many points in one go — erase a smaller area.',
                'deleted' => 0, 'kept_latest' => false];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$workDate)) {
        return ['ok' => false, 'message' => 'That is not a valid date.', 'deleted' => 0,
                'kept_latest' => false];
    }

    // The newest position on this date, protected whatever the request says.
    $newest = $db->prepare("
        SELECT id FROM att_location_logs
        WHERE att_employee_id = ? AND work_date = ?
        ORDER BY recorded_at DESC, id DESC LIMIT 1
    ");
    $newest->execute([$id, $workDate]);
    $newestId = (int)$newest->fetchColumn();

    $keptLatest = in_array($newestId, $ids, true);
    if ($keptLatest) {
        $ids = array_values(array_diff($ids, [$newestId]));
    }
    if (!$ids) {
        return ['ok' => false, 'deleted' => 0, 'kept_latest' => true,
                'message' => 'That was the only position left, so it was kept — a route needs '
                    . 'one point or the employee looks as though they stopped reporting.'];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Scoped by employee and date in the DELETE itself, so an id from elsewhere simply
    // matches nothing rather than being taken on trust.
    $stmt = $db->prepare("
        DELETE FROM att_location_logs
        WHERE att_employee_id = ? AND work_date = ? AND id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$id, $workDate], $ids));
    $deleted = $stmt->rowCount();

    if ($deleted > 0) {
        attAudit('route_points_erased', 'att_employee', $id, [
            'summary' => $deleted . ' recorded position' . ($deleted === 1 ? '' : 's')
                . ' erased from the map for ' . $workDate,
            'work_date' => $workDate,
            'deleted' => $deleted,
            'requested' => count($ids) + ($keptLatest ? 1 : 0),
            'kept_latest' => $keptLatest,
        ], 'admin', $adminUserId);
    }

    return [
        'ok' => true,
        'deleted' => $deleted,
        'kept_latest' => $keptLatest,
        'message' => 'Erased ' . number_format($deleted) . ' position'
            . ($deleted === 1 ? '' : 's')
            . ($keptLatest ? '. The most recent one was kept.' : '.'),
    ];
}
