<?php
/**
 * Giving drivers attendance accounts, one project at a time.
 *
 * Mirrors monitor_enrol.php: drivers arrive staffed per project (67 of them,
 * most already linked to one), so the unit of work is a project, not a person.
 * Password encryption lives in monitor_enrol.php and is reused, not redefined.
 */

require_once __DIR__ . '/monitor_enrol.php';

/**
 * Drivers on a project, with whether they already have an attendance account.
 *
 * A driver counts as enrollable while active and not terminated/resigned —
 * the same line login.php draws when it refuses a sign-in.
 */
function attDriversForProject($projectId) {
    $stmt = attDB()->prepare("
        SELECT d.id, d.driver_code, d.first_name, d.last_name, d.phone,
               d.license_type, d.status,
               a.id AS att_employee_id, a.login_code, a.is_active, a.initial_password_cipher,
               a.last_login_at, a.must_change_password,
               s.sessions_per_day, s.shift2_start, s.shift2_end,
               s.shift_start, s.shift_end, s.geofence_id,
               g.name AS geofence_name
        FROM fleet_drivers d
        LEFT JOIN att_employees a ON a.driver_id = d.id
        LEFT JOIN att_employee_settings s ON s.att_employee_id = a.id
        LEFT JOIN att_geofences g ON g.id = s.geofence_id
        WHERE d.project_id = ? AND d.is_active = 1
          AND d.status NOT IN ('terminated', 'resigned')
        ORDER BY d.driver_code, d.id
    ");
    $stmt->execute([(int)$projectId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Projects that have enrollable drivers, with enrolment progress.
 *
 * Drivers without a project are deliberately excluded: with no school there is
 * no timetable and no work area to give them, so they would enrol broken.
 */
function attProjectsWithDrivers() {
    return attDB()->query("
        SELECT p.id, p.project_name,
               COUNT(d.id) AS drivers,
               SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS enrolled
        FROM projects p
        JOIN fleet_drivers d ON d.project_id = p.id AND d.is_active = 1
          AND d.status NOT IN ('terminated', 'resigned')
        LEFT JOIN att_employees a ON a.driver_id = d.id
        GROUP BY p.id, p.project_name
        ORDER BY p.project_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Enrol a set of drivers in one go.
 *
 * Each driver is its own transaction: one duplicate code skips with a named
 * reason instead of rolling back the batch. The login code is the driver's own
 * code; passwords are generated per person and the account starts with the
 * given timetable (the project's, prefilled on the form).
 *
 * @return array{created:array,skipped:array}
 */
function attEnrolDrivers(array $driverIds, $geofenceId, $adminUserId, $sessionsPerDay = 1, array $shifts = []) {
    $db = attDB();
    $defaults = attSettings();

    $shiftStart = trim($shifts['shift_start'] ?? '') ?: substr((string)($defaults['default_shift_start'] ?? '08:00:00'), 0, 5);
    $shiftEnd = trim($shifts['shift_end'] ?? '') ?: substr((string)($defaults['default_shift_end'] ?? '17:00:00'), 0, 5);
    $shift2Start = trim($shifts['shift2_start'] ?? '');
    $shift2End = trim($shifts['shift2_end'] ?? '');
    $sessions = ((int)$sessionsPerDay === 2) ? 2 : 1;
    $hasSecond = $sessions === 2 && $shift2Start !== '' && $shift2End !== '';

    $created = [];
    $skipped = [];

    foreach ($driverIds as $driverId) {
        $driverId = (int)$driverId;
        if ($driverId <= 0) {
            continue;
        }

        $driver = $db->prepare("
            SELECT d.id, d.driver_code, d.first_name, d.last_name,
                   a.id AS existing
            FROM fleet_drivers d
            LEFT JOIN att_employees a ON a.driver_id = d.id
            WHERE d.id = ? LIMIT 1
        ");
        $driver->execute([$driverId]);
        $row = $driver->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $skipped[] = ['name' => 'Driver #' . $driverId, 'why' => 'no longer exists'];
            continue;
        }

        $name = trim($row['first_name'] . ' ' . $row['last_name']);

        if ($row['existing']) {
            $skipped[] = ['name' => $name, 'why' => 'already enrolled'];
            continue;
        }

        $loginCode = trim((string)$row['driver_code']);
        if ($loginCode === '') {
            $skipped[] = ['name' => $name, 'why' => 'has no driver code'];
            continue;
        }

        $taken = $db->prepare("SELECT 1 FROM att_employees WHERE login_code = ? LIMIT 1");
        $taken->execute([$loginCode]);
        if ($taken->fetch()) {
            $skipped[] = ['name' => $name, 'why' => 'login code "' . $loginCode . '" is already in use'];
            continue;
        }

        // Readable but not guessable: no O/0/I/l, so it survives being written
        // on paper and read back over a phone.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < 8; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        try {
            $db->beginTransaction();

            $db->prepare("
                INSERT INTO att_employees
                    (person_type, driver_id, login_code, password, initial_password_cipher, must_change_password, created_by)
                VALUES ('driver', ?, ?, ?, ?, 1, ?)
            ")->execute([$driverId, $loginCode, password_hash($password, PASSWORD_DEFAULT), attCredentialEncrypt($password), $adminUserId]);

            $attEmployeeId = (int)$db->lastInsertId();

            $db->prepare("
                INSERT INTO att_employee_settings
                    (att_employee_id, geofence_id, shift_start, shift_end,
                     tracking_interval_min, max_accuracy_m, sessions_per_day,
                     shift2_start, shift2_end)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $attEmployeeId,
                $geofenceId ?: null,
                $shiftStart . ':00',
                $shiftEnd . ':00',
                (int)$defaults['default_interval_min'],
                (int)$defaults['default_max_accuracy_m'],
                $sessions,
                $hasSecond ? $shift2Start . ':00' : null,
                $hasSecond ? $shift2End . ':00' : null,
            ]);

            // Same as monitors: the primary area belongs in the join table too,
            // which is what the app judges "inside" against first.
            if ($geofenceId) {
                $db->prepare("
                    INSERT IGNORE INTO att_employee_geofences (att_employee_id, geofence_id)
                    VALUES (?, ?)
                ")->execute([$attEmployeeId, (int)$geofenceId]);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            $skipped[] = ['name' => $name, 'why' => 'database refused it: ' . $e->getMessage()];
            continue;
        }

        $created[] = [
            'name' => $name,
            'login_code' => $loginCode,
            'password' => $password,
        ];
    }

    if ($created) {
        attAudit('drivers_enrolled', 'att_employee', null, [
            'summary' => count($created) . ' driver account' . (count($created) === 1 ? '' : 's') . ' created',
            'codes' => array_column($created, 'login_code'),
        ], 'admin', $adminUserId);
    }

    return ['created' => $created, 'skipped' => $skipped];
}

/**
 * Issue a fresh password for a driver account. Old passwords are hashes, so a
 * reset is the only recovery — and it signs the app out with it.
 *
 * @return array{ok:bool,message:string,login_code?:string,password?:string}
 */
function attResetDriverPassword($attEmployeeId, $adminUserId) {
    $db = attDB();
    $stmt = $db->prepare("
        SELECT e.id, e.login_code FROM att_employees e
        WHERE e.id = ? AND e.person_type = 'driver' LIMIT 1
    ");
    $stmt->execute([(int)$attEmployeeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'That driver account no longer exists.'];
    }

    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 8; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $db->prepare("
        UPDATE att_employees
        SET password = ?, initial_password_cipher = ?, must_change_password = 1
        WHERE id = ?
    ")->execute([password_hash($password, PASSWORD_DEFAULT), attCredentialEncrypt($password), (int)$attEmployeeId]);

    $db->prepare("UPDATE att_tokens SET revoked_at = NOW() WHERE att_employee_id = ? AND revoked_at IS NULL")
       ->execute([(int)$attEmployeeId]);

    attAudit('driver_password_reset', 'att_employee', (int)$attEmployeeId,
        ['login_code' => $row['login_code']], 'admin', (int)$adminUserId);

    return ['ok' => true, 'message' => 'Password reset for ' . $row['login_code'] . '.',
            'login_code' => $row['login_code'], 'password' => $password];
}

/**
 * Update one enrolled driver's working rules: work area, sessions per day,
 * and the optional second-shift hours.
 *
 * @return array{ok:bool,message:string}
 */
function attUpdateDriverAccount($attEmployeeId, $geofenceId, $sessionsPerDay, $shift2Start, $shift2End, $adminUserId) {
    $db = attDB();
    $stmt = $db->prepare("SELECT id FROM att_employees WHERE id = ? AND person_type = 'driver' LIMIT 1");
    $stmt->execute([(int)$attEmployeeId]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'message' => 'That driver account no longer exists.'];
    }

    $sessions = ((int)$sessionsPerDay === 2) ? 2 : 1;
    $shift2Start = trim((string)$shift2Start);
    $shift2End = trim((string)$shift2End);
    $hasSecond = $sessions === 2 && $shift2Start !== '' && $shift2End !== '';
    $fence = (int)$geofenceId ?: null;

    $exists = $db->prepare("SELECT att_employee_id FROM att_employee_settings WHERE att_employee_id = ?");
    $exists->execute([(int)$attEmployeeId]);
    if ($exists->fetch()) {
        $db->prepare("
            UPDATE att_employee_settings
            SET geofence_id = ?, sessions_per_day = ?,
                shift2_start = ?, shift2_end = ?
            WHERE att_employee_id = ?
        ")->execute([$fence, $sessions,
            $hasSecond ? $shift2Start . ':00' : null,
            $hasSecond ? $shift2End . ':00' : null,
            (int)$attEmployeeId]);
    } else {
        $defaults = attSettings();
        $db->prepare("
            INSERT INTO att_employee_settings
                (att_employee_id, geofence_id, shift_start, shift_end,
                 tracking_interval_min, max_accuracy_m, sessions_per_day,
                 shift2_start, shift2_end)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            (int)$attEmployeeId, $fence,
            $defaults['default_shift_start'] ?? '08:00:00',
            $defaults['default_shift_end'] ?? '17:00:00',
            (int)($defaults['default_interval_min'] ?? 10),
            (int)($defaults['default_max_accuracy_m'] ?? 50),
            $sessions,
            $hasSecond ? $shift2Start . ':00' : null,
            $hasSecond ? $shift2End . ':00' : null,
        ]);
    }

    $db->prepare("DELETE FROM att_employee_geofences WHERE att_employee_id = ?")
       ->execute([(int)$attEmployeeId]);
    if ($fence) {
        $db->prepare("INSERT IGNORE INTO att_employee_geofences (att_employee_id, geofence_id) VALUES (?, ?)")
           ->execute([(int)$attEmployeeId, $fence]);
    }

    attAudit('driver_settings_saved', 'att_employee', (int)$attEmployeeId, [
        'geofence_id' => $fence, 'sessions_per_day' => $sessions,
        'shift2_start' => $hasSecond ? $shift2Start : null,
        'shift2_end' => $hasSecond ? $shift2End : null,
    ], 'admin', (int)$adminUserId);

    return ['ok' => true, 'message' => 'Work rules updated. The app picks it up on its next sync.'];
}
