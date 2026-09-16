<?php
/**
 * Live board feed: latest known position of every active account.
 *
 * Same login as the Website admin panel (via its bootstrap), but no role
 * gating inside — this board shows everything to whoever may open it.
 */

require_once __DIR__ . '/../Website/admin/bootstrap.php';

// A feed must always answer JSON. Any stray notice emitted above would corrupt
// it, and the board would flash "session expired" for what was really a slow
// first query — so discard accidental output and envelope real failures.
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');

try {

$type = $_GET['type'] ?? 'all';
if (!in_array($type, ['all', 'employee', 'monitor', 'driver'], true)) {
    $type = 'all';
}
$projectId = (int)($_GET['project'] ?? 0) ?: null;
$today = date('Y-m-d');

$sql = "
    SELECT e.id AS att_employee_id, e.person_type,
           emp.employee_code,
           CONCAT(emp.first_name, ' ', emp.last_name) AS name,
           p.project_name,
           s.shift_start, s.shift_end,
           a.check_in_at, a.check_out_at, a.status,
           l.lat, l.lng, l.accuracy_m, l.battery_pct, l.speed_kmh,
           l.inside_fence, l.recorded_at,
           lp.lat AS live_lat, lp.lng AS live_lng, lp.accuracy_m AS live_accuracy,
           lp.battery_pct AS live_battery, lp.is_mock AS live_mock,
           lp.recorded_at AS live_at,
           d.device_model, d.last_seen_at
    FROM att_employees e
    JOIN att_person emp ON emp.person_type = e.person_type
      AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
    LEFT JOIN projects p ON p.id = emp.project_id
    LEFT JOIN att_employee_settings s ON s.att_employee_id = e.id
    LEFT JOIN att_attendance a ON a.id = (
        SELECT a2.id FROM att_attendance a2
        WHERE a2.att_employee_id = e.id AND a2.work_date = ?
        ORDER BY (a2.check_out_at IS NULL AND a2.check_in_at IS NOT NULL) DESC, a2.session_no DESC
        LIMIT 1
    )
    LEFT JOIN att_location_logs l ON l.id = (
        SELECT l2.id FROM att_location_logs l2
        WHERE l2.att_employee_id = e.id
        ORDER BY l2.recorded_at DESC LIMIT 1
    )
    -- The board's own channel: one row per employee, around the clock. The
    -- register never reads it, and it never feeds the register — preferred
    -- here whenever it is fresher than the last route point.
    LEFT JOIN att_live_pings lp ON lp.att_employee_id = e.id
    LEFT JOIN att_devices d ON d.att_employee_id = e.id AND d.status = 'active'
    WHERE e.is_active = 1
";
$params = [$today];

if ($type !== 'all') {
    $sql .= " AND e.person_type = ?";
    $params[] = $type;
}
if ($projectId) {
    $sql .= " AND emp.project_id = ?";
    $params[] = $projectId;
}
$sql .= " ORDER BY emp.first_name, emp.last_name";

$stmt = attDB()->prepare($sql);
$stmt->execute($params);

$staff = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    // Newest wins between the route trail and the board channel. Off shift the
    // trail goes quiet and the board ping carries; on shift the trail is newer.
    $useLive = $row['live_at'] !== null
        && ($row['recorded_at'] === null || strtotime($row['live_at']) >= strtotime($row['recorded_at']));

    $lat = $useLive ? $row['live_lat'] : $row['lat'];
    $lng = $useLive ? $row['live_lng'] : $row['lng'];
    $recordedAt = $useLive ? $row['live_at'] : $row['recorded_at'];
    $hasPosition = $lat !== null;
    $ageMin = $hasPosition
        ? (int)round((time() - strtotime($recordedAt)) / 60)
        : null;

    $staff[] = [
        'id' => (int)$row['att_employee_id'],
        'type' => $row['person_type'],
        'code' => $row['employee_code'],
        'name' => $row['name'],
        'project' => $row['project_name'],
        'shift' => $row['shift_start']
            ? substr($row['shift_start'], 0, 5) . '–' . substr($row['shift_end'], 0, 5) : null,
        'checked_in' => $row['check_in_at'] !== null,
        'checked_out' => $row['check_out_at'] !== null,
        'status' => $row['status'] ?? ($row['check_in_at'] ? 'incomplete' : 'absent'),
        'source' => $hasPosition ? ($useLive ? 'live' : 'route') : null,
        'has_position' => $hasPosition,
        'lat' => $hasPosition ? (float)$lat : null,
        'lng' => $hasPosition ? (float)$lng : null,
        'accuracy_m' => ($useLive ? $row['live_accuracy'] : $row['accuracy_m']) !== null
            ? (float)($useLive ? $row['live_accuracy'] : $row['accuracy_m']) : null,
        'battery_pct' => ($useLive ? $row['live_battery'] : $row['battery_pct']) !== null
            ? (int)($useLive ? $row['live_battery'] : $row['battery_pct']) : null,
        'speed_kmh' => !$useLive && $row['speed_kmh'] !== null ? (float)$row['speed_kmh'] : null,
        'inside_fence' => !$useLive && $row['inside_fence'] !== null ? (bool)$row['inside_fence'] : null,
        'is_mock' => $useLive ? (bool)$row['live_mock'] : null,
        'recorded_at' => $recordedAt,
        'age_min' => $ageMin,
        'stale' => $hasPosition && $ageMin > 30,
        'device' => $row['device_model'],
        'last_seen_at' => $row['last_seen_at'],
    ];
}

$fences = [];
foreach (attGeofenceList(true) as $fence) {
    $payload = attGeofencePayload($fence);
    if ($payload) {
        $fences[] = $payload;
    }
}

echo json_encode([
    'staff' => $staff,
    'geofences' => $fences,
    'server_time' => date('c'),
], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'feed_failed']);
}
