<?php
/**
 * Route replay: one employee, one day — where they went, and which parts of it
 * were outside their assigned area.
 */

require_once __DIR__ . '/bootstrap.php';

// Clearing one day's route so a test can be repeated. Narrow by design: this page is
// always looking at exactly one employee on one date, which is the right scope for it.
// Erasing a drawn area. The ids come from the map, where the admin has just seen exactly
// which points were highlighted.
if (($_POST['action'] ?? null) === 'erase_points') {
    attCheckCsrf();
    attRequireManage();

    $target = (int)($_POST['att_employee_id'] ?? 0);
    $targetDate = $_POST['work_date'] ?? '';
    $ids = array_filter(explode(',', (string)($_POST['point_ids'] ?? '')));

    $result = attDeleteRoutePoints($target, $targetDate, $ids, (int)$attAdmin['id']);
    attFlash($result['ok'] ? 'success' : 'error', $result['message']);
    attRedirect('routes.php?employee=' . $target . '&date=' . urlencode($targetDate));
}

if (($_POST['action'] ?? null) === 'clear_route') {
    attCheckCsrf();
    attRequireManage();

    $target = (int)($_POST['att_employee_id'] ?? 0);
    $targetDate = $_POST['work_date'] ?? '';
    $result = attClearRoute($target, $targetDate, !empty($_POST['include_trips']), (int)$attAdmin['id']);

    attFlash($result['ok'] ? 'success' : 'error', $result['message']);
    attRedirect('routes.php?employee=' . $target . '&date=' . urlencode($targetDate));
}

$attEmployeeId = (int)($_GET['employee'] ?? 0);
$workDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    $workDate = date('Y-m-d');
}

$people = attDB()->query("
    SELECT e.id, emp.employee_code, CONCAT(emp.first_name, ' ', emp.last_name) AS name
    FROM att_employees e
    JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
    WHERE e.is_active = 1
    ORDER BY emp.first_name
")->fetchAll(PDO::FETCH_ASSOC);

$employee = $attEmployeeId ? attEmployee($attEmployeeId) : null;
$routeFootprint = $attEmployeeId ? attRouteFootprint($attEmployeeId, $workDate) : null;

$pageTitle = 'Routes';
$pageSubtitle = $employee
    ? $employee['name'] . ' · ' . date('l, d F Y', strtotime($workDate))
    : 'Pick an employee and a date';
$needsMap = true;
include __DIR__ . '/layout_top.php';
?>

<form class="toolbar" method="get">
    <div class="field" style="min-width:250px">
        <label for="employee">Employee</label>
        <select id="employee" name="employee" data-autosubmit>
            <option value="">Select an employee…</option>
            <?php foreach ($people as $person): ?>
                <option value="<?php echo (int)$person['id']; ?>"
                    <?php echo $attEmployeeId === (int)$person['id'] ? 'selected' : ''; ?>>
                    <?php echo e($person['name'] . ' (' . $person['employee_code'] . ')'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="date">Date</label>
        <input type="date" id="date" name="date" value="<?php echo e($workDate); ?>" max="<?php echo date('Y-m-d'); ?>" data-autosubmit>
    </div>
    <button class="btn ghost" type="submit">Show route</button>
</form>

<?php if ($employee && $attCanManage && $routeFootprint && $routeFootprint['points'] > 0): ?>
<div class="card" style="margin-bottom:16px">
    <div class="card-body">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <strong style="font-size:13px">Eraser</strong>
            <span style="font-size:12.5px;color:var(--ink-soft)">
                Draw round the points you want gone. Nothing is deleted until you confirm, and
                the most recent position is always kept.
            </span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:11px">
            <button class="btn ghost small" type="button" id="eraseArea">Draw an area</button>
            <button class="btn ghost small" type="button" id="eraseCircle">Draw a circle</button>
            <button class="btn ghost small" type="button" id="eraseClear" style="display:none">Start again</button>
            <span id="eraseCount" style="font-size:12.5px;color:var(--ink-soft)"></span>
            <form method="post" id="eraseForm" style="display:none;margin-left:auto"
                  data-confirm="Delete the highlighted positions? This cannot be undone.">
                <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                <input type="hidden" name="action" value="erase_points">
                <input type="hidden" name="att_employee_id" value="<?php echo (int)$attEmployeeId; ?>">
                <input type="hidden" name="work_date" value="<?php echo e($workDate); ?>">
                <input type="hidden" name="point_ids" id="erasePointIds">
                <button class="btn danger small" type="submit" id="eraseSubmit">Delete selected</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($employee && $attCanManage && $routeFootprint && $routeFootprint['points'] > 0): ?>
<div class="card" style="margin-bottom:16px">
    <div class="card-body" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <div style="flex:1;min-width:260px;font-size:12.5px;color:var(--ink-soft)">
            <strong style="color:var(--ink)">Clear this day's route</strong> — removes the
            <?php echo number_format($routeFootprint['points']); ?> recorded position<?php
                echo $routeFootprint['points'] === 1 ? '' : 's'; ?> for
            <?php echo e($employee['name']); ?> on <?php echo date('j M Y', strtotime($workDate)); ?>,
            so the day can be recorded again from scratch.
            <div style="margin-top:4px">Check-in, check-out, hours and photos are kept — this
                only clears the GPS trail.</div>
        </div>
        <form method="post" data-confirm="Delete <?php echo number_format($routeFootprint['points']); ?> recorded position<?php
                echo $routeFootprint['points'] === 1 ? '' : 's'; ?> for <?php echo e($employee['name']); ?> on <?php
                echo date('j M Y', strtotime($workDate)); ?>? This cannot be undone.">
            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
            <input type="hidden" name="action" value="clear_route">
            <input type="hidden" name="att_employee_id" value="<?php echo (int)$attEmployeeId; ?>">
            <input type="hidden" name="work_date" value="<?php echo e($workDate); ?>">
            <?php if ($routeFootprint['trips'] > 0): ?>
            <label style="display:block;font-size:12px;margin-bottom:7px">
                <input type="checkbox" name="include_trips" value="1">
                Also clear the <?php echo (int)$routeFootprint['trips']; ?> outside trip<?php
                    echo $routeFootprint['trips'] === 1 ? '' : 's'; ?> and their reasons
            </label>
            <?php endif; ?>
            <button class="btn danger" type="submit">Clear route</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$employee): ?>
    <div class="card"><div class="empty">Choose an employee above to see their route for the day.</div></div>
<?php else: ?>

<div class="tiles" id="routeTiles">
    <div class="tile"><div class="label">Check in</div><div class="value" style="font-size:22px" id="tIn">—</div>
        <div class="hint" id="tInNote">&nbsp;</div></div>
    <div class="tile"><div class="label">Check out</div><div class="value" style="font-size:22px" id="tOut">—</div>
        <div class="hint" id="tOutNote">&nbsp;</div></div>
    <div class="tile"><div class="label">Worked</div><div class="value" style="font-size:22px" id="tWorked">—</div></div>
    <div class="tile" id="tileOutside"><div class="label">Time outside</div>
        <div class="value" style="font-size:22px" id="tOutside">—</div>
        <div class="hint" id="tTrips">&nbsp;</div></div>
    <div class="tile"><div class="label">Distance travelled</div>
        <div class="value" style="font-size:22px" id="tDistance">—</div>
        <div class="hint" id="tStops">&nbsp;</div>
        <div class="hint" id="tPoints">&nbsp;</div></div>
</div>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Route</h2>
            <p>Green sections are inside the work area, red sections outside it.</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <?php /* Thins the drawn route without touching what was recorded, so a
                     1-minute log can be read at a 5-minute stride. Crossings are
                     always kept — see route-replay.js. */ ?>
            <label for="minGap" style="font-size:12.5px;color:var(--ink-soft)">Show every</label>
            <select id="minGap" class="small">
                <option value="0">All points</option>
                <option value="60">1 min</option>
                <option value="120">2 min</option>
                <option value="300">5 min</option>
                <option value="600">10 min</option>
                <option value="900">15 min</option>
                <option value="1800">30 min</option>
            </select>
            <button class="btn ghost small" type="button" id="playBtn">▶ Play</button>
            <input type="range" id="scrub" min="0" max="0" value="0" style="width:200px">
            <span id="scrubLabel" style="font-size:12.5px;color:var(--ink-soft);min-width:118px">—</span>
        </div>
    </div>
    <div class="card-body tight">
        <div id="routeMap" class="map"></div>
    </div>
    <div class="map-legend">
        <span><span class="dot inside"></span> inside area</span>
        <span><span class="dot outside"></span> outside area</span>
        <span>▲ check in</span>
        <span>■ check out</span>
        <span id="gapNote"></span>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>Trips outside the work area</h2><p id="tripsNote">—</p></div>
        <a class="btn ghost small" href="trips.php?employee=<?php echo (int)$attEmployeeId; ?>">Review queue</a>
    </div>
    <div class="card-body tight">
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Left</th><th>Returned</th><th class="num">Away</th><th class="num">Max distance</th>
                <th>Category</th><th>Employee's reason</th><th>Decision</th></tr></thead>
            <tbody id="tripsBody"><tr><td colspan="7" class="empty">Loading…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head"><div><h2>Check-in and check-out photos</h2></div></div>
    <div class="card-body" id="photoBox"><span style="color:var(--ink-soft)">Loading…</span></div>
</div>

<script>
window.ATT_ROUTE_REQUEST = {
    employee: <?php echo (int)$attEmployeeId; ?>,
    date: <?php echo json_encode($workDate); ?>
};
</script>
<?php
$footExtra = '<script src="' . ATT_ASSETS_URL . '/route-replay.js?v=' . ATT_VERSION . '"></script>';
endif;
include __DIR__ . '/layout_bottom.php';
?>
