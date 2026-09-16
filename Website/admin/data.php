<?php
/**
 * JSON feed for the panel's own pages (live map polling, route loading).
 *
 * Separate from api/v1, which serves the mobile app: this one is guarded by the
 * admin session, not by an app bearer token.
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$what = $_GET['what'] ?? '';

// Requesting a live position changes state and is a monitoring action, so it is a
// POST behind the CSRF check and the manage role, not a readable GET.
if ($what === 'locate') {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required.']);
        exit;
    }
    attCheckCsrf();
    if (!attCanManage()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your role cannot request locations.']);
        exit;
    }

    $result = attRequestLocate((int)($_POST['employee'] ?? 0), (int)$attAdmin['id']);
    echo json_encode([
        'success' => $result['ok'],
        'message' => $result['message'],
        'poll_seconds' => attCommandPollSeconds(),
    ]);
    exit;
}

// Following someone in real time is a monitoring action with a real cost to their
// battery, so it is a POST behind CSRF and the manage role, and it is audit-logged.
if ($what === 'follow' || $what === 'unfollow') {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required.']);
        exit;
    }
    attCheckCsrf();
    if (!attCanManage()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your role cannot follow employees.']);
        exit;
    }

    $employee = (int)($_POST['employee'] ?? 0);
    $result = $what === 'follow'
        ? attStartFollow($employee, (int)$attAdmin['id'], (int)($_POST['seconds'] ?? 8),
            (int)($_POST['minutes'] ?? ATT_FOLLOW_MINUTES))
        : attStopFollow($employee, (int)$attAdmin['id']);

    echo json_encode([
        'success' => $result['ok'],
        'message' => $result['message'],
        'seconds' => $result['seconds'] ?? 0,
    ]);
    exit;
}

if ($what === 'live') {
    echo json_encode([
        'success' => true,
        'server_time' => date('c'),
        'people' => attLivePositions(),
        // So the map can show which requests are still outstanding.
        'locating' => attPendingLocateIds(),
        // Who is currently being followed, so the page can keep the window alive and
        // show it — including a follow another admin started.
        'following' => attFollowingIds(),
        'poll_seconds' => attCommandPollSeconds(),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($what === 'route') {
    $attEmployeeId = (int)($_GET['employee'] ?? 0);
    $workDate = $_GET['date'] ?? date('Y-m-d');

    if (!$attEmployeeId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'employee and date are required.']);
        exit;
    }

    $employee = attEmployee($attEmployeeId);
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Employee not found.']);
        exit;
    }

    $route = attRoute($attEmployeeId, $workDate);
    $attendance = attFindAttendance($attEmployeeId, $workDate);
    $trips = attTrips(['att_employee_id' => $attEmployeeId, 'work_date' => $workDate]);

    echo json_encode([
        'success' => true,
        'employee' => [
            'id' => (int)$employee['id'],
            'name' => $employee['name'],
            'employee_code' => $employee['employee_code'],
        ],
        'geofence' => $employee['geofence_id']
            ? attGeofencePayload(attGeofence($employee['geofence_id']))
            : null,
        'route' => $route,
        'attendance' => $attendance ? [
            'check_in_at' => $attendance['check_in_at'],
            'check_out_at' => $attendance['check_out_at'],
            'check_in_lat' => $attendance['check_in_lat'] !== null ? (float)$attendance['check_in_lat'] : null,
            'check_in_lng' => $attendance['check_in_lng'] !== null ? (float)$attendance['check_in_lng'] : null,
            'check_out_lat' => $attendance['check_out_lat'] !== null ? (float)$attendance['check_out_lat'] : null,
            'check_out_lng' => $attendance['check_out_lng'] !== null ? (float)$attendance['check_out_lng'] : null,
            'check_in_photo_url' => attPhotoUrl($attendance['check_in_photo']),
            'check_out_photo_url' => attPhotoUrl($attendance['check_out_photo']),
            'worked_minutes' => $attendance['worked_minutes'] !== null ? (int)$attendance['worked_minutes'] : null,
            'outside_minutes' => (int)$attendance['outside_minutes'],
            'status' => $attendance['status'],
        ] : null,
        'trips' => array_map(function ($trip) {
            return [
                'id' => (int)$trip['id'],
                'exit_at' => $trip['exit_at'],
                'entry_at' => $trip['entry_at'],
                'duration_min' => $trip['duration_min'] !== null ? (int)$trip['duration_min'] : null,
                'max_distance_m' => (int)$trip['max_distance_m'],
                'farthest_lat' => $trip['farthest_lat'] !== null ? (float)$trip['farthest_lat'] : null,
                'farthest_lng' => $trip['farthest_lng'] !== null ? (float)$trip['farthest_lng'] : null,
                'category' => $trip['category'],
                'employee_reason' => $trip['employee_reason'],
                'review_status' => $trip['review_status'],
            ];
        }, $trips),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'Unknown request.']);
