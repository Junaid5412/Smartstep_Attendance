<?php
/**
 * App authentication and the one-device rule.
 *
 * Tokens are opaque random strings; only their SHA-256 is stored, and each one is
 * tied to the att_devices row it was issued under. If an admin resets the device,
 * that row leaves 'active' status and every token issued for it stops working —
 * so a reset logs the old handset out without needing a push message.
 */

/** Issue a bearer token for an employee/device pair. Returns the plaintext token. */
function attIssueToken($attEmployeeId, $deviceId) {
    $settings = attSettings();
    $lifetimeDays = (int)($settings['token_lifetime_days'] ?? 30);

    $token = bin2hex(random_bytes(32));
    $stmt = attDB()->prepare("
        INSERT INTO att_tokens (att_employee_id, device_id, token_hash, expires_at)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))
    ");
    $stmt->execute([$attEmployeeId, $deviceId, hash('sha256', $token), $lifetimeDays]);

    return $token;
}

/** Extract the bearer token from the Authorization header. */
function attBearerToken() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
        return $matches[1];
    }
    // Some shared hosts strip the Authorization header; the app also sends the
    // token in this fallback header so it still works there.
    return $_SERVER['HTTP_X_APP_TOKEN'] ?? null;
}

/**
 * Resolve the caller, or end the request with an error the app knows how to show.
 *
 * @return array employee + settings + geofence + device, ready for endpoint use.
 */
function attAuthenticate() {
    $token = attBearerToken();
    if (!$token) {
        attFail('UNAUTHENTICATED', 'Authentication token missing.', 401);
    }

    $stmt = attDB()->prepare("
        SELECT t.id AS token_id, t.expires_at, t.revoked_at,
               e.id AS att_employee_id, e.employee_id, e.person_type, e.person_ref,
               e.login_code, e.is_active,
               e.consent_accepted_at, e.must_change_password,
               e.security_blocked_at, e.security_block_reason,
               d.id AS device_id, d.device_uid, d.status AS device_status, d.app_version,
               emp.employee_code, emp.first_name, emp.last_name, emp.photo, emp.position,
               emp.employment_status
        FROM att_tokens t
        JOIN att_employees e ON e.id = t.att_employee_id
        JOIN att_devices d ON d.id = t.device_id
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        WHERE t.token_hash = ?
        LIMIT 1
    ");
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        attFail('INVALID_TOKEN', 'Session not recognised. Please sign in again.', 401);
    }
    if ($row['revoked_at'] !== null) {
        attFail('TOKEN_REVOKED', 'Session ended. Please sign in again.', 401);
    }
    if (strtotime($row['expires_at']) < time()) {
        attFail('TOKEN_EXPIRED', 'Session expired. Please sign in again.', 401);
    }
    if ($row['device_status'] !== 'active') {
        // The admin released or blocked this handset while it was still logged in.
        attFail('DEVICE_RESET', 'This device was unlinked by the administrator. Please sign in again.', 401);
    }
    if ((int)$row['is_active'] !== 1) {
        attFail('ACCOUNT_DISABLED', 'Your attendance account has been disabled.', 403);
    }

    attDB()->prepare("UPDATE att_tokens SET last_used_at = NOW() WHERE id = ?")
           ->execute([$row['token_id']]);
    attDB()->prepare("UPDATE att_devices SET last_seen_at = NOW() WHERE id = ?")
           ->execute([$row['device_id']]);

    // An install below the permitted minimum is refused everywhere, which is what
    // makes a forced update actually forced rather than a dismissible prompt. The
    // download address rides along on the refusal: an app locked out this way has no
    // other way to learn where the update is.
    if (attAppVersionTooOld($row['app_version'] ?? null)) {
        $release = attReleaseInfo();
        attFail('UPGRADE_REQUIRED',
            'This version of the app is no longer supported. Please update to continue.',
            403, [
                'min_version' => $release['min_version'],
                'latest_version' => $release['latest_version'],
                'download_url' => $release['download_url'],
                'notes' => $release['notes'],
            ]);
    }

    // Refused here rather than at check-in, so a blocked account cannot keep
    // uploading, polling or reading anything with a token it already holds.
    if (!empty($row['security_blocked_at'])) {
        attFail('ACCOUNT_BLOCKED',
            'Your account is blocked: ' . ($row['security_block_reason'] ?: 'a security check failed') .
            '. Contact your administrator.', 403);
    }

    $row['settings'] = attEmployeeSettings($row['att_employee_id']);

    // Every area this employee may legitimately be in. Endpoints judge position
    // against all of them; 'geofence' remains the primary one, which is what a
    // check-in is stamped with and what the app labels its map with.
    $row['geofences'] = attEmployeeGeofences($row['att_employee_id'], $row['settings']);
    $row['geofence'] = $row['settings']['geofence_id']
        ? attGeofence($row['settings']['geofence_id'])
        : ($row['geofences'][0] ?? null);

    return $row;
}

/**
 * All active work areas assigned to an employee.
 *
 * Falls back to the single geofence_id on their settings row when nothing has been
 * assigned in the join table, so an employee configured before multi-area existed
 * keeps working without anyone having to re-save their rules.
 */
function attEmployeeGeofences($attEmployeeId, $settings = null) {
    $stmt = attDB()->prepare("
        SELECT g.*
        FROM att_employee_geofences eg
        JOIN att_geofences g ON g.id = eg.geofence_id
        WHERE eg.att_employee_id = ? AND g.is_active = 1
        ORDER BY g.name
    ");
    $stmt->execute([$attEmployeeId]);
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($areas) {
        return $areas;
    }

    $settings = $settings ?: attEmployeeSettings($attEmployeeId);
    if (!empty($settings['geofence_id'])) {
        $single = attGeofence($settings['geofence_id']);
        return $single ? [$single] : [];
    }
    return [];
}

/**
 * Per-employee rules, falling back to the module defaults so an employee who has
 * never been configured still gets a usable (if unfenced) setup.
 */
function attEmployeeSettings($attEmployeeId) {
    $stmt = attDB()->prepare("SELECT * FROM att_employee_settings WHERE att_employee_id = ? LIMIT 1");
    $stmt->execute([$attEmployeeId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($settings) {
        return $settings;
    }

    $defaults = attSettings();
    return [
        'att_employee_id' => $attEmployeeId,
        'geofence_id' => null,
        'shift_start' => $defaults['default_shift_start'] ?? '08:00:00',
        'shift_end' => $defaults['default_shift_end'] ?? '17:00:00',
        'sessions_per_day' => 1,
        'shift2_start' => null,
        'shift2_end' => null,
        'overnight_shift' => 0,
        'work_days' => '1,2,3,4,5,6',
        'tracking_interval_min' => (int)($defaults['default_interval_min'] ?? 10),
        'late_grace_min' => 15,
        'max_overtime_hours' => 6,
        'require_photo' => 1,
        'enforce_geofence' => 0,
        'enforce_geofence_checkout' => 0,
        'max_accuracy_m' => (int)($defaults['default_max_accuracy_m'] ?? 50),
        'allow_mock_location' => 0,
    ];
}

function attGeofence($geofenceId) {
    $stmt = attDB()->prepare("SELECT * FROM att_geofences WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$geofenceId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Bind a device to an employee, enforcing the one-device rule.
 *
 * @return array{device_id:int,first_bind:bool}
 */
function attBindDevice($attEmployeeId, $deviceUid, array $deviceInfo) {
    $db = attDB();

    $stmt = $db->prepare("
        SELECT id, device_uid, device_model, status
        FROM att_devices
        WHERE att_employee_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$attEmployeeId]);
    $active = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($active) {
        if (!hash_equals((string)$active['device_uid'], (string)$deviceUid)) {
            attFail(
                'DEVICE_ALREADY_BOUND',
                'Your account is already linked to another device (' .
                    ($active['device_model'] ?: 'unknown device') .
                    '). Ask your administrator to reset it before signing in here.',
                409,
                ['bound_device_model' => $active['device_model']]
            );
        }

        // Same handset signing in again: refresh what the app reported about it.
        $update = $db->prepare("
            UPDATE att_devices
            SET device_model = ?, device_brand = ?, os_version = ?, app_version = ?,
                platform = ?, fcm_token = COALESCE(?, fcm_token), last_seen_at = NOW()
            WHERE id = ?
        ");
        $update->execute([
            $deviceInfo['model'] ?? null,
            $deviceInfo['brand'] ?? null,
            $deviceInfo['os_version'] ?? null,
            $deviceInfo['app_version'] ?? null,
            $deviceInfo['platform'] ?? 'android',
            $deviceInfo['fcm_token'] ?? null,
            $active['id'],
        ]);

        return ['device_id' => (int)$active['id'], 'first_bind' => false];
    }

    // A blocked device must not be able to re-bind itself by signing in again.
    $blocked = $db->prepare("
        SELECT id FROM att_devices
        WHERE att_employee_id = ? AND device_uid = ? AND status = 'blocked'
        LIMIT 1
    ");
    $blocked->execute([$attEmployeeId, $deviceUid]);
    if ($blocked->fetch()) {
        attFail('DEVICE_BLOCKED', 'This device has been blocked by the administrator.', 403);
    }

    $insert = $db->prepare("
        INSERT INTO att_devices
            (att_employee_id, device_uid, device_model, device_brand, os_version,
             app_version, platform, fcm_token, status, bound_at, last_seen_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
    ");
    $insert->execute([
        $attEmployeeId,
        $deviceUid,
        $deviceInfo['model'] ?? null,
        $deviceInfo['brand'] ?? null,
        $deviceInfo['os_version'] ?? null,
        $deviceInfo['app_version'] ?? null,
        $deviceInfo['platform'] ?? 'android',
        $deviceInfo['fcm_token'] ?? null,
    ]);

    return ['device_id' => (int)$db->lastInsertId(), 'first_bind' => true];
}

/** Revoke every live token for a device (used on logout and on admin reset). */
function attRevokeDeviceTokens($deviceId) {
    attDB()->prepare("UPDATE att_tokens SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL")
           ->execute([$deviceId]);
}

/**
 * Release an employee's active device so a new one may bind. Called from the
 * admin panel; logs who did it and why.
 */
function attResetDevice($attEmployeeId, $reason = null, $adminUserId = null) {
    $db = attDB();

    $stmt = $db->prepare("SELECT id, device_uid FROM att_devices WHERE att_employee_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$attEmployeeId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        return false;
    }

    $db->prepare("UPDATE att_devices SET status = 'reset', released_at = NOW() WHERE id = ?")
       ->execute([$device['id']]);
    attRevokeDeviceTokens($device['id']);

    $db->prepare("
        INSERT INTO att_device_resets (att_employee_id, device_id, old_device_uid, reason, reset_by)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$attEmployeeId, $device['id'], $device['device_uid'], $reason, $adminUserId]);

    attAudit('device_reset', 'att_employee', $attEmployeeId, $reason, 'admin', $adminUserId);
    return true;
}
