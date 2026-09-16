<?php
/**
 * App accounts: enrol an employee, set their work area, shift and ping interval,
 * reset a password, or release their device.
 */

require_once __DIR__ . '/bootstrap.php';

$action = $_POST['action'] ?? null;

if ($action) {
    attCheckCsrf();
    attRequireManage();
    $db = attDB();
    $defaults = attSettings();

    // Remove an account from the attendance system, leaving the person in SSTQA.
    if ($action === 'delete_account') {
        $id = (int)($_POST['att_employee_id'] ?? 0);
        $typed = trim($_POST['confirm_code'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        $check = $db->prepare("
            SELECT emp.employee_code FROM att_employees e
            JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
            WHERE e.id = ? LIMIT 1
        ");
        $check->execute([$id]);
        $expected = (string)$check->fetchColumn();

        if ($expected === '') {
            attFlash('error', 'That account no longer exists.');
        } elseif ($reason === '') {
            attFlash('error', 'Give a reason — it is the one thing kept after the deletion.');
        } elseif (!hash_equals($expected, $typed)) {
            // Typing the code is the guard against deleting the wrong person. A plain
            // "are you sure" is dismissed by reflex; this cannot be.
            attFlash('error', 'The code you typed does not match. Nothing was deleted.');
        } else {
            $result = attDeleteAccount($id, $reason, (int)$attAdmin['id']);
            attFlash($result['ok'] ? 'success' : 'error', $result['message']);
        }
        attRedirect('employees.php');
    }

    if ($action === 'enrol') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $loginCode = trim($_POST['login_code'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $employee = $db->prepare("SELECT employee_code, first_name, last_name FROM hr_employees WHERE id = ?");
        $employee->execute([$employeeId]);
        $employee = $employee->fetch(PDO::FETCH_ASSOC);

        if (!$employee) {
            attFlash('error', 'That employee does not exist.');
        } elseif (strlen($password) < 6) {
            attFlash('error', 'Password must be at least 6 characters.');
        } else {
            // Default the login code to the ERP employee code so staff have one
            // number to remember across both systems.
            if ($loginCode === '') {
                $loginCode = $employee['employee_code'];
            }

            $taken = $db->prepare("SELECT 1 FROM att_employees WHERE login_code = ?");
            $taken->execute([$loginCode]);

            if ($taken->fetch()) {
                attFlash('error', 'Login code "' . $loginCode . '" is already in use.');
            } else {
                try {
                    $db->beginTransaction();
                    $db->prepare("
                        INSERT INTO att_employees (employee_id, login_code, password, must_change_password, created_by)
                        VALUES (?, ?, ?, 1, ?)
                    ")->execute([$employeeId, $loginCode, password_hash($password, PASSWORD_DEFAULT), (int)$attAdmin['id']]);

                    $newId = (int)$db->lastInsertId();

                    $db->prepare("
                        INSERT INTO att_employee_settings
                            (att_employee_id, geofence_id, shift_start, shift_end,
                             tracking_interval_min, max_accuracy_m)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $newId,
                        (int)($_POST['geofence_id'] ?? 0) ?: null,
                        $defaults['default_shift_start'],
                        $defaults['default_shift_end'],
                        (int)$defaults['default_interval_min'],
                        (int)$defaults['default_max_accuracy_m'],
                    ]);
                    $db->commit();

                    attAudit('employee_enrolled', 'att_employee', $newId,
                        $employee['employee_code'], 'admin', (int)$attAdmin['id']);
                    attFlash('success', trim($employee['first_name'] . ' ' . $employee['last_name']) .
                        ' enrolled. Login code: ' . $loginCode . ' — give them this and the password you set.');
                } catch (PDOException $e) {
                    $db->rollBack();
                    attFlash('error', 'Could not enrol: this employee may already have an account.');
                }
            }
        }
        attRedirect('employees.php');
    }

    if ($action === 'manual_add') {
        $result = attCreateManualEmployee($_POST, (int)$attAdmin['id']);
        if ($result['ok']) {
            attFlash('success', 'Employee <strong>' . e($result['name']) . '</strong> (' . e($result['position']) . ') created and enrolled! App Login Code: <strong>' . e($result['login_code']) . '</strong> | Password: <strong>' . e($result['password']) . '</strong>');
        } else {
            attFlash('error', $result['message']);
        }
        attRedirect('employees.php');
    }

    if ($action === 'edit_employee') {
        $id = (int)($_POST['att_employee_id'] ?? 0);
        $result = attUpdateEmployeeDetails($id, $_POST, (int)$attAdmin['id']);
        attFlash($result['ok'] ? 'success' : 'error', $result['message']);
        attRedirect('employees.php');
    }

    if ($action === 'save_settings') {
        $id = (int)$_POST['att_employee_id'];

        $days = array_values(array_intersect(
            array_map('intval', (array)($_POST['work_days'] ?? [])),
            [1, 2, 3, 4, 5, 6, 7]
        ));
        if (!$days) {
            $days = [1, 2, 3, 4, 5, 6];
        }

        $interval = (int)($_POST['tracking_interval_min'] ?? 10);
        // A ping under a minute would flatten the battery and flood the table.
        $interval = max(1, min(120, $interval));

        $shiftStart = $_POST['shift_start'] ?: '08:00';
        $shiftEnd = $_POST['shift_end'] ?: '17:00';

        // A split shift: two check-ins and two check-outs in a day, each with its own
        // hours. Both halves of the second pair must be given or it stays unset — half a
        // window would measure the afternoon against midnight.
        $sessions = ($_POST['sessions_per_day'] ?? '1') === '2' ? 2 : 1;
        $shift2Start = trim($_POST['shift2_start'] ?? '');
        $shift2End = trim($_POST['shift2_end'] ?? '');
        $hasSecond = $sessions === 2 && $shift2Start !== '' && $shift2End !== '';
        // A shift ending before it starts can only mean it runs past midnight.
        $overnight = strtotime($shiftEnd) <= strtotime($shiftStart) ? 1 : 0;

        $exists = $db->prepare("SELECT id FROM att_employee_settings WHERE att_employee_id = ?");
        $exists->execute([$id]);

        // Read the current values before overwriting them, so the audit entry can say
        // what actually changed. allow_mock_location is the reason this matters: it is
        // the switch that lets a fake-GPS app check somebody in from anywhere, and until
        // now it could be turned on and off leaving nothing behind but a badge on a page.
        $beforeRow = $db->prepare("
            SELECT allow_mock_location, enforce_geofence, enforce_geofence_checkout,
                   max_accuracy_m, tracking_interval_min, shift_start, shift_end,
                   work_days, home_lat, home_lng
            FROM att_employee_settings WHERE att_employee_id = ?
        ");
        $beforeRow->execute([$id]);
        $before = $beforeRow->fetch(PDO::FETCH_ASSOC) ?: [];

        $params = [
            (int)($_POST['geofence_id'] ?? 0) ?: null,
            $shiftStart . ':00',
            $shiftEnd . ':00',
            $overnight,
            implode(',', $days),
            $interval,
            max(0, (int)($_POST['late_grace_min'] ?? 15)),
            max(0, min(18, (int)($_POST['max_overtime_hours'] ?? 6))),
            isset($_POST['require_photo']) ? 1 : 0,
            isset($_POST['enforce_geofence']) ? 1 : 0,
            isset($_POST['enforce_geofence_checkout']) ? 1 : 0,
            // Home location. Blank clears it, because an admin removing a home address
            // must actually be able to remove it.
            is_numeric($_POST['home_lat'] ?? '') ? (float)$_POST['home_lat'] : null,
            is_numeric($_POST['home_lng'] ?? '') ? (float)$_POST['home_lng'] : null,
            max(30, min(2000, (int)($_POST['home_radius_m'] ?? 150))),
            trim($_POST['home_label'] ?? '') ?: null,
            max(10, min(2000, (int)($_POST['max_accuracy_m'] ?? 50))),
            isset($_POST['allow_mock_location']) ? 1 : 0,
            $sessions,
            $hasSecond ? $shift2Start . ':00' : null,
            $hasSecond ? $shift2End . ':00' : null,
        ];

        if ($exists->fetch()) {
            $params[] = $id;
            $db->prepare("
                UPDATE att_employee_settings
                SET geofence_id = ?, shift_start = ?, shift_end = ?, overnight_shift = ?,
                    work_days = ?, tracking_interval_min = ?, late_grace_min = ?,
                    max_overtime_hours = ?,
                    require_photo = ?, enforce_geofence = ?, enforce_geofence_checkout = ?,
                    home_lat = ?, home_lng = ?, home_radius_m = ?, home_label = ?,
                    max_accuracy_m = ?, allow_mock_location = ?,
                    sessions_per_day = ?, shift2_start = ?, shift2_end = ?
                WHERE att_employee_id = ?
            ")->execute($params);
        } else {
            array_unshift($params, $id);
            $db->prepare("
                INSERT INTO att_employee_settings
                    (att_employee_id, geofence_id, shift_start, shift_end, overnight_shift,
                     work_days, tracking_interval_min, late_grace_min, max_overtime_hours,
                     require_photo, enforce_geofence, enforce_geofence_checkout,
                     home_lat, home_lng, home_radius_m, home_label,
                     max_accuracy_m, allow_mock_location,
                     sessions_per_day, shift2_start, shift2_end)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute($params);
        }

        // Area assignments. The primary area is always one of them: it is what a
        // check-in is stamped with, so leaving it out would let someone be flagged as
        // away from the very site the panel says is their base.
        $primary = (int)($_POST['geofence_id'] ?? 0);
        $assigned = array_map('intval', (array)($_POST['extra_geofence_ids'] ?? []));
        if ($primary > 0) {
            $assigned[] = $primary;
        }
        $assigned = array_values(array_unique(array_filter($assigned)));

        // Replaced wholesale rather than diffed: an unticked box must actually remove
        // the assignment, and a delete-then-insert is the only way that reads clearly.
        $db->prepare("DELETE FROM att_employee_geofences WHERE att_employee_id = ?")->execute([$id]);
        if ($assigned) {
            $insertArea = $db->prepare("
                INSERT IGNORE INTO att_employee_geofences (att_employee_id, geofence_id)
                VALUES (?, ?)
            ");
            foreach ($assigned as $fenceId) {
                $insertArea->execute([$id, $fenceId]);
            }
        }

        // A field-by-field diff rather than "settings saved". Whoever reads this later
        // needs to know which rule moved and what it was before, not merely that
        // somebody opened the form.
        $after = [
            'allow_mock_location' => isset($_POST['allow_mock_location']) ? 1 : 0,
            'enforce_geofence' => isset($_POST['enforce_geofence']) ? 1 : 0,
            'enforce_geofence_checkout' => isset($_POST['enforce_geofence_checkout']) ? 1 : 0,
            'max_accuracy_m' => max(10, min(2000, (int)($_POST['max_accuracy_m'] ?? 50))),
            'tracking_interval_min' => $interval,
            'shift_start' => $shiftStart . ':00',
            'shift_end' => $shiftEnd . ':00',
            'work_days' => implode(',', $days),
            'home_lat' => is_numeric($_POST['home_lat'] ?? '') ? (float)$_POST['home_lat'] : null,
            'home_lng' => is_numeric($_POST['home_lng'] ?? '') ? (float)$_POST['home_lng'] : null,
        ];

        $changes = [];
        foreach ($after as $field => $newValue) {
            $oldValue = $before[$field] ?? null;
            // Loose comparison on purpose: these come back from MySQL as strings.
            if ((string)$oldValue !== (string)$newValue) {
                $changes[$field] = ['from' => $oldValue, 'to' => $newValue];
            }
        }

        $detail = ['areas' => $assigned];
        if ($changes) {
            $detail['changed'] = $changes;
        }
        // Named separately so this one can be found without reading every diff.
        if (isset($changes['allow_mock_location'])) {
            $detail['mock_location_permission'] =
                $changes['allow_mock_location']['to'] ? 'ENABLED' : 'disabled';
        }

        attAudit('employee_settings_saved', 'att_employee', $id,
            $detail, 'admin', (int)$attAdmin['id']);
        attFlash('success', 'Attendance rules updated' .
            (count($assigned) > 1 ? ' with ' . count($assigned) . ' work areas' : '') .
            '. The app picks up the change on its next sync.');
        attRedirect('employees.php');
    }

    // Lifting a security block. The block itself is automatic — impossible travel, or a
    // simulated position — so there has to be a way back that does not involve editing
    // the database by hand. The note is mandatory: an account unblocked with no reason
    // recorded is indistinguishable from one that was never blocked.
    if ($action === 'security_unblock') {
        $id = (int)($_POST['att_employee_id'] ?? 0);
        $result = attSecurityUnblock($id, $_POST['note'] ?? '', (int)$attAdmin['id']);
        attFlash($result['ok'] ? 'success' : 'error', $result['message']);
        attRedirect('employees.php');
    }

    if ($action === 'reset_password') {
        $id = (int)$_POST['att_employee_id'];
        $password = (string)($_POST['password'] ?? '');

        if (strlen($password) < 6) {
            attFlash('error', 'Password must be at least 6 characters.');
        } else {
            $db->prepare("
                UPDATE att_employees SET password = ?, must_change_password = 1 WHERE id = ?
            ")->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            attAudit('password_reset', 'att_employee', $id, null, 'admin', (int)$attAdmin['id']);
            attFlash('success', 'Password reset. The employee will be asked to change it after signing in.');
        }
        attRedirect('employees.php');
    }

    if ($action === 'reset_device') {
        $id = (int)$_POST['att_employee_id'];
        $reason = trim($_POST['reason'] ?? '');

        if ($reason === '') {
            attFlash('error', 'Give a reason for the device reset — it is recorded in the audit log.');
        } elseif (attResetDevice($id, $reason, (int)$attAdmin['id'])) {
            attFlash('success', 'Device released. The employee can now sign in on a new handset, ' .
                'and the old one has been signed out.');
        } else {
            attFlash('info', 'That employee has no device linked right now.');
        }
        attRedirect('employees.php');
    }

    if ($action === 'toggle_active') {
        $id = (int)$_POST['att_employee_id'];
        $db->prepare("UPDATE att_employees SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);

        $state = $db->prepare("SELECT is_active FROM att_employees WHERE id = ?");
        $state->execute([$id]);
        $isActive = (int)$state->fetchColumn();

        if (!$isActive) {
            // Disabling must take effect immediately, not at token expiry.
            $db->prepare("
                UPDATE att_tokens SET revoked_at = NOW()
                WHERE att_employee_id = ? AND revoked_at IS NULL
            ")->execute([$id]);
        }

        attAudit($isActive ? 'employee_enabled' : 'employee_disabled',
            'att_employee', $id, null, 'admin', (int)$attAdmin['id']);
        attFlash('success', $isActive ? 'Account enabled.' : 'Account disabled and signed out.');
        attRedirect('employees.php');
    }
}


$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? '';
$categoryFilter = trim($_GET['category'] ?? '');
$employees = attEmployeeList($search !== '' ? $search : null, $categoryFilter !== '' ? $categoryFilter : null);

if ($filter === 'no_geofence') {
    $employees = array_values(array_filter($employees, function ($row) {
        return empty($row['geofence_id']);
    }));
}
if ($filter === 'no_device') {
    $employees = array_values(array_filter($employees, function ($row) {
        return empty($row['device_id']);
    }));
}

$geofences = attGeofenceList(true);
$unenrolled = attUnenrolledEmployees();
$designations = attDesignationList();
$departments = attDepartmentList();
$nextCode = attNextEmployeeCode();
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

$pageTitle = 'Employees';
$pageSubtitle = count($employees) . ' app account' . (count($employees) === 1 ? '' : 's') .
    ' · ' . count($unenrolled) . ' not enrolled yet';
include __DIR__ . '/layout_top.php';
?>

<form class="toolbar" method="get">
    <div class="field" style="min-width:220px">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?php echo e($search); ?>" placeholder="Name, code, category...">
    </div>
    <div class="field">
        <label for="category">Category</label>
        <select id="category" name="category" data-autosubmit>
            <option value="">All categories</option>
            <?php foreach ($designations as $des): ?>
                <option value="<?php echo e($des['designation_name']); ?>" <?php echo $categoryFilter === $des['designation_name'] ? 'selected' : ''; ?>>
                    <?php echo e($des['designation_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="filter">Show</label>
        <select id="filter" name="filter" data-autosubmit>
            <option value="">All accounts</option>
            <option value="no_geofence" <?php echo $filter === 'no_geofence' ? 'selected' : ''; ?>>Missing work area</option>
            <option value="no_device" <?php echo $filter === 'no_device' ? 'selected' : ''; ?>>No device linked</option>
        </select>
    </div>
    <button class="btn ghost" type="submit">Apply</button>
    <div class="spacer"></div>
    <?php if ($attCanManage): ?>
        <button class="btn" type="button" data-modal-open="manualAddModal" style="background:#0F766E;border-color:#0F766E;color:#fff;font-weight:600">
            <svg style="width:14px;height:14px;vertical-align:-2px;margin-right:4px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>Add New Employee
        </button>
        <?php if ($unenrolled): ?>
            <button class="btn ghost" type="button" data-modal-open="enrolModal">Enrol from HR (<?php echo count($unenrolled); ?>)</button>
        <?php endif; ?>
    <?php endif; ?>
</form>

<div class="card">
    <div class="card-body tight">
        <?php if (!$employees): ?>
            <div class="empty">No accounts match. <?php echo $unenrolled ? 'Use "Add New Employee" or "Enrol from HR" to add one.' : 'Use "Add New Employee" to add one.'; ?></div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Employee / Category</th><th>Login code</th><th>Work area</th><th>Shift</th>
                    <th>Ping</th><th>Rules</th><th>Device</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $row): ?>
                <?php
                $days = array_filter(array_map('intval', explode(',', (string)$row['work_days'])));
                $dayLabel = $days ? implode(' ', array_map(function ($d) use ($dayNames) {
                    return $dayNames[$d] ?? '';
                }, $days)) : '—';
                ?>
                <tr>
                    <td class="who-cell">
                        <strong><?php echo e($row['name']); ?></strong><?php
                            // The account exists but no person record matched it, so the
                            // employee cannot sign in. Flagged here because the alternative
                            // is an admin who sees nothing wrong and an employee who is
                            // told their password is incorrect.
                            if (empty($row['person_found'])): ?>
                            <span class="badge bad" style="margin-left:6px;font-size:10px"
                                  title="No employee or monitor record matched this account. Delete it and enrol the person again.">not linked</span>
                        <?php endif; ?>
                        <div style="margin-top:3px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                            <small style="font-weight:600;color:var(--ink)"><?php echo e($row['employee_code']); ?></small>
                            <?php if (!empty($row['position'])): ?>
                                <span class="badge info" style="font-size:11px;padding:2px 7px;border-radius:6px;background:rgba(14,165,233,0.12);color:#0369a1;border:1px solid rgba(14,165,233,0.3)">
                                    <?php echo e($row['position']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($row['department_name'])): ?>
                                <small style="color:var(--ink-soft)">· <?php echo e($row['department_name']); ?></small>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><code><?php echo e($row['login_code']); ?></code></td>
                    <td>
                        <?php if ($row['geofence_name']): ?>
                            <?php echo e($row['geofence_name']); ?>
                            <?php
                            // Say so when they work across more than one site, otherwise
                            // the column reads as though only the primary area counts.
                            $areaCount = $row['area_ids']
                                ? count(array_filter(explode(',', (string)$row['area_ids'])))
                                : 0;
                            ?>
                            <?php if ($areaCount > 1): ?>
                                <br><small class="badge info"
                                    title="<?php echo e($row['area_names'] ?? ''); ?>">+<?php
                                    echo $areaCount - 1; ?> more area<?php
                                    echo $areaCount - 1 === 1 ? '' : 's'; ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge warn">not set</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['shift_start']): ?>
                            <?php echo substr($row['shift_start'], 0, 5); ?>–<?php echo substr($row['shift_end'], 0, 5); ?>
                            <br><small style="color:var(--ink-soft)"><?php echo e($dayLabel); ?></small>
                        <?php else: ?>
                            <span class="badge muted">default</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int)$row['tracking_interval_min'] ?: '—'; ?> min</td>
                    <td>
                        <?php if ((int)$row['require_photo'] === 1): ?><span class="badge info" title="Photo required at check-in and check-out">photo</span><?php endif; ?>
                        <?php if ((int)$row['enforce_geofence'] === 1): ?><span class="badge ok" title="Check-in blocked outside the area">fenced</span><?php endif; ?>
                        <?php if ((int)$row['allow_mock_location'] === 1): ?><span class="badge bad" title="Mock locations are accepted">mock ok</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['device_id']): ?>
                            <small><?php echo e(trim($row['device_brand'] . ' ' . $row['device_model'])) ?: 'Linked'; ?></small><br>
                            <small style="color:var(--ink-soft)">
                                <?php echo $row['last_seen_at']
                                    ? 'seen ' . date('d M H:i', strtotime($row['last_seen_at']))
                                    : 'never active'; ?>
                            </small>
                        <?php else: ?>
                            <span class="badge muted">none</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($row['security_blocked_at'])): ?>
                            <?php /* Shown ahead of everything else: this is the state that
                                     stops someone working, and the reason is the first
                                     thing an admin needs before deciding to lift it. */ ?>
                            <span class="badge bad" title="Signed out and refused by the server">blocked</span>
                            <br><small style="color:var(--bad)"><?php
                                echo e($row['security_block_reason'] ?: 'security check'); ?></small>
                            <br><small style="color:var(--ink-soft)"><?php
                                echo e(date('d M H:i', strtotime($row['security_blocked_at']))); ?></small>
                        <?php elseif ((int)$row['is_active'] !== 1): ?>
                            <span class="badge bad">disabled</span>
                        <?php elseif ($row['consent_accepted_at'] === null): ?>
                            <span class="badge warn" title="Has not accepted the tracking notice">no consent</span>
                        <?php elseif ($row['last_login_at'] === null): ?>
                            <span class="badge info">never signed in</span>
                        <?php else: ?>
                            <span class="badge ok">active</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ($attCanManage): ?>
                        <button class="btn ghost small" type="button"
                                data-modal-open="editEmployeeModal"
                                data-subject="<?php echo e($row['name']); ?>"
                                data-set-att_employee_id="<?php echo (int)$row['id']; ?>"
                                data-set-first_name="<?php echo e($row['first_name'] ?? ''); ?>"
                                data-set-last_name="<?php echo e($row['last_name'] ?? ''); ?>"
                                data-set-login_code="<?php echo e($row['login_code'] ?? ''); ?>"
                                data-set-position="<?php echo e($row['position'] ?? ''); ?>"
                                data-set-department_id="<?php echo (int)($row['department_id'] ?? 0); ?>"
                                data-set-phone="<?php echo e($row['phone'] ?? ''); ?>">Edit</button>
                        <button class="btn ghost small" type="button"
                                data-modal-open="rulesModal"
                                data-subject="<?php echo e($row['name']); ?>"
                                data-set-att_employee_id="<?php echo (int)$row['id']; ?>"
                                data-set-geofence_id="<?php echo (int)$row['geofence_id']; ?>"
                                data-areas="<?php echo e($row['area_ids'] ?? ''); ?>"
                                data-set-shift_start="<?php echo e(substr($row['shift_start'] ?? '08:00:00', 0, 5)); ?>"
                                data-set-shift_end="<?php echo e(substr($row['shift_end'] ?? '17:00:00', 0, 5)); ?>"
                                data-set-tracking_interval_min="<?php echo (int)($row['tracking_interval_min'] ?: 10); ?>"
                                data-set-late_grace_min="<?php echo (int)($row['late_grace_min'] ?? 15); ?>"
                                data-set-max_overtime_hours="<?php echo (int)($row['max_overtime_hours'] ?? 6); ?>"
                                data-set-home_lat="<?php echo e($row['home_lat'] ?? ''); ?>"
                                data-set-home_lng="<?php echo e($row['home_lng'] ?? ''); ?>"
                                data-set-home_radius_m="<?php echo (int)($row['home_radius_m'] ?? 150); ?>"
                                data-set-home_label="<?php echo e($row['home_label'] ?? ''); ?>"
                                data-set-max_accuracy_m="<?php echo (int)($row['max_accuracy_m'] ?: 50); ?>"
                                data-set-require_photo="<?php echo (int)$row['require_photo']; ?>"
                                data-set-enforce_geofence="<?php echo (int)$row['enforce_geofence']; ?>"
                                data-set-enforce_geofence_checkout="<?php echo (int)($row['enforce_geofence_checkout'] ?? 0); ?>"
                                data-set-allow_mock_location="<?php echo (int)$row['allow_mock_location']; ?>"
                                data-set-sessions_per_day="<?php echo (int)($row['sessions_per_day'] ?? 1); ?>"
                                data-set-shift2_start="<?php echo e(!empty($row['shift2_start']) ? substr($row['shift2_start'], 0, 5) : ''); ?>"
                                data-set-shift2_end="<?php echo e(!empty($row['shift2_end']) ? substr($row['shift2_end'], 0, 5) : ''); ?>"
                                data-days="<?php echo e($row['work_days'] ?? '1,2,3,4,5,6'); ?>">Rules</button>
                        <button class="btn ghost small" type="button"
                                data-modal-open="passwordModal"
                                data-subject="<?php echo e($row['name']); ?>"
                                data-set-att_employee_id="<?php echo (int)$row['id']; ?>">Password</button>
                        <?php if (!empty($row['security_blocked_at'])): ?>
                        <button class="btn small" type="button"
                                data-modal-open="unblockModal"
                                data-subject="<?php echo e($row['name'] . ' — ' . ($row['security_block_reason'] ?: 'security check')); ?>"
                                data-set-att_employee_id="<?php echo (int)$row['id']; ?>">Unblock</button>
                        <?php endif; ?>
                        <?php if ($row['device_id']): ?>
                        <button class="btn danger small" type="button"
                                data-modal-open="deviceModal"
                                data-subject="<?php echo e($row['name'] . ' — ' . trim($row['device_brand'] . ' ' . $row['device_model'])); ?>"
                                data-set-att_employee_id="<?php echo (int)$row['id']; ?>">Reset device</button>
                        <?php endif; ?>
                        <form method="post" style="display:inline"
                              data-confirm="<?php echo (int)$row['is_active'] === 1
                                  ? 'Disable ' . e($row['name']) . '? They will be signed out of the app immediately.'
                                  : 'Enable ' . e($row['name']) . '?'; ?>">
                            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="att_employee_id" value="<?php echo (int)$row['id']; ?>">
                            <button class="btn ghost small" type="submit"><?php
                                echo (int)$row['is_active'] === 1 ? 'Disable' : 'Enable'; ?></button>
                        </form>
                        <button class="btn danger small" type="button"
                                data-modal-open="deleteAccountModal"
                                data-subject="<?php echo e($row['name'] . ' (' . $row['employee_code'] . ')'); ?>"
                                data-set-att_employee_id="<?php echo (int)$row['id']; ?>"
                                data-code="<?php echo e($row['employee_code']); ?>"
                                data-counts="<?php
                                    // Counted per row so the dialog can name what goes,
                                    // rather than making the admin guess.
                                    $f = attAccountFootprint((int)$row['id']);
                                    $parts = [];
                                    foreach ($f as $label => $n) { if ($n > 0) { $parts[] = number_format($n) . ' ' . $label; } }
                                    echo e($parts ? implode(' · ', $parts) : 'no recorded activity yet');
                                ?>">Delete</button>
                        <?php else: ?>
                            <span style="color:var(--ink-soft);font-size:12px">view only</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($attCanManage): ?>

<!-- Manual Add Employee -->
<div class="modal-backdrop" id="manualAddModal">
    <div class="modal" style="max-width:560px">
        <form method="post">
            <div class="modal-head">
                <h3>Add New Employee</h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body" style="max-height:calc(85vh - 120px);overflow-y:auto">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="manual_add">

                <div style="padding:10px 14px;background:#F0FDFA;border:1px solid #CCFBF1;border-radius:8px;font-size:12.5px;color:#0F766E;margin-bottom:14px;line-height:1.4">
                    Creates an employee record in HR and activates their attendance account instantly. They can sign straight into the mobile app with their login code and password.
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="m_fname">First Name <span class="required">*</span></label>
                        <input type="text" id="m_fname" name="first_name" required placeholder="e.g. Ahmed">
                    </div>
                    <div class="field">
                        <label for="m_lname">Last Name</label>
                        <input type="text" id="m_lname" name="last_name" placeholder="e.g. Khan">
                    </div>
                </div>

                <div class="field">
                    <label for="m_position">Category / Designation <span class="required">*</span></label>
                    <select id="m_position" name="position" required>
                        <optgroup label="Technical &amp; Maintenance">
                            <option value="Mechanic">Mechanic</option>
                            <option value="Technician">Technician</option>
                            <option value="Electrician">Electrician</option>
                        </optgroup>
                        <optgroup label="Other Standard Categories">
                            <?php foreach ($designations as $des): ?>
                                <?php if (!in_array($des['designation_name'], ['Mechanic', 'Technician', 'Electrician'], true)): ?>
                                    <option value="<?php echo e($des['designation_name']); ?>"><?php echo e($des['designation_name']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <option value="__custom__" style="font-weight:600;color:#0F766E">+ Add Custom Category (Type below)...</option>
                    </select>
                    <div id="m_custom_position_wrap" style="display:none;margin-top:8px">
                        <input type="text" id="m_custom_position" name="custom_position" placeholder="Type custom category (e.g. Master Mechanic, Welder, Plumber)">
                        <div class="help">This custom category will be saved to designations for future employees.</div>
                    </div>
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="m_dept">Department</label>
                        <select id="m_dept" name="department_id">
                            <option value="">None / General</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo (int)$dept['id']; ?>" <?php echo strtolower($dept['department_name']) === 'operations' ? 'selected' : ''; ?>>
                                    <?php echo e($dept['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="m_phone">Phone / Mobile</label>
                        <input type="tel" id="m_phone" name="phone" placeholder="e.g. 55551234">
                    </div>
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="m_emp_code">Employee Code <span class="required">*</span></label>
                        <input type="text" id="m_emp_code" name="employee_code" value="<?php echo e($nextCode); ?>" required placeholder="e.g. 0016">
                    </div>
                    <div class="field">
                        <label for="m_login_code">Login Code <span class="opt">(app username)</span></label>
                        <input type="text" id="m_login_code" name="login_code" value="<?php echo e($nextCode); ?>" required placeholder="e.g. 0016">
                    </div>
                </div>

                <div class="field">
                    <label for="m_password">App Password <span class="required">*</span></label>
                    <div style="display:flex;gap:8px">
                        <input type="text" id="m_password" name="password" required minlength="6" value="123456">
                        <button class="btn ghost" type="button" data-generate-password="#m_password">Generate</button>
                    </div>
                    <div class="help">Give this password to the employee to sign in on their phone.</div>
                </div>

                <div class="check" style="margin-bottom:14px">
                    <input type="checkbox" id="m_must_change" name="must_change_password" value="1">
                    <div>
                        <label for="m_must_change">Require password change on first sign in</label>
                        <div class="help">Leave unchecked for immediate, frictionless sign in with the assigned password.</div>
                    </div>
                </div>

                <div class="field">
                    <label for="m_geofence">Primary Work Area</label>
                    <select id="m_geofence" name="geofence_id">
                        <option value="">Assign later / None</option>
                        <?php foreach ($geofences as $fence): ?>
                            <option value="<?php echo (int)$fence['id']; ?>"><?php echo e($fence['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="m_start">Shift Start</label>
                        <input type="time" id="m_start" name="shift_start" value="08:00">
                    </div>
                    <div class="field">
                        <label for="m_end">Shift End</label>
                        <input type="time" id="m_end" name="shift_end" value="17:00">
                    </div>
                </div>

                <div class="check">
                    <input type="checkbox" id="m_photo" name="require_photo" value="1" checked>
                    <div>
                        <label for="m_photo">Selfie compulsory at check-in / check-out</label>
                    </div>
                </div>

                <div class="check">
                    <input type="checkbox" id="m_enforce" name="enforce_geofence" value="1">
                    <div>
                        <label for="m_enforce">Block check-in outside assigned work area</label>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn" type="submit" style="background:#0F766E;border-color:#0F766E;color:#fff;font-weight:600">Create &amp; Enrol Employee</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Employee Details -->
<div class="modal-backdrop" id="editEmployeeModal">
    <div class="modal" style="max-width:520px">
        <form method="post">
            <div class="modal-head">
                <h3>Edit Employee — <span data-modal-subject></span></h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="edit_employee">
                <input type="hidden" name="att_employee_id">

                <div class="grid">
                    <div class="field">
                        <label for="ed_fname">First Name <span class="required">*</span></label>
                        <input type="text" id="ed_fname" name="first_name" required>
                    </div>
                    <div class="field">
                        <label for="ed_lname">Last Name</label>
                        <input type="text" id="ed_lname" name="last_name">
                    </div>
                </div>

                <div class="field">
                    <label for="ed_position">Category / Designation <span class="required">*</span></label>
                    <select id="ed_position" name="position" required>
                        <optgroup label="Technical &amp; Maintenance">
                            <option value="Mechanic">Mechanic</option>
                            <option value="Technician">Technician</option>
                            <option value="Electrician">Electrician</option>
                        </optgroup>
                        <optgroup label="Other Categories">
                            <?php foreach ($designations as $des): ?>
                                <?php if (!in_array($des['designation_name'], ['Mechanic', 'Technician', 'Electrician'], true)): ?>
                                    <option value="<?php echo e($des['designation_name']); ?>"><?php echo e($des['designation_name']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <option value="__custom__" style="font-weight:600;color:#0F766E">+ Custom Category (Type below)...</option>
                    </select>
                    <div id="ed_custom_position_wrap" style="display:none;margin-top:8px">
                        <input type="text" id="ed_custom_position" name="custom_position" placeholder="Type custom category">
                    </div>
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="ed_dept">Department</label>
                        <select id="ed_dept" name="department_id">
                            <option value="">None / General</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo (int)$dept['id']; ?>"><?php echo e($dept['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="ed_phone">Phone / Mobile</label>
                        <input type="tel" id="ed_phone" name="phone">
                    </div>
                </div>

                <div class="field">
                    <label for="ed_login_code">Login Code <span class="required">*</span></label>
                    <input type="text" id="ed_login_code" name="login_code" required>
                    <div class="help">The code the employee types into the app to sign in.</div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn" type="submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Enrol -->
<div class="modal-backdrop" id="enrolModal">
    <div class="modal">
        <form method="post">
            <div class="modal-head">
                <h3>Enrol an employee</h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="enrol">

                <div class="field">
                    <label for="e_employee">Employee</label>
                    <select id="e_employee" name="employee_id" required>
                        <option value="">Select from HR records…</option>
                        <?php foreach ($unenrolled as $person): ?>
                            <option value="<?php echo (int)$person['id']; ?>">
                                <?php echo e($person['name'] . ' (' . $person['employee_code'] . ')');
                                echo $person['department_name'] ? ' — ' . e($person['department_name']) : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Only employees without an app account are listed.</div>
                </div>

                <div class="field">
                    <label for="e_login">Login code <span class="opt">(leave blank to use the employee code)</span></label>
                    <input type="text" id="e_login" name="login_code" placeholder="e.g. 003">
                </div>

                <div class="field">
                    <label for="e_password">Temporary password</label>
                    <div style="display:flex;gap:8px">
                        <input type="text" id="e_password" name="password" required minlength="6">
                        <button class="btn ghost" type="button" data-generate-password="#e_password">Generate</button>
                    </div>
                    <div class="help">The employee is asked to change this the first time they sign in.</div>
                </div>

                <div class="field">
                    <label for="e_geofence">Work area</label>
                    <select id="e_geofence" name="geofence_id">
                        <option value="">Assign later</option>
                        <?php foreach ($geofences as $fence): ?>
                            <option value="<?php echo (int)$fence['id']; ?>"><?php echo e($fence['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn" type="submit">Create account</button>
            </div>
        </form>
    </div>
</div>

<!-- Rules -->
<div class="modal-backdrop" id="rulesModal">
    <div class="modal">
        <form method="post">
            <div class="modal-head">
                <h3>Attendance rules — <span data-modal-subject></span></h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="att_employee_id">

                <div class="field">
                    <label for="r_geofence">Primary work area</label>
                    <select id="r_geofence" name="geofence_id">
                        <option value="">None — no geofence checks</option>
                        <?php foreach ($geofences as $fence): ?>
                            <option value="<?php echo (int)$fence['id']; ?>"><?php echo e($fence['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Their base. Shown against them in the register and on the map.</div>
                </div>

                <div class="field">
                    <label>Also counts as at work in</label>
                    <?php /* Multi-office: someone assigned to two sites is inside their
                             work area at either, instead of being asked to justify being
                             at their own second office. The primary area is always
                             included, so it does not need ticking here. */ ?>
                    <div id="r_extra_areas" style="display:flex;flex-wrap:wrap;gap:12px">
                        <?php if (!$geofences): ?>
                            <span class="muted" style="font-size:12.5px">No work areas defined yet.</span>
                        <?php endif; ?>
                        <?php foreach ($geofences as $fence): ?>
                            <label style="font-weight:500;display:flex;gap:5px;align-items:center;margin:0">
                                <input type="checkbox" name="extra_geofence_ids[]"
                                       value="<?php echo (int)$fence['id']; ?>"
                                       style="width:15px;height:15px">
                                <?php echo e($fence['name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="help">Tick every other site where this person legitimately
                        works. They will not be warned or asked for a reason at any of them.</div>
                </div>

                <div class="field">
                    <label for="r_sessions">Shifts per day</label>
                    <select id="r_sessions" name="sessions_per_day">
                        <option value="1">One — checks in and out once</option>
                        <option value="2">Two — a split shift, in and out twice</option>
                    </select>
                    <div class="help">Part-time monitors typically work a morning run and an
                        afternoon run, with hours off in between. Their location is not
                        recorded during the gap.</div>
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="r_start"><span class="shift-label">Shift</span> start</label>
                        <input type="time" id="r_start" name="shift_start" required>
                    </div>
                    <div class="field">
                        <label for="r_end"><span class="shift-label">Shift</span> end</label>
                        <input type="time" id="r_end" name="shift_end" required>
                        <div class="help">An end time before the start is treated as an overnight shift.</div>
                    </div>
                </div>

                <div class="grid" id="secondShiftRow" style="display:none">
                    <div class="field">
                        <label for="r_start2">Second shift start</label>
                        <input type="time" id="r_start2" name="shift2_start">
                    </div>
                    <div class="field">
                        <label for="r_end2">Second shift end</label>
                        <input type="time" id="r_end2" name="shift2_end">
                        <div class="help">Lateness and overtime for the afternoon are measured
                            against these, not the morning's. Leave both blank to use the
                            first shift's hours for both.</div>
                    </div>
                </div>

                <div class="field">
                    <label>Working days</label>
                    <div style="display:flex;flex-wrap:wrap;gap:12px">
                        <?php foreach ($dayNames as $number => $label): ?>
                            <label style="font-weight:500;display:flex;gap:5px;align-items:center;margin:0">
                                <input type="checkbox" name="work_days[]" value="<?php echo $number; ?>"
                                       class="day-check" style="width:15px;height:15px">
                                <?php echo $label; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="r_interval">Location ping every (minutes)</label>
                        <input type="number" id="r_interval" name="tracking_interval_min" min="1" max="120" required>
                        <div class="help">5 or 10 is typical. Shorter drains the battery faster.</div>
                    </div>
                    <div class="field">
                        <label for="r_grace">Late grace (minutes)</label>
                        <input type="number" id="r_grace" name="late_grace_min" min="0" max="240">
                    </div>
                    <div class="field">
                        <label for="r_overtime">Overtime allowance (hours)</label>
                        <input type="number" id="r_overtime" name="max_overtime_hours" min="0" max="18">
                        <div class="help">How long after the shift ends they can still check out.
                            Past this the day is flagged as having no check-out and the next shift
                            can begin. 0 closes the shift on time.</div>
                    </div>
                    <div class="field">
                        <label for="r_accuracy">Required GPS accuracy (m)</label>
                        <input type="number" id="r_accuracy" name="max_accuracy_m" min="10" max="2000">
                        <div class="help">Check-in is refused when the fix is worse than this.</div>
                    </div>
                </div>

                <div class="check">
                    <input type="checkbox" id="r_photo" name="require_photo" value="1">
                    <div>
                        <label for="r_photo">Photo compulsory at check-in and check-out</label>
                        <div class="help">Camera only — the app does not allow choosing from the gallery.</div>
                    </div>
                </div>
                <div class="check">
                    <input type="checkbox" id="r_enforce" name="enforce_geofence" value="1">
                    <div>
                        <label for="r_enforce">Block check-in outside the work area</label>
                        <div class="help">When off, an outside check-in is allowed but flagged for review.</div>
                    </div>
                </div>
                <?php /* Office-only. Never sent to the app — see the API note in
                         app_config.php. Kept next to the work areas because it is the
                         same kind of setting, but its purpose is quite different: it
                         answers "did they go home mid-shift", from route points that
                         already exist rather than by collecting anything new. */ ?>
                <div class="field">
                    <label>Home location <span class="badge muted">office only</span></label>
                    <div class="grid">
                        <div class="field">
                            <label for="r_hlat">Latitude</label>
                            <input type="text" id="r_hlat" name="home_lat" placeholder="25.2854">
                        </div>
                        <div class="field">
                            <label for="r_hlng">Longitude</label>
                            <input type="text" id="r_hlng" name="home_lng" placeholder="51.5310">
                        </div>
                        <div class="field">
                            <label for="r_hrad">Radius (m)</label>
                            <input type="number" id="r_hrad" name="home_radius_m" min="30" max="2000"
                                   placeholder="150">
                        </div>
                    </div>
                    <div class="field">
                        <label for="r_hlabel">Note</label>
                        <input type="text" id="r_hlabel" name="home_label" maxlength="150"
                               placeholder="e.g. Al Sadd flat">
                    </div>
                    <div class="help">
                        Leave the coordinates blank to record no home address, or to remove one.
                        Only route points from <strong>within the shift</strong> are ever compared
                        against it — nothing outside working hours is examined.
                        <strong>The employee's app never receives this.</strong>
                    </div>
                </div>

                <div class="check">
                    <input type="checkbox" id="r_enforce_out" name="enforce_geofence_checkout" value="1">
                    <div>
                        <label for="r_enforce_out">Block check-out outside the work area</label>
                        <div class="help">Usually left off. When off, they can clock off from anywhere
                            and the position is flagged for review — better than leaving a shift open
                            because someone finished away from site.</div>
                    </div>
                </div>
                <div class="check">
                    <input type="checkbox" id="r_mock" name="allow_mock_location" value="1">
                    <div>
                        <label for="r_mock">Accept mock locations</label>
                        <div class="help">Leave off in normal use. Only for testing with a simulated GPS.</div>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn" type="submit">Save rules</button>
            </div>
        </form>
    </div>
</div>

<!-- Password -->
<div class="modal-backdrop" id="passwordModal">
    <div class="modal" style="max-width:440px">
        <form method="post">
            <div class="modal-head">
                <h3>Reset password — <span data-modal-subject></span></h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="att_employee_id">
                <div class="field">
                    <label for="p_password">New temporary password</label>
                    <div style="display:flex;gap:8px">
                        <input type="text" id="p_password" name="password" required minlength="6">
                        <button class="btn ghost" type="button" data-generate-password="#p_password">Generate</button>
                    </div>
                    <div class="help">The employee must change it after signing in. Their device stays linked.</div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn" type="submit">Reset password</button>
            </div>
        </form>
    </div>
</div>

<!-- Lifting a security block -->
<div class="modal-backdrop" id="unblockModal">
    <div class="modal" style="max-width:520px">
        <form method="post">
            <div class="modal-head">
                <h3>Unblock account</h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="security_unblock">
                <input type="hidden" name="att_employee_id">
                <p style="margin:0 0 14px"><strong data-modal-subject></strong></p>
                <p style="margin:0 0 16px;color:var(--ink-soft);font-size:13px">
                    This account was blocked automatically — either a simulated position was
                    reported, or it moved further between two fixes than anybody could travel.
                    Find out what happened before lifting it: a genuine cause is worth knowing
                    about, and a phone with a fake-GPS app still installed will simply be
                    blocked again.
                </p>
                <div class="field">
                    <label for="u_note">What happened, and why the block is being lifted</label>
                    <input type="text" id="u_note" name="note" required maxlength="500"
                           placeholder="e.g. tested with a mock-location app during setup, app removed">
                    <div class="help">Required, and kept in the audit log with your name against
                        it. An account unblocked with no reason recorded looks exactly like one
                        that was never blocked.</div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn" type="submit">Lift the block</button>
            </div>
        </form>
    </div>
</div>

<!-- Device reset -->
<div class="modal-backdrop" id="deviceModal">
    <div class="modal" style="max-width:480px">
        <form method="post">
            <div class="modal-head">
                <h3>Reset device</h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="reset_device">
                <input type="hidden" name="att_employee_id">
                <p style="margin:0 0 14px"><strong data-modal-subject></strong></p>
                <p style="margin:0 0 16px;color:var(--ink-soft);font-size:13px">
                    The linked handset will be signed out immediately, and the employee will be able to
                    sign in on one new device. Do this only when the phone was genuinely replaced, lost
                    or reset — it is the control that stops attendance being marked from someone else's phone.
                </p>
                <div class="field">
                    <label for="d_reason">Reason (recorded in the audit log)</label>
                    <input type="text" id="d_reason" name="reason" required
                           placeholder="e.g. phone replaced, old handset damaged">
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn danger" type="submit">Release device</button>
            </div>
        </form>
    </div>
</div>

<script>
// The working-day boxes are a set, not a single value, so the generic data-set-*
// prefill in admin.js cannot handle them.
document.querySelector('[data-modal-open="rulesModal"]') && document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-modal-open="rulesModal"]');
    if (!trigger) return;
    var days = (trigger.getAttribute('data-days') || '').split(',');
    document.querySelectorAll('#rulesModal .day-check').forEach(function (box) {
        box.checked = days.indexOf(box.value) !== -1;
    });

    // Work areas are a set too. The primary area is ticked and disabled rather than
    // hidden: it *is* one of their areas, and a box that silently excludes it would
    // suggest the base site somehow does not count.
    var areas = (trigger.getAttribute('data-areas') || '').split(',');
    var primary = trigger.getAttribute('data-set-geofence_id') || '';
    syncAreaBoxes(areas, primary);
});

/** Tick the assigned areas, and lock whichever one is primary. */
function syncAreaBoxes(areas, primary) {
    document.querySelectorAll('#r_extra_areas input[type=checkbox]').forEach(function (box) {
        var isPrimary = box.value === String(primary);
        box.checked = isPrimary || areas.indexOf(box.value) !== -1;
        box.disabled = isPrimary;
        box.parentElement.style.opacity = isPrimary ? '0.6' : '';
        box.parentElement.title = isPrimary ? 'Primary area — always included' : '';
    });
}

// Changing the primary area has to move the lock with it, or the previous primary
// stays disabled and the new one can be unticked.
document.getElementById('r_geofence') && document.getElementById('r_geofence')
    .addEventListener('change', function () {
        var ticked = [];
        document.querySelectorAll('#r_extra_areas input[type=checkbox]').forEach(function (box) {
            if (box.checked) ticked.push(box.value);
        });
        syncAreaBoxes(ticked, this.value);
    });
</script>
<?php endif; ?>

<script>
(function () {
    var sessions = document.getElementById('r_sessions');
    var row = document.getElementById('secondShiftRow');
    if (!sessions || !row) return;

    function refresh() {
        var split = sessions.value === '2';
        row.style.display = split ? '' : 'none';
        // "Shift start" is ambiguous once there are two of them.
        Array.prototype.forEach.call(document.querySelectorAll('.shift-label'), function (el) {
            el.textContent = split ? 'First shift' : 'Shift';
        });
    }

    sessions.addEventListener('change', refresh);
    refresh();

    // The rules drawer is filled in by JS when a row is opened, so the visibility has to
    // be re-evaluated then too — otherwise a split-shift employee opens with their second
    // pair populated but hidden.
    document.addEventListener('att:rules-loaded', refresh);
})();
</script>

<div class="modal-backdrop" id="deleteAccountModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Remove from attendance</h3>
            <button class="modal-x" type="button" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
            <input type="hidden" name="action" value="delete_account">
            <input type="hidden" name="att_employee_id" id="del_att_employee_id">
            <div class="modal-body">
                <p style="font-size:13.5px"><strong data-modal-subject></strong></p>

                <div style="padding:12px 13px;background:#FDF2DC;border:1px solid #F0D9A8;border-radius:10px;font-size:12.5px;line-height:1.5">
                    <strong>This deletes, permanently:</strong>
                    <div id="del_counts" style="margin-top:5px;color:#92400E"></div>
                    <div style="margin-top:8px">
                        Their sign-in, device binding, attendance days, routes, outside trips,
                        check-in photos and log entries all go. It cannot be undone.
                    </div>
                </div>

                <div style="padding:12px 13px;background:#E8F6EE;border:1px solid #B7E2C7;border-radius:10px;font-size:12.5px;margin-top:10px;line-height:1.5">
                    <strong>What is kept:</strong> their record in the main SSTQA software is
                    untouched — they stay an employee and you can add them here again straight
                    away with a clean slate.
                </div>

                <div class="field" style="margin-top:14px">
                    <label for="del_reason">Reason <span class="required">*</span></label>
                    <input type="text" id="del_reason" name="reason" required
                           placeholder="e.g. starting fresh after a device problem">
                    <div class="help">Kept on the one log entry that survives the deletion.</div>
                </div>

                <div class="field">
                    <label for="del_confirm">Type the employee code <code id="del_code_hint"></code> to confirm</label>
                    <input type="text" id="del_confirm" name="confirm_code" required autocomplete="off">
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn danger" type="submit">Delete account</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // The counts and the expected code live on the button that opened the dialog; the
    // generic modal filler only copies data-set-* into fields, so these are read here.
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-modal-open="deleteAccountModal"]');
        if (!trigger) return;
        var counts = document.getElementById('del_counts');
        var hint = document.getElementById('del_code_hint');
        var typed = document.getElementById('del_confirm');
        if (counts) counts.textContent = trigger.dataset.counts || '';
        if (hint) hint.textContent = trigger.dataset.code || '';
        // Never prefilled — typing it is the whole point of the check.
        if (typed) typed.value = '';
    });
})();

(function () {
    // 1. Manual Add Employee - Custom Category toggle
    var mPos = document.getElementById('m_position');
    var mWrap = document.getElementById('m_custom_position_wrap');
    var mCustomInput = document.getElementById('m_custom_position');
    if (mPos && mWrap && mCustomInput) {
        mPos.addEventListener('change', function () {
            var isCustom = this.value === '__custom__';
            mWrap.style.display = isCustom ? 'block' : 'none';
            mCustomInput.required = isCustom;
            if (isCustom) mCustomInput.focus();
        });
    }

    // Auto-sync employee code to login code in manual add modal
    var empCodeInput = document.getElementById('m_emp_code');
    var loginCodeInput = document.getElementById('m_login_code');
    if (empCodeInput && loginCodeInput) {
        var lastAuto = empCodeInput.value;
        empCodeInput.addEventListener('input', function () {
            if (loginCodeInput.value === '' || loginCodeInput.value === lastAuto) {
                loginCodeInput.value = this.value;
            }
            lastAuto = this.value;
        });
    }

    // 2. Edit Employee - Custom Category toggle & prefill
    var edPos = document.getElementById('ed_position');
    var edWrap = document.getElementById('ed_custom_position_wrap');
    var edCustomInput = document.getElementById('ed_custom_position');
    if (edPos && edWrap && edCustomInput) {
        edPos.addEventListener('change', function () {
            var isCustom = this.value === '__custom__';
            edWrap.style.display = isCustom ? 'block' : 'none';
            edCustomInput.required = isCustom;
            if (isCustom) edCustomInput.focus();
        });

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-modal-open="editEmployeeModal"]');
            if (!trigger) return;
            var pos = trigger.dataset.setPosition || '';
            // Check if pos matches an existing option
            var matched = false;
            for (var i = 0; i < edPos.options.length; i++) {
                if (edPos.options[i].value.toLowerCase() === pos.toLowerCase()) {
                    edPos.selectedIndex = i;
                    matched = true;
                    break;
                }
            }
            if (matched) {
                edWrap.style.display = 'none';
                edCustomInput.required = false;
                edCustomInput.value = '';
            } else if (pos !== '') {
                edPos.value = '__custom__';
                edWrap.style.display = 'block';
                edCustomInput.required = true;
                edCustomInput.value = pos;
            } else {
                edWrap.style.display = 'none';
                edCustomInput.required = false;
                edCustomInput.value = '';
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/layout_bottom.php'; ?>
