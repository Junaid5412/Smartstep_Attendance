<?php
/**
 * POST /api/v1/geofence-reason
 * Body: event_id, category, reason
 *
 * The employee explains a trip outside the assigned area. Whether it counted as
 * company work is then the admin's call, not the app's, so this only records the
 * claim and leaves review_status at 'pending'.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

attRequireMethod('POST');

$auth = attAuthenticate();
$fields = attRequire(['event_id', 'category']);

$allowed = ['company_work', 'client_visit', 'personal', 'break', 'transit', 'other'];
$category = (string)$fields['category'];
if (!in_array($category, $allowed, true)) {
    attFail('CATEGORY_INVALID', 'Category must be one of: ' . implode(', ', $allowed), 422);
}

$reason = trim((string)attParam('reason', ''));
if ($category !== 'break' && $reason === '') {
    attFail('REASON_REQUIRED', 'Please describe why you were outside your work area.', 422);
}

$stmt = attDB()->prepare("
    SELECT id, reason_submitted_at FROM att_geofence_events
    WHERE id = ? AND att_employee_id = ? LIMIT 1
");
$stmt->execute([(int)$fields['event_id'], $auth['att_employee_id']]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    attFail('EVENT_NOT_FOUND', 'That trip record was not found.', 404);
}

// Once an admin has ruled on it the employee can no longer rewrite the reason.
$reviewed = attDB()->prepare("SELECT review_status FROM att_geofence_events WHERE id = ?");
$reviewed->execute([$event['id']]);
if ($reviewed->fetchColumn() !== 'pending') {
    attFail('ALREADY_REVIEWED', 'This trip has already been reviewed by the administrator.', 409);
}

attDB()->prepare("
    UPDATE att_geofence_events
    SET category = ?, employee_reason = ?, reason_submitted_at = NOW()
    WHERE id = ?
")->execute([$category, $reason !== '' ? $reason : null, $event['id']]);

attAudit('geofence_reason', 'att_geofence_event', (int)$event['id'], [
    'category' => $category,
], 'employee', (int)$auth['att_employee_id']);

attOk(null, 'Reason submitted for review.');
