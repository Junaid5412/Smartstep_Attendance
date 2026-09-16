<?php
require_once __DIR__ . '/bootstrap.php';

$attEmployeeId = (int)($_GET['employee'] ?? 0);
$workDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    $workDate = date('Y-m-d');
}

$employee = $attEmployeeId ? attEmployee($attEmployeeId) : null;

if (!$employee) {
    attRedirect('register.php');
}

$pageTitle = 'Daily Report';
$pageSubtitle = $employee['name'] . ' · ' . date('l, d F Y', strtotime($workDate));
include __DIR__ . '/layout_top.php';

$db = attDB();

// Fetch all attendance sessions for the day
$stmt = $db->prepare("SELECT * FROM att_attendance WHERE att_employee_id = ? AND work_date = ? ORDER BY session_no ASC");
$stmt->execute([$attEmployeeId, $workDate]);
$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch outside events
$stmt = $db->prepare("SELECT * FROM att_geofence_events WHERE att_employee_id = ? AND work_date = ? ORDER BY exit_at ASC");
$stmt->execute([$attEmployeeId, $workDate]);
$outsideEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch location logs to find gaps
$stmt = $db->prepare("SELECT recorded_at FROM att_location_logs WHERE att_employee_id = ? AND work_date = ? ORDER BY recorded_at ASC");
$stmt->execute([$attEmployeeId, $workDate]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$timeline = [];

foreach ($attendances as $att) {
    if ($att['check_in_at']) {
        $timeline[] = [
            'time' => strtotime($att['check_in_at']),
            'type' => 'check_in',
            'label' => 'Checked in (Session ' . $att['session_no'] . ')',
            'details' => 'At ' . date('H:i', strtotime($att['check_in_at']))
        ];
    }
    if ($att['check_out_at']) {
        $timeline[] = [
            'time' => strtotime($att['check_out_at']),
            'type' => 'check_out',
            'label' => 'Checked out (Session ' . $att['session_no'] . ')',
            'details' => 'At ' . date('H:i', strtotime($att['check_out_at']))
        ];
    }
}

foreach ($outsideEvents as $ev) {
    $duration = $ev['duration_min'] !== null ? $ev['duration_min'] . ' mins' : 'Ongoing';
    $details = 'Outside for ' . $duration;
    if ($ev['reason']) {
        $details .= '<br>Reason: ' . e($ev['reason']);
    }
    $timeline[] = [
        'time' => strtotime($ev['exit_at']),
        'type' => 'outside',
        'label' => 'Left Work Area',
        'details' => $details,
        'time_label' => date('H:i', strtotime($ev['exit_at'])) . ($ev['entry_at'] ? ' - ' . date('H:i', strtotime($ev['entry_at'])) : '')
    ];
}

foreach ($attendances as $att) {
    if (!$att['check_in_at']) continue;
    $sessionStart = strtotime($att['check_in_at']);
    
    $isOngoingSession = false;
    if ($att['check_out_at']) {
        $sessionEnd = strtotime($att['check_out_at']);
    } else {
        if ($workDate === date('Y-m-d')) {
            $sessionEnd = time();
            $isOngoingSession = true;
        } else {
            $sessionEnd = strtotime($workDate . ' 23:59:59');
        }
    }
    
    $sessionLogs = array_filter($logs, function($p) use ($sessionStart, $sessionEnd) {
        $t = strtotime($p['recorded_at']);
        return $t >= $sessionStart && $t <= $sessionEnd;
    });
    
    $lastPointTime = $sessionStart;
    
    // Add artificial end point to check for trailing gaps
    $sessionLogs[] = ['recorded_at' => date('Y-m-d H:i:s', $sessionEnd), '_is_end' => true];
    
    foreach ($sessionLogs as $point) {
        $time = strtotime($point['recorded_at']);
        $gap = $time - $lastPointTime;
        if ($gap > 15 * 60) {
            $isTrailing = isset($point['_is_end']);
            
            $timeLabel = date('H:i', $lastPointTime);
            if ($isTrailing && $isOngoingSession) {
                $timeLabel .= ' - Ongoing';
            } else {
                $timeLabel .= ' - ' . date('H:i', $time);
            }
            
            $timeline[] = [
                'time' => $lastPointTime,
                'type' => 'offline',
                'label' => 'Offline / Location Not Detected',
                'details' => 'No location recorded for ' . round($gap / 60) . ' mins',
                'time_label' => $timeLabel
            ];
        }
        $lastPointTime = $time;
    }
}

usort($timeline, function($a, $b) { return $a['time'] <=> $b['time']; });
?>

<div class="toolbar" style="margin-bottom: 20px; justify-content: space-between;">
    <div class="field">
        <a href="register.php?date=<?php echo e($workDate); ?>" class="btn ghost">&larr; Back to Register</a>
    </div>
    <div class="field">
        <button type="button" class="btn ghost" onclick="window.print()">
            <svg style="width:16px;height:16px;margin-right:-2px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Download PDF
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 style="margin-top: 0;">Daily Timeline</h2>
        <?php if (!$timeline): ?>
            <p>No events recorded for this date.</p>
        <?php else: ?>
            <table class="grid">
                <thead>
                    <tr>
                        <th style="width: 150px;">Time</th>
                        <th style="width: 250px;">Event</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timeline as $item): ?>
                        <tr>
                            <td><?php echo isset($item['time_label']) ? e($item['time_label']) : date('H:i', $item['time']); ?></td>
                            <td>
                                <?php if ($item['type'] === 'check_in' || $item['type'] === 'check_out'): ?>
                                    <span class="badge ok"><?php echo e($item['label']); ?></span>
                                <?php elseif ($item['type'] === 'outside'): ?>
                                    <span class="badge fail"><?php echo e($item['label']); ?></span>
                                <?php elseif ($item['type'] === 'offline'): ?>
                                    <span class="badge warn"><?php echo e($item['label']); ?></span>
                                <?php else: ?>
                                    <?php echo e($item['label']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $item['details']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/layout_bottom.php'; ?>
