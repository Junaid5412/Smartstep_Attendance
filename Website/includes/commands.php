<?php
/**
 * On-demand commands sent to a device.
 *
 * The phone always initiates contact, so a request is parked here and collected on
 * the device's next poll. Everything is deliberately short-lived: a location
 * request that cannot be served within a couple of minutes is no longer a live
 * location, and answering it later would be misleading rather than helpful.
 */

/** How long a request stays worth serving. */
define('ATT_COMMAND_TTL_SECONDS', 180);

function attCommandPollSeconds() {
    $value = (int)(attSettings()['command_poll_seconds'] ?? 45);
    // Floor of 15s so a mistyped setting cannot turn the fleet into a request storm.
    return max(15, min(600, $value));
}

/**
 * Ask a device for an immediate position.
 *
 * Returns the queued command, or an explanation of why nothing was queued — a
 * request that will never be answered should say so rather than sit silently
 * "pending" while the admin waits.
 *
 * @return array{ok:bool,message:string,id:?int}
 */
/**
 * Authorise removal of the app from an employee's phone.
 *
 * Queued as a command rather than done from here, because only the handset can lift its
 * own protection. Given a long life: the phone may be off or out of signal when an
 * employee is dismissed, and the authorisation should still be waiting when it comes
 * back rather than having quietly expired.
 */
function attAllowUninstall($attEmployeeId, $adminUserId) {
    $db = attDB();

    $device = $db->prepare("
        SELECT id FROM att_devices WHERE att_employee_id = ? AND status = 'active' LIMIT 1
    ");
    $device->execute([$attEmployeeId]);
    $deviceId = $device->fetchColumn();

    if (!$deviceId) {
        return ['ok' => false, 'message' => 'No device is linked to this employee.'];
    }

    $existing = $db->prepare("
        SELECT id FROM att_device_commands
        WHERE att_employee_id = ? AND command = 'release_admin' AND status = 'pending'
              AND expires_at > NOW()
        LIMIT 1
    ");
    $existing->execute([$attEmployeeId]);
    if ($existing->fetchColumn()) {
        return ['ok' => true, 'message' => 'Already authorised — waiting for the phone to check in.'];
    }

    $db->prepare("
        INSERT INTO att_device_commands
            (att_employee_id, device_id, command, status, requested_by, expires_at)
        VALUES (?, ?, 'release_admin', 'pending', ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
    ")->execute([$attEmployeeId, $deviceId, $adminUserId]);

    attAudit('uninstall_authorised', 'att_employee', (int)$attEmployeeId, [
        'summary' => 'Removal of the app was authorised for this phone',
    ], 'admin', $adminUserId);

    return ['ok' => true, 'message' =>
        'Authorised. The phone lifts protection at its next check-in, usually within a minute.'];
}

function attRequestLocate($attEmployeeId, $adminUserId) {
    $db = attDB();

    $device = $db->prepare("
        SELECT d.id, d.last_seen_at, e.is_active
        FROM att_employees e
        LEFT JOIN att_devices d ON d.att_employee_id = e.id AND d.status = 'active'
        WHERE e.id = ?
        LIMIT 1
    ");
    $device->execute([$attEmployeeId]);
    $row = $device->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'message' => 'That employee does not exist.', 'id' => null];
    }
    if ((int)$row['is_active'] !== 1) {
        return ['ok' => false, 'message' => 'That account is disabled.', 'id' => null];
    }
    if (!$row['id']) {
        return ['ok' => false, 'message' => 'No device is linked to this employee yet.', 'id' => null];
    }

    // Reuse an outstanding request rather than stacking duplicates when an
    // impatient admin taps twice.
    $existing = $db->prepare("
        SELECT id FROM att_device_commands
        WHERE att_employee_id = ? AND command = 'locate' AND status = 'pending'
              AND expires_at > NOW()
        LIMIT 1
    ");
    $existing->execute([$attEmployeeId]);
    if ($open = $existing->fetchColumn()) {
        return ['ok' => true, 'message' => 'A location request is already waiting.', 'id' => (int)$open];
    }

    $db->prepare("
        INSERT INTO att_device_commands
            (att_employee_id, device_id, command, status, requested_by, expires_at)
        VALUES (?, ?, 'locate', 'pending', ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
    ")->execute([$attEmployeeId, $row['id'], $adminUserId, ATT_COMMAND_TTL_SECONDS]);

    $id = (int)$db->lastInsertId();

    // Logged because requesting someone's live position is a monitoring action, not
    // a neutral read.
    attAudit('locate_requested', 'att_employee', $attEmployeeId, null, 'admin', $adminUserId);

    $quiet = $row['last_seen_at']
        ? round((time() - strtotime($row['last_seen_at'])) / 60)
        : null;

    $message = 'Location requested.';
    if ($quiet === null) {
        $message .= ' This device has never reported in, so it may not answer.';
    } elseif ($quiet > 10) {
        $message .= ' The device has been quiet for ' . $quiet . ' minutes, so it may not answer.';
    } else {
        $message .= ' The phone should report within ' . attCommandPollSeconds() . ' seconds.';
    }

    return ['ok' => true, 'message' => $message, 'id' => $id];
}

/**
 * Commands waiting for a device, marking them delivered as they go out.
 *
 * Expiring first means a phone that has been offline does not act on a request
 * nobody is waiting for any more.
 */
function attCollectCommands($attEmployeeId, $deviceId) {
    $db = attDB();

    $db->prepare("
        UPDATE att_device_commands
        SET status = 'expired'
        WHERE att_employee_id = ? AND status = 'pending' AND expires_at <= NOW()
    ")->execute([$attEmployeeId]);

    $stmt = $db->prepare("
        SELECT id, command FROM att_device_commands
        WHERE att_employee_id = ? AND status = 'pending' AND expires_at > NOW()
        ORDER BY id ASC
        LIMIT 5
    ");
    $stmt->execute([$attEmployeeId]);
    $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($commands) {
        $ids = array_column($commands, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("
            UPDATE att_device_commands
            SET status = 'delivered', delivered_at = NOW(), device_id = COALESCE(device_id, ?)
            WHERE id IN ($placeholders)
        ")->execute(array_merge([$deviceId], $ids));
    }

    return array_map(function ($row) {
        return ['id' => (int)$row['id'], 'command' => $row['command']];
    }, $commands);
}

/** Called by the device once it has acted on a command. */
function attCompleteCommand($attEmployeeId, $commandId) {
    attDB()->prepare("
        UPDATE att_device_commands
        SET status = 'done', done_at = NOW()
        WHERE id = ? AND att_employee_id = ?
    ")->execute([$commandId, $attEmployeeId]);
}

/** Outstanding locate requests, for the live map to show a pending state. */
function attPendingLocateIds() {
    $rows = attDB()->query("
        SELECT att_employee_id FROM att_device_commands
        WHERE command = 'locate' AND status IN ('pending','delivered') AND expires_at > NOW()
    ")->fetchAll(PDO::FETCH_COLUMN);

    return array_map('intval', $rows);
}

/* ============================ live follow ============================ */

/** Seconds between reports while following. Clamped: 3s is the GPS floor. */
const ATT_FOLLOW_MIN_SECONDS = 5;
const ATT_FOLLOW_MAX_SECONDS = 60;

/** How long a single follow request lasts, in minutes. */
const ATT_FOLLOW_MINUTES = 5;

/**
 * Ask an employee's device to report every few seconds for a short window.
 *
 * Deliberately short-lived and renewed by the watching page rather than switched on:
 * a few-second reporting rate is a real drain on the employee's battery, and watching
 * a named person move in real time is a monitoring action that should stop by itself
 * when nobody is looking. It is audit-logged for the same reason.
 */
function attStartFollow($attEmployeeId, $adminUserId, $seconds = 8, $minutes = ATT_FOLLOW_MINUTES) {
    $seconds = max(ATT_FOLLOW_MIN_SECONDS, min(ATT_FOLLOW_MAX_SECONDS, (int)$seconds));
    // Up to four hours, matching what the panel offers — a dropdown promising 4 hours
    // while the server silently granted 1 is a UI that lies about what it did.
    //
    // Self-limiting in practice: the watching page renews the window every 90 seconds,
    // so closing the tab ends it regardless. This ceiling only bounds what happens if
    // the page is left open, and it is why the follow is audit-logged.
    $minutes = max(1, min(240, (int)$minutes));

    $db = attDB();

    $check = $db->prepare("
        SELECT e.is_active, d.id AS device_id
        FROM att_employees e
        LEFT JOIN att_devices d ON d.att_employee_id = e.id AND d.status = 'active'
        WHERE e.id = ? LIMIT 1
    ");
    $check->execute([$attEmployeeId]);
    $row = $check->fetch(PDO::FETCH_ASSOC);

    // Read before the upsert below replaces it, or every renewal looks like a first call.
    $following = $db->prepare("
        SELECT 1 FROM att_live_follow WHERE att_employee_id = ? AND until > NOW() LIMIT 1
    ");
    $following->execute([$attEmployeeId]);
    $alreadyFollowing = (bool)$following->fetchColumn();

    if (!$row) {
        return ['ok' => false, 'message' => 'That employee does not exist.'];
    }
    if ((int)$row['is_active'] !== 1) {
        return ['ok' => false, 'message' => 'That account is disabled.'];
    }
    if (!$row['device_id']) {
        return ['ok' => false, 'message' => 'No device is linked to this employee yet.'];
    }

    // One row per employee, so a second admin watching the same person extends the
    // same window rather than fighting over it.
    $db->prepare("
        INSERT INTO att_live_follow (att_employee_id, interval_seconds, until, requested_by)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)
        ON DUPLICATE KEY UPDATE
            interval_seconds = VALUES(interval_seconds),
            until = VALUES(until),
            requested_by = VALUES(requested_by)
    ")->execute([$attEmployeeId, $seconds, $minutes, $adminUserId]);

    // Nudged rather than waited for: the device may be up to command_poll_seconds away
    // from its next poll, and the point of following is not to wait for that.
    //
    // Only on the FIRST call, though. The watching page renews this window every 90
    // seconds, and nudging again each time asked a phone that was already reporting every
    // eight seconds for yet another forced fix. A forced fix deliberately bypasses every
    // filter — including the stationary check — so a still phone stored a fresh, often
    // wifi-derived position every ninety seconds, and the route map drew a scribble
    // hundreds of metres wide around somebody who had not moved. Renewing a window that is
    // already open needs no nudge: the phone is mid-stream.
    if (!$alreadyFollowing) {
        attRequestLocate($attEmployeeId, $adminUserId);
    }

    attAudit('live_follow', 'att_employee', (int)$attEmployeeId, [
        'interval_seconds' => $seconds, 'minutes' => $minutes,
    ], 'admin', $adminUserId);

    return [
        'ok' => true,
        'message' => 'Following for ' . $minutes . ' minute(s), reporting every ' . $seconds . 's.',
        'seconds' => $seconds,
    ];
}

/** Stop following now, rather than letting the window run out. */
function attStopFollow($attEmployeeId, $adminUserId) {
    attDB()->prepare("DELETE FROM att_live_follow WHERE att_employee_id = ?")
           ->execute([$attEmployeeId]);
    attAudit('live_follow_stop', 'att_employee', (int)$attEmployeeId, null, 'admin', $adminUserId);
    return ['ok' => true, 'message' => 'Stopped following.'];
}

/**
 * The live-reporting rate this employee's device should currently use.
 *
 * Returns seconds = 0 when no window is open, which the device reads as "back to the
 * normal interval". Expired rows are cleared on read so a stale window can never keep
 * a device reporting every few seconds after everyone has stopped watching.
 */
function attFollowState($attEmployeeId) {
    $db = attDB();

    $stmt = $db->prepare("
        SELECT interval_seconds, until
        FROM att_live_follow
        WHERE att_employee_id = ? AND until > NOW()
        LIMIT 1
    ");
    $stmt->execute([$attEmployeeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $db->prepare("DELETE FROM att_live_follow WHERE att_employee_id = ? AND until <= NOW()")
           ->execute([$attEmployeeId]);
        return ['seconds' => 0, 'until' => null];
    }

    return [
        'seconds' => (int)$row['interval_seconds'],
        'until' => $row['until'],
    ];
}

/**
 * Employee ids with a live follow window still open.
 *
 * Reported to the panel so the page can show a follow another admin started, rather
 * than two people each thinking they are the only one watching.
 */
function attFollowingIds() {
    $rows = attDB()->query("
        SELECT att_employee_id FROM att_live_follow WHERE until > NOW()
    ")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}
