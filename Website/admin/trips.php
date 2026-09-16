<?php
/**
 * Review queue for trips outside the work area — the page that answers
 * "was he out for company work, or somewhere else?"
 */

require_once __DIR__ . '/bootstrap.php';

if (($_POST['action'] ?? null) === 'review') {
    attCheckCsrf();
    attRequireManage();

    $id = (int)$_POST['event_id'];
    $verdict = $_POST['verdict'] === 'approved' ? 'approved' : 'rejected';
    $note = trim($_POST['review_note'] ?? '');

    // How much of the trip counts as duty. Only meaningful on approval, and only the
    // offered steps are accepted — a crafted request must not credit arbitrary hours.
    // A rejected trip always clears any earlier credit, so flipping a decision cannot
    // leave the pay from the old one behind.
    $creditSteps = [0, 30, 60, 120, 180, 240, 300, 360];
    $approvedMinutes = null;
    if ($verdict === 'approved') {
        $requested = (int)($_POST['approved_minutes'] ?? 0);
        $approvedMinutes = in_array($requested, $creditSteps, true) && $requested > 0
            ? $requested : null;
    }

    // An admin may correct the category: employees sometimes pick the wrong one.
    $allowed = ['unspecified', 'company_work', 'client_visit', 'personal', 'break', 'transit', 'other'];
    $category = in_array($_POST['category'] ?? '', $allowed, true) ? $_POST['category'] : null;

    if ($category) {
        attDB()->prepare("
            UPDATE att_geofence_events
            SET review_status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW(),
                approved_minutes = ?, category = ?
            WHERE id = ?
        ")->execute([$verdict, $note ?: null, (int)$attAdmin['id'], $approvedMinutes, $category, $id]);
    } else {
        attDB()->prepare("
            UPDATE att_geofence_events
            SET review_status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW(),
                approved_minutes = ?
            WHERE id = ?
        ")->execute([$verdict, $note ?: null, (int)$attAdmin['id'], $approvedMinutes, $id]);
    }

    // The credit changes the day's worked time, so the register row is recomputed now,
    // not at the next check-out — for a closed day there is no next check-out.
    $tripRow = attDB()->prepare("
        SELECT att_employee_id, attendance_id FROM att_geofence_events WHERE id = ? LIMIT 1
    ");
    $tripRow->execute([$id]);
    $trip = $tripRow->fetch(PDO::FETCH_ASSOC);
    if ($trip && $trip['attendance_id']) {
        attFinaliseDay((int)$trip['attendance_id'], attEmployeeSettings((int)$trip['att_employee_id']));
    }

    attAudit('trip_reviewed', 'att_geofence_event', $id,
        ['verdict' => $verdict, 'approved_minutes' => $approvedMinutes],
        'admin', (int)$attAdmin['id']);
    attFlash('success', 'Trip marked as ' . $verdict .
        ($approvedMinutes ? ' — ' . ($approvedMinutes >= 60
            ? ($approvedMinutes / 60) . ' hour' . ($approvedMinutes > 60 ? 's' : '')
            : $approvedMinutes . ' minutes') . ' counted as duty' : '') . '.');
    attRedirect('trips.php?' . http_build_query(array_intersect_key($_GET, array_flip(
        ['review_status', 'employee', 'from', 'to', 'open_only']
    ))));
}

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
foreach (['from', 'to'] as $key) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$key)) {
        $$key = $key === 'from' ? date('Y-m-01') : date('Y-m-d');
    }
}

$filters = [
    'from' => $from,
    'to' => $to,
    'review_status' => $_GET['review_status'] ?? null,
    'att_employee_id' => (int)($_GET['employee'] ?? 0) ?: null,
    'open_only' => !empty($_GET['open_only']) ? 1 : null,
];

$trips = attTrips($filters);

$people = attDB()->query("
    SELECT e.id, CONCAT(emp.first_name, ' ', emp.last_name) AS name, emp.employee_code
    FROM att_employees e
    JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
    ORDER BY emp.first_name
")->fetchAll(PDO::FETCH_ASSOC);

$totals = ['count' => count($trips), 'minutes' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0];
foreach ($trips as $trip) {
    $totals['minutes'] += (int)$trip['duration_min'];
    $totals[$trip['review_status']]++;
}

$categoryLabels = [
    'unspecified' => 'Not given',
    'company_work' => 'Company work',
    'client_visit' => 'Client visit',
    'personal' => 'Personal',
    'break' => 'Break',
    'transit' => 'Travelling',
    'other' => 'Other',
];

$pageTitle = 'Outside Trips';
$pageSubtitle = 'Every time an employee left their assigned work area';
include __DIR__ . '/layout_top.php';
?>

<form class="toolbar" method="get">
    <div class="field">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?php echo e($from); ?>">
    </div>
    <div class="field">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?php echo e($to); ?>">
    </div>
    <div class="field" style="min-width:220px">
        <label for="employee">Employee</label>
        <select id="employee" name="employee" data-autosubmit>
            <option value="">Everyone</option>
            <?php foreach ($people as $person): ?>
                <option value="<?php echo (int)$person['id']; ?>"
                    <?php echo (int)$filters['att_employee_id'] === (int)$person['id'] ? 'selected' : ''; ?>>
                    <?php echo e($person['name'] . ' (' . $person['employee_code'] . ')'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="review_status">Decision</label>
        <select id="review_status" name="review_status" data-autosubmit>
            <option value="">Any</option>
            <?php foreach (['pending' => 'Awaiting review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label): ?>
                <option value="<?php echo $value; ?>" <?php echo $filters['review_status'] === $value ? 'selected' : ''; ?>>
                    <?php echo $label; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="open_only">Still outside</label>
        <select id="open_only" name="open_only" data-autosubmit>
            <option value="">No filter</option>
            <option value="1" <?php echo $filters['open_only'] ? 'selected' : ''; ?>>Not returned yet</option>
        </select>
    </div>
    <button class="btn ghost" type="submit">Apply</button>
</form>

<div class="tiles">
    <div class="tile"><div class="label">Trips</div><div class="value"><?php echo $totals['count']; ?></div></div>
    <div class="tile"><div class="label">Total time outside</div>
        <div class="value" style="font-size:22px"><?php echo attMinutes($totals['minutes']); ?></div></div>
    <div class="tile<?php echo $totals['pending'] > 0 ? ' attention' : ''; ?>">
        <div class="label">Awaiting review</div><div class="value"><?php echo $totals['pending']; ?></div></div>
    <div class="tile good"><div class="label">Approved</div><div class="value"><?php echo $totals['approved']; ?></div></div>
    <div class="tile<?php echo $totals['rejected'] > 0 ? ' alert' : ''; ?>">
        <div class="label">Rejected</div><div class="value"><?php echo $totals['rejected']; ?></div></div>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (!$trips): ?>
            <div class="empty">No trips outside the work area for these filters.</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead>
                <tr><th>Employee</th><th>Date</th><th>Left</th><th>Returned</th>
                    <th class="num">Away</th><th class="num">Max distance</th>
                    <th>Category</th><th>Employee's reason</th><th>Decision</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($trips as $trip): ?>
                <tr>
                    <td class="who-cell">
                        <strong><?php echo e($trip['name']); ?></strong>
                        <small><?php echo e($trip['employee_code']); ?><?php
                            echo $trip['geofence_name'] ? ' · ' . e($trip['geofence_name']) : ''; ?></small>
                    </td>
                    <td><?php echo date('d M Y', strtotime($trip['work_date'])); ?></td>
                    <td><?php echo attTime($trip['exit_at']); ?></td>
                    <td><?php echo $trip['entry_at']
                        ? attTime($trip['entry_at'])
                        : '<span class="badge bad">still outside</span>'; ?></td>
                    <td class="num"><?php echo attMinutes($trip['duration_min']); ?></td>
                    <td class="num"><?php echo number_format((int)$trip['max_distance_m']); ?> m</td>
                    <td>
                        <span class="badge <?php echo $trip['category'] === 'unspecified' ? 'muted' : 'info'; ?>">
                            <?php echo e($categoryLabels[$trip['category']] ?? $trip['category']); ?>
                        </span>
                    </td>
                    <td style="max-width:260px">
                        <?php if ($trip['employee_reason']): ?>
                            <?php echo e($trip['employee_reason']); ?>
                        <?php else: ?>
                            <span class="badge muted">no reason submitted</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $verdictClass = ['pending' => 'info', 'approved' => 'ok', 'rejected' => 'bad'];
                        ?>
                        <span class="badge <?php echo $verdictClass[$trip['review_status']]; ?>">
                            <?php echo e($trip['review_status']); ?>
                        </span>
                        <?php if ($trip['reviewed_by_name']): ?>
                            <br><small style="color:var(--ink-soft)">by <?php echo e($trip['reviewed_by_name']); ?></small>
                        <?php endif; ?>
                        <?php if ($trip['review_status'] === 'approved'): ?>
                            <br><small style="color:<?php echo $trip['approved_minutes'] ? 'var(--ok)' : 'var(--ink-soft)'; ?>">
                                <?php echo $trip['approved_minutes']
                                    ? attMinutes(min((int)$trip['approved_minutes'],
                                          (int)($trip['duration_min'] ?: $trip['approved_minutes']))) . ' counted as duty'
                                    : 'no duty credit — time deducted'; ?>
                            </small>
                        <?php endif; ?>
                        <?php if ($trip['review_note']): ?>
                            <br><small><?php echo e($trip['review_note']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <a class="btn ghost small"
                           href="routes.php?employee=<?php echo (int)$trip['att_employee_id']; ?>&date=<?php echo e($trip['work_date']); ?>">Route</a>
                        <?php if ($attCanManage): ?>
                        <button class="btn small" type="button"
                                data-modal-open="reviewModal"
                                data-subject="<?php echo e($trip['name'] . ' · ' . date('d M', strtotime($trip['work_date'])) .
                                    ' · ' . attMinutes($trip['duration_min']) . ' away, ' .
                                    number_format((int)$trip['max_distance_m']) . ' m out'); ?>"
                                data-set-event_id="<?php echo (int)$trip['id']; ?>"
                                data-set-category="<?php echo e($trip['category']); ?>"
                                data-set-review_note="<?php echo e($trip['review_note']); ?>">Decide</button>
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
<div class="modal-backdrop" id="reviewModal">
    <div class="modal" style="max-width:520px">
        <form method="post">
            <div class="modal-head">
                <h3>Review trip</h3>
                <button class="x-close" type="button" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="review">
                <input type="hidden" name="event_id">
                <p style="margin:0 0 16px"><strong data-modal-subject></strong></p>

                <div class="field">
                    <label for="v_category">Category <span class="opt">(correct it if the employee chose wrongly)</span></label>
                    <select id="v_category" name="category">
                        <?php foreach ($categoryLabels as $value => $label): ?>
                            <option value="<?php echo $value; ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="v_credit">Count as duty <span class="opt">(if approving)</span></label>
                    <select id="v_credit" name="approved_minutes">
                        <option value="0">None — deduct the whole trip from worked hours</option>
                        <option value="30">30 minutes</option>
                        <option value="60">1 hour</option>
                        <option value="120">2 hours</option>
                        <option value="180">3 hours</option>
                        <option value="240">4 hours</option>
                        <option value="300">5 hours</option>
                        <option value="360">6 hours</option>
                    </select>
                    <div class="help">Up to this much of the trip is paid as working time; anything
                        beyond it is still deducted. Capped at the trip's real length, so crediting
                        2 hours of a 40-minute trip credits 40 minutes. Ignored when rejecting.</div>
                </div>

                <div class="field">
                    <label for="v_note">Note <span class="opt">(optional)</span></label>
                    <input type="text" id="v_note" name="review_note" placeholder="e.g. confirmed with site supervisor">
                </div>

                <p style="color:var(--ink-soft);font-size:12.5px;margin:0">
                    Approving records the time away as legitimate. Without a duty credit the time
                    still comes off worked hours — the credit is what pays it.
                </p>
            </div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn danger" type="submit" name="verdict" value="rejected">Reject</button>
                <button class="btn" type="submit" name="verdict" value="approved">Approve</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/layout_bottom.php'; ?>
