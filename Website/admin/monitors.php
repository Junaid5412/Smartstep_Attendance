<?php
/**
 * Monitors → attendance accounts, a project at a time.
 *
 * Separate from employees.php because the job is genuinely different: HR employees are
 * enrolled one at a time as they join, while monitors arrive as a staffed project and
 * fifty accounts are needed the same afternoon.
 */

require_once __DIR__ . '/bootstrap.php';

$projectId = (int)($_GET['project'] ?? 0);

if (($_POST['action'] ?? null) === 'enrol_monitors') {
    attCheckCsrf();
    attRequireManage();

    $ids = array_map('intval', (array)($_POST['monitor_ids'] ?? []));
    $geofenceId = (int)($_POST['geofence_id'] ?? 0) ?: null;
    $sessions = ($_POST['sessions_per_day'] ?? '1') === '2' ? 2 : 1;
    $shifts = [
        'shift_start' => trim($_POST['shift1_start'] ?? ''),
        'shift_end' => trim($_POST['shift1_end'] ?? ''),
        'shift2_start' => trim($_POST['shift2_start'] ?? ''),
        'shift2_end' => trim($_POST['shift2_end'] ?? ''),
    ];
    $backTo = (int)($_POST['project_id'] ?? 0);

    if (!$ids) {
        attFlash('error', 'Select at least one monitor.');
        attRedirect('monitors.php?project=' . $backTo);
    }

    // Shift times come with the batch — prefilled from the project's timetable,
    // so the school's hours land on the account from birth.
    $result = attEnrolMonitors($ids, $geofenceId, (int)$attAdmin['id'], $sessions, $shifts);

    // The generated passwords are shown once, here, and then remain viewable
    // via the encrypted copy. Held in the session so the redirect can display
    // them without putting credentials in a URL.
    $_SESSION['att_enrol_result'] = $result;

    attFlash(
        $result['created'] ? 'success' : 'error',
        count($result['created']) . ' account' . (count($result['created']) === 1 ? '' : 's') . ' created'
            . ($result['skipped'] ? ', ' . count($result['skipped']) . ' skipped.' : '.')
    );
    attRedirect('monitors.php?project=' . $backTo);
}

if (($_POST['action'] ?? null) === 'reset_monitor_password') {
    attCheckCsrf();
    attRequireManage();
    $backTo = (int)($_POST['project_id'] ?? 0);
    $result = attResetMonitorPassword((int)($_POST['att_employee_id'] ?? 0), (int)$attAdmin['id']);
    if ($result['ok']) {
        $_SESSION['att_reset_result'] = $result;
    }
    attFlash($result['ok'] ? 'success' : 'error', $result['ok']
        ? $result['message'] . ' New password is shown below — write it down now.'
        : $result['message']);
    attRedirect('monitors.php?project=' . $backTo);
}

if (($_POST['action'] ?? null) === 'reset_monitor_device') {
    attCheckCsrf();
    attRequireManage();
    $backTo = (int)($_POST['project_id'] ?? 0);
    $result = attResetDevice((int)($_POST['att_employee_id'] ?? 0), 'Requested by admin', (int)$attAdmin['id']);
    attFlash($result ? 'success' : 'error', $result 
        ? 'Device unlinked. The monitor can now sign in on a new phone.' 
        : 'No active device found for this account.');
    attRedirect('monitors.php?project=' . $backTo);
}

if (($_POST['action'] ?? null) === 'save_project_shifts') {
    attCheckCsrf();
    attRequireManage();
    $backTo = (int)($_POST['project_id'] ?? 0);
    $result = attSaveProjectShifts($backTo, [
        'shift1_start' => trim($_POST['shift1_start'] ?? ''),
        'shift1_end' => trim($_POST['shift1_end'] ?? ''),
        'sessions_default' => ($_POST['sessions_default'] ?? '1') === '2' ? 2 : 1,
        'shift2_start' => trim($_POST['shift2_start'] ?? ''),
        'shift2_end' => trim($_POST['shift2_end'] ?? ''),
        'default_geofence_id' => (int)($_POST['default_geofence_id'] ?? 0),
    ], (int)$attAdmin['id']);
    attFlash($result['ok'] ? 'success' : 'error', $result['message']);
    attRedirect('monitors.php?project=' . $backTo);
}

if (($_POST['action'] ?? null) === 'apply_project_shifts') {
    attCheckCsrf();
    attRequireManage();
    $backTo = (int)($_POST['project_id'] ?? 0);
    $result = attApplyProjectShiftsToAccounts($backTo, (int)$attAdmin['id']);
    attFlash($result['ok'] ? 'success' : 'error', $result['message']);
    attRedirect('monitors.php?project=' . $backTo);
}

if (($_POST['action'] ?? null) === 'update_monitor_account') {
    attCheckCsrf();
    attRequireManage();
    $backTo = (int)($_POST['project_id'] ?? 0);
    $result = attUpdateMonitorAccount(
        (int)($_POST['att_employee_id'] ?? 0),
        (int)($_POST['geofence_id'] ?? 0) ?: null,
        ($_POST['sessions_per_day'] ?? '1') === '2' ? 2 : 1,
        trim($_POST['shift2_start'] ?? ''),
        trim($_POST['shift2_end'] ?? ''),
        (int)$attAdmin['id']
    );
    attFlash($result['ok'] ? 'success' : 'error', $result['message']);
    attRedirect('monitors.php?project=' . $backTo);
}

$justEnrolled = $_SESSION['att_enrol_result'] ?? null;
unset($_SESSION['att_enrol_result']);
$justReset = $_SESSION['att_reset_result'] ?? null;
unset($_SESSION['att_reset_result']);

$projects = attProjectsWithMonitors();
$monitors = $projectId ? attMonitorsForProject($projectId) : [];
$geofences = attDB()->query("SELECT id, name FROM att_geofences ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$projectName = '';
foreach ($projects as $project) {
    if ((int)$project['id'] === $projectId) {
        $projectName = $project['project_name'];
    }
}

// The school's own timetable for the popup and the prefilled enrol form.
// Falls back to the module defaults until somebody saves project hours.
$timetable = $projectId ? attProjectShiftDefaults($projectId) : null;
$timetableFenceName = '';
if ($timetable && $timetable['default_geofence_id']) {
    foreach ($geofences as $fence) {
        if ((int)$fence['id'] === (int)$timetable['default_geofence_id']) {
            $timetableFenceName = $fence['name'];
        }
    }
}

$pageTitle = 'Monitors';
$pageSubtitle = 'Give project monitors an attendance account';
include __DIR__ . '/layout_top.php';
?>

<?php if ($justReset && ($justReset['ok'] ?? false)): ?>
<div class="card" style="margin-bottom:20px;border-color:#B7E2C7">
    <div class="card-head"><div>
        <h2>New password — write it down now</h2>
        <p>The old password no longer works and the app session was signed out.</p>
    </div>
    <button class="btn ghost" type="button" onclick="window.print()" style="margin-left:auto">Print</button>
    </div>
    <div class="card-body">
        <table class="data">
            <thead><tr><th>Login code (username)</th><th>New password</th></tr></thead>
            <tbody><tr>
                <td><code><?php echo e($justReset['login_code']); ?></code></td>
                <td><code style="font-size:16px;letter-spacing:1px"><?php echo e($justReset['password']); ?></code></td>
            </tr></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($justEnrolled && $justEnrolled['created']): ?>
<div class="card" style="margin-bottom:20px;border-color:#B7E2C7">
    <div class="card-head"><div>
        <h2>Accounts created — write these down now</h2>
        <p>Passwords are also kept encrypted for this panel, so they can be shown
           again below. Each monitor is asked to change theirs at first sign-in.</p>
    </div>
    <button class="btn ghost" type="button" onclick="window.print()" style="margin-left:auto">Print</button>
    </div>
    <div class="card-body">
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Monitor</th><th>Login code (username)</th><th>Password</th><th>Shifts</th></tr></thead>
            <tbody>
            <?php foreach ($justEnrolled['created'] as $account): ?>
                <tr>
                    <td><?php echo e($account['name']); ?></td>
                    <td><code><?php echo e($account['login_code']); ?></code></td>
                    <td><code style="font-size:15px;letter-spacing:1px"><?php echo e($account['password']); ?></code></td>
                    <td><?php echo $account['part_time']
                        ? '<span class="badge warn">2 a day</span>'
                        : '<span class="badge muted">1 a day</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($justEnrolled['skipped']): ?>
            <div style="margin-top:14px">
                <strong style="font-size:13px">Skipped</strong>
                <ul style="font-size:12.5px;color:var(--ink-soft);margin:6px 0 0 18px">
                <?php foreach ($justEnrolled['skipped'] as $skip): ?>
                    <li><?php echo e($skip['name']); ?> — <?php echo e($skip['why']); ?></li>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><div>
        <h2>Projects</h2>
        <p>Monitors come from the SSTQA projects module. Enrolment progress is per project,
           so a half-finished project is obvious.</p>
    </div></div>
    <div class="card-body">
        <?php if (!$projects): ?>
            <div class="empty">No projects have active monitors yet.</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Project</th><th class="num">Monitors</th><th class="num">Enrolled</th>
                <th class="num">Part time</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($projects as $project): ?>
                <?php
                    $total = (int)$project['monitors'];
                    $done = (int)$project['enrolled'];
                    $complete = $done >= $total;
                ?>
                <tr<?php echo (int)$project['id'] === $projectId ? ' style="background:#F4F6FA"' : ''; ?>>
                    <td><strong><?php echo e($project['project_name']); ?></strong></td>
                    <td class="num"><?php echo $total; ?></td>
                    <td class="num">
                        <span class="badge <?php echo $complete ? 'ok' : ($done ? 'warn' : 'muted'); ?>">
                            <?php echo $done; ?> of <?php echo $total; ?></span>
                    </td>
                    <td class="num"><?php echo (int)$project['part_time'] ?: '—'; ?></td>
                    <td><a class="btn ghost small" href="monitors.php?project=<?php echo (int)$project['id']; ?>">
                        <?php echo $complete ? 'Review' : 'Enrol'; ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($projectId && $monitors): ?>
<?php
    $enrolled = array_values(array_filter($monitors, function ($m) { return !empty($m['att_employee_id']); }));
    $pending = array_values(array_filter($monitors, function ($m) { return empty($m['att_employee_id']); }));
?>
<div class="card" style="margin-top:20px">
    <div class="card-head"><div>
        <h2><?php echo e($projectName); ?> — shift timetable</h2>
        <p>
            <?php if ($timetable && $timetable['is_custom']): ?>
                Mornings <strong><?php echo e($timetable['shift1_start']); ?>–<?php echo e($timetable['shift1_end']); ?></strong>
                · <?php echo $timetable['sessions_default'] === 2 ? '<strong>2 sessions a day</strong>' : '1 session a day'; ?>
                <?php if ($timetable['sessions_default'] === 2): ?>
                    · afternoons <strong><?php echo $timetable['shift2_start'] !== '' ? e($timetable['shift2_start'] . '–' . $timetable['shift2_end']) : 'reuse morning hours'; ?></strong>
                <?php endif; ?>
                · work area <strong><?php echo $timetableFenceName !== '' ? e($timetableFenceName) : 'not set'; ?></strong>
            <?php else: ?>
                No timetable saved for this school yet — enrolment falls back to the
                module defaults (08:00–17:00, one session, no work area).
            <?php endif; ?>
        </p>
    </div>
    <?php if ($attCanManage): ?>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
        <button class="btn ghost" type="button" data-modal-open="projectShiftsModal"
            data-subject="<?php echo e($projectName); ?>"
            data-set-shift1_start="<?php echo e($timetable['shift1_start'] ?? '08:00'); ?>"
            data-set-shift1_end="<?php echo e($timetable['shift1_end'] ?? '17:00'); ?>"
            data-set-sessions_default="<?php echo (int)($timetable['sessions_default'] ?? 1); ?>"
            data-set-shift2_start="<?php echo e($timetable['shift2_start'] ?? ''); ?>"
            data-set-shift2_end="<?php echo e($timetable['shift2_end'] ?? ''); ?>"
            data-set-default_geofence_id="<?php echo (int)($timetable['default_geofence_id'] ?? 0); ?>"
            title="Set this school's default shift hours">⚙ Timetable</button>
        <?php if ($timetable && $timetable['is_custom'] && $enrolled): ?>
        <form method="post" style="display:inline"
              data-confirm="Apply this timetable to all <?php echo count($enrolled); ?> enrolled accounts on <?php echo e($projectName); ?>? Per-monitor exceptions you set before will be overwritten.">
            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
            <input type="hidden" name="action" value="apply_project_shifts">
            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
            <button class="btn ghost small" type="submit">Apply to <?php echo count($enrolled); ?> accounts</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-head"><div>
        <h2><?php echo e($projectName); ?> — enrolled accounts (<?php echo count($enrolled); ?>)</h2>
        <p>Username is the monitor code. Passwords are stored encrypted and can be
           shown again here; when one is missing, reset it. Sessions and work area
           are live rules — the app enables the second check-in/out and the work
           area as soon as they are saved.</p>
    </div></div>
    <div class="card-body">
        <?php if (!$enrolled): ?>
            <div class="empty">No accounts yet — enrol them below.</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr>
                <th>Monitor</th><th>Username</th><th>Password</th>
                <th>Sessions</th><th>Work area</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($enrolled as $monitor): ?>
                <?php $savedPassword = attCredentialDecrypt($monitor['initial_password_cipher'] ?? null); ?>
                <tr>
                    <td><strong><?php echo e(trim($monitor['first_name'] . ' ' . $monitor['last_name'])); ?></strong>
                        <br><small style="color:var(--ink-soft)"><?php echo e($monitor['monitor_code']); ?><?php echo $monitor['designation'] ? ' · ' . e($monitor['designation']) : ''; ?></small></td>
                    <td><code><?php echo e($monitor['login_code']); ?></code></td>
                    <td><?php if ($savedPassword): ?>
                            <code title="Decrypted for this admin session"><?php echo e($savedPassword); ?></code>
                        <?php else: ?>
                            <span class="badge warn" title="Created before passwords were stored encrypted">not stored</span>
                        <?php endif; ?></td>
                    <td><?php $sess = (int)($monitor['sessions_per_day'] ?? 1); ?>
                        <?php if ($sess >= 2): ?>
                            <span class="badge warn">2 / day</span>
                            <br><small style="color:var(--ink-soft)"><?php
                                echo e(substr((string)($monitor['shift_start'] ?? ''), 0, 5)); ?>–<?php
                                echo e(substr((string)($monitor['shift_end'] ?? ''), 0, 5)); ?><?php
                                if (!empty($monitor['shift2_start'])) {
                                    echo ' + ' . e(substr((string)$monitor['shift2_start'], 0, 5)) . '–' . e(substr((string)$monitor['shift2_end'], 0, 5));
                                } else { echo ' + 2nd hours not set'; }
                            ?></small>
                        <?php else: ?>
                            <span class="badge muted">1 / day</span>
                        <?php endif; ?></td>
                    <td><?php if (!empty($monitor['geofence_name'])): ?>
                            <?php echo e($monitor['geofence_name']); ?>
                        <?php else: ?>
                            <span class="badge bad">not set</span>
                        <?php endif; ?></td>
                    <td><?php if ((int)$monitor['is_active'] !== 1): ?>
                            <span class="badge bad">disabled</span>
                        <?php elseif (empty($monitor['last_login_at'])): ?>
                            <span class="badge info">never signed in</span>
                        <?php else: ?>
                            <span class="badge ok">active</span>
                            <br><small style="color:var(--ink-soft)">seen <?php echo e(date('d M H:i', strtotime($monitor['last_login_at']))); ?></small>
                        <?php endif; ?>
                        <?php if (!empty($monitor['must_change_password'])): ?>
                            <br><small style="color:var(--ink-soft)">must change password</small>
                        <?php endif; ?></td>
                    <td style="white-space:nowrap">
                        <?php if ($attCanManage): ?>
                        <form method="post" style="display:inline" data-confirm="Reset password for <?php echo e($monitor['login_code']); ?>? The old password stops working immediately.">
                            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                            <input type="hidden" name="action" value="reset_monitor_password">
                            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                            <input type="hidden" name="att_employee_id" value="<?php echo (int)$monitor['att_employee_id']; ?>">
                            <button class="btn ghost small" type="submit">Reset password</button>
                        </form>
                        <form method="post" style="display:inline" data-confirm="Reset device for <?php echo e($monitor['login_code']); ?>? They will be logged out and can sign in on a new phone.">
                            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                            <input type="hidden" name="action" value="reset_monitor_device">
                            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                            <input type="hidden" name="att_employee_id" value="<?php echo (int)$monitor['att_employee_id']; ?>">
                            <button class="btn ghost small" type="submit" title="Unlink current phone">Reset device</button>
                        </form>
                        <button class="btn ghost small" type="button"
                            data-modal-open="monitorRulesModal"
                            data-subject="<?php echo e(trim($monitor['first_name'] . ' ' . $monitor['last_name']) . ' (' . $monitor['login_code'] . ')'); ?>"
                            data-set-att_employee_id="<?php echo (int)$monitor['att_employee_id']; ?>"
                            data-set-geofence_id="<?php echo (int)($monitor['geofence_id'] ?? 0); ?>"
                            data-set-sessions_per_day="<?php echo (int)($monitor['sessions_per_day'] ?? 1); ?>"
                            data-set-shift2_start="<?php echo e(!empty($monitor['shift2_start']) ? substr($monitor['shift2_start'], 0, 5) : ''); ?>"
                            data-set-shift2_end="<?php echo e(!empty($monitor['shift2_end']) ? substr($monitor['shift2_end'], 0, 5) : ''); ?>">Rules</button>
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

<div class="card" style="margin-top:20px">
    <div class="card-head"><div>
        <h2>Not yet enrolled (<?php echo count($pending); ?>)</h2>
        <p>Login codes are the monitor's own code from SSTQA, so there is one number to
           remember across both systems. Passwords are generated per person.</p>
    </div></div>
    <div class="card-body">
        <?php if (!$pending): ?>
            <div class="empty">Everyone on this project has an account.</div>
        <?php else: ?>
        <form method="post" id="enrolForm">
            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
            <input type="hidden" name="action" value="enrol_monitors">
            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">

            <div class="field" style="max-width:340px">
                <label for="sessions_per_day">Daily check-in/out sessions</label>
                <select id="sessions_per_day" name="sessions_per_day">
                    <option value="1"<?php echo ($timetable['sessions_default'] ?? 1) === 1 ? ' selected' : ''; ?>>One session (full time)</option>
                    <option value="2"<?php echo ($timetable['sessions_default'] ?? 1) === 2 ? ' selected' : ''; ?>>Two sessions (part time)</option>
                </select>
                <div class="help">Two sessions enables the second check-in/out in the app. Prefilled from the school's timetable above.</div>
            </div>
            <div class="grid" style="max-width:520px">
                <div class="field">
                    <label for="enrol_shift1_start">Morning shift start</label>
                    <input type="time" id="enrol_shift1_start" name="shift1_start" value="<?php echo e($timetable['shift1_start'] ?? '08:00'); ?>">
                </div>
                <div class="field">
                    <label for="enrol_shift1_end">Morning shift end</label>
                    <input type="time" id="enrol_shift1_end" name="shift1_end" value="<?php echo e($timetable['shift1_end'] ?? '17:00'); ?>">
                </div>
            </div>
            <div class="grid" id="enrolShift2Row" style="display:none;max-width:520px">
                <div class="field">
                    <label for="enrol_shift2_start">Second shift start</label>
                    <input type="time" id="enrol_shift2_start" name="shift2_start" value="<?php echo e($timetable['shift2_start'] ?? ''); ?>">
                </div>
                <div class="field">
                    <label for="enrol_shift2_end">Second shift end</label>
                    <input type="time" id="enrol_shift2_end" name="shift2_end" value="<?php echo e($timetable['shift2_end'] ?? ''); ?>">
                </div>
            </div>
            <div class="help" style="margin:-4px 0 12px">Lateness and overtime for the afternoon are measured against these. Leave blank to reuse the morning hours. All prefilled from the school's timetable.</div>
            <div class="field" style="max-width:340px">
                <label for="geofence_id">Work area for these accounts</label>
                <select id="geofence_id" name="geofence_id">
                    <option value="">None — set it per monitor later</option>
                    <?php foreach ($geofences as $fence): ?>
                        <option value="<?php echo (int)$fence['id']; ?>"<?php echo (int)($timetable['default_geofence_id'] ?? 0) === (int)$fence['id'] ? ' selected' : ''; ?>><?php echo e($fence['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="help">Applied to every account created in this batch. Without one the app shows “No work area assigned” and cannot enforce the fence.</div>
            </div>

            <div class="table-wrap">
            <table class="data">
                <thead><tr>
                    <th style="width:34px"><input type="checkbox" id="pickAll" title="Select all not yet enrolled"></th>
                    <th>Code</th><th>Name</th><th>Designation</th><th>Type</th><th>Phone</th>
                </tr></thead>
                <tbody>
                <?php foreach ($pending as $monitor): ?>
                    <tr>
                        <td><input type="checkbox" class="pick" name="monitor_ids[]"
                                   value="<?php echo (int)$monitor['id']; ?>"
                                   <?php echo $monitor['employment_type'] === 'part_time' ? 'data-parttime="1"' : ''; ?>></td>
                        <td><code><?php echo e($monitor['monitor_code']); ?></code></td>
                        <td><?php echo e(trim($monitor['first_name'] . ' ' . $monitor['last_name'])); ?></td>
                        <td><small><?php echo e($monitor['designation'] ?: '—'); ?></small></td>
                        <td><?php echo $monitor['employment_type'] === 'part_time'
                            ? '<span class="badge warn">Part time</span>'
                            : '<span class="badge muted">Full time</span>'; ?></td>
                        <td><small><?php echo e($monitor['phone']); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ($attCanManage): ?>
            <div style="margin-top:14px">
                <button class="btn" type="submit">Create accounts for selected</button>
                <span class="help" id="pickCount" style="margin-left:10px">none selected</span>
            </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($attCanManage): ?>
<div class="modal-backdrop" id="projectShiftsModal">
    <div class="modal" style="max-width:520px">
        <form method="post">
            <div class="modal-head">
                <h3>Shift timetable — <span data-modal-subject></span></h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="save_project_shifts">
                <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                <div class="grid">
                    <div class="field">
                        <label for="ps_shift1_start">Morning shift start</label>
                        <input type="time" id="ps_shift1_start" name="shift1_start" required>
                    </div>
                    <div class="field">
                        <label for="ps_shift1_end">Morning shift end</label>
                        <input type="time" id="ps_shift1_end" name="shift1_end" required>
                    </div>
                </div>
                <div class="field">
                    <label for="ps_sessions">Daily check-in/out sessions</label>
                    <select id="ps_sessions" name="sessions_default">
                        <option value="1">One — checks in and out once</option>
                        <option value="2">Two — a split shift, in and out twice</option>
                    </select>
                    <div class="help">Prefills the enrol form, so part-time schools enrol with two sessions by default.</div>
                </div>
                <div class="grid" id="projectShift2Row">
                    <div class="field">
                        <label for="ps_shift2_start">Afternoon shift start</label>
                        <input type="time" id="ps_shift2_start" name="shift2_start">
                    </div>
                    <div class="field">
                        <label for="ps_shift2_end">Afternoon shift end</label>
                        <input type="time" id="ps_shift2_end" name="shift2_end">
                    </div>
                </div>
                <div class="help">Each school keeps different hours — set this school's once. Leave the afternoon blank to reuse the morning hours.</div>
                <div class="field">
                    <label for="ps_geofence">Default work area for new accounts</label>
                    <select id="ps_geofence" name="default_geofence_id">
                        <option value="">None — choose at enrolment</option>
                        <?php foreach ($geofences as $fence): ?>
                            <option value="<?php echo (int)$fence['id']; ?>"><?php echo e($fence['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Prefills the enrol form. Saving here changes no existing account — use “Apply” for that.</div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn" type="submit">Save timetable</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($attCanManage && $enrolled): ?>
<div class="modal-backdrop" id="monitorRulesModal">
    <div class="modal" style="max-width:520px">
        <form method="post">
            <div class="modal-head">
                <h3>Work rules — <span data-modal-subject></span></h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="update_monitor_account">
                <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                <input type="hidden" name="att_employee_id">
                <div class="field">
                    <label for="m_geofence">Work area</label>
                    <select id="m_geofence" name="geofence_id">
                        <option value="">None — no geofence checks</option>
                        <?php foreach ($geofences as $fence): ?>
                            <option value="<?php echo (int)$fence['id']; ?>"><?php echo e($fence['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">The app enforces and maps against this area from its next sync.</div>
                </div>
                <div class="field">
                    <label for="m_sessions">Daily check-in/out sessions</label>
                    <select id="m_sessions" name="sessions_per_day">
                        <option value="1">One — checks in and out once</option>
                        <option value="2">Two — a split shift, in and out twice</option>
                    </select>
                    <div class="help">Two sessions enables the second check-in/out in the app.</div>
                </div>
                <div class="grid" id="monitorShift2Row">
                    <div class="field">
                        <label for="m_shift2_start">Second shift start</label>
                        <input type="time" id="m_shift2_start" name="shift2_start">
                    </div>
                    <div class="field">
                        <label for="m_shift2_end">Second shift end</label>
                        <input type="time" id="m_shift2_end" name="shift2_end">
                    </div>
                </div>
                <div class="help">Leave both blank to measure the afternoon against the morning hours.</div>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn" type="submit">Save rules</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var all = document.getElementById('pickAll');
    var picks = Array.prototype.slice.call(document.querySelectorAll('.pick'));
    var label = document.getElementById('pickCount');
    var sessions = document.getElementById('sessions_per_day');
    var shift2Row = document.getElementById('enrolShift2Row');

    function refresh() {
        var n = picks.filter(function (p) { return p.checked; }).length;
        if (label) label.textContent = n === 0 ? 'none selected' : n + ' selected';
    }

    function refreshShift2() {
        if (shift2Row) shift2Row.style.display = (sessions && sessions.value === '2') ? '' : 'none';
        var mSessions = document.getElementById('m_sessions');
        var mRow = document.getElementById('monitorShift2Row');
        if (mSessions && mRow) mRow.style.display = mSessions.value === '2' ? '' : 'none';
        var psSessions = document.getElementById('ps_sessions');
        var psRow = document.getElementById('projectShift2Row');
        if (psSessions && psRow) psRow.style.display = psSessions.value === '2' ? '' : 'none';
    }

    if (all) {
        all.addEventListener('change', function () {
            picks.forEach(function (p) { p.checked = all.checked; });
            refresh();
        });
    }
    picks.forEach(function (p) { p.addEventListener('change', refresh); });
    if (sessions) sessions.addEventListener('change', refreshShift2);
    document.addEventListener('change', function (e) {
        if (e.target && (e.target.id === 'm_sessions' || e.target.id === 'ps_sessions')) refreshShift2();
    });
    document.addEventListener('att:rules-loaded', refreshShift2);
    // If any part-timer is ticked, suggest two sessions — the admin can override.
    picks.forEach(function (p) {
        p.addEventListener('change', function () {
            if (p.checked && p.getAttribute('data-parttime') === '1' && sessions) {
                sessions.value = '2';
                refreshShift2();
            }
        });
    });
    refresh();
    refreshShift2();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/layout_bottom.php'; ?>
