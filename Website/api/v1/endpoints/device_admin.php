<?php
/**
 * POST /api/v1/device-admin   { "active": true|false }
 *
 * The handset reporting whether uninstall protection is on. Sent when it is switched on
 * or off, including when the employee turns it off themselves — which is the case worth
 * knowing about, and the reason this is a push rather than something the panel asks for.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

attRequireMethod('POST');

$auth = attAuthenticate();
$active = filter_var(attParam('active'), FILTER_VALIDATE_BOOLEAN);

$db = attDB();
$db->prepare("UPDATE att_devices SET admin_active = ?, admin_reported_at = NOW() WHERE id = ?")
   ->execute([$active ? 1 : 0, $auth['device_id']]);

// Protection going away is logged; switching it on is not. One is a change in what the
// company can rely on, the other is the expected state.
if (!$active) {
    attAudit('uninstall_protection_off', 'att_employee', $auth['att_employee_id'], [
        'summary' => 'Removal protection was switched off on this phone',
        'device_id' => (int)$auth['device_id'],
    ], 'system', null);
}

attOk(['recorded' => true]);
