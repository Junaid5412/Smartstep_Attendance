<?php
/**
 * The attendance register for one date, with photo verification and CSV export.
 */

require_once __DIR__ . '/bootstrap.php';

if (($_POST['action'] ?? null) === 'mark_leave') {
    attCheckCsrf();
    attRequireManage();

    $result = attMarkLeave(
        (int)($_POST['att_employee_id'] ?? 0),
        $_POST['from_date'] ?? '',
        $_POST['to_date'] ?? '',
        $_POST['leave_status'] ?? 'leave',
        trim($_POST['note'] ?? ''),
        (int)$attAdmin['id']
    );

    if (!empty($result['error'])) {
        attFlash('error', $result['error']);
    } else {
        $message = $result['marked'] . ' day(s) marked as ' .
            ($_POST['leave_status'] === 'holiday' ? 'holiday' : 'leave') . '.';
        if ($result['skipped'] > 0) {
            // Named explicitly: silently skipping days would leave the admin believing
            // a range was fully applied when part of it was already worked.
            $message .= ' ' . $result['skipped'] . ' day(s) left untouched because they ' .
                'were already checked in to (' . implode(', ', $result['skipped_dates']) . ').';
        }
        attFlash($result['marked'] > 0 ? 'success' : 'error', $message);
    }
    attRedirect('register.php?' . http_build_query(array_intersect_key(
        $_POST, array_flip(['date', 'geofence_id', 'project', 'status', 'q', 'type'])
    )));
}

if (($_POST['action'] ?? null) === 'delete') {
    attCheckCsrf();
    attRequireManage();

    $ids = array_filter(array_map('intval', (array)($_POST['attendance_id'] ?? [])));
    $reason = trim($_POST['reason'] ?? '');
    $alsoRoute = !empty($_POST['also_route']);
    $returnTo = 'register.php?' . http_build_query(array_intersect_key(
        $_POST, array_flip(['date', 'geofence_id', 'project', 'status', 'q', 'type'])
    ));

    if (!$ids) {
        attFlash('error', 'No attendance record was selected.');
        attRedirect($returnTo);
    }
    if ($reason === '') {
        attFlash('error', 'Give a reason for the deletion — it is recorded in the audit log.');
        attRedirect($returnTo);
    }

    $deleted = 0;
    $photos = 0;
    $points = 0;
    $trips = 0;
    $failures = [];

    foreach ($ids as $id) {
        $result = attDeleteAttendance($id, $alsoRoute, $reason, (int)$attAdmin['id']);
        if ($result['ok']) {
            $deleted++;
            $photos += $result['photos'];
            $points += $result['points'];
            $trips += $result['trips'];
        } else {
            $failures[] = $result['message'];
        }
    }

    if ($deleted > 0) {
        $summary = $deleted . ' attendance record' . ($deleted === 1 ? '' : 's') . ' deleted';
        if ($photos > 0) $summary .= ', ' . $photos . ' photo' . ($photos === 1 ? '' : 's') . ' removed';
        if ($points > 0) $summary .= ', ' . number_format($points) . ' route points removed';
        if ($trips > 0) $summary .= ', ' . $trips . ' trip record' . ($trips === 1 ? '' : 's') . ' removed';
        attFlash($failures ? 'info' : 'success', $summary . '.' .
            ($failures ? ' Some failed: ' . implode(' ', $failures) : ''));
    } else {
        attFlash('error', 'Nothing was deleted. ' . implode(' ', $failures));
    }

    attRedirect($returnTo);
}

$workDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    $workDate = date('Y-m-d');
}

$filters = [
    'geofence_id' => $_GET['geofence_id'] ?? null,
    'status' => $_GET['status'] ?? null,
    'search' => trim($_GET['q'] ?? '') ?: null,
];

// Employees vs monitors vs drivers tabs. Anything unrecognised means all, so
// old bookmarked URLs keep showing everybody.
$type = $_GET['type'] ?? 'all';
if (!in_array($type, ['all', 'employee', 'monitor', 'driver'], true)) {
    $type = 'all';
}
if ($type !== 'all') {
    $filters['person_type'] = $type;
}

// One school at a time. Employees belong to no project, so picking one hides
// them — said on the dropdown rather than discovered by surprise.
$projectFilter = (int)($_GET['project'] ?? 0) ?: null;
if ($projectFilter) {
    $filters['project_id'] = $projectFilter;
}
$registerProjects = attDB()->query("
    SELECT DISTINCT p.id, p.project_name
    FROM projects p
    JOIN att_person emp ON emp.project_id = p.id
    JOIN att_employees e ON e.person_type = emp.person_type
      AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
    WHERE e.is_active = 1
    ORDER BY p.project_name
")->fetchAll(PDO::FETCH_ASSOC);

$tabCounts = attRegisterTypeCounts($workDate, $filters);
$rows = attRegister($workDate, $filters);

// CSV is streamed before any HTML so the download is not polluted by page chrome.
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance-' . $workDate . ($type !== 'all' ? '-' . $type : '') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee code', 'Name', 'Type', 'Department / Project', 'Work area', 'Shift',
                   'Check in', 'In area', 'Check out', 'Out area',
                   'Worked (min)', 'Outside (min)', 'Late (min)', 'Overtime (min)',
                   'Trips outside', 'Status']);

    foreach ($rows as $row) {
        $isSplit = (int)($row['sessions_per_day'] ?? 1) > 1;
        if ($isSplit) {
            $s1Shift = $row['shift_start'] ? substr($row['shift_start'], 0, 5) . '-' . substr($row['shift_end'], 0, 5) : '';
            $s2Shift = !empty($row['shift2_start']) ? substr($row['shift2_start'], 0, 5) . '-' . substr($row['shift2_end'], 0, 5) : '';
            $shiftStr = trim($s1Shift . ($s2Shift ? ' + ' . $s2Shift : ''));

            $s1In = $row['sessions'][1]['check_in_at'] ?? '';
            $s2In = $row['sessions'][2]['check_in_at'] ?? '';
            $checkInStr = trim(($s1In ? 'S1: ' . $s1In : '') . ($s2In ? ($s1In ? ' | ' : '') . 'S2: ' . $s2In : ''));

            $s1InFence = isset($row['sessions'][1]['check_in_inside_fence']) && $row['sessions'][1]['check_in_inside_fence'] !== null
                ? ((int)$row['sessions'][1]['check_in_inside_fence'] === 1 ? 'yes' : 'no') : '';
            $s2InFence = isset($row['sessions'][2]['check_in_inside_fence']) && $row['sessions'][2]['check_in_inside_fence'] !== null
                ? ((int)$row['sessions'][2]['check_in_inside_fence'] === 1 ? 'yes' : 'no') : '';
            $inAreaStr = trim(($s1InFence ? 'S1: ' . $s1InFence : '') . ($s2InFence ? ($s1InFence ? ' | ' : '') . 'S2: ' . $s2InFence : ''));

            $s1Out = $row['sessions'][1]['check_out_at'] ?? '';
            $s2Out = $row['sessions'][2]['check_out_at'] ?? '';
            $checkOutStr = trim(($s1Out ? 'S1: ' . $s1Out : '') . ($s2Out ? ($s1Out ? ' | ' : '') . 'S2: ' . $s2Out : ''));

            $s1OutFence = isset($row['sessions'][1]['check_out_inside_fence']) && $row['sessions'][1]['check_out_inside_fence'] !== null
                ? ((int)$row['sessions'][1]['check_out_inside_fence'] === 1 ? 'yes' : 'no') : '';
            $s2OutFence = isset($row['sessions'][2]['check_out_inside_fence']) && $row['sessions'][2]['check_out_inside_fence'] !== null
                ? ((int)$row['sessions'][2]['check_out_inside_fence'] === 1 ? 'yes' : 'no') : '';
            $outAreaStr = trim(($s1OutFence ? 'S1: ' . $s1OutFence : '') . ($s2OutFence ? ($s1OutFence ? ' | ' : '') . 'S2: ' . $s2OutFence : ''));
        } else {
            $shiftStr = $row['shift_start'] ? substr($row['shift_start'], 0, 5) . '-' . substr($row['shift_end'], 0, 5) : '';
            $checkInStr = $row['check_in_at'];
            $inAreaStr = $row['check_in_inside_fence'] === null ? '' : ((int)$row['check_in_inside_fence'] === 1 ? 'yes' : 'no');
            $checkOutStr = $row['check_out_at'];
            $outAreaStr = $row['check_out_inside_fence'] === null ? '' : ((int)$row['check_out_inside_fence'] === 1 ? 'yes' : 'no');
        }

        fputcsv($out, [
            $row['employee_code'],
            $row['name'],
            $row['person_type'] === 'monitor' ? 'Monitor' : ($row['person_type'] === 'driver' ? 'Driver' : 'Employee'),
            $row['department_name'] ?: ($row['project_name'] ?? ''),
            $row['geofence_name'],
            $shiftStr,
            $checkInStr,
            $inAreaStr,
            $checkOutStr,
            $outAreaStr,
            $row['worked_minutes'],
            $row['outside_minutes'],
            $row['late_minutes'],
            $row['overtime_minutes'],
            $row['trips_outside'],
            attRowStatus($row),
        ]);
    }
    fclose($out);
    exit;
}

$geofences = attGeofenceList(true);

$totals = ['present' => 0, 'absent' => 0, 'off' => 0, 'worked' => 0, 'outside' => 0,
           'overtime' => 0, 'no_checkout' => 0, 'leave' => 0];
foreach ($rows as $row) {
    // Authorised leave is not an absence and must not be counted as one — that figure
    // is what a supervisor chases people about.
    if (!$row['check_in_at'] && in_array(attRowStatus($row), ['leave', 'holiday'], true)) {
        $totals['leave']++;
        continue;
    }
    if ($row['check_in_at']) {
        $totals['present']++;
        $totals['worked'] += (int)$row['worked_minutes'];
        $totals['outside'] += (int)$row['outside_minutes'];
        $totals['overtime'] += (int)$row['overtime_minutes'];
        if ($row['status'] === 'missing-checkout') {
            $totals['no_checkout']++;
        }
    } elseif (attRowStatus($row) === 'off') {
        // Scheduled rest day — counted separately so it never inflates the absence
        // figure a supervisor acts on.
        $totals['off']++;
    } else {
        $totals['absent']++;
    }
}

$pageTitle = 'Attendance Register';
$pageSubtitle = date('l, d F Y', strtotime($workDate));
include __DIR__ . '/layout_top.php';
?>

<form class="toolbar" method="get">
    <div class="field">
        <label for="date">Date</label>
        <input type="date" id="date" name="date" value="<?php echo e($workDate); ?>" max="<?php echo date('Y-m-d'); ?>" data-autosubmit>
    </div>
    <div class="field">
        <label for="geofence_id">Work area</label>
        <select id="geofence_id" name="geofence_id" data-autosubmit>
            <option value="">All areas</option>
            <?php foreach ($geofences as $fence): ?>
                <option value="<?php echo (int)$fence['id']; ?>"
                    <?php echo (string)$filters['geofence_id'] === (string)$fence['id'] ? 'selected' : ''; ?>>
                    <?php echo e($fence['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="project">Project</label>
        <select id="project" name="project" data-autosubmit>
            <option value="">All projects</option>
            <?php foreach ($registerProjects as $proj): ?>
                <option value="<?php echo (int)$proj['id']; ?>"
                    <?php echo (int)($projectFilter ?: 0) === (int)$proj['id'] ? 'selected' : ''; ?>>
                    <?php echo e($proj['project_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="help">HR employees belong to no project, so picking one hides them.</div>
    </div>
    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status" data-autosubmit>
            <option value="">All</option>
            <?php foreach (['present', 'late', 'half-day', 'incomplete', 'missing-checkout', 'absent'] as $status): ?>
                <option value="<?php echo $status; ?>" <?php echo $filters['status'] === $status ? 'selected' : ''; ?>>
                    <?php echo e(attStatusLabel($status)); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="min-width:190px">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?php echo e($filters['search']); ?>" placeholder="Name or code">
    </div>
    <button class="btn ghost" type="submit">Apply</button>
    <div class="spacer"></div>
    <a class="btn ghost" href="?<?php echo e(http_build_query(array_merge($_GET, ['date' => $workDate, 'export' => 'csv']))); ?>">Export CSV</a>
    <button type="button" class="btn ghost" onclick="window.print()">
        <svg style="width:16px;height:16px;margin-right:-2px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
        Download PDF
    </button>
</form>

<?php
// Staff tabs. Counts are accounts; the table can hold two rows for one account
// on a split-shift day, so the numbers are close, not exact.
$tabBase = array_filter([
    'date' => $workDate,
    'geofence_id' => $filters['geofence_id'] ?: null,
    'project' => $projectFilter ?: null,
    'status' => $filters['status'] ?: null,
    'q' => $filters['search'],
]);
$tabDefs = [
    'all' => 'All (' . ($tabCounts['employee'] + $tabCounts['monitor'] + $tabCounts['driver']) . ')',
    'employee' => 'Employees (' . $tabCounts['employee'] . ')',
    'monitor' => 'Monitors (' . $tabCounts['monitor'] . ')',
    'driver' => 'Drivers (' . $tabCounts['driver'] . ')',
];
?>
<div style="display:flex;gap:8px;margin:0 0 14px">
    <?php foreach ($tabDefs as $tabKey => $tabLabel): ?>
        <?php if ($tabKey === $type): ?>
            <span class="btn small" style="pointer-events:none"><?php echo $tabLabel; ?></span>
        <?php else: ?>
            <a class="btn ghost small" href="?<?php echo e(http_build_query($tabKey === 'all' ? $tabBase : array_merge($tabBase, ['type' => $tabKey]))); ?>"><?php echo $tabLabel; ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php
function attTileUrl($status) {
    global $workDate;
    return '?' . http_build_query(array_merge($_GET, ['date' => $workDate, 'status' => $status]));
}
?>
<style>
a.tile { text-decoration: none; color: inherit; display: block; transition: all 0.2s; }
a.tile:hover { border-color: var(--brand); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(31, 79, 216, 0.1); }
</style>
<div class="tiles">
    <div class="tile"><div class="label">Rows</div><div class="value"><?php echo count($rows); ?></div></div>
    
    <a href="<?php echo e(attTileUrl('present')); ?>" class="tile good"><div class="label">Present</div><div class="value"><?php echo $totals['present']; ?></div></a>
    
    <a href="<?php echo e(attTileUrl('absent')); ?>" class="tile<?php echo $totals['absent'] > 0 ? ' attention' : ''; ?>">
        <div class="label">Absent</div><div class="value"><?php echo $totals['absent']; ?></div>
        <div class="hint">excludes rostered days off</div>
    </a>
    
    <?php if ($totals['leave'] > 0): ?>
        <a href="<?php echo e(attTileUrl('leave')); ?>" class="tile"><div class="label">On leave</div><div class="value"><?php echo $totals['leave']; ?></div>
            <div class="hint">authorised — not counted absent</div></a>
    <?php endif; ?>
    
    <?php if ($totals['off'] > 0): ?>
        <a href="<?php echo e(attTileUrl('off')); ?>" class="tile"><div class="label">Day off</div><div class="value"><?php echo $totals['off']; ?></div>
            <div class="hint">not scheduled to work</div></a>
    <?php endif; ?>
    
    <div class="tile"><div class="label">Total worked</div>
        <div class="value" style="font-size:22px"><?php echo attMinutes($totals['worked']); ?></div></div>
        
    <div class="tile<?php echo $totals['outside'] > 0 ? ' alert' : ''; ?>">
        <div class="label">Total outside</div>
        <div class="value" style="font-size:22px"><?php echo attMinutes($totals['outside']); ?></div></div>
        
    <?php if ($totals['overtime'] > 0): ?>
        <div class="tile"><div class="label">Overtime</div>
            <div class="value" style="font-size:22px"><?php echo attMinutes($totals['overtime']); ?></div>
            <div class="hint">worked beyond shift length</div></div>
    <?php endif; ?>
    
    <?php if ($totals['no_checkout'] > 0): ?>
        <a href="<?php echo e(attTileUrl('missing-checkout')); ?>" class="tile attention"><div class="label">No check-out</div>
            <div class="value"><?php echo $totals['no_checkout']; ?></div>
            <div class="hint">hours need setting by hand</div></a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (!$rows): ?>
            <div class="empty">Nobody matches these filters.</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <?php if ($attCanManage): ?>
                        <th style="width:34px">
                            <input type="checkbox" id="selectAll" title="Select all records on this page">
                        </th>
                    <?php endif; ?>
                    <th><?php echo $type === 'monitor' ? 'Monitor' : ($type === 'driver' ? 'Driver' : ($type === 'employee' ? 'Employee' : 'Staff')); ?></th><th>Work area</th><th>Shift</th>
                    <th>In</th><th>Out</th><th class="num">Worked</th><th class="num">Outside</th>
                    <th class="num">Trips</th><th>At home</th><th>Status</th><th>Photos</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $isSplit = (int)($row['sessions_per_day'] ?? 1) > 1;
                $rowAttendanceIds = !empty($row['attendance_ids']) ? implode(',', $row['attendance_ids']) : ($row['attendance_id'] ? (string)$row['attendance_id'] : '');
                ?>
                <tr>
                    <?php if ($attCanManage): ?>
                        <td>
                            <?php if ($rowAttendanceIds !== ''): ?>
                                <input type="checkbox" class="row-select"
                                       value="<?php echo e($rowAttendanceIds); ?>"
                                       data-label="<?php echo e($row['name']); ?>">
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td class="who-cell">
                        <strong><?php echo e($row['name']); ?></strong><?php
                            if ($isSplit): ?>
                            <span class="badge info" style="margin-left:6px;font-size:10px">Split shift (2 runs)</span>
                        <?php endif; ?>
                        <?php
                        $rowSub = $row['department_name'] ?: ($row['project_name'] ?? '');
                        ?>
                        <small><?php echo e($row['employee_code']); ?><?php
                            echo $rowSub ? ' · ' . e($rowSub) : ''; ?></small>
                    </td>
                    <td><?php echo e($row['geofence_name'] ?? '—'); ?></td>
                    <td>
                        <?php if ($isSplit): ?>
                            <small><strong>S1:</strong> <?php echo $row['shift_start'] ? substr($row['shift_start'], 0, 5) . '–' . substr($row['shift_end'], 0, 5) : '—'; ?></small><br>
                            <small><strong>S2:</strong> <?php echo !empty($row['shift2_start']) ? substr($row['shift2_start'], 0, 5) . '–' . substr($row['shift2_end'], 0, 5) : '—'; ?></small>
                        <?php else: ?>
                            <small><?php echo $row['shift_start']
                                ? substr($row['shift_start'], 0, 5) . '–' . substr($row['shift_end'], 0, 5)
                                : '—'; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isSplit): ?>
                            <div>
                                <small class="muted">S1:</small> <?php echo attTime($row['sessions'][1]['check_in_at'] ?? null); ?>
                                <?php if ((int)($row['sessions'][1]['late_minutes'] ?? 0) > 0): ?>
                                    <small class="badge warn"><?php echo (int)$row['sessions'][1]['late_minutes']; ?>m late</small>
                                <?php endif; ?>
                                <?php if (isset($row['sessions'][1]['check_in_inside_fence']) && $row['sessions'][1]['check_in_inside_fence'] !== null && !(int)$row['sessions'][1]['check_in_inside_fence']): ?>
                                    <small class="badge bad">outside</small>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top:2px">
                                <small class="muted">S2:</small> <?php echo attTime($row['sessions'][2]['check_in_at'] ?? null); ?>
                                <?php if ((int)($row['sessions'][2]['late_minutes'] ?? 0) > 0): ?>
                                    <small class="badge warn"><?php echo (int)$row['sessions'][2]['late_minutes']; ?>m late</small>
                                <?php endif; ?>
                                <?php if (isset($row['sessions'][2]['check_in_inside_fence']) && $row['sessions'][2]['check_in_inside_fence'] !== null && !(int)$row['sessions'][2]['check_in_inside_fence']): ?>
                                    <small class="badge bad">outside</small>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php echo attTime($row['check_in_at']); ?>
                            <?php if ((int)$row['late_minutes'] > 0): ?>
                                <br><small class="badge warn"><?php echo (int)$row['late_minutes']; ?>m late</small>
                            <?php endif; ?>
                            <?php if ($row['check_in_inside_fence'] !== null && !(int)$row['check_in_inside_fence']): ?>
                                <br><small class="badge bad">outside area</small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isSplit): ?>
                            <div>
                                <small class="muted">S1:</small> <?php echo attShiftTime($row['sessions'][1]['check_out_at'] ?? null, $workDate); ?>
                                <?php if (isset($row['sessions'][1]['check_out_inside_fence']) && $row['sessions'][1]['check_out_inside_fence'] !== null && !(int)$row['sessions'][1]['check_out_inside_fence']): ?>
                                    <small class="badge bad">outside</small>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top:2px">
                                <small class="muted">S2:</small> <?php echo attShiftTime($row['sessions'][2]['check_out_at'] ?? null, $workDate); ?>
                                <?php if (isset($row['sessions'][2]['check_out_inside_fence']) && $row['sessions'][2]['check_out_inside_fence'] !== null && !(int)$row['sessions'][2]['check_out_inside_fence']): ?>
                                    <small class="badge bad">outside</small>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php echo attShiftTime($row['check_out_at'], $workDate); ?>
                            <?php if ($row['check_out_inside_fence'] !== null && !(int)$row['check_out_inside_fence']): ?>
                                <br><small class="badge bad">outside area</small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <?php echo attMinutes($row['worked_minutes']); ?>
                        <?php if ($isSplit && (int)$row['worked_minutes'] > 0): ?>
                            <br><small class="muted">S1: <?php echo attMinutes($row['sessions'][1]['worked_minutes'] ?? 0); ?> · S2: <?php echo attMinutes($row['sessions'][2]['worked_minutes'] ?? 0); ?></small>
                        <?php endif; ?>
                        <?php if ((int)$row['overtime_minutes'] > 0): ?>
                            <br><small class="badge info">+<?php echo attMinutes($row['overtime_minutes']); ?> OT</small>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?php echo (int)$row['outside_minutes'] > 0
                        ? '<span class="badge bad">' . attMinutes($row['outside_minutes']) . '</span>' : '—'; ?></td>
                    <td class="num"><?php echo (int)$row['trips_outside'] ?: '—'; ?></td>
                    <td>
                        <?php
                        // Office-only, computed from route points already stored for the
                        // shift. Nothing extra is collected and nothing is shown in the app.
                        $home = $row['check_in_at']
                            ? attHomeVisit((int)$row['att_employee_id'], $workDate, $row)
                            : ['configured' => false, 'visited' => false];
                        ?>
                        <?php if (!$home['configured']): ?>
                            <small class="muted">—</small>
                        <?php elseif ($home['visited']): ?>
                            <span class="badge warn" title="<?php echo e($row['home_label'] ?: 'home'); ?>">at home</span>
                            <br><small><?php echo attTime($home['first_at']); ?><?php
                                echo $home['minutes'] > 0 ? ' · ' . attMinutes($home['minutes']) : ''; ?></small>
                        <?php else: ?>
                            <span class="badge ok">no</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $status = attRowStatus($row); ?>
                        <span class="badge <?php echo attStatusClass($status); ?>"><?php echo e(attStatusLabel($status)); ?></span>
                        <?php if ($status === 'missing-checkout'): ?>
                            <br><small class="muted">never checked out — hours need setting by hand</small>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ($isSplit): ?>
                            <?php
                            $photosS1 = array_filter(['In' => $row['sessions'][1]['check_in_photo'] ?? null, 'Out' => $row['sessions'][1]['check_out_photo'] ?? null]);
                            $photosS2 = array_filter(['In' => $row['sessions'][2]['check_in_photo'] ?? null, 'Out' => $row['sessions'][2]['check_out_photo'] ?? null]);
                            ?>
                            <?php if ($photosS1): ?>
                                <div style="display:flex;align-items:center;gap:3px;margin-bottom:2px">
                                    <small class="muted" style="font-size:10px">S1:</small>
                                    <?php foreach ($photosS1 as $lbl => $ph): ?>
                                        <img class="thumb" src="<?php echo e(attPhotoUrl($ph)); ?>" alt="S1 <?php echo $lbl; ?>" title="S1 <?php echo $lbl; ?> photo" data-zoom="<?php echo e(attPhotoUrl($ph)); ?>">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($photosS2): ?>
                                <div style="display:flex;align-items:center;gap:3px">
                                    <small class="muted" style="font-size:10px">S2:</small>
                                    <?php foreach ($photosS2 as $lbl => $ph): ?>
                                        <img class="thumb" src="<?php echo e(attPhotoUrl($ph)); ?>" alt="S2 <?php echo $lbl; ?>" title="S2 <?php echo $lbl; ?> photo" data-zoom="<?php echo e(attPhotoUrl($ph)); ?>">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!$photosS1 && !$photosS2): ?>
                                <small class="muted">—</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php foreach (['check_in_photo' => 'In', 'check_out_photo' => 'Out'] as $col => $label): ?>
                                <?php if ($row[$col]): ?>
                                    <img class="thumb" src="<?php echo e(attPhotoUrl($row[$col])); ?>"
                                         alt="<?php echo $label; ?>" title="<?php echo $label; ?> photo"
                                         data-zoom="<?php echo e(attPhotoUrl($row[$col])); ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <a class="btn ghost small"
                           href="report.php?employee=<?php echo (int)$row['att_employee_id']; ?>&date=<?php echo e($workDate); ?>">
                            Report
                        </a>
                        <?php if ((int)$row['route_points'] > 0): ?>
                            <a class="btn ghost small"
                               href="routes.php?employee=<?php echo (int)$row['att_employee_id']; ?>&date=<?php echo e($workDate); ?>">
                                Route (<?php echo (int)$row['route_points']; ?>)
                            </a>
                        <?php endif; ?>
                        <?php if ($attCanManage && !$row['check_in_at']): ?>
                            <?php // Only offered where there is no check-in: marking leave
                                  // over a worked day would misstate measured hours. ?>
                            <button class="btn ghost small" type="button"
                                    data-modal-open="leaveModal"
                                    data-subject="<?php echo e($row['name']); ?>"
                                    data-set-att_employee_id="<?php echo (int)$row['att_employee_id']; ?>">Leave</button>
                        <?php endif; ?>
                        <?php if ($attCanManage && $rowAttendanceIds !== ''): ?>
                            <button class="btn danger small" type="button"
                                    data-modal-open="deleteModal"
                                    data-subject="<?php echo e($row['name'] . ' — ' .
                                        date('d M Y', strtotime($workDate)) . ($isSplit ? ' (both sessions)' : (' · in ' .
                                        attTime($row['check_in_at']) . ', out ' .
                                        attTime($row['check_out_at'])))); ?>"
                                    data-ids="<?php echo e($rowAttendanceIds); ?>"
                                    data-points="<?php echo (int)$row['route_points']; ?>"
                                    data-trips="<?php echo (int)$row['trips_outside']; ?>">Delete</button>
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
<!-- Appears only once something is selected, so the destructive action is never
     sitting idle next to the ordinary filters. -->
<div id="bulkBar" style="position:sticky;bottom:0;z-index:10;
     background:#fff;border:1px solid var(--line);border-radius:12px;
     box-shadow:0 -2px 14px rgba(22,35,58,.09);padding:12px 16px;margin-top:14px;
     display:none;align-items:center;gap:14px">
    <strong id="bulkCount"></strong>
    <span style="flex:1"></span>
    <button class="btn ghost small" type="button" id="bulkClear">Clear selection</button>
    <button class="btn danger small" type="button" id="bulkDelete">Delete selected</button>
</div>

<?php if ($attCanManage): ?>
<div class="modal-backdrop" id="leaveModal">
    <div class="modal" style="max-width:520px">
        <form method="post">
            <div class="modal-head">
                <h3>Mark leave</h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="mark_leave">
                <input type="hidden" name="date" value="<?php echo e($workDate); ?>">
                <input type="hidden" name="project" value="<?php echo e($projectFilter ?: ''); ?>">
                <input type="hidden" name="type" value="<?php echo e($type); ?>">
                <input type="hidden" name="att_employee_id">

                <p style="margin:0 0 14px">Recording authorised leave for
                    <strong data-modal-subject>this employee</strong>. It will not be
                    counted as an absence, and the app will not ask them for anything
                    on those days.</p>

                <div class="grid">
                    <div class="field">
                        <label for="l_from">From</label>
                        <input type="date" id="l_from" name="from_date"
                               value="<?php echo e($workDate); ?>" required>
                    </div>
                    <div class="field">
                        <label for="l_to">To</label>
                        <input type="date" id="l_to" name="to_date"
                               value="<?php echo e($workDate); ?>" required>
                        <div class="help">Same date for a single day.</div>
                    </div>
                </div>
                <div class="field">
                    <label for="l_status">Type</label>
                    <select id="l_status" name="leave_status">
                        <option value="leave">Leave</option>
                        <option value="holiday">Holiday</option>
                    </select>
                </div>
                <div class="field">
                    <label for="l_note">Note</label>
                    <input type="text" id="l_note" name="note" maxlength="500"
                           placeholder="Annual leave, sick leave, public holiday…">
                </div>
                <p class="help" style="margin:0">Days already checked in to are left
                    untouched — real recorded hours are never overwritten.</p>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn primary" type="submit">Mark leave</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal-backdrop" id="deleteModal">
    <div class="modal" style="max-width:520px">
        <form method="post" id="deleteForm">
            <div class="modal-head">
                <h3>Delete attendance record</h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="delete">
                <!-- Filters travel with the post so the redirect lands back on the
                     same view the admin was looking at. -->
                <input type="hidden" name="date" value="<?php echo e($workDate); ?>">
                <input type="hidden" name="geofence_id" value="<?php echo e($filters['geofence_id']); ?>">
                <input type="hidden" name="project" value="<?php echo e($projectFilter ?: ''); ?>">
                <input type="hidden" name="status" value="<?php echo e($filters['status']); ?>">
                <input type="hidden" name="q" value="<?php echo e($filters['search']); ?>">
                <input type="hidden" name="type" value="<?php echo e($type); ?>">
                <div id="deleteIdInputs"></div>

                <p style="margin:0 0 6px"><strong data-modal-subject></strong></p>
                <p id="deleteExtra" style="margin:0 0 16px;font-size:12.5px;color:var(--ink-soft)"></p>

                <div style="padding:13px;border-radius:11px;background:var(--bad-bg);
                            border:1px solid #f3c2c6;margin-bottom:16px">
                    <strong style="color:var(--bad);font-size:13px">This cannot be undone.</strong>
                    <p style="margin:6px 0 0;font-size:12.5px;color:var(--ink)">
                        The check-in and check-out times and their photos are deleted permanently.
                        A full copy of the record is written to the audit log first, which is the
                        only way to see afterwards what was removed.
                    </p>
                </div>

                <div class="check">
                    <input type="checkbox" id="d_route" name="also_route" value="1">
                    <div>
                        <label for="d_route">Also delete this day's route history</label>
                        <div class="help">
                            Removes the recorded GPS trail and any outside-area trips for the same
                            day. Leave unticked to keep the route for reference — it stays visible
                            on the Routes page without a check-in attached.
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label for="d_reason">Reason</label>
                    <input type="text" id="d_reason" name="reason" required
                           placeholder="e.g. test record, duplicate, recorded in error">
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn danger" type="submit">Delete permanently</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('deleteModal');
    var idBox = document.getElementById('deleteIdInputs');
    var extra = document.getElementById('deleteExtra');
    var bar = document.getElementById('bulkBar');
    var count = document.getElementById('bulkCount');

    /* Rebuild the hidden id inputs each time, so a previous selection can never
       ride along into the next deletion. */
    function setIds(ids) {
        idBox.innerHTML = '';
        ids.forEach(function (idStr) {
            String(idStr).split(',').forEach(function(id) {
                id = id.trim();
                if (!id) return;
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'attendance_id[]';
                input.value = id;
                idBox.appendChild(input);
            });
        });
    }

    function selected() {
        return [].slice.call(document.querySelectorAll('.row-select:checked'));
    }

    function syncBar() {
        var n = selected().length;
        bar.style.display = n > 0 ? 'flex' : 'none';
        count.textContent = n + ' person' + (n === 1 ? '' : 's') + ' selected';
    }

    document.querySelectorAll('.row-select').forEach(function (box) {
        box.addEventListener('change', syncBar);
    });

    var all = document.getElementById('selectAll');
    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('.row-select').forEach(function (box) {
                box.checked = all.checked;
            });
            syncBar();
        });
    }

    document.getElementById('bulkClear').addEventListener('click', function () {
        document.querySelectorAll('.row-select').forEach(function (b) { b.checked = false; });
        if (all) all.checked = false;
        syncBar();
    });

    /* Single-row delete: the generic modal opener in admin.js fills the subject,
       and this adds what that row specifically will take with it. */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-modal-open="deleteModal"]');
        if (!trigger) return;
        setIds([trigger.getAttribute('data-ids')]);
        var points = parseInt(trigger.getAttribute('data-points') || '0', 10);
        var trips = parseInt(trigger.getAttribute('data-trips') || '0', 10);
        extra.textContent = points > 0
            ? points.toLocaleString() + ' route point(s) and ' + trips +
              ' trip record(s) exist for this day.'
            : 'No route history recorded for this day.';
        document.getElementById('d_route').checked = false;
        document.getElementById('d_reason').value = '';
    });

    document.getElementById('bulkDelete').addEventListener('click', function () {
        var boxes = selected();
        if (!boxes.length) return;

        setIds(boxes.map(function (b) { return b.value; }));

        var names = boxes.slice(0, 3).map(function (b) { return b.getAttribute('data-label'); });
        modal.querySelector('[data-modal-subject]').textContent =
            boxes.length + ' record' + (boxes.length === 1 ? '' : 's') + ': ' +
            names.join(', ') + (boxes.length > 3 ? ' and ' + (boxes.length - 3) + ' more' : '');
        extra.textContent = 'All on ' + <?php echo json_encode(date('d M Y', strtotime($workDate))); ?> + '.';

        document.getElementById('d_route').checked = false;
        document.getElementById('d_reason').value = '';
        modal.classList.add('open');
        document.getElementById('d_reason').focus();
    });

    syncBar();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/layout_bottom.php'; ?>
