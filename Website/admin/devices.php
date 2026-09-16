<?php
/**
 * Device manager: which handset each account is locked to, and the reset history.
 */

require_once __DIR__ . '/bootstrap.php';

if (($_POST['action'] ?? null) === 'reset_device') {
    attCheckCsrf();
    attRequireManage();

    $id = (int)$_POST['att_employee_id'];
    $reason = trim($_POST['reason'] ?? '');

    if ($reason === '') {
        attFlash('error', 'Give a reason for the reset — it is recorded in the audit log.');
    } elseif (attResetDevice($id, $reason, (int)$attAdmin['id'])) {
        attFlash('success', 'Device released and the old handset signed out.');
    } else {
        attFlash('info', 'That employee has no device linked right now.');
    }
    attRedirect('devices.php');
}

// Authorise removal of the app from a handset. Separate from Release, which unbinds the
// account: this only lifts the uninstall block, for a phone being handed back or wiped.
if (($_POST['action'] ?? null) === 'allow_uninstall') {
    attCheckCsrf();
    attRequireManage();

    $result = attAllowUninstall((int)$_POST['att_employee_id'], (int)$attAdmin['id']);
    attFlash($result['ok'] ? 'success' : 'error', $result['message']);
    attRedirect('devices.php');
}

if (($_POST['action'] ?? null) === 'block_device') {
    attCheckCsrf();
    attRequireManage();

    $deviceId = (int)$_POST['device_id'];
    attDB()->prepare("UPDATE att_devices SET status = 'blocked', released_at = NOW() WHERE id = ?")
           ->execute([$deviceId]);
    attRevokeDeviceTokens($deviceId);
    attAudit('device_blocked', 'att_device', $deviceId, null, 'admin', (int)$attAdmin['id']);
    attFlash('success', 'Device blocked. It cannot be used to sign in again, even by the same employee.');
    attRedirect('devices.php');
}

$devices = attDB()->query("
    SELECT d.*, e.id AS att_employee_id, e.login_code, e.is_active,
           emp.employee_code, CONCAT(emp.first_name, ' ', emp.last_name) AS name,
           (SELECT COUNT(*) FROM att_device_resets r WHERE r.att_employee_id = e.id) AS reset_count
    FROM att_devices d
    JOIN att_employees e ON e.id = d.att_employee_id
    JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
    WHERE d.status = 'active'
    ORDER BY d.last_seen_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$waiting = attDB()->query("
    SELECT e.id, e.login_code, e.last_login_at,
           emp.employee_code, CONCAT(emp.first_name, ' ', emp.last_name) AS name
    FROM att_employees e
    JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
    WHERE e.is_active = 1
      AND NOT EXISTS (SELECT 1 FROM att_devices d WHERE d.att_employee_id = e.id AND d.status = 'active')
    ORDER BY emp.first_name
")->fetchAll(PDO::FETCH_ASSOC);

$history = attDB()->query("
    SELECT r.*, emp.employee_code, CONCAT(emp.first_name, ' ', emp.last_name) AS name,
           u.username AS reset_by_name
    FROM att_device_resets r
    JOIN att_employees e ON e.id = r.att_employee_id
    JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
    LEFT JOIN users u ON u.id = r.reset_by
    ORDER BY r.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$blocked = attDB()->query("
    SELECT d.id, d.device_model, d.device_brand, d.released_at,
           CONCAT(emp.first_name, ' ', emp.last_name) AS name
    FROM att_devices d
    JOIN att_employees e ON e.id = d.att_employee_id
    JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
    WHERE d.status = 'blocked'
    ORDER BY d.released_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Devices';
$pageSubtitle = count($devices) . ' linked · ' . count($waiting) . ' waiting for first sign-in';
include __DIR__ . '/layout_top.php';
?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Linked devices</h2>
            <p>Each account can be used on one handset only. Releasing it lets the employee sign in on a new one.</p>
        </div>
    </div>
    <div class="card-body tight">
        <?php if (!$devices): ?>
            <div class="empty">No devices linked yet. A device links itself the first time an employee signs in.</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead>
                <tr><th>Employee</th><th>Device</th><th>OS / app</th><th>Linked since</th>
                    <th>Last seen</th><th>Removal</th><th class="num">Resets</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($devices as $device): ?>
                <?php
                $lastSeen = $device['last_seen_at'] ? strtotime($device['last_seen_at']) : null;
                $quietHours = $lastSeen ? (time() - $lastSeen) / 3600 : null;
                ?>
                <tr>
                    <td class="who-cell">
                        <strong><?php echo e($device['name']); ?></strong>
                        <small><?php echo e($device['employee_code']); ?> · code <?php echo e($device['login_code']); ?></small>
                    </td>
                    <td>
                        <?php echo e(trim($device['device_brand'] . ' ' . $device['device_model'])) ?: '<em>unknown</em>'; ?>
                        <br><small style="color:var(--ink-soft)"><?php echo e($device['platform']); ?></small>
                    </td>
                    <td><small><?php echo e($device['os_version'] ?: '—'); ?><br>
                        app <?php echo e($device['app_version'] ?: '—'); ?></small></td>
                    <td><small><?php echo date('d M Y H:i', strtotime($device['bound_at'])); ?></small></td>
                    <td>
                        <?php if (!$lastSeen): ?>
                            <span class="badge muted">never</span>
                        <?php elseif ($quietHours > 24): ?>
                            <span class="badge bad" title="The tracking service has not reported in over a day">
                                <?php echo floor($quietHours / 24); ?>d ago</span>
                        <?php elseif ($quietHours > 2): ?>
                            <span class="badge warn"><?php echo floor($quietHours); ?>h ago</span>
                        <?php else: ?>
                            <span class="badge ok"><?php echo date('H:i', $lastSeen); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php
                        // What the phone last told us. "Unprotected" is not a fault — it
                        // is simply what an employee who declined, or switched it off,
                        // leaves behind, and the Security log says which.
                        if (!empty($device['admin_active'])) {
                            echo '<span class="badge ok">protected</span>';
                        } else {
                            echo '<span class="badge muted">unprotected</span>';
                        }
                    ?></td>
                    <td class="num"><?php echo (int)$device['reset_count']; ?></td>
                    <td style="white-space:nowrap">
                        <?php if ($attCanManage): ?>
                        <?php if (!empty($device['admin_active'])): ?>
                        <form method="post" style="display:inline"
                              data-confirm="Allow the app to be uninstalled from this phone? The phone lifts protection at its next check-in.">
                            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                            <input type="hidden" name="action" value="allow_uninstall">
                            <input type="hidden" name="att_employee_id" value="<?php echo (int)$device['att_employee_id']; ?>">
                            <button class="btn ghost small" type="submit">Allow uninstall</button>
                        </form>
                        <?php endif; ?>
                        <button class="btn danger small" type="button"
                                data-modal-open="resetModal"
                                data-subject="<?php echo e($device['name'] . ' — ' . trim($device['device_brand'] . ' ' . $device['device_model'])); ?>"
                                data-set-att_employee_id="<?php echo (int)$device['att_employee_id']; ?>">Release</button>
                        <form method="post" style="display:inline"
                              data-confirm="Block this device permanently? It will never be able to sign in again, even for the same employee.">
                            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                            <input type="hidden" name="action" value="block_device">
                            <input type="hidden" name="device_id" value="<?php echo (int)$device['id']; ?>">
                            <button class="btn ghost small" type="submit">Block</button>
                        </form>
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

<?php if ($waiting): ?>
<div class="card">
    <div class="card-head">
        <div>
            <h2>Waiting for first sign-in</h2>
            <p>These accounts exist but no device has claimed them yet.</p>
        </div>
    </div>
    <div class="card-body tight">
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Employee</th><th>Login code</th><th>Last sign-in attempt</th></tr></thead>
            <tbody>
            <?php foreach ($waiting as $row): ?>
                <tr>
                    <td class="who-cell"><strong><?php echo e($row['name']); ?></strong>
                        <small><?php echo e($row['employee_code']); ?></small></td>
                    <td><code><?php echo e($row['login_code']); ?></code></td>
                    <td><?php echo $row['last_login_at']
                        ? date('d M Y H:i', strtotime($row['last_login_at']))
                        : '<span class="badge muted">never signed in</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($blocked): ?>
<div class="card">
    <div class="card-head"><div><h2>Blocked devices</h2>
        <p>Refused at sign-in permanently.</p></div></div>
    <div class="card-body tight">
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Employee</th><th>Device</th><th>Blocked at</th></tr></thead>
            <tbody>
            <?php foreach ($blocked as $row): ?>
                <tr>
                    <td><?php echo e($row['name']); ?></td>
                    <td><?php echo e(trim($row['device_brand'] . ' ' . $row['device_model'])) ?: '—'; ?></td>
                    <td><small><?php echo e($row['released_at'] ? date('d M Y H:i', strtotime($row['released_at'])) : '—'); ?></small></td>
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
            <h2>Reset history</h2>
            <p>Every device release, who authorised it and why.</p>
        </div>
    </div>
    <div class="card-body tight">
        <?php if (!$history): ?>
            <div class="empty">No device resets recorded.</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>When</th><th>Employee</th><th>Old device ID</th><th>Reason</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <tr>
                    <td><small><?php echo date('d M Y H:i', strtotime($row['created_at'])); ?></small></td>
                    <td class="who-cell"><strong><?php echo e($row['name']); ?></strong>
                        <small><?php echo e($row['employee_code']); ?></small></td>
                    <td><small style="font-family:monospace"><?php
                        // Truncated: the full fingerprint is long and only its
                        // identity matters here, not its value.
                        echo e(substr((string)$row['old_device_uid'], 0, 18)); ?>…</small></td>
                    <td><?php echo e($row['reason'] ?: '—'); ?></td>
                    <td><small><?php echo e($row['reset_by_name'] ?: 'system'); ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($attCanManage): ?>
<div class="modal-backdrop" id="resetModal">
    <div class="modal" style="max-width:480px">
        <form method="post">
            <div class="modal-head">
                <h3>Release device</h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="reset_device">
                <input type="hidden" name="att_employee_id">
                <p style="margin:0 0 14px"><strong data-modal-subject></strong></p>
                <p style="margin:0 0 16px;color:var(--ink-soft);font-size:13px">
                    The handset is signed out immediately and the employee may link one new device.
                </p>
                <div class="field">
                    <label for="rd_reason">Reason</label>
                    <input type="text" id="rd_reason" name="reason" required
                           placeholder="e.g. phone replaced, handset lost">
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn danger" type="submit">Release device</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/layout_bottom.php'; ?>
