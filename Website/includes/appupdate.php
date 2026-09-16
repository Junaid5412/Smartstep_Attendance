<?php
/**
 * App releases: publishing a new APK and forcing outdated installs to take it.
 *
 * The app is sideloaded, so there is no store to handle this. The server keeps the
 * current APK and two version numbers: the latest available, and the minimum still
 * permitted. An install below the minimum is refused at authentication — which is what
 * makes "force" mean something rather than being a prompt an employee can dismiss.
 */

/** Where published APKs live. */
function attApkDir() {
    $dir = ATT_UPLOAD_PATH . '/apk';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * A version string the app can be compared against, or null.
 *
 * Anything that is not a plain dotted number is rejected rather than guessed at: a
 * malformed minimum would either lock every employee out or silently never fire, and
 * both failures are invisible until someone cannot work.
 */
function attNormaliseVersion($value) {
    $value = trim((string)$value);
    return preg_match('/^\d+(\.\d+){0,3}$/', $value) ? $value : null;
}

/**
 * Is this app version below the minimum still allowed?
 *
 * Unknown versions are treated as acceptable. The alternative — refusing anything we
 * cannot parse — would lock out a device that simply had not reported its version yet,
 * and locking someone out of their own attendance record is a worse outcome than
 * letting one stale install through for another few minutes.
 */
function attAppVersionTooOld($appVersion, $minimum = null) {
    $minimum = attNormaliseVersion($minimum ?? (attSettings()['min_app_version'] ?? ''));
    $appVersion = attNormaliseVersion($appVersion);

    if ($minimum === null || $appVersion === null) {
        return false;
    }
    return version_compare($appVersion, $minimum, '<');
}

/** What the app needs to decide whether to update, and where to get it. */
function attReleaseInfo() {
    $settings = attSettings();

    $file = $settings['latest_apk_file'] ?? null;
    $available = $file && is_file(attApkDir() . '/' . basename($file));

    return [
        'latest_version' => attNormaliseVersion($settings['latest_app_version'] ?? ''),
        'min_version' => attNormaliseVersion($settings['min_app_version'] ?? ''),
        'notes' => $settings['release_notes'] ?? null,
        'published_at' => $settings['release_published_at'] ?? null,
        'available' => (bool)$available,
        'bytes' => $available ? filesize(attApkDir() . '/' . basename($file)) : 0,
        // Routed through the API rather than the static path: hosts commonly refuse
        // .apk by configuration — Hostinger LiteSpeed returns 403 — and the app then
        // receives an HTML error page where it expected an APK. Absolute, because an
        // app being forced to update has nothing else to work from.
        'download_url' => $available
            ? rtrim(ATT_URL, '/') . '/api/v1/app-download'
            : null,
    ];
}

/**
 * Publish an uploaded APK.
 *
 * The file is stored under its version so an older build stays retrievable — if a
 * release turns out to be broken, the fix is to re-point at the previous file rather
 * than to find an APK nobody kept.
 *
 * @return array{ok:bool,message:string}
 */
function attPublishRelease(array $file, $version, $notes, $forceMinimum, $adminUserId) {
    $version = attNormaliseVersion($version);
    if ($version === null) {
        return ['ok' => false, 'message' => 'Version must look like 1.0.1 — digits and dots only.'];
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'message' => 'No APK was uploaded.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed (error ' . $file['error'] . '). It may be larger than the server allows.'];
    }

    // An APK is a ZIP. Checking the magic bytes catches the common mistake of
    // uploading the wrong file far more usefully than trusting the extension.
    $handle = fopen($file['tmp_name'], 'rb');
    $magic = fread($handle, 2);
    fclose($handle);
    if ($magic !== 'PK') {
        return ['ok' => false, 'message' => 'That file is not an APK.'];
    }

    $name = 'sst-attendance-' . $version . '.apk';
    if (!move_uploaded_file($file['tmp_name'], attApkDir() . '/' . $name)) {
        return ['ok' => false, 'message' => 'Could not store the APK on the server.'];
    }
    @chmod(attApkDir() . '/' . $name, 0644);

    // Forcing sets the minimum to this release. Not forcing leaves the existing
    // minimum alone, so a previous force is never silently undone by a later
    // optional release.
    $minimum = $forceMinimum ? $version : (attSettings()['min_app_version'] ?? null);

    attDB()->prepare("
        UPDATE att_settings
        SET latest_app_version = ?, latest_apk_file = ?, release_notes = ?,
            release_published_at = NOW(), min_app_version = ?
        WHERE id = 1
    ")->execute([$version, $name, mb_substr((string)$notes, 0, 1000) ?: null, $minimum]);

    attSettingsRefresh();

    attAudit('release_published', 'att_settings', 1, [
        'version' => $version, 'forced' => (bool)$forceMinimum,
        'bytes' => filesize(attApkDir() . '/' . $name),
    ], 'admin', $adminUserId);

    return [
        'ok' => true,
        'message' => 'Version ' . $version . ' published' .
            ($forceMinimum
                ? ' and required — older installs will stop working until they update.'
                : ' as an optional update.'),
    ];
}

/**
 * Which installs are on which version.
 *
 * Answers "who has updated and who has not" from data the app already reports at login
 * and on every heartbeat, so nothing new is collected to produce it.
 *
 * Anyone whose device has never reported a version is listed as unknown rather than
 * outdated: they may simply not have opened the app since it was enrolled, and calling
 * that "outdated" would send someone chasing a person who has nothing to fix.
 */
function attInstallReport() {
    $latest = attNormaliseVersion(attSettings()['latest_app_version'] ?? '');
    $minimum = attNormaliseVersion(attSettings()['min_app_version'] ?? '');

    $rows = attDB()->query("
        SELECT e.id AS att_employee_id, e.login_code, e.is_active,
               CONCAT(emp.first_name, ' ', emp.last_name) AS name,
               emp.employee_code,
               d.app_version, d.device_model, d.last_seen_at, d.status AS device_status
        FROM att_employees e
        JOIN att_person emp ON emp.person_type = e.person_type
          AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
        LEFT JOIN att_devices d ON d.att_employee_id = e.id AND d.status = 'active'
        WHERE e.is_active = 1
        ORDER BY emp.first_name, emp.last_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $summary = ['current' => 0, 'outdated' => 0, 'blocked_out' => 0, 'unknown' => 0];

    foreach ($rows as $i => $row) {
        $version = attNormaliseVersion($row['app_version']);

        if (!$row['app_version'] || $version === null) {
            $state = 'unknown';
        } elseif ($minimum !== null && version_compare($version, $minimum, '<')) {
            // Below the required minimum: this install is refused by the server, so it
            // is not merely behind — it cannot work until it updates.
            $state = 'blocked_out';
        } elseif ($latest !== null && version_compare($version, $latest, '<')) {
            $state = 'outdated';
        } else {
            $state = 'current';
        }

        $rows[$i]['update_state'] = $state;
        $summary[$state]++;
    }

    return ['rows' => $rows, 'summary' => $summary, 'latest' => $latest, 'minimum' => $minimum];
}
