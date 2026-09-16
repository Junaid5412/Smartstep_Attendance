<?php
/**
 * Security: simulated locations, blocked accounts, and who changed the rules.
 *
 * Its own page because the answer to "has anyone faked their position?" was previously
 * scattered — a purple dot on a route map, a badge on the employees list, a flag in a
 * table nobody queries. All three are records of the same question and belong together,
 * with dates attached, so it can be answered without opening every route in turn.
 */

require_once __DIR__ . '/bootstrap.php';

// Unblocking lives here too, not only on the employees page: this is where an admin
// arrives when someone says the app will not let them in.
if (($_POST['action'] ?? null) === 'security_unblock') {
    attCheckCsrf();
    attRequireManage();

    $result = attSecurityUnblock(
        (int)($_POST['att_employee_id'] ?? 0),
        $_POST['note'] ?? '',
        (int)$attAdmin['id']
    );
    attFlash($result['ok'] ? 'success' : 'error', $result['message']);
    attRedirect('security.php');
}

$blocked = attBlockedAccounts();
$mockAllowed = attMockAllowed();
$mockHistory = attMockHistory();
$audit = attSecurityAudit();

$totalMockPoints = array_sum(array_column($mockHistory, 'mock_points'));

$pageTitle = 'Security';
$pageSubtitle = 'Simulated locations, blocked accounts, and changes to the rules';
include __DIR__ . '/layout_top.php';
?>

<div class="tiles">
    <div class="tile <?php echo $blocked ? 'alert' : 'good'; ?>">
        <div class="label">Blocked accounts</div>
        <div class="value"><?php echo count($blocked); ?></div>
        <div class="hint"><?php echo $blocked ? 'cannot sign in' : 'none'; ?></div>
    </div>
    <div class="tile <?php echo $mockAllowed ? 'attention' : 'good'; ?>">
        <div class="label">Mock locations allowed</div>
        <div class="value"><?php echo count($mockAllowed); ?></div>
        <div class="hint"><?php echo $mockAllowed
            ? 'these accounts can fake position' : 'nobody'; ?></div>
    </div>
    <div class="tile <?php echo $totalMockPoints > 0 ? 'attention' : ''; ?>">
        <div class="label">Simulated positions recorded</div>
        <div class="value"><?php echo number_format($totalMockPoints); ?></div>
        <div class="hint"><?php echo count($mockHistory); ?> employee-day(s)</div>
    </div>
</div>

<?php if ($mockAllowed): ?>
<div class="card">
    <div class="card-head">
        <div>
            <h2>Accounts allowed to report simulated positions</h2>
            <p>This switch exists for testing. While it is on, a fake-GPS app can check
               that person in from anywhere and the server will accept it — and the
               automatic block for spoofing does not apply to them.</p>
        </div>
    </div>
    <div class="card-body tight">
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Employee</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($mockAllowed as $row): ?>
                <tr>
                    <td class="who-cell">
                        <strong><?php echo e($row['name']); ?></strong>
                        <small><?php echo e($row['employee_code']); ?></small>
                    </td>
                    <td><span class="badge bad">mock allowed</span></td>
                    <td><a class="btn ghost small"
                           href="employees.php?q=<?php echo urlencode($row['employee_code']); ?>">Turn it off</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($blocked): ?>
<div class="card" style="margin-top:20px">
    <div class="card-head">
        <div>
            <h2>Blocked accounts</h2>
            <p>Blocked automatically and signed out of every device. Find out what
               happened before lifting it.</p>
        </div>
    </div>
    <div class="card-body tight">
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Employee</th><th>Reason</th><th>Blocked</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($blocked as $row): ?>
                <tr>
                    <td class="who-cell">
                        <strong><?php echo e($row['name']); ?></strong>
                        <small><?php echo e($row['employee_code']); ?></small>
                    </td>
                    <td><span class="badge bad"><?php
                        echo e($row['security_block_reason'] ?: 'security check'); ?></span></td>
                    <td><small><?php echo e(date('d M Y H:i',
                        strtotime($row['security_blocked_at']))); ?></small></td>
                    <td>
                        <?php if ($attCanManage): ?>
                        <button class="btn small" type="button"
                                data-modal-open="unblockModal"
                                data-subject="<?php echo e($row['name'] . ' — ' .
                                    ($row['security_block_reason'] ?: 'security check')); ?>"
                                data-set-att_employee_id="<?php echo (int)$row['id']; ?>">Unblock</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:20px">
    <div class="card-head">
        <div>
            <h2>Simulated locations recorded</h2>
            <p>Grouped by employee and day. Every one of these positions is still stored
               on the route — open it to see exactly where the phone claimed to be.</p>
        </div>
    </div>
    <div class="card-body tight">
        <?php if (!$mockHistory): ?>
            <div class="empty">No simulated positions have ever been recorded.</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead>
                <tr><th>Employee</th><th>Date</th><th>Points</th><th>Between</th>
                    <th>Outside area</th><th>Real position</th><th>Permitted?</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($mockHistory as $row): ?>
                <tr>
                    <td class="who-cell">
                        <strong><?php echo e($row['name']); ?></strong>
                        <small><?php echo e($row['employee_code']); ?></small>
                    </td>
                    <td><small><?php echo e(date('d M Y', strtotime($row['work_date']))); ?></small></td>
                    <td><strong><?php echo number_format($row['mock_points']); ?></strong></td>
                    <td><small><?php
                        echo e(date('H:i', strtotime($row['first_at']))) . ' – ' .
                             e(date('H:i', strtotime($row['last_at']))); ?></small></td>
                    <td><?php
                        // The figure that decides whether this was a test or an attempt to
                        // be marked present somewhere they were not.
                        if ((int)$row['outside_points'] > 0) {
                            echo '<span class="badge bad">' . (int)$row['outside_points'] .
                                 ' of ' . (int)$row['mock_points'] . '</span>';
                        } else {
                            echo '<span class="badge muted">none</span>';
                        }
                    ?></td>
                    <td><?php
                        // Whether the phone could also say where it genuinely was. Only
                        // possible when the fake-GPS app left a raw provider untouched,
                        // so "not captured" is the normal answer, not a failure.
                        $realCount = (int)($row['real_captured'] ?? 0);
                        echo $realCount > 0
                            ? '<span class="badge ok">' . $realCount . ' point' .
                              ($realCount === 1 ? '' : 's') . ' — see the route</span>'
                            : '<span class="badge muted">not captured</span>';
                    ?></td>
                    <td><?php echo (int)$row['allow_mock_location'] === 1
                        ? '<span class="badge warn">allowed then</span>'
                        : '<span class="badge bad">not allowed</span>'; ?></td>
                    <td><a href="routes.php?employee=<?php echo (int)$row['att_employee_id'];
                        ?>&date=<?php echo e($row['work_date']); ?>">View route →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="card-body" style="padding-top:0">
            <p style="margin:0;font-size:12.5px;color:var(--ink-soft)">
                <strong>"Permitted?"</strong> reflects the setting as it stands now, not as it
                stood on the day — the setting itself has no history before today, so an older
                row cannot prove which it was. Changes made from now on appear in the log below.
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-head">
        <div>
            <h2>Security changes</h2>
            <p>Blocks lifted, devices released, accounts disabled, and any change to the
               mock-location permission — with who did it.</p>
        </div>
    </div>
    <div class="card-body tight">
        <?php if (!$audit): ?>
            <div class="empty">Nothing recorded yet.</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>When</th><th>What</th><th>Who it concerns</th>
                       <th>Detail</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($audit as $row): ?>
                <?php
                $details = json_decode((string)$row['details'], true);
                [$class, $label] = match ($row['action']) {
                    'impossible_travel' => ['bad', 'Impossible movement'],
                    'fake_gps_detected' => ['bad', 'Fake GPS detected'],
                    'uninstall_protection_off' => ['warn', 'Removal protection off'],
                    'home_area_entered' => ['warn', 'At home on duty'],
                    'security_blocked' => ['bad', 'Account blocked'],
                    'security_unblocked' => ['ok', 'Block lifted'],
                    'device_reset' => ['warn', 'Device released'],
                    'employee_disabled' => ['bad', 'Account disabled'],
                    'employee_enabled' => ['ok', 'Account enabled'],
                    default => ['warn', 'Mock permission changed'],
                };
                ?>
                <tr>
                    <td><small><?php echo e(date('d M Y H:i', strtotime($row['created_at']))); ?></small></td>
                    <td><span class="badge <?php echo $class; ?>"><?php echo $label; ?></span></td>
                    <td><small><?php echo $row['subject_name']
                        ? e($row['subject_name']) . ' (' . e($row['subject_code']) . ')'
                        : '—'; ?></small></td>
                    <td><small><?php
                        if (is_array($details)) {
                            if (isset($details['summary'])) {
                                echo '<strong>' . e($details['summary']) . '</strong>'
                                   . (isset($details['from'])
                                      ? ' · ' . e(substr($details['from'], 11, 5)) . '–'
                                        . e(substr($details['to'], 11, 5)) : '');
                            } elseif (isset($details['mock_location_permission'])) {
                                echo '<strong>mock location ' .
                                     e($details['mock_location_permission']) . '</strong>';
                            } elseif (isset($details['note'])) {
                                echo e($details['note']);
                            } elseif (isset($details['reason'])) {
                                echo e($details['reason']);
                            } else {
                                echo '—';
                            }
                        } else {
                            echo '—';
                        }
                    ?></small></td>
                    <td><small><?php echo e($row['actor_type']); ?>
                        #<?php echo (int)$row['actor_id']; ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($attCanManage): ?>
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
                    A phone with a fake-GPS app still installed will simply be blocked
                    again, so find out what happened first.
                </p>
                <div class="field">
                    <label for="u_note">What happened, and why the block is being lifted</label>
                    <input type="text" id="u_note" name="note" required maxlength="500"
                           placeholder="e.g. tested with a mock-location app during setup, app removed">
                    <div class="help">Required, and kept in the log below with your name
                        against it.</div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn" type="submit">Lift the block</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/layout_bottom.php'; ?>
