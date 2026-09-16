<?php
/**
 * POST /api/v1/consent
 *
 * Records that the employee read and accepted the background-location disclosure.
 * The app blocks tracking until this succeeds, which is what Play Store policy
 * for background location requires and what makes the monitoring defensible.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

attRequireMethod('POST');

$auth = attAuthenticate();

if (!filter_var(attParam('accepted'), FILTER_VALIDATE_BOOLEAN)) {
    attFail('CONSENT_REQUIRED', 'Location tracking consent is required to use the app.', 422);
}

attDB()->prepare("UPDATE att_employees SET consent_accepted_at = NOW() WHERE id = ?")
       ->execute([$auth['att_employee_id']]);

attAudit('consent_accepted', 'att_employee', (int)$auth['att_employee_id'], null, 'employee', (int)$auth['att_employee_id']);

attOk(['accepted_at' => date('c')], 'Consent recorded.');
