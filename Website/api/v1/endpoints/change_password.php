<?php
/**
 * POST /api/v1/change-password
 * Body: current_password, new_password
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

attRequireMethod('POST');

$auth = attAuthenticate();
$fields = attRequire(['current_password', 'new_password']);

$stmt = attDB()->prepare("SELECT password FROM att_employees WHERE id = ? LIMIT 1");
$stmt->execute([$auth['att_employee_id']]);
$hash = $stmt->fetchColumn();

if (!$hash || !password_verify((string)$fields['current_password'], $hash)) {
    attFail('INVALID_CREDENTIALS', 'Current password is incorrect.', 401);
}

$new = (string)$fields['new_password'];
if (strlen($new) < 6) {
    attFail('PASSWORD_WEAK', 'New password must be at least 6 characters.', 422);
}
if (hash_equals($new, (string)$fields['current_password'])) {
    attFail('PASSWORD_SAME', 'New password must be different from the current one.', 422);
}

attDB()->prepare("
    UPDATE att_employees SET password = ?, must_change_password = 0 WHERE id = ?
")->execute([password_hash($new, PASSWORD_DEFAULT), $auth['att_employee_id']]);

attAudit('password_changed', 'att_employee', (int)$auth['att_employee_id'], null, 'employee', (int)$auth['att_employee_id']);

attOk(null, 'Password updated.');
