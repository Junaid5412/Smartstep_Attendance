<?php
/**
 * POST /api/v1/logout
 *
 * Revokes the session but deliberately keeps the device binding: signing out must
 * not become a way to move the account to a different handset. Only an admin
 * reset releases the binding.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

attRequireMethod('POST');

$auth = attAuthenticate();

attDB()->prepare("UPDATE att_tokens SET revoked_at = NOW() WHERE id = ?")
       ->execute([$auth['token_id']]);

attAudit('logout', 'att_employee', (int)$auth['att_employee_id'], null, 'employee', (int)$auth['att_employee_id']);

attOk(null, 'Signed out.');
