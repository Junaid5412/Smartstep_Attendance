<?php
/**
 * Giving monitors attendance accounts, one project at a time.
 *
 * Monitors arrive in groups — a project is staffed and fifty people need accounts the
 * same afternoon — so the useful unit of work is a project, not a person. Everything here
 * is written for that: pick a project, see who is not enrolled, enrol them together.
 */
function attCredentialEncrypt($value) {
    $key = hash('sha256', ATT_TOKEN_SECRET, true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function attCredentialDecrypt($value) {
    if (!$value) return null;
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) <= 16) return null;
    $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', hash('sha256', ATT_TOKEN_SECRET, true), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? null : $plain;
}
/**
 * Monitors on a project, with whether they already have an attendance account.
 *
 * Includes the enrolled ones deliberately. A list of only the un-enrolled cannot answer
 * "did I already do this project?", which is the question someone repeating the job
 * actually has.
 */
function attMonitorsForProject($projectId) {
    $stmt = attDB()->prepare("
        SELECT m.id, m.monitor_code, m.first_name, m.last_name, m.phone,
               m.designation, m.employment_type, m.status,
               a.id AS att_employee_id, a.login_code, a.is_active, a.initial_password_cipher,
               a.last_login_at, a.must_change_password,
               s.sessions_per_day, s.shift2_start, s.shift2_end,
               s.shift_start, s.shift_end, s.geofence_id,
               g.name AS geofence_name
        FROM monitors m
        LEFT JOIN att_employees a ON a.monitor_id = m.id
        LEFT JOIN att_employee_settings s ON s.att_employee_id = a.id
        LEFT JOIN att_geofences g ON g.id = s.geofence_id
        WHERE m.project_id = ? AND m.is_active = 1
        ORDER BY m.monitor_code, m.id
    ");
    $stmt->execute([(int)$projectId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Projects that have monitors, with enrolment progress. */
function attProjectsWithMonitors() {
    return attDB()->query("
        SELECT p.id, p.project_name,
               COUNT(m.id) AS monitors,
               SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS enrolled,
               SUM(CASE WHEN m.employment_type = 'part_time' THEN 1 ELSE 0 END) AS part_time
        FROM projects p
        JOIN monitors m ON m.project_id = p.id AND m.is_active = 1
        LEFT JOIN att_employees a ON a.monitor_id = m.id
        GROUP BY p.id, p.project_name
        ORDER BY p.project_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Enrol a set of monitors in one go.
 *
 * Each monitor is its own transaction rather than one transaction for the batch. Fifty
 * accounts where the forty-ninth has a duplicate code should leave forty-eight working
 * accounts and one clear complaint — not roll back the afternoon's work.
 *
 * The login code is the monitor's own code, which is what they already answer to. Where
 * that collides with an existing account the monitor is skipped and named, because
 * inventing "Bus_03_2" would hand somebody a number nobody told them.
 *
 * Passwords are generated, not chosen. A shared password typed fifty times is one leak
 * away from fifty compromised accounts, and must_change_password means the employee
 * replaces it at first sign-in anyway.
 *
 * @return array{created:array,skipped:array}
 */
function attEnrolMonitors(array $monitorIds, $geofenceId, $adminUserId, $sessionsPerDay = 1, array $shifts = []) {
    $db = attDB();
    $defaults = attSettings();

    // The project's own timetable when the caller passes one (monitors.php
    // does, from att_project_shifts); otherwise the module-wide defaults.
    // Shift times live on the account from birth so the first check-in is
    // already measured against the school's real hours.
    $shiftStart = trim($shifts['shift_start'] ?? '') ?: substr((string)($defaults['default_shift_start'] ?? '08:00:00'), 0, 5);
    $shiftEnd = trim($shifts['shift_end'] ?? '') ?: substr((string)($defaults['default_shift_end'] ?? '17:00:00'), 0, 5);
    $shift2Start = trim($shifts['shift2_start'] ?? '');
    $shift2End = trim($shifts['shift2_end'] ?? '');
    $sessions = ((int)$sessionsPerDay === 2) ? 2 : 1;
    $hasSecond = $sessions === 2 && $shift2Start !== '' && $shift2End !== '';

    $created = [];
    $skipped = [];

    foreach ($monitorIds as $monitorId) {
        $monitorId = (int)$monitorId;
        if ($monitorId <= 0) {
            continue;
        }

        $monitor = $db->prepare("
            SELECT m.id, m.monitor_code, m.first_name, m.last_name, m.employment_type,
                   a.id AS existing
            FROM monitors m
            LEFT JOIN att_employees a ON a.monitor_id = m.id
            WHERE m.id = ? LIMIT 1
        ");
        $monitor->execute([$monitorId]);
        $row = $monitor->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $skipped[] = ['name' => 'Monitor #' . $monitorId, 'why' => 'no longer exists'];
            continue;
        }

        $name = trim($row['first_name'] . ' ' . $row['last_name']);

        if ($row['existing']) {
            $skipped[] = ['name' => $name, 'why' => 'already enrolled'];
            continue;
        }

        $loginCode = trim((string)$row['monitor_code']);
        if ($loginCode === '') {
            $skipped[] = ['name' => $name, 'why' => 'has no monitor code'];
            continue;
        }

        $taken = $db->prepare("SELECT 1 FROM att_employees WHERE login_code = ? LIMIT 1");
        $taken->execute([$loginCode]);
        if ($taken->fetch()) {
            $skipped[] = ['name' => $name, 'why' => 'login code "' . $loginCode . '" is already in use'];
            continue;
        }

        // Readable but not guessable: no O/0/I/l, so it survives being written on paper
        // and read back over a phone.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < 8; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        try {
            $db->beginTransaction();

            $db->prepare("
                INSERT INTO att_employees
                    (person_type, monitor_id, login_code, password, initial_password_cipher, must_change_password, created_by)
                VALUES ('monitor', ?, ?, ?, ?, 1, ?)
            ")->execute([$monitorId, $loginCode, password_hash($password, PASSWORD_DEFAULT), attCredentialEncrypt($password), $adminUserId]);

            $attEmployeeId = (int)$db->lastInsertId();

            // A part-time monitor works a split shift, so their day is two sessions.
            // Recorded on the account rather than read from the monitor row at check-in:
            // changing someone's employment type should not silently rewrite how days
            // already in progress are counted.
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

            // Keep the multi-area join table in sync with the primary area, the
            // same way employees.php does. The app judges "inside" against the
            // join table first and only falls back to settings.geofence_id when
            // it is empty — without this row a freshly enrolled monitor looked
            // unfenced to one code path and fenced to another.
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
            'part_time' => $row['employment_type'] === 'part_time',
        ];
    }

    if ($created) {
        attAudit('monitors_enrolled', 'att_employee', null, [
            'summary' => count($created) . ' monitor account' . (count($created) === 1 ? '' : 's') . ' created',
            'codes' => array_column($created, 'login_code'),
        ], 'admin', $adminUserId);
    }

    return ['created' => $created, 'skipped' => $skipped];
}

/**
 * Generate a readable one-time password for a monitor account.
 *
 * Stored as a hash (for sign-in) plus an encrypted copy (so the admin panel
 * can show it again). Accounts created before the cipher column existed have
 * NULL there — that is why old passwords "disappeared", and resetting is the
 * supported recovery, not a hash reversal.
 *
 * @return array{ok:bool,message:string,login_code?:string,password?:string}
 */
function attResetMonitorPassword($attEmployeeId, $adminUserId) {
    $db = attDB();
    $stmt = $db->prepare("
        SELECT e.id, e.login_code FROM att_employees e
        WHERE e.id = ? AND e.person_type = 'monitor' LIMIT 1
    ");
    $stmt->execute([(int)$attEmployeeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'That monitor account no longer exists.'];
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

    // A reset must sign the app out: the handset holds a token, not the
    // password, and leaving it live would make the "new password" meaningless.
    $db->prepare("UPDATE att_tokens SET revoked_at = NOW() WHERE att_employee_id = ? AND revoked_at IS NULL")
       ->execute([(int)$attEmployeeId]);

    attAudit('monitor_password_reset', 'att_employee', (int)$attEmployeeId,
        ['login_code' => $row['login_code']], 'admin', (int)$adminUserId);

    return ['ok' => true, 'message' => 'Password reset for ' . $row['login_code'] . '.',
            'login_code' => $row['login_code'], 'password' => $password];
}

/**
 * Update one enrolled monitor's working rules: work area, sessions per day,
 * and the optional second-shift hours.
 *
 * @return array{ok:bool,message:string}
 */
function attUpdateMonitorAccount($attEmployeeId, $geofenceId, $sessionsPerDay, $shift2Start, $shift2End, $adminUserId) {
    $db = attDB();
    $stmt = $db->prepare("SELECT id FROM att_employees WHERE id = ? AND person_type = 'monitor' LIMIT 1");
    $stmt->execute([(int)$attEmployeeId]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'message' => 'That monitor account no longer exists.'];
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

    // Same wholesale replacement employees.php uses: unticked means removed,
    // and the primary area is always one of the assigned areas.
    $db->prepare("DELETE FROM att_employee_geofences WHERE att_employee_id = ?")
       ->execute([(int)$attEmployeeId]);
    if ($fence) {
        $db->prepare("INSERT IGNORE INTO att_employee_geofences (att_employee_id, geofence_id) VALUES (?, ?)")
           ->execute([(int)$attEmployeeId, $fence]);
    }

    attAudit('monitor_settings_saved', 'att_employee', (int)$attEmployeeId, [
        'geofence_id' => $fence, 'sessions_per_day' => $sessions,
        'shift2_start' => $hasSecond ? $shift2Start : null,
        'shift2_end' => $hasSecond ? $shift2End : null,
    ], 'admin', (int)$adminUserId);

    return ['ok' => true, 'message' => 'Work rules updated. The app picks it up on its next sync.'];
}
