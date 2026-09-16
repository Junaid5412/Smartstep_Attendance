<?php
/**
 * GET /api/v1/trips?limit=&offset=
 *
 * Every departure from the work area this employee has had, newest first, with the
 * full story of each: when they left and returned, how far, what they said, and what
 * the administrator decided.
 *
 * Separate from /me on purpose. /me answers "what must I deal with right now" and is
 * polled every fifteen seconds; this is the history behind it, read only when the
 * employee opens their notifications. Sending the whole log on every poll would make
 * the common case pay for the rare one.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

$auth = attAuthenticate();

$limit = (int)(attParam('limit') ?? 50);
$limit = max(1, min(200, $limit));
$offset = max(0, (int)(attParam('offset') ?? 0));

$stmt = attDB()->prepare("
    SELECT ev.id, ev.work_date, ev.exit_at, ev.entry_at, ev.duration_min,
           ev.max_distance_m, ev.category, ev.employee_reason, ev.reason_submitted_at,
           ev.review_status, ev.review_note, ev.reviewed_at,
           ev.exit_lat, ev.exit_lng,
           g.name AS geofence_name,
           u.username AS reviewed_by_name
    FROM att_geofence_events ev
    LEFT JOIN att_geofences g ON g.id = ev.geofence_id
    LEFT JOIN users u ON u.id = ev.reviewed_by
    WHERE ev.att_employee_id = ?
    ORDER BY ev.exit_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $auth['att_employee_id'], PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$trips = array_map(function ($row) {
    return [
        'id' => (int)$row['id'],
        'work_date' => $row['work_date'],
        'exit_at' => $row['exit_at'],
        'entry_at' => $row['entry_at'],
        'duration_min' => $row['duration_min'] !== null ? (int)$row['duration_min'] : null,
        'max_distance_m' => (int)$row['max_distance_m'],
        'geofence_name' => $row['geofence_name'],
        'category' => $row['category'],
        'employee_reason' => $row['employee_reason'],
        'reason_submitted_at' => $row['reason_submitted_at'],
        'review_status' => $row['review_status'],
        'review_note' => $row['review_note'],
        'reviewed_at' => $row['reviewed_at'],
        'reviewed_by_name' => $row['reviewed_by_name'],
        // Derived here so the app does not have to re-implement the same three-way
        // decision and risk disagreeing with the panel about what a trip's state is.
        'state' => $row['reason_submitted_at'] === null
            ? 'needs_reason'
            : (in_array($row['review_status'], ['approved', 'rejected'], true)
                ? $row['review_status']
                : 'awaiting_review'),
        'still_out' => $row['entry_at'] === null,
    ];
}, $rows);

$counts = attDB()->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(reason_submitted_at IS NULL) AS needs_reason,
        SUM(reason_submitted_at IS NOT NULL AND review_status = 'pending') AS awaiting_review,
        SUM(review_status = 'approved') AS approved,
        SUM(review_status = 'rejected') AS rejected
    FROM att_geofence_events
    WHERE att_employee_id = ?
");
$counts->execute([$auth['att_employee_id']]);
$summary = $counts->fetch(PDO::FETCH_ASSOC) ?: [];

attOk([
    'trips' => $trips,
    'summary' => array_map('intval', [
        'total' => $summary['total'] ?? 0,
        'needs_reason' => $summary['needs_reason'] ?? 0,
        'awaiting_review' => $summary['awaiting_review'] ?? 0,
        'approved' => $summary['approved'] ?? 0,
        'rejected' => $summary['rejected'] ?? 0,
    ]),
    'has_more' => count($rows) === $limit,
    'server_time' => date('c'),
]);
