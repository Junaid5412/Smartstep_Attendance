<?php
/**
 * Read queries shared by the admin pages and the panel's AJAX endpoints.
 */

/** Formats minutes as "7h 25m" for the tables. */
function attMinutes($minutes) {
    if ($minutes === null || $minutes === '') {
        return '—';
    }
    $minutes = (int)$minutes;
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;
    return $hours > 0 ? $hours . 'h ' . $rest . 'm' : $rest . 'm';
}

function attTime($datetime) {
    return $datetime ? date('H:i', strtotime($datetime)) : '—';
}

/**
 * A time, marked when it falls on a later date than the shift it belongs to.
 *
 * An overnight shift or a long overtime run checks out on the following calendar
 * day. Printing a bare "07:00" against a shift dated the 5th reads as though someone
 * clocked off thirteen hours before they clocked in.
 */
function attShiftTime($datetime, $workDate) {
    if (!$datetime) {
        return '—';
    }
    $days = (int)round((strtotime(date('Y-m-d', strtotime($datetime))) - strtotime($workDate)) / 86400);
    return date('H:i', strtotime($datetime)) . ($days > 0 ? ' <small class="muted">+' . $days . 'd</small>' : '');
}

/**
 * The status to show for a register row.
 *
 * A day that is not in the employee's working days is a scheduled rest day, not an
 * absence — reporting it as absent would penalise people for their own roster.
 */
function attRowStatus(array $row) {
    if (!empty($row['check_in_at'])) {
        return $row['status'];
    }
    // An authorised absence was recorded deliberately by an admin, so it outranks any
    // guess this function would otherwise make from the roster.
    if (in_array($row['status'] ?? null, ['leave', 'holiday'], true)) {
        return $row['status'];
    }
    $settings = [
        'work_days' => $row['work_days'] ?? '1,2,3,4,5,6',
        'shift_start' => $row['shift_start'] ?? '08:00:00',
        'shift_end' => $row['shift_end'] ?? '17:00:00',
        'overnight_shift' => $row['overnight_shift'] ?? 0,
    ];
    return attIsWorkDay($settings, $row['work_date_context']) ? 'absent' : 'off';
}

/** Badge class for an attendance status. */
function attStatusClass($status) {
    $map = [
        'present' => 'ok', 'late' => 'warn', 'half-day' => 'warn',
        'absent' => 'bad', 'incomplete' => 'info', 'holiday' => 'muted', 'leave' => 'muted',
        // Needs a person to fix it, so it reads as a problem rather than as neutral
        // information — nobody checked out and the hours cannot be calculated.
        'missing-checkout' => 'bad',
        'off' => 'muted',
    ];
    return $map[$status] ?? 'muted';
}

/** Human label for an attendance status. */
function attStatusLabel($status) {
    $map = [
        'half-day' => 'Half day',
        'incomplete' => 'In progress',
        'missing-checkout' => 'No check-out',
        'off' => 'Rest day',
    ];
    return $map[$status] ?? ucfirst((string)$status);
}

/** Counts for the dashboard tiles on a given date. */
function attDashboardStats($workDate) {
    $db = attDB();

    $enrolled = (int)$db->query("SELECT COUNT(*) FROM att_employees WHERE is_active = 1")->fetchColumn();

    $stmt = $db->prepare("
        SELECT
            SUM(check_in_at IS NOT NULL) AS checked_in,
            SUM(check_out_at IS NOT NULL) AS checked_out,
            SUM(status = 'late') AS late,
            SUM(status = 'half-day') AS half_day,
            SUM(outside_minutes) AS outside_minutes
        FROM att_attendance
        WHERE work_date = ?
    ");
    $stmt->execute([$workDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $openTrips = $db->prepare("
        SELECT COUNT(*) FROM att_geofence_events WHERE entry_at IS NULL AND work_date = ?
    ");
    $openTrips->execute([$workDate]);

    $pendingReview = (int)$db->query("
        SELECT COUNT(*) FROM att_geofence_events WHERE review_status = 'pending' AND entry_at IS NOT NULL
    ")->fetchColumn();

    $checkedIn = (int)($row['checked_in'] ?? 0);

    return [
        'enrolled' => $enrolled,
        'checked_in' => $checkedIn,
        'checked_out' => (int)($row['checked_out'] ?? 0),
        'not_checked_in' => max(0, $enrolled - $checkedIn),
        'late' => (int)($row['late'] ?? 0),
        'half_day' => (int)($row['half_day'] ?? 0),
        'outside_minutes' => (int)($row['outside_minutes'] ?? 0),
        'currently_outside' => (int)$openTrips->fetchColumn(),
        'pending_review' => $pendingReview,
    ];
}

/**
 * The register for one date, including employees with no row yet so the admin can
 * see who has not checked in rather than only who has.
 *
 * For employees with split shifts (e.g. monitors with 2 runs per day), their sessions
 * are consolidated into a single record with both sessions' details, so they appear
 * once in the register instead of twice.
 */
function attRegister($workDate, $filters = []) {
    $sql = "
        SELECT e.id AS att_employee_id, e.person_type, emp.employee_code,
               CONCAT(emp.first_name, ' ', emp.last_name) AS name,
               emp.position, d.department_name, p.project_name,
               g.name AS geofence_name,
               s.shift_start, s.shift_end, s.shift2_start, s.shift2_end, s.work_days, s.overnight_shift,
               s.home_lat, s.home_lng, s.home_radius_m, s.home_label,
               a.id AS attendance_id, a.check_in_at, a.check_out_at,
               a.check_in_photo, a.check_out_photo,
               a.check_in_lat, a.check_in_lng, a.check_out_lat, a.check_out_lng,
               a.check_in_inside_fence, a.check_out_inside_fence,
               a.worked_minutes, a.outside_minutes, a.late_minutes, a.overtime_minutes, a.status,
               a.session_no, COALESCE(s.sessions_per_day, 1) AS sessions_per_day,
               (SELECT COUNT(*) FROM att_geofence_events ev
                 WHERE ev.att_employee_id = e.id AND ev.work_date = ?
               ) AS trips_outside,
               (SELECT COUNT(*) FROM att_location_logs l
                 WHERE l.att_employee_id = e.id AND l.work_date = ?
               ) AS route_points
        FROM att_employees e
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        LEFT JOIN hr_departments d ON d.id = emp.department_id
        LEFT JOIN projects p ON p.id = emp.project_id
        LEFT JOIN att_employee_settings s ON s.att_employee_id = e.id
        LEFT JOIN att_attendance a ON a.att_employee_id = e.id AND a.work_date = ?
        LEFT JOIN att_geofences g ON g.id = COALESCE(a.geofence_id, s.geofence_id)
        WHERE e.is_active = 1
    ";
    $params = [$workDate, $workDate, $workDate];

    // Sliced by role tab
    if (!empty($filters['person_type']) && in_array($filters['person_type'], ['employee', 'monitor', 'driver'], true)) {
        $sql .= " AND e.person_type = ?";
        $params[] = $filters['person_type'];
    }
    if (!empty($filters['project_id'])) {
        $sql .= " AND emp.project_id = ?";
        $params[] = (int)$filters['project_id'];
    }
    if (!empty($filters['geofence_id'])) {
        $sql .= " AND (s.geofence_id = ? OR e.id IN (SELECT att_employee_id FROM att_employee_geofences WHERE geofence_id = ?))";
        $params[] = (int)$filters['geofence_id'];
        $params[] = (int)$filters['geofence_id'];
    }
    if (!empty($filters['search'])) {
        $sql .= " AND (emp.first_name LIKE ? OR emp.last_name LIKE ? OR emp.employee_code LIKE ?)";
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like);
    }

    $sql .= " ORDER BY emp.first_name, emp.last_name, a.session_no";

    $stmt = attDB()->prepare($sql);
    $stmt->execute($params);
    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group rows by att_employee_id to consolidate split shifts into 1 row per person
    $grouped = [];
    foreach ($rawRows as $raw) {
        $empId = (int)$raw['att_employee_id'];
        if (!isset($grouped[$empId])) {
            $sessionsPerDay = (int)($raw['sessions_per_day'] ?? 1);
            $grouped[$empId] = [
                'att_employee_id' => $empId,
                'person_type' => $raw['person_type'],
                'employee_code' => $raw['employee_code'],
                'name' => $raw['name'],
                'position' => $raw['position'],
                'department_name' => $raw['department_name'],
                'project_name' => $raw['project_name'],
                'geofence_name' => $raw['geofence_name'],
                'shift_start' => $raw['shift_start'],
                'shift_end' => $raw['shift_end'],
                'shift2_start' => $raw['shift2_start'] ?? null,
                'shift2_end' => $raw['shift2_end'] ?? null,
                'work_days' => $raw['work_days'],
                'overnight_shift' => $raw['overnight_shift'],
                'home_lat' => $raw['home_lat'],
                'home_lng' => $raw['home_lng'],
                'home_radius_m' => $raw['home_radius_m'],
                'home_label' => $raw['home_label'],
                'sessions_per_day' => $sessionsPerDay,
                'trips_outside' => (int)($raw['trips_outside'] ?? 0),
                'route_points' => (int)($raw['route_points'] ?? 0),
                'work_date_context' => $workDate,

                'attendance_id' => null,
                'attendance_ids' => [],
                'check_in_at' => null,
                'check_out_at' => null,
                'check_in_photo' => null,
                'check_out_photo' => null,
                'check_in_lat' => null,
                'check_in_lng' => null,
                'check_out_lat' => null,
                'check_out_lng' => null,
                'check_in_inside_fence' => null,
                'check_out_inside_fence' => null,
                'worked_minutes' => 0,
                'outside_minutes' => 0,
                'late_minutes' => 0,
                'overtime_minutes' => 0,
                'status' => null,
                'sessions' => [],
            ];
        }

        if (!empty($raw['attendance_id'])) {
            $sessNo = (int)($raw['session_no'] ?: 1);
            $grouped[$empId]['sessions'][$sessNo] = $raw;
            $grouped[$empId]['attendance_ids'][] = (int)$raw['attendance_id'];
        }
    }

    foreach ($grouped as $empId => &$emp) {
        $sessCount = count($emp['sessions']);
        if ($sessCount === 0) {
            $emp['attendance_id'] = null;
            $emp['check_in_at'] = null;
            $emp['check_out_at'] = null;
            $emp['status'] = null;
        } elseif ($sessCount === 1 && $emp['sessions_per_day'] <= 1) {
            $s = reset($emp['sessions']);
            $emp['attendance_id'] = (int)$s['attendance_id'];
            $emp['check_in_at'] = $s['check_in_at'];
            $emp['check_out_at'] = $s['check_out_at'];
            $emp['check_in_photo'] = $s['check_in_photo'];
            $emp['check_out_photo'] = $s['check_out_photo'];
            $emp['check_in_lat'] = $s['check_in_lat'];
            $emp['check_in_lng'] = $s['check_in_lng'];
            $emp['check_out_lat'] = $s['check_out_lat'];
            $emp['check_out_lng'] = $s['check_out_lng'];
            $emp['check_in_inside_fence'] = $s['check_in_inside_fence'];
            $emp['check_out_inside_fence'] = $s['check_out_inside_fence'];
            $emp['worked_minutes'] = (int)($s['worked_minutes'] ?? 0);
            $emp['outside_minutes'] = (int)($s['outside_minutes'] ?? 0);
            $emp['late_minutes'] = (int)($s['late_minutes'] ?? 0);
            $emp['overtime_minutes'] = (int)($s['overtime_minutes'] ?? 0);
            $emp['status'] = $s['status'];
        } else {
            // Split shift (multiple sessions)
            $firstSess = $emp['sessions'][1] ?? reset($emp['sessions']);
            $lastSess = $emp['sessions'][2] ?? end($emp['sessions']);

            $emp['attendance_id'] = (int)$firstSess['attendance_id'];
            $emp['check_in_at'] = $firstSess['check_in_at'] ?? ($lastSess['check_in_at'] ?? null);
            $emp['check_out_at'] = $lastSess['check_out_at'] ?? ($firstSess['check_out_at'] ?? null);
            $emp['check_in_photo'] = $firstSess['check_in_photo'] ?? null;
            $emp['check_out_photo'] = $lastSess['check_out_photo'] ?? null;
            $emp['check_in_lat'] = $firstSess['check_in_lat'] ?? null;
            $emp['check_in_lng'] = $firstSess['check_in_lng'] ?? null;
            $emp['check_out_lat'] = $lastSess['check_out_lat'] ?? null;
            $emp['check_out_lng'] = $lastSess['check_out_lng'] ?? null;

            $inFences = array_filter(array_column($emp['sessions'], 'check_in_inside_fence'), function($v) { return $v !== null; });
            $emp['check_in_inside_fence'] = $inFences ? (in_array(0, array_map('intval', $inFences), true) ? 0 : 1) : null;
            $outFences = array_filter(array_column($emp['sessions'], 'check_out_inside_fence'), function($v) { return $v !== null; });
            $emp['check_out_inside_fence'] = $outFences ? (in_array(0, array_map('intval', $outFences), true) ? 0 : 1) : null;

            $totalWorked = 0;
            $totalOutside = 0;
            $totalLate = 0;
            $totalOvertime = 0;
            $statuses = [];

            foreach ($emp['sessions'] as $s) {
                $totalWorked += (int)($s['worked_minutes'] ?? 0);
                $totalOutside += (int)($s['outside_minutes'] ?? 0);
                $totalLate += (int)($s['late_minutes'] ?? 0);
                $totalOvertime += (int)($s['overtime_minutes'] ?? 0);
                if (!empty($s['status'])) {
                    $statuses[] = $s['status'];
                }
            }

            $emp['worked_minutes'] = $totalWorked;
            $emp['outside_minutes'] = $totalOutside;
            $emp['late_minutes'] = $totalLate;
            $emp['overtime_minutes'] = $totalOvertime;

            if (in_array('missing-checkout', $statuses, true)) {
                $emp['status'] = 'missing-checkout';
            } elseif (in_array('incomplete', $statuses, true)) {
                $emp['status'] = 'incomplete';
            } elseif (in_array('half-day', $statuses, true)) {
                $emp['status'] = 'half-day';
            } elseif (in_array('late', $statuses, true)) {
                $emp['status'] = 'late';
            } elseif (in_array('present', $statuses, true)) {
                if ($emp['sessions_per_day'] > 1 && $sessCount < $emp['sessions_per_day']) {
                    $emp['status'] = ($workDate === date('Y-m-d')) ? 'incomplete' : 'half-day';
                } else {
                    $emp['status'] = 'present';
                }
            } else {
                $emp['status'] = reset($statuses) ?: 'present';
            }
        }
    }
    unset($emp);
    $rows = array_values($grouped);

    // Filter by calculated status if requested
    if (!empty($filters['status'])) {
        $filterStatus = $filters['status'];
        $rows = array_values(array_filter($rows, function ($r) use ($filterStatus) {
            return attRowStatus($r) === $filterStatus;
        }));
    }

    return $rows;
}

/**
 * How many register rows each tab holds for a date.
 *
 * Respects the area, project and search filters (so the tab counts match what
 * each tab would show) but not the status filter — status cuts across tabs and
 * is applied after switching.
 *
 * @return array{employee:int,monitor:int,driver:int}
 */
function attRegisterTypeCounts($workDate, $filters = []) {
    $sql = "
        SELECT e.person_type, COUNT(*) AS n
        FROM att_employees e
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        LEFT JOIN att_employee_settings s ON s.att_employee_id = e.id
        WHERE e.is_active = 1
    ";
    $params = [];

    if (!empty($filters['geofence_id'])) {
        $sql .= " AND (s.geofence_id = ? OR e.id IN (SELECT att_employee_id FROM att_employee_geofences WHERE geofence_id = ?))";
        $params[] = (int)$filters['geofence_id'];
        $params[] = (int)$filters['geofence_id'];
    }
    if (!empty($filters['search'])) {
        $sql .= " AND (emp.first_name LIKE ? OR emp.last_name LIKE ? OR emp.employee_code LIKE ?)";
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like);
    }
    if (!empty($filters['project_id'])) {
        $sql .= " AND emp.project_id = ?";
        $params[] = (int)$filters['project_id'];
    }
    $sql .= " GROUP BY e.person_type";

    $stmt = attDB()->prepare($sql);
    $stmt->execute($params);
    $counts = ['employee' => 0, 'monitor' => 0, 'driver' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($counts[$row['person_type']])) {
            $counts[$row['person_type']] = (int)$row['n'];
        }
    }
    return $counts;
}

/** Every app account with its rules, device and last activity. */
function attEmployeeList($search = null, $category = null) {
    $sql = "
        SELECT e.id, e.login_code, e.is_active, e.last_login_at, e.consent_accepted_at,
               e.must_change_password, e.employee_id,
               -- Without these the page cannot show that an account is locked out, and
               -- the only symptom an admin sees is an employee saying the app refuses
               -- to let them in.
               e.security_blocked_at, e.security_block_reason,
               -- Fallbacks so an account whose person record cannot be matched still
               -- appears, named by its login code. With an inner join and a bare CONCAT
               -- it vanished from this page entirely, which is the worst possible
               -- behaviour: the admin concludes the account does not exist while the
               -- employee is being told their password is wrong.
               COALESCE(emp.employee_code, e.login_code) AS employee_code,
               COALESCE(CONCAT(emp.first_name, ' ', emp.last_name),
                        CONCAT('Unlinked account (', e.login_code, ')')) AS name,
               emp.employee_code AS person_found,
               emp.first_name, emp.last_name, emp.position, emp.employment_status,
               emp.department_id, dep.department_name,
               hre.phone,
               s.shift_start, s.shift_end, s.tracking_interval_min, s.require_photo,
               s.enforce_geofence, s.enforce_geofence_checkout, s.geofence_id, s.work_days, s.max_accuracy_m,
               s.late_grace_min, s.max_overtime_hours, s.overnight_shift, s.allow_mock_location,
               s.sessions_per_day, s.shift2_start, s.shift2_end,
               s.home_lat, s.home_lng, s.home_radius_m, s.home_label,
               g.name AS geofence_name,
               d.id AS device_id, d.device_model, d.device_brand, d.platform,
               d.bound_at, d.last_seen_at, d.app_version,
               -- Every assigned area, so the rules dialog can pre-tick them and the
               -- list can show at a glance who works across more than one site.
               (SELECT GROUP_CONCAT(eg.geofence_id ORDER BY eg.geofence_id)
                  FROM att_employee_geofences eg
                 WHERE eg.att_employee_id = e.id) AS area_ids,
               (SELECT GROUP_CONCAT(g2.name ORDER BY g2.name SEPARATOR ', ')
                  FROM att_employee_geofences eg2
                  JOIN att_geofences g2 ON g2.id = eg2.geofence_id
                 WHERE eg2.att_employee_id = e.id) AS area_names
        FROM att_employees e
        LEFT JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        LEFT JOIN hr_employees hre ON hre.id = e.employee_id
        LEFT JOIN hr_departments dep ON dep.id = emp.department_id
        LEFT JOIN att_employee_settings s ON s.att_employee_id = e.id
        LEFT JOIN att_geofences g ON g.id = s.geofence_id
        LEFT JOIN att_devices d ON d.att_employee_id = e.id AND d.status = 'active'
        WHERE e.person_type = 'employee'
    ";
    $params = [];

    if ($search) {
        $sql .= " AND (emp.first_name LIKE ? OR emp.last_name LIKE ?
                        OR emp.employee_code LIKE ? OR e.login_code LIKE ?
                        OR emp.position LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($category !== null && $category !== '') {
        $sql .= " AND emp.position = ?";
        $params[] = $category;
    }

    $sql .= " ORDER BY emp.first_name, emp.last_name";

    $stmt = attDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Active designations from hr_designations, ensuring core categories exist. */
function attDesignationList() {
    $db = attDB();
    // Ensure core technical categories exist in hr_designations
    $coreCategories = ['Mechanic', 'Technician', 'Electrician'];
    $check = $db->prepare("SELECT id FROM hr_designations WHERE LOWER(designation_name) = LOWER(?) LIMIT 1");
    $insert = $db->prepare("INSERT INTO hr_designations (designation_name, is_active) VALUES (?, 1)");
    foreach ($coreCategories as $cat) {
        $check->execute([$cat]);
        if (!$check->fetch()) {
            try {
                $insert->execute([$cat]);
            } catch (Exception $e) {}
        }
    }

    return $db->query("
        SELECT id, designation_name
        FROM hr_designations
        WHERE is_active = 1
        ORDER BY designation_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** Active departments from hr_departments. */
function attDepartmentList() {
    return attDB()->query("
        SELECT id, department_name, department_code
        FROM hr_departments
        WHERE is_active = 1
        ORDER BY department_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** Suggest next sequential numeric employee code (e.g. 0016). */
function attNextEmployeeCode() {
    $db = attDB();
    $stmt = $db->query("
        SELECT employee_code FROM hr_employees
        WHERE employee_code REGEXP '^[0-9]+$'
        ORDER BY CAST(employee_code AS UNSIGNED) DESC
        LIMIT 1
    ");
    $last = $stmt->fetchColumn();
    if ($last !== false && is_numeric($last)) {
        $next = (int)$last + 1;
        return sprintf('%04d', $next);
    }
    return '0001';
}

/**
 * Creates a brand new employee in HR and simultaneously activates their
 * attendance account and settings so they can immediately sign in on the app.
 */
function attCreateManualEmployee(array $data, int $adminUserId) {
    $db = attDB();
    $defaults = attSettings();

    $firstName = trim($data['first_name'] ?? '');
    $lastName = trim($data['last_name'] ?? '');
    $position = trim($data['position'] ?? '');
    $customPosition = trim($data['custom_position'] ?? '');
    if ($position === '__custom__' || $position === 'Custom') {
        $position = $customPosition;
    }
    if ($position === '') {
        $position = 'Employee';
    }

    $departmentId = (int)($data['department_id'] ?? 0) ?: null;
    $phone = trim($data['phone'] ?? '') ?: null;
    $employeeCode = trim($data['employee_code'] ?? '');
    if ($employeeCode === '') {
        $employeeCode = attNextEmployeeCode();
    }
    $loginCode = trim($data['login_code'] ?? '');
    if ($loginCode === '') {
        $loginCode = $employeeCode;
    }
    $password = (string)($data['password'] ?? '');
    $mustChange = !empty($data['must_change_password']) ? 1 : 0;

    if ($firstName === '') {
        return ['ok' => false, 'message' => 'First name is required.'];
    }
    if (strlen($password) < 6) {
        return ['ok' => false, 'message' => 'Password must be at least 6 characters.'];
    }

    // Check unique employee_code in hr_employees
    $codeExists = $db->prepare("SELECT id FROM hr_employees WHERE employee_code = ? LIMIT 1");
    $codeExists->execute([$employeeCode]);
    if ($codeExists->fetch()) {
        return ['ok' => false, 'message' => 'Employee code "' . $employeeCode . '" is already taken in HR.'];
    }

    // Check unique login_code in att_employees
    $loginExists = $db->prepare("SELECT id FROM att_employees WHERE login_code = ? LIMIT 1");
    $loginExists->execute([$loginCode]);
    if ($loginExists->fetch()) {
        return ['ok' => false, 'message' => 'Login code "' . $loginCode . '" is already in use for attendance.'];
    }

    // Save custom category to hr_designations if not already present
    if ($position !== '') {
        $desExists = $db->prepare("SELECT id FROM hr_designations WHERE LOWER(designation_name) = LOWER(?) LIMIT 1");
        $desExists->execute([$position]);
        if (!$desExists->fetch()) {
            try {
                $db->prepare("INSERT INTO hr_designations (designation_name, is_active) VALUES (?, 1)")->execute([$position]);
            } catch (Exception $e) {}
        }
    }

    $geofenceId = (int)($data['geofence_id'] ?? 0) ?: null;
    $shiftStart = !empty($data['shift_start']) ? $data['shift_start'] : ($defaults['default_shift_start'] ?? '08:00');
    if (strlen($shiftStart) === 5) $shiftStart .= ':00';
    $shiftEnd = !empty($data['shift_end']) ? $data['shift_end'] : ($defaults['default_shift_end'] ?? '17:00');
    if (strlen($shiftEnd) === 5) $shiftEnd .= ':00';

    $interval = max(1, min(120, (int)($data['tracking_interval_min'] ?? ($defaults['default_interval_min'] ?? 10))));
    $requirePhoto = isset($data['require_photo']) ? 1 : 0;
    $enforceGeofence = isset($data['enforce_geofence']) ? 1 : 0;
    $workDays = is_array($data['work_days'] ?? null) ? implode(',', array_map('intval', $data['work_days'])) : '1,2,3,4,5,6';
    if (!$workDays) $workDays = '1,2,3,4,5,6';

    try {
        $db->beginTransaction();

        // 1. Create HR Employee record
        $hrStmt = $db->prepare("
            INSERT INTO hr_employees (
                employee_code, first_name, last_name, phone,
                department_id, position, employment_type, employment_status,
                date_of_joining, is_active, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, 'full-time', 'active', CURDATE(), 1, ?)
        ");
        $hrStmt->execute([
            $employeeCode,
            $firstName,
            $lastName,
            $phone,
            $departmentId,
            $position,
            $adminUserId
        ]);
        $hrId = (int)$db->lastInsertId();

        // 2. Create Attendance Employee Account
        $attStmt = $db->prepare("
            INSERT INTO att_employees (
                person_type, employee_id, login_code, password,
                must_change_password, is_active, created_by
            ) VALUES ('employee', ?, ?, ?, ?, 1, ?)
        ");
        $attStmt->execute([
            $hrId,
            $loginCode,
            password_hash($password, PASSWORD_DEFAULT),
            $mustChange,
            $adminUserId
        ]);
        $attId = (int)$db->lastInsertId();

        // 3. Create Settings
        $setStmt = $db->prepare("
            INSERT INTO att_employee_settings (
                att_employee_id, geofence_id, shift_start, shift_end,
                tracking_interval_min, max_accuracy_m, require_photo,
                enforce_geofence, work_days
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $setStmt->execute([
            $attId,
            $geofenceId,
            $shiftStart,
            $shiftEnd,
            $interval,
            (int)($defaults['default_max_accuracy_m'] ?? 50),
            $requirePhoto,
            $enforceGeofence,
            $workDays
        ]);

        // 4. Primary geofence assignment
        if ($geofenceId) {
            $db->prepare("INSERT IGNORE INTO att_employee_geofences (att_employee_id, geofence_id) VALUES (?, ?)")
               ->execute([$attId, $geofenceId]);
        }

        $db->commit();

        attAudit('employee_created_manual', 'att_employee', $attId, [
            'hr_id' => $hrId,
            'employee_code' => $employeeCode,
            'login_code' => $loginCode,
            'position' => $position,
            'name' => trim($firstName . ' ' . $lastName),
        ], 'admin', $adminUserId);

        return [
            'ok' => true,
            'att_employee_id' => $attId,
            'hr_employee_id' => $hrId,
            'employee_code' => $employeeCode,
            'login_code' => $loginCode,
            'name' => trim($firstName . ' ' . $lastName),
            'position' => $position,
            'password' => $password,
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'message' => 'Failed to create employee: ' . $e->getMessage()];
    }
}

/** Update employee details: name, category/position, department, phone, login code. */
function attUpdateEmployeeDetails(int $attEmployeeId, array $data, int $adminUserId) {
    $db = attDB();
    $stmt = $db->prepare("SELECT employee_id, login_code FROM att_employees WHERE id = ? LIMIT 1");
    $stmt->execute([$attEmployeeId]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$acc || empty($acc['employee_id'])) {
        return ['ok' => false, 'message' => 'Account not found or not linked to an employee record.'];
    }

    $firstName = trim($data['first_name'] ?? '');
    $lastName = trim($data['last_name'] ?? '');
    $position = trim($data['position'] ?? '');
    $customPosition = trim($data['custom_position'] ?? '');
    if ($position === '__custom__' || $position === 'Custom') {
        $position = $customPosition;
    }
    $departmentId = (int)($data['department_id'] ?? 0) ?: null;
    $phone = trim($data['phone'] ?? '') ?: null;
    $loginCode = trim($data['login_code'] ?? '');

    if ($firstName === '') {
        return ['ok' => false, 'message' => 'First name is required.'];
    }
    if ($loginCode === '') {
        return ['ok' => false, 'message' => 'Login code cannot be empty.'];
    }

    // Check login code uniqueness if changed
    if ($loginCode !== $acc['login_code']) {
        $check = $db->prepare("SELECT id FROM att_employees WHERE login_code = ? AND id != ? LIMIT 1");
        $check->execute([$loginCode, $attEmployeeId]);
        if ($check->fetch()) {
            return ['ok' => false, 'message' => 'Login code "' . $loginCode . '" is already in use by another account.'];
        }
    }

    // Save custom category to hr_designations if not already present
    if ($position !== '') {
        $desExists = $db->prepare("SELECT id FROM hr_designations WHERE LOWER(designation_name) = LOWER(?) LIMIT 1");
        $desExists->execute([$position]);
        if (!$desExists->fetch()) {
            try {
                $db->prepare("INSERT INTO hr_designations (designation_name, is_active) VALUES (?, 1)")->execute([$position]);
            } catch (Exception $e) {}
        }
    }

    try {
        $db->beginTransaction();

        $db->prepare("
            UPDATE hr_employees
            SET first_name = ?, last_name = ?, position = ?, department_id = ?, phone = ?
            WHERE id = ?
        ")->execute([$firstName, $lastName, $position, $departmentId, $phone, (int)$acc['employee_id']]);

        $db->prepare("UPDATE att_employees SET login_code = ? WHERE id = ?")->execute([$loginCode, $attEmployeeId]);

        $db->commit();

        attAudit('employee_details_updated', 'att_employee', $attEmployeeId, [
            'name' => trim($firstName . ' ' . $lastName),
            'position' => $position,
            'login_code' => $loginCode
        ], 'admin', $adminUserId);

        return ['ok' => true, 'message' => 'Employee details updated.'];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'message' => 'Failed to update employee: ' . $e->getMessage()];
    }
}

/** Employees on the ERP payroll who do not have an app account yet. */
function attUnenrolledEmployees() {
    return attDB()->query("
        SELECT emp.id, emp.employee_code,
               CONCAT(emp.first_name, ' ', emp.last_name) AS name,
               emp.position, dep.department_name
        FROM hr_employees emp
        LEFT JOIN hr_departments dep ON dep.id = emp.department_id
        LEFT JOIN att_employees e ON e.employee_id = emp.id
        WHERE e.id IS NULL
          AND emp.is_active = 1
          AND emp.employment_status NOT IN ('terminated', 'resigned')
        ORDER BY emp.first_name, emp.last_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function attGeofenceList($activeOnly = true) {
    $sql = "
        SELECT g.*, (SELECT COUNT(*) FROM att_employee_settings s WHERE s.geofence_id = g.id) AS assigned_count
        FROM att_geofences g
    ";
    if ($activeOnly) {
        $sql .= " WHERE g.is_active = 1";
    }
    $sql .= " ORDER BY g.name";
    return attDB()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Latest known position of every active employee, for the live map.
 *
 * Everyone is returned, including those who have never reported a position — with
 * lat/lng null and has_position false. Dropping them looked tidier but made the map
 * useless off shift: routes are only recorded during working hours, so outside them
 * nobody has a recent fix, the list came back empty, and there was no row left to
 * press "Locate now" on. The one control that works off shift was unreachable
 * exactly when it was needed.
 *
 * A position older than $staleMinutes means the tracking service has gone quiet.
 */
function attLivePositions($staleMinutes = 30) {
    $rows = attDB()->query("
        SELECT e.id AS att_employee_id, emp.employee_code,
               CONCAT(emp.first_name, ' ', emp.last_name) AS name,
               emp.position, emp.photo, e.person_type,
               s.shift_start, s.shift_end, s.overnight_shift, s.work_days,
               s.tracking_interval_min, s.geofence_id,
               g.name AS geofence_name, g.type AS geofence_type, g.center_lat, g.center_lng,
               g.radius_m, g.polygon, g.color,
               a.check_in_at, a.check_out_at, a.status,
               l.lat, l.lng, l.accuracy_m, l.battery_pct, l.speed_kmh,
               l.inside_fence, l.distance_from_fence_m, l.recorded_at, l.is_mock,
               d.last_seen_at, d.device_model
        FROM att_employees e
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        LEFT JOIN att_employee_settings s ON s.att_employee_id = e.id
        LEFT JOIN att_geofences g ON g.id = s.geofence_id
        LEFT JOIN att_devices d ON d.att_employee_id = e.id AND d.status = 'active'
        LEFT JOIN att_attendance a
               ON a.id = (SELECT a2.id FROM att_attendance a2
                           WHERE a2.att_employee_id = e.id AND a2.work_date = CURDATE()
                           ORDER BY (a2.check_out_at IS NULL AND a2.check_in_at IS NOT NULL) DESC, a2.session_no DESC
                           LIMIT 1)
        LEFT JOIN att_location_logs l
               ON l.id = (SELECT l2.id FROM att_location_logs l2
                           WHERE l2.att_employee_id = e.id
                           ORDER BY l2.recorded_at DESC LIMIT 1)
        WHERE e.is_active = 1
        ORDER BY emp.first_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        $settings = [
            'shift_start' => $row['shift_start'] ?: '08:00:00',
            'shift_end' => $row['shift_end'] ?: '17:00:00',
            'overnight_shift' => $row['overnight_shift'],
            'work_days' => $row['work_days'] ?: '1,2,3,4,5,6',
        ];

        $hasPosition = $row['lat'] !== null;

        // Age is meaningless without a position; null rather than a number that would
        // read as "reported 999999 minutes ago".
        $ageMinutes = $hasPosition
            ? (int)round((time() - strtotime($row['recorded_at'])) / 60)
            : null;

        $out[] = [
            'att_employee_id' => (int)$row['att_employee_id'],
            'employee_code' => $row['employee_code'],
            'person_type' => $row['person_type'],
            'name' => $row['name'],
            'position' => $row['position'],
            'has_position' => $hasPosition,
            'lat' => $hasPosition ? (float)$row['lat'] : null,
            'lng' => $hasPosition ? (float)$row['lng'] : null,
            'accuracy_m' => $row['accuracy_m'] !== null ? (float)$row['accuracy_m'] : null,
            'battery_pct' => $row['battery_pct'] !== null ? (int)$row['battery_pct'] : null,
            'speed_kmh' => $row['speed_kmh'] !== null ? (float)$row['speed_kmh'] : null,
            'inside_fence' => $row['inside_fence'] === null ? null : (bool)$row['inside_fence'],
            'distance_from_fence_m' => (int)$row['distance_from_fence_m'],
            'is_mock' => (bool)$row['is_mock'],
            'recorded_at' => $row['recorded_at'],
            'age_minutes' => $ageMinutes,
            'stale' => $hasPosition && $ageMinutes > $staleMinutes,
            'on_shift' => attWithinShift($settings),
            'has_device' => $row['device_model'] !== null,
            'checked_in' => $row['check_in_at'] !== null,
            'checked_out' => $row['check_out_at'] !== null,
            'check_in_at' => $row['check_in_at'],
            'status' => $row['status'] ?? 'absent',
            'device_model' => $row['device_model'],
            'geofence' => $row['geofence_id'] ? attGeofencePayload([
                'id' => $row['geofence_id'],
                'name' => $row['geofence_name'],
                'type' => $row['geofence_type'],
                'center_lat' => $row['center_lat'],
                'center_lng' => $row['center_lng'],
                'radius_m' => $row['radius_m'],
                'polygon' => $row['polygon'],
                'color' => $row['color'],
            ]) : null,
        ];
    }
    return $out;
}

/**
 * A day's route for one employee, split into segments wherever the gap between
 * points exceeds ATT_ROUTE_GAP_MIN — drawing a straight line across a two-hour
 * hole would invent a journey that was never recorded.
 */
function attRoute($attEmployeeId, $workDate) {
    $stmt = attDB()->prepare("
        SELECT id, lat, lng, accuracy_m, speed_kmh, battery_pct, inside_fence,
               distance_from_fence_m, is_mock, real_lat, real_lng, provider, recorded_at
        FROM att_location_logs
        WHERE att_employee_id = ? AND work_date = ?
        ORDER BY recorded_at ASC
    ");
    $stmt->execute([$attEmployeeId, $workDate]);
    $points = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Types normalised before anything reads the list, so the odometer, the dwell walk and
    // the segment split all see the same values.
    $imprecise = 0;
    foreach ($points as $index => $point) {
        $points[$index]['lat'] = (float)$point['lat'];
        $points[$index]['lng'] = (float)$point['lng'];
        $points[$index]['inside_fence'] = $point['inside_fence'] === null
            ? null : (bool)$point['inside_fence'];
        $points[$index]['is_mock'] = (bool)$point['is_mock'];

        // Marked, not dropped. A fix accurate to ±200 m says almost nothing about where
        // somebody was, and joining those to their neighbours is most of the visible
        // scatter — but deleting them from the response would leave an employee whose
        // phone only manages coarse fixes indoors with a blank map and no explanation.
        // The map leaves them out of the line and still shows them, faintly.
        $accuracy = $point['accuracy_m'] === null ? null : (float)$point['accuracy_m'];
        $points[$index]['imprecise'] = $accuracy !== null && $accuracy > ATT_ROUTE_MAX_ACCURACY_M;
        if ($points[$index]['imprecise']) {
            $imprecise++;
        }

        // Filled in below, once the dwells are known.
        $points[$index]['dwell'] = null;
    }

    // A point that could only be reached by travelling implausibly fast is an outlier, and
    // one outlier draws two long spurs — out to it and back — which is most of what makes a
    // route look unstable. Judged against both neighbours rather than just the previous
    // point: a single bad fix between two good ones is a spike, whereas a genuine burst of
    // speed continues into the next point.
    //
    // Marked with the same flag the accuracy gate uses, so the line skips it and the dot
    // still shows. Nothing is deleted; the reading remains in the record.
    $count = count($points);
    for ($i = 1; $i < $count - 1; $i++) {
        if ($points[$i]['imprecise']) {
            continue;
        }

        $previous = $points[$i - 1];
        $next = $points[$i + 1];

        $toHere = attHaversine($previous['lat'], $previous['lng'], $points[$i]['lat'], $points[$i]['lng']);
        $onward = attHaversine($points[$i]['lat'], $points[$i]['lng'], $next['lat'], $next['lng']);
        $across = attHaversine($previous['lat'], $previous['lng'], $next['lat'], $next['lng']);

        $seconds = max(1, strtotime($next['recorded_at']) - strtotime($previous['recorded_at']));
        $impliedKmh = ($toHere + $onward) / $seconds * 3.6;

        // Out and straight back, faster than anyone travels: a spike. The detour has to be
        // substantial as well as fast, or a genuine turn at speed would be discarded.
        if ($impliedKmh > ATT_ROUTE_MAX_KMH && ($toHere + $onward) > ($across * 3) + 100) {
            $points[$i]['imprecise'] = true;
            $imprecise++;
        }
    }

    // Distance and dwells are measured on the precise points only: an imprecise fix
    // cannot establish that somebody moved, and letting it try is what put phantom
    // kilometres on the odometer in the first place. Keys are preserved so the indexes
    // the dwells report still address the full list.
    $movement = attRouteMovement(array_values(array_filter($points, function ($point) {
        return !$point['imprecise'];
    })));

    // Each dwell's absorbed points carry its ordinal, so the map can collapse them to one
    // place without repeating the arithmetic. Tagging the points rather than handing over
    // index ranges survives the client thinning the list.
    //
    // The dwell indexes address the filtered list, so they are translated back through the
    // same filter. Without this an imprecise fix early in the day would shift every stop.
    $preciseIndexes = [];
    foreach ($points as $index => $point) {
        if (!$point['imprecise']) {
            $preciseIndexes[] = $index;
        }
    }
    foreach ($movement['stops'] as $ordinal => $stop) {
        for ($i = (int)$stop['first_index']; $i <= (int)$stop['last_index']; $i++) {
            if (isset($preciseIndexes[$i])) {
                $points[$preciseIndexes[$i]]['dwell'] = $ordinal;
            }
        }
    }

    $segments = [];
    $current = [];
    $previousTime = null;

    foreach ($points as $point) {
        $time = strtotime($point['recorded_at']);
        if ($previousTime !== null && ($time - $previousTime) > ATT_ROUTE_GAP_MIN * 60) {
            if (count($current) > 0) {
                $segments[] = $current;
            }
            $current = [];
        }
        $current[] = $point;
        $previousTime = $time;
    }
    if ($current) {
        $segments[] = $current;
    }

    return [
        'points' => $points,
        'segments' => $segments,
        'distance_m' => $movement['distance_m'],
        // Where they stayed put, so a cluster of wobble reads as "stopped here for
        // 20 minutes" instead of a scribble that looks like pacing.
        'stops' => $movement['stops'],
        'point_count' => count($points),
        // Reported so the page can say why the line skips them, rather than appearing to
        // have lost points.
        'imprecise_count' => $imprecise,
        'accuracy_limit_m' => ATT_ROUTE_MAX_ACCURACY_M,
    ];
}

/** Trips outside the fence, for the review queue and the route page. */
function attTrips($filters = []) {
    $sql = "
        SELECT ev.*, e.id AS att_employee_id, emp.employee_code,
               CONCAT(emp.first_name, ' ', emp.last_name) AS name,
               g.name AS geofence_name,
               u.username AS reviewed_by_name
        FROM att_geofence_events ev
        JOIN att_employees e ON e.id = ev.att_employee_id
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        LEFT JOIN att_geofences g ON g.id = ev.geofence_id
        LEFT JOIN users u ON u.id = ev.reviewed_by
        WHERE 1 = 1
    ";
    $params = [];

    if (!empty($filters['att_employee_id'])) {
        $sql .= " AND ev.att_employee_id = ?";
        $params[] = (int)$filters['att_employee_id'];
    }
    if (!empty($filters['work_date'])) {
        $sql .= " AND ev.work_date = ?";
        $params[] = $filters['work_date'];
    }
    if (!empty($filters['from']) && !empty($filters['to'])) {
        $sql .= " AND ev.work_date BETWEEN ? AND ?";
        $params[] = $filters['from'];
        $params[] = $filters['to'];
    }
    if (!empty($filters['review_status'])) {
        $sql .= " AND ev.review_status = ?";
        $params[] = $filters['review_status'];
    }
    if (!empty($filters['open_only'])) {
        $sql .= " AND ev.entry_at IS NULL";
    }

    $sql .= " ORDER BY ev.exit_at DESC LIMIT " . (int)($filters['limit'] ?? 200);

    $stmt = attDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** One app account with its rules and device, or null. */
function attEmployee($attEmployeeId) {
    $stmt = attDB()->prepare("
        SELECT e.*, emp.employee_code, CONCAT(emp.first_name, ' ', emp.last_name) AS name,
               emp.position, emp.photo, emp.employment_status, dep.department_name,
               s.geofence_id, s.shift_start, s.shift_end, s.overnight_shift, s.work_days,
               s.tracking_interval_min, s.late_grace_min, s.max_overtime_hours, s.require_photo, s.enforce_geofence,
               s.enforce_geofence_checkout,
               s.max_accuracy_m, s.allow_mock_location,
               s.sessions_per_day, s.shift2_start, s.shift2_end,
               g.name AS geofence_name,
               d.id AS device_id, d.device_uid, d.device_model, d.device_brand,
               d.os_version, d.app_version, d.platform, d.bound_at, d.last_seen_at
        FROM att_employees e
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        LEFT JOIN hr_departments dep ON dep.id = emp.department_id
        LEFT JOIN att_employee_settings s ON s.att_employee_id = e.id
        LEFT JOIN att_geofences g ON g.id = s.geofence_id
        LEFT JOIN att_devices d ON d.att_employee_id = e.id AND d.status = 'active'
        WHERE e.id = ?
        LIMIT 1
    ");
    $stmt->execute([$attEmployeeId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
