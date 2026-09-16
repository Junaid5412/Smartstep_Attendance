<?php
/**
 * Attendance dashboard: today at a glance, plus whatever needs the admin's
 * attention (people outside their area right now, trips awaiting review).
 */

require_once __DIR__ . '/bootstrap.php';

$workDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    $workDate = date('Y-m-d');
}

$stats = attDashboardStats($workDate);
$openTrips = attTrips(['work_date' => $workDate, 'open_only' => 1, 'limit' => 25]);
$pending = attTrips(['review_status' => 'pending', 'limit' => 12]);
$register = attRegister($workDate);

$recent = array_values(array_filter($register, function ($row) {
    return $row['check_in_at'] !== null;
}));
usort($recent, function ($a, $b) {
    return strcmp($b['check_in_at'], $a['check_in_at']);
});
$recent = array_slice($recent, 0, 10);

$noDevice = (int)attDB()->query("
    SELECT COUNT(*) FROM att_employees e
    WHERE e.is_active = 1
      AND NOT EXISTS (SELECT 1 FROM att_devices d WHERE d.att_employee_id = e.id AND d.status = 'active')
")->fetchColumn();

$noGeofence = (int)attDB()->query("
    SELECT COUNT(*) FROM att_employees e
    LEFT JOIN att_employee_settings s ON s.att_employee_id = e.id
    WHERE e.is_active = 1 AND (s.id IS NULL OR s.geofence_id IS NULL)
")->fetchColumn();

$pageTitle = 'Dashboard';
$pageSubtitle = date('l, d F Y', strtotime($workDate)) .
    ($workDate === date('Y-m-d') ? ' · today' : '');
include __DIR__ . '/layout_top.php';
?>

<form class="toolbar" method="get">
    <div class="field">
        <label for="date">Date</label>
        <input type="date" id="date" name="date" value="<?php echo e($workDate); ?>" max="<?php echo date('Y-m-d'); ?>">
    </div>
    <button class="btn ghost" type="submit">Show</button>
    <div class="spacer"></div>
    <a class="btn" href="live-map.php">Open live map</a>
</form>

<style>
a.tile { text-decoration: none; color: inherit; display: block; transition: all 0.2s; }
a.tile:hover { border-color: var(--brand); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(31, 79, 216, 0.1); }
</style>
<div class="tiles">
    <a href="employees.php" class="tile">
        <div class="label">Enrolled</div>
        <div class="value"><?php echo $stats['enrolled']; ?></div>
        <div class="hint">app accounts active</div>
    </a>
    <a href="register.php?date=<?php echo e($workDate); ?>&status=present" class="tile good">
        <div class="label">Checked in</div>
        <div class="value"><?php echo $stats['checked_in']; ?></div>
        <div class="hint"><?php echo $stats['checked_out']; ?> already checked out</div>
    </a>
    <a href="register.php?date=<?php echo e($workDate); ?>&status=absent" class="tile<?php echo $stats['not_checked_in'] > 0 ? ' attention' : ''; ?>">
        <div class="label">Not checked in</div>
        <div class="value"><?php echo $stats['not_checked_in']; ?></div>
        <div class="hint">no check-in on this date</div>
    </a>
    <a href="register.php?date=<?php echo e($workDate); ?>&status=late" class="tile<?php echo $stats['late'] > 0 ? ' attention' : ''; ?>">
        <div class="label">Late</div>
        <div class="value"><?php echo $stats['late']; ?></div>
        <div class="hint"><?php echo $stats['half_day']; ?> half-day</div>
    </a>
    <a href="trips.php?date=<?php echo e($workDate); ?>&status=outside" class="tile<?php echo $stats['currently_outside'] > 0 ? ' alert' : ''; ?>">
        <div class="label">Outside area now</div>
        <div class="value"><?php echo $stats['currently_outside']; ?></div>
        <div class="hint">still away from their fence</div>
    </a>
    <a href="trips.php?date=<?php echo e($workDate); ?>&status=pending" class="tile<?php echo $stats['pending_review'] > 0 ? ' attention' : ''; ?>">
        <div class="label">Awaiting review</div>
        <div class="value"><?php echo $stats['pending_review']; ?></div>
        <div class="hint">trips outside the area</div>
    </a>
    <div class="tile">
        <div class="label">Time outside</div>
        <div class="value"><?php echo attMinutes($stats['outside_minutes']); ?></div>
        <div class="hint">total across all staff</div>
    </div>
</div>

<?php if ($noDevice > 0 || $noGeofence > 0): ?>
<div class="card">
    <div class="card-head">
        <div>
            <h2>Setup still needed</h2>
            <p>These accounts cannot record attendance properly yet.</p>
        </div>
    </div>
    <div class="card-body">
        <?php if ($noGeofence > 0): ?>
            <p style="margin:0 0 10px">
                <span class="badge warn"><?php echo $noGeofence; ?></span>
                employee<?php echo $noGeofence === 1 ? '' : 's'; ?> have no work area assigned —
                their check-ins cannot be geofenced.
                <a href="employees.php?filter=no_geofence">Assign areas</a>
            </p>
        <?php endif; ?>
        <?php if ($noDevice > 0): ?>
            <p style="margin:0">
                <span class="badge info"><?php echo $noDevice; ?></span>
                employee<?php echo $noDevice === 1 ? '' : 's'; ?> have not signed in from a device yet.
                <a href="devices.php">View devices</a>
            </p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($openTrips): ?>
<div class="card">
    <div class="card-head">
        <div>
            <h2>Currently outside their work area</h2>
            <p>Live — these trips have not closed with a return yet.</p>
        </div>
        <a class="btn ghost small" href="trips.php?open_only=1">All open trips</a>
    </div>
    <div class="card-body tight">
        <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Employee</th><th>Work area</th><th>Left at</th>
                    <th class="num">Away</th><th class="num">Distance</th><th>Reason given</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($openTrips as $trip): ?>
                <?php $away = max(0, (int)round((time() - strtotime($trip['exit_at'])) / 60)); ?>
                <tr>
                    <td class="who-cell">
                        <strong><?php echo e($trip['name']); ?></strong>
                        <small><?php echo e($trip['employee_code']); ?></small>
                    </td>
                    <td><?php echo e($trip['geofence_name'] ?? '—'); ?></td>
                    <td><?php echo attTime($trip['exit_at']); ?></td>
                    <td class="num"><?php echo attMinutes($away); ?></td>
                    <td class="num"><?php echo number_format((int)$trip['max_distance_m']); ?> m</td>
                    <td>
                        <?php if ($trip['category'] !== 'unspecified'): ?>
                            <span class="badge info"><?php echo e(str_replace('_', ' ', $trip['category'])); ?></span>
                        <?php else: ?>
                            <span class="badge muted">not given</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="btn ghost small"
                           href="routes.php?employee=<?php echo (int)$trip['att_employee_id']; ?>&date=<?php echo e($trip['work_date']); ?>">
                            Route
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Latest check-ins</h2>
            <p><?php echo date('d M Y', strtotime($workDate)); ?></p>
        </div>
        <a class="btn ghost small" href="register.php?date=<?php echo e($workDate); ?>">Full register</a>
    </div>
    <div class="card-body tight">
        <?php if (!$recent): ?>
            <div class="empty">No check-ins recorded on this date.</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Employee</th><th>Work area</th><th>In</th><th>Out</th>
                    <th class="num">Worked</th><th class="num">Outside</th><th>Status</th><th>Photos</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
                <tr>
                    <td class="who-cell">
                        <strong><?php echo e($row['name']); ?></strong>
                        <small><?php echo e($row['employee_code']); ?><?php
                            echo $row['department_name'] ? ' · ' . e($row['department_name']) : ''; ?></small>
                    </td>
                    <td><?php echo e($row['geofence_name'] ?? '—'); ?></td>
                    <td>
                        <?php echo attTime($row['check_in_at']); ?>
                        <?php if ($row['check_in_inside_fence'] !== null && !$row['check_in_inside_fence']): ?>
                            <span class="badge bad" title="Checked in outside the assigned area">outside</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo attTime($row['check_out_at']); ?></td>
                    <td class="num"><?php echo attMinutes($row['worked_minutes']); ?></td>
                    <td class="num"><?php echo (int)$row['outside_minutes'] > 0 ? attMinutes($row['outside_minutes']) : '—'; ?></td>
                    <td><span class="badge <?php echo attStatusClass($row['status']); ?>"><?php echo e($row['status']); ?></span></td>
                    <td>
                        <?php foreach (['check_in_photo' => 'In', 'check_out_photo' => 'Out'] as $col => $label): ?>
                            <?php if ($row[$col]): ?>
                                <img class="thumb" src="<?php echo e(attPhotoUrl($row[$col])); ?>"
                                     alt="<?php echo $label; ?> photo" title="<?php echo $label; ?> photo"
                                     data-zoom="<?php echo e(attPhotoUrl($row[$col])); ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($pending): ?>
<div class="card">
    <div class="card-head">
        <div>
            <h2>Trips awaiting your decision</h2>
            <p>Approve as company work, or reject.</p>
        </div>
        <a class="btn ghost small" href="trips.php?review_status=pending">Review queue</a>
    </div>
    <div class="card-body tight">
        <div class="table-wrap">
        <table class="data">
            <thead>
                <tr><th>Employee</th><th>Date</th><th class="num">Away</th><th class="num">Distance</th>
                    <th>Category</th><th>Employee's reason</th></tr>
            </thead>
            <tbody>
            <?php foreach ($pending as $trip): ?>
                <tr>
                    <td class="who-cell">
                        <strong><?php echo e($trip['name']); ?></strong>
                        <small><?php echo e($trip['employee_code']); ?></small>
                    </td>
                    <td><?php echo date('d M', strtotime($trip['work_date'])); ?>
                        <small style="color:var(--ink-soft)"><?php echo attTime($trip['exit_at']); ?></small></td>
                    <td class="num"><?php echo attMinutes($trip['duration_min']); ?></td>
                    <td class="num"><?php echo number_format((int)$trip['max_distance_m']); ?> m</td>
                    <td>
                        <?php if ($trip['category'] !== 'unspecified'): ?>
                            <span class="badge info"><?php echo e(str_replace('_', ' ', $trip['category'])); ?></span>
                        <?php else: ?>
                            <span class="badge muted">not given</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $trip['employee_reason'] ? e($trip['employee_reason']) : '<span class="badge muted">no reason submitted</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/layout_bottom.php'; ?>
