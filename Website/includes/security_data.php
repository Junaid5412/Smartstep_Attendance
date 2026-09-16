<?php
/**
 * Queries behind admin/security.php.
 *
 * Kept separate from admin_data.php because these answer a different question: not
 * "where is everyone" but "what has been tampered with, and who changed the rules".
 */

/**
 * Days on which simulated positions were recorded, newest first.
 *
 * Grouped by employee and work date rather than listed per point. A spoofing session
 * produces dozens of mock fixes a minute apart, and a flat list of them buries the one
 * fact that matters — which day, whose phone, and for how long.
 *
 * Note this is a record of what the *app reported*. A patched build could stop setting
 * the flag, which is why the impossible-travel check exists as well: that one is derived
 * from stored positions and cannot be switched off from the handset.
 */
function attMockHistory($limit = 200) {
    $stmt = attDB()->prepare("
        SELECT l.att_employee_id, l.work_date,
               emp.employee_code,
               CONCAT(emp.first_name, ' ', emp.last_name) AS name,
               COUNT(*) AS mock_points,
               MIN(l.recorded_at) AS first_at,
               MAX(l.recorded_at) AS last_at,
               MAX(l.distance_from_fence_m) AS max_distance_m,
               SUM(l.inside_fence = 0) AS outside_points,
               SUM(l.real_lat IS NOT NULL) AS real_captured,
               s.allow_mock_location
        FROM att_location_logs l
        JOIN att_employees e ON e.id = l.att_employee_id
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        LEFT JOIN att_employee_settings s ON s.att_employee_id = e.id
        WHERE l.is_mock = 1
        GROUP BY l.att_employee_id, l.work_date, emp.employee_code, emp.first_name,
                 emp.last_name, s.allow_mock_location
        ORDER BY l.work_date DESC, mock_points DESC
        LIMIT " . max(1, (int)$limit) . "
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Accounts currently allowed to report simulated positions. */
function attMockAllowed() {
    return attDB()->query("
        SELECT e.id, emp.employee_code,
               CONCAT(emp.first_name, ' ', emp.last_name) AS name,
               e.is_active
        FROM att_employee_settings s
        JOIN att_employees e ON e.id = s.att_employee_id
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        WHERE s.allow_mock_location = 1
        ORDER BY emp.first_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** Accounts the server is currently refusing, with the reason it refused them. */
function attBlockedAccounts() {
    return attDB()->query("
        SELECT e.id, emp.employee_code,
               CONCAT(emp.first_name, ' ', emp.last_name) AS name,
               e.security_blocked_at, e.security_block_reason
        FROM att_employees e
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        WHERE e.security_blocked_at IS NOT NULL
        ORDER BY e.security_blocked_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The security-relevant slice of the audit log.
 *
 * Blocks, unblocks, device releases and rule changes that touched the mock-location
 * permission. Deliberately not the whole log: a page that shows everything is one
 * nobody reads, and these are the entries that answer "who allowed this".
 */
function attSecurityAudit($limit = 100) {
    $stmt = attDB()->prepare("
        SELECT a.id, a.action, a.entity_id, a.details, a.created_at, a.actor_id,
               a.actor_type, a.ip_address,
               CONCAT(emp.first_name, ' ', emp.last_name) AS subject_name,
               emp.employee_code AS subject_code
        FROM att_audit_logs a
        LEFT JOIN att_employees e ON e.id = a.entity_id AND a.entity = 'att_employee'
        LEFT JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        WHERE a.action IN ('security_blocked', 'security_unblocked', 'device_reset',
                           'employee_disabled', 'employee_enabled', 'impossible_travel',
                           'home_area_entered', 'fake_gps_detected',
                           'uninstall_protection_off')
           OR (a.action = 'employee_settings_saved'
               AND a.details LIKE '%mock_location_permission%')
        ORDER BY a.id DESC
        LIMIT " . max(1, (int)$limit) . "
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
