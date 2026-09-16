<?php
/**
 * Per-project shift defaults for monitors.
 *
 * Every school keeps different hours. This is the one place those hours are
 * written down per project; enrolment copies them onto each new account, and
 * "Apply to enrolled accounts" copies them onto existing ones. Afterwards an
 * account is independent — a per-monitor exception set later is not touched by
 * later edits here.
 */

/** Raw defaults row for a project, or null when nobody set one yet. */
function attProjectShifts($projectId) {
    $stmt = attDB()->prepare("SELECT * FROM att_project_shifts WHERE project_id = ? LIMIT 1");
    $stmt->execute([(int)$projectId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Effective timetable for a project: its saved row, falling back to the
 * module defaults for anything unset. Always complete — the enrol form and
 * the popup both render from this, so neither has to handle a missing row.
 */
function attProjectShiftDefaults($projectId) {
    $global = attSettings();
    $row = attProjectShifts($projectId);

    $out = [
        'shift1_start' => substr((string)($row['shift1_start'] ?? $global['default_shift_start'] ?? '08:00:00'), 0, 5),
        'shift1_end' => substr((string)($row['shift1_end'] ?? $global['default_shift_end'] ?? '17:00:00'), 0, 5),
        'sessions_default' => (int)($row['sessions_default'] ?? 1),
        'shift2_start' => !empty($row['shift2_start']) ? substr((string)$row['shift2_start'], 0, 5) : '',
        'shift2_end' => !empty($row['shift2_end']) ? substr((string)$row['shift2_end'], 0, 5) : '',
        'default_geofence_id' => (int)($row['default_geofence_id'] ?? 0),
        'is_custom' => $row !== null,
    ];
    if ($out['sessions_default'] !== 2) {
        $out['sessions_default'] = 1;
    }
    return $out;
}

/**
 * Save a project's timetable.
 *
 * @return array{ok:bool,message:string}
 */
function attSaveProjectShifts($projectId, array $data, $adminUserId) {
    $projectId = (int)$projectId;
    $check = attDB()->prepare("SELECT id FROM projects WHERE id = ? LIMIT 1");
    $check->execute([$projectId]);
    if (!$check->fetch()) {
        return ['ok' => false, 'message' => 'That project no longer exists.'];
    }

    $shift1Start = trim($data['shift1_start'] ?? '') ?: '08:00';
    $shift1End = trim($data['shift1_end'] ?? '') ?: '17:00';
    $sessions = ((int)($data['sessions_default'] ?? 1) === 2) ? 2 : 1;
    $shift2Start = trim($data['shift2_start'] ?? '');
    $shift2End = trim($data['shift2_end'] ?? '');
    $hasSecond = $sessions === 2 && $shift2Start !== '' && $shift2End !== '';
    $fence = (int)($data['default_geofence_id'] ?? 0) ?: null;

    attDB()->prepare("
        INSERT INTO att_project_shifts
            (project_id, shift1_start, shift1_end, sessions_default,
             shift2_start, shift2_end, default_geofence_id, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            shift1_start = VALUES(shift1_start),
            shift1_end = VALUES(shift1_end),
            sessions_default = VALUES(sessions_default),
            shift2_start = VALUES(shift2_start),
            shift2_end = VALUES(shift2_end),
            default_geofence_id = VALUES(default_geofence_id),
            updated_by = VALUES(updated_by)
    ")->execute([
        $projectId, $shift1Start . ':00', $shift1End . ':00', $sessions,
        $hasSecond ? $shift2Start . ':00' : null,
        $hasSecond ? $shift2End . ':00' : null,
        $fence, (int)$adminUserId,
    ]);

    attAudit('project_shifts_saved', 'project', $projectId, [
        'shift1' => $shift1Start . '–' . $shift1End,
        'sessions_default' => $sessions,
        'shift2' => $hasSecond ? $shift2Start . '–' . $shift2End : null,
        'default_geofence_id' => $fence,
    ], 'admin', (int)$adminUserId);

    return ['ok' => true, 'message' => 'Timetable saved. New accounts on this project start with it.'];
}

/**
 * Copy a project's timetable onto every enrolled monitor account in it.
 *
 * Shift times and the session count are always applied. The work area is only
 * touched when the project has a default one — clearing somebody's area
 * because the project default is empty would destroy information.
 *
 * @return array{ok:bool,message:string,updated:int}
 */
function attApplyProjectShiftsToAccounts($projectId, $adminUserId) {
    $db = attDB();
    $projectId = (int)$projectId;
    $timetable = attProjectShifts($projectId);
    if (!$timetable) {
        return ['ok' => false, 'message' => 'Set the timetable for this project first.', 'updated' => 0];
    }

    $ids = $db->prepare("
        SELECT a.id FROM att_employees a
        LEFT JOIN monitors m ON m.id = a.monitor_id
          AND m.project_id = ? AND m.is_active = 1
        LEFT JOIN fleet_drivers d ON d.id = a.driver_id
          AND d.project_id = ? AND d.is_active = 1
          AND d.status NOT IN ('terminated', 'resigned')
        WHERE a.person_type IN ('monitor', 'driver')
          AND (m.id IS NOT NULL OR d.id IS NOT NULL)
    ");
    $ids->execute([$projectId, $projectId]);
    $accountIds = $ids->fetchAll(PDO::FETCH_COLUMN);

    if (!$accountIds) {
        return ['ok' => false, 'message' => 'No enrolled accounts on this project yet.', 'updated' => 0];
    }

    $hasSecond = (int)$timetable['sessions_default'] === 2
        && !empty($timetable['shift2_start']) && !empty($timetable['shift2_end']);
    $fence = (int)($timetable['default_geofence_id'] ?? 0) ?: null;

    $updated = 0;
    foreach ($accountIds as $attEmployeeId) {
        $exists = $db->prepare("SELECT att_employee_id FROM att_employee_settings WHERE att_employee_id = ?");
        $exists->execute([(int)$attEmployeeId]);
        if ($exists->fetch()) {
            $params = [
                substr((string)$timetable['shift1_start'], 0, 5) . ':00',
                substr((string)$timetable['shift1_end'], 0, 5) . ':00',
                (int)$timetable['sessions_default'],
                $hasSecond ? substr((string)$timetable['shift2_start'], 0, 5) . ':00' : null,
                $hasSecond ? substr((string)$timetable['shift2_end'], 0, 5) . ':00' : null,
            ];
            $sql = "
                UPDATE att_employee_settings
                SET shift_start = ?, shift_end = ?,
                    sessions_per_day = ?, shift2_start = ?, shift2_end = ?";
            if ($fence) {
                $sql .= ", geofence_id = ?";
                $params[] = $fence;
            }
            $sql .= " WHERE att_employee_id = ?";
            $params[] = (int)$attEmployeeId;
            $db->prepare($sql)->execute($params);
        } else {
            $global = attSettings();
            $db->prepare("
                INSERT INTO att_employee_settings
                    (att_employee_id, geofence_id, shift_start, shift_end,
                     tracking_interval_min, max_accuracy_m,
                     sessions_per_day, shift2_start, shift2_end)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                (int)$attEmployeeId, $fence,
                substr((string)$timetable['shift1_start'], 0, 5) . ':00',
                substr((string)$timetable['shift1_end'], 0, 5) . ':00',
                (int)($global['default_interval_min'] ?? 10),
                (int)($global['default_max_accuracy_m'] ?? 50),
                (int)$timetable['sessions_default'],
                $hasSecond ? substr((string)$timetable['shift2_start'], 0, 5) . ':00' : null,
                $hasSecond ? substr((string)$timetable['shift2_end'], 0, 5) . ':00' : null,
            ]);
        }

        if ($fence) {
            $db->prepare("INSERT IGNORE INTO att_employee_geofences (att_employee_id, geofence_id) VALUES (?, ?)")
               ->execute([(int)$attEmployeeId, $fence]);
        }
        $updated++;
    }

    attAudit('project_shifts_applied', 'project', $projectId, [
        'accounts' => $updated,
        'shift1' => substr((string)$timetable['shift1_start'], 0, 5) . '–' . substr((string)$timetable['shift1_end'], 0, 5),
        'sessions' => (int)$timetable['sessions_default'],
        'geofence_id' => $fence,
    ], 'admin', (int)$adminUserId);

    return ['ok' => true,
            'message' => 'Timetable applied to ' . $updated . ' account' . ($updated === 1 ? '' : 's') . '. The apps pick it up on their next sync.',
            'updated' => $updated];
}
