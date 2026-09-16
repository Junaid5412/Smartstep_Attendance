<?php
/**
 * Module defaults applied to new accounts, plus the map tile source and the
 * minimum app version gate.
 */

require_once __DIR__ . '/bootstrap.php';

if (($_POST['action'] ?? null) === 'branding') {
    attCheckCsrf();
    attRequireManage();

    $displayName = trim($_POST['app_display_name'] ?? '');

    if (!empty($_FILES['app_logo']['name'])) {
        $file = $_FILES['app_logo'];

        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            attFlash('error', 'Logo upload failed.');
            attRedirect('settings.php');
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            attFlash('error', 'Logo must be 2MB or smaller.');
            attRedirect('settings.php');
        }

        // Trust the file's contents, not its name or the client-supplied type.
        $info = @getimagesize($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = $info['mime'] ?? null;

        if (!$mime || !isset($allowed[$mime])) {
            attFlash('error', 'Logo must be a JPG, PNG or WebP image.');
            attRedirect('settings.php');
        }

        $dir = ATT_UPLOAD_PATH . '/branding';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            attFlash('error', 'Could not create the branding folder on the server.');
            attRedirect('settings.php');
        }

        $name = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            attFlash('error', 'Could not store the logo.');
            attRedirect('settings.php');
        }
        @chmod($dir . '/' . $name, 0644);

        // Remove the previous one so uploads do not pile up.
        $previous = attSettings()['app_logo'] ?? null;
        if ($previous) {
            attDeletePhotoFile($previous);
        }

        attDB()->prepare("UPDATE att_settings SET app_logo = ? WHERE id = 1")
               ->execute(['branding/' . $name]);
    }

    if (!empty($_POST['clear_logo'])) {
        $previous = attSettings()['app_logo'] ?? null;
        if ($previous) {
            attDeletePhotoFile($previous);
        }
        attDB()->prepare("UPDATE att_settings SET app_logo = NULL WHERE id = 1")->execute();
    }

    $splash = (float)($_POST['splash_seconds'] ?? 3);
    $splash = max(0.0, min(10.0, $splash));

    attDB()->prepare("UPDATE att_settings SET app_display_name = ?, splash_seconds = ? WHERE id = 1")
           ->execute([$displayName !== '' ? $displayName : null, $splash]);

    attSettingsRefresh();
    attAudit('branding_saved', 'att_settings', 1, null, 'admin', (int)$attAdmin['id']);
    attFlash('success', 'App branding updated. Phones pick it up at their next sync.');
    attRedirect('settings.php');
}

// Publishing a release is its own action: it takes a file upload, and it can change
// whether every installed app still works. That should never ride along on someone
// saving an unrelated setting.
// Publishing lives on app-version.php now, which reports upload progress. This
// handler stays only so a bookmarked or replayed post does not silently do nothing.
if (($_POST['action'] ?? null) === 'publish_release') {
    attCheckCsrf();
    attRequireManage();

    $result = attPublishRelease(
        $_FILES['apk'] ?? [],
        $_POST['release_version'] ?? '',
        $_POST['release_notes_text'] ?? '',
        isset($_POST['force_update']),
        (int)$attAdmin['id']
    );
    attFlash($result['ok'] ? 'success' : 'error', $result['message']);
    attRedirect('settings.php');
}

// Downloading the style is separate from saving settings: it makes an outbound request
// that can fail slowly, and an admin changing an unrelated setting should not be made
// to wait on Protomaps.
if (($_POST['action'] ?? null) === 'fetch_style') {
    attCheckCsrf();
    attRequireManage();

    $current = attSettings();
    $result = attPackFetchStyle(
        $current['protomaps_api_key'] ?? '',
        $_POST['style_flavour'] ?? 'light',
        $_POST['style_language'] ?? 'en'
    );

    // The key is never included in the audit detail or the message.
    attAudit('map_style_fetched', 'att_settings', 1,
        ['ok' => $result['ok'], 'flavour' => $_POST['style_flavour'] ?? 'light'],
        'admin', (int)$attAdmin['id']);

    attFlash($result['ok'] ? 'success' : 'error', $result['message']);
    attRedirect('settings.php');
}

if (($_POST['action'] ?? null) === 'save') {
    attCheckCsrf();
    attRequireManage();

    // Captured before the write so the session token can be invalidated only when
    // something it depends on actually changed.
    $settingsBefore = attSettings();

    $interval = max(1, min(120, (int)$_POST['default_interval_min']));
    $radius = max(20, min(50000, (int)$_POST['default_radius_m']));
    $accuracy = max(10, min(2000, (int)$_POST['default_max_accuracy_m']));
    // 0 is allowed and means a hard boundary; the upper bound stops a tolerance so wide
    // that leaving the site would never register at all.
    $buffer = max(0, min(1000, (int)($_POST['geofence_buffer_m'] ?? 50)));
    $tokenDays = max(1, min(365, (int)$_POST['token_lifetime_days']));
    $photoKb = max(64, min(8192, (int)$_POST['photo_max_kb']));
    $pollSeconds = max(15, min(600, (int)($_POST['command_poll_seconds'] ?? 45)));
    // 0 disables the warning; anything above that is floored at 5s because the GPS
    // hardware cannot usefully go faster.
    $watchRaw = (int)($_POST['geofence_watch_seconds'] ?? 10);
    $watchSeconds = $watchRaw <= 0 ? 0 : max(5, min(300, $watchRaw));
    $alertSound = isset($_POST['alert_sound']) ? 1 : 0;

    $provider = ($_POST['map_provider'] ?? 'osm') === 'google' ? 'google' : 'osm';
    $googleKey = trim($_POST['google_api_key'] ?? '');
    $googleType = in_array($_POST['google_map_type'] ?? '', ['roadmap', 'satellite', 'terrain'], true)
        ? $_POST['google_map_type'] : 'roadmap';
    $googleLang = preg_match('/^[a-zA-Z-]{2,15}$/', $_POST['google_language'] ?? '')
        ? $_POST['google_language'] : 'en';
    $googleRegion = preg_match('/^[A-Za-z]{2}$/', $_POST['google_region'] ?? '')
        ? strtoupper($_POST['google_region']) : 'QA';

    // A blank field means "leave it alone", not "clear it". The input is rendered empty
    // deliberately — echoing a saved key back would put it in the page source of every
    // settings visit — so treating blank as a delete would wipe the key the first time
    // anyone saved an unrelated setting.
    $protomapsKey = trim($_POST['protomaps_api_key'] ?? '');
    if ($protomapsKey === '') {
        $protomapsKey = (string)($settingsBefore['protomaps_api_key'] ?? '');
    }
    // An explicit tick is how the key gets removed.
    if (isset($_POST['protomaps_clear'])) {
        $protomapsKey = '';
    }

    // Basename only: this ends up in a filesystem path.
    $offlineFile = basename(trim($_POST['offline_map_file'] ?? 'qatar.pmtiles'));
    if ($offlineFile === '' || !preg_match('/\.pmtiles$/i', $offlineFile)) {
        $offlineFile = 'qatar.pmtiles';
    }

    if ($provider === 'google' && $googleKey === '') {
        attFlash('error', 'Enter a Google Maps Platform API key, or keep the provider on OpenStreetMap.');
        attRedirect('settings.php');
    }

    $tileUrl = trim($_POST['tile_url'] ?? '');
    // Validated even when Google is active, because this stays the fallback the
    // whole product drops back to if Google's session call fails.
    if (!preg_match('#^https://#i', $tileUrl) ||
        strpos($tileUrl, '{z}') === false ||
        strpos($tileUrl, '{x}') === false ||
        strpos($tileUrl, '{y}') === false) {
        attFlash('error', 'The OpenStreetMap fallback URL must be an https template containing {z}, {x} and {y}.');
        attRedirect('settings.php');
    }

    $minVersion = trim($_POST['min_app_version'] ?? '');
    if ($minVersion !== '' && !preg_match('/^\d+(\.\d+){0,2}$/', $minVersion)) {
        attFlash('error', 'Minimum app version must look like 1.0.0.');
        attRedirect('settings.php');
    }

    // Changing any of these invalidates the cached session token: it is tied to the
    // key, map type, language and region it was created with.
    $sessionAffecting = $googleKey !== ($settingsBefore['google_api_key'] ?? '')
        || $googleType !== ($settingsBefore['google_map_type'] ?? '')
        || $googleLang !== ($settingsBefore['google_language'] ?? '')
        || $googleRegion !== ($settingsBefore['google_region'] ?? '');

    // The column and its bound value are added together, or not at all. They were once
    // added separately — the value without the placeholder — which made every Save fail
    // with "Invalid parameter number" and a bare HTTP 500. Keeping them in one condition
    // is what stops that recurring.
    $captureColumn = attColumnExists('att_settings', 'allow_screen_capture');

    $settingsParams = [
        ($_POST['default_shift_start'] ?: '08:00') . ':00',
        ($_POST['default_shift_end'] ?: '17:00') . ':00',
        $interval, $radius, $accuracy, $buffer, $tokenDays, $photoKb, $pollSeconds,
        $watchSeconds, $alertSound,
        $tileUrl,
        trim($_POST['tile_attribution'] ?? '') ?: '© OpenStreetMap contributors',
        $minVersion !== '' ? $minVersion : null,
        $provider,
        $googleKey !== '' ? $googleKey : null,
        $googleType, $googleLang, $googleRegion,
        $protomapsKey, $offlineFile,
    ];

    $captureClause = '';
    if ($captureColumn) {
        $captureClause = ', allow_screen_capture = ?';
        $settingsParams[] = isset($_POST['allow_screen_capture']) ? 1 : 0;
    }

    // The personal live board's channel. Guarded like the capture flag: a server
    // whose files are newer than its migrations saves everything else instead
    // of dying on the missing columns.
    $liveClause = '';
    if (attColumnExists('att_settings', 'live_board_enabled')
        && attColumnExists('att_settings', 'live_ping_interval_min')) {
        $liveClause = ', live_board_enabled = ?, live_ping_interval_min = ?';
        $settingsParams[] = isset($_POST['live_board_enabled']) ? 1 : 0;
        $settingsParams[] = max(5, min(120, (int)($_POST['live_ping_interval_min'] ?? 15)));
    }

    try {
        attDB()->prepare("
            UPDATE att_settings
            SET default_shift_start = ?, default_shift_end = ?, default_interval_min = ?,
                default_radius_m = ?, default_max_accuracy_m = ?, geofence_buffer_m = ?,
                token_lifetime_days = ?,
                photo_max_kb = ?, command_poll_seconds = ?,
                geofence_watch_seconds = ?, alert_sound = ?, tile_url = ?, tile_attribution = ?, min_app_version = ?,
                map_provider = ?, google_api_key = ?, google_map_type = ?,
                google_language = ?, google_region = ?,
                protomaps_api_key = ?, offline_map_file = ?" . $captureClause . $liveClause . "
            WHERE id = 1
        ")->execute($settingsParams);
    } catch (Throwable $e) {
        // A readable message beats a white 500 page: the admin can act on this one.
        error_log('[SST Attendance] settings save failed: ' . $e->getMessage());
        attFlash('error', 'Settings could not be saved. The database rejected the change: '
            . $e->getMessage());
        attRedirect('settings.php');
    }

    if ($sessionAffecting) {
        attDB()->prepare("
            UPDATE att_settings
            SET google_session_token = NULL, google_session_expires_at = NULL, google_session_error = NULL
            WHERE id = 1
        ")->execute();
    }

    attSettingsRefresh();
    attAudit('settings_saved', 'att_settings', 1, null, 'admin', (int)$attAdmin['id']);

    // Try Google straight away so the admin finds out here, rather than discovering
    // blank maps later. attTileConfig reports the reason if it had to fall back.
    if ($provider === 'google') {
        $resolved = attTileConfig(true);
        if ($resolved['provider'] !== 'google') {
            attFlash('error', 'Settings saved, but Google tiles are not working yet. ' .
                $resolved['fallback_reason']);
            attRedirect('settings.php');
        }
        attFlash('success', 'Settings saved. Google tiles are live on the panel and the app.');
        attRedirect('settings.php');
    }

    attFlash('success', 'Settings saved.');
    attRedirect('settings.php');
}

$settings = attDB()->query("SELECT * FROM att_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

$counts = attDB()->query("
    SELECT
        (SELECT COUNT(*) FROM att_employees) AS accounts,
        (SELECT COUNT(*) FROM att_devices WHERE status = 'active') AS devices,
        (SELECT COUNT(*) FROM att_geofences WHERE is_active = 1) AS geofences,
        (SELECT COUNT(*) FROM att_attendance) AS attendance_rows,
        (SELECT COUNT(*) FROM att_location_logs) AS location_rows,
        (SELECT COUNT(*) FROM att_geofence_events) AS trips
")->fetch(PDO::FETCH_ASSOC);

$oldestLog = attDB()->query("SELECT MIN(work_date) FROM att_location_logs")->fetchColumn();

$pageTitle = 'Settings';
$pageSubtitle = 'Defaults for new accounts, map source and app version gate';
include __DIR__ . '/layout_top.php';
?>

<?php $branding = attBranding(); ?>
<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
    <input type="hidden" name="action" value="branding">

    <div class="card">
        <div class="card-head">
            <div>
                <h2>App branding</h2>
                <p>Shown on the mobile app's splash screen and dashboard header.</p>
            </div>
        </div>
        <div class="card-body">
            <div class="grid two">
                <div>
                    <div class="field">
                        <label for="b_name">Display name</label>
                        <input type="text" id="b_name" name="app_display_name"
                               value="<?php echo e($settings['app_display_name']); ?>"
                               placeholder="<?php echo e($branding['company_name']); ?>">
                        <div class="help">
                            Leave blank to use the company name from ERP settings
                            (<strong><?php echo e($branding['company_name']); ?></strong>).
                        </div>
                    </div>
                    <div class="field">
                        <label for="b_logo">Logo <span class="opt">(JPG, PNG or WebP, max 2MB)</span></label>
                        <input type="file" id="b_logo" name="app_logo" accept="image/jpeg,image/png,image/webp">
                        <div class="help">
                            Leave empty to keep using the ERP company logo. A square image
                            around 512×512 looks best on the splash screen.
                        </div>
                    </div>
                    <div class="field">
                        <label for="b_splash">Splash screen duration (seconds)</label>
                        <input type="number" id="b_splash" name="splash_seconds"
                               min="0" max="10" step="0.5"
                               value="<?php echo e(rtrim(rtrim((string)$settings['splash_seconds'], '0'), '.')); ?>">
                        <div class="help">
                            How long the branded loading screen stays on screen. This is a
                            <em>minimum</em> — if the account check takes longer, the splash waits
                            for it rather than cutting away to a spinner. 0 shows it only as long
                            as loading actually takes.
                        </div>
                    </div>
                    <?php if (!empty($settings['app_logo'])): ?>
                        <div class="check">
                            <input type="checkbox" id="b_clear" name="clear_logo" value="1">
                            <div>
                                <label for="b_clear">Remove the attendance logo</label>
                                <div class="help">Goes back to the ERP company logo.</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Current logo</label>
                    <div style="border:1px solid var(--line);border-radius:11px;padding:18px;
                                background:#fff;text-align:center">
                        <?php if ($branding['logo_url']): ?>
                            <img src="<?php echo e($branding['logo_url']); ?>" alt="App logo"
                                 style="max-width:150px;max-height:110px;object-fit:contain">
                            <div style="margin-top:9px;font-size:11.5px;color:var(--ink-soft)">
                                <?php echo !empty($settings['app_logo'])
                                    ? 'Attendance-specific logo'
                                    : 'Inherited from ERP company settings'; ?>
                            </div>
                        <?php else: ?>
                            <div style="color:var(--ink-soft);font-size:12.5px;padding:22px 0">
                                No logo set — the app shows its built-in mark.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <button class="btn" type="submit">Save branding</button>
        </div>
    </div>
</form>

<form method="post">
    <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
    <input type="hidden" name="action" value="save">

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Defaults for new accounts</h2>
                <p>Applied when an employee is enrolled. Existing accounts keep their own values.</p>
            </div>
        </div>
        <div class="card-body">
            <div class="grid">
                <div class="field">
                    <label for="s_start">Shift start</label>
                    <input type="time" id="s_start" name="default_shift_start"
                           value="<?php echo e(substr($settings['default_shift_start'], 0, 5)); ?>">
                </div>
                <div class="field">
                    <label for="s_end">Shift end</label>
                    <input type="time" id="s_end" name="default_shift_end"
                           value="<?php echo e(substr($settings['default_shift_end'], 0, 5)); ?>">
                </div>
                <div class="field">
                    <label for="s_interval">Location ping every (minutes)</label>
                    <input type="number" id="s_interval" name="default_interval_min" min="1" max="120"
                           value="<?php echo (int)$settings['default_interval_min']; ?>">
                    <div class="help">5 or 10 balances route detail against battery life.</div>
                </div>
                <div class="field">
                    <label for="s_radius">Default geofence radius (m)</label>
                    <input type="number" id="s_radius" name="default_radius_m" min="20" max="50000"
                           value="<?php echo (int)$settings['default_radius_m']; ?>">
                </div>
                <div class="field">
                    <label for="s_accuracy">Required GPS accuracy (m)</label>
                    <input type="number" id="s_accuracy" name="default_max_accuracy_m" min="10" max="2000"
                           value="<?php echo (int)$settings['default_max_accuracy_m']; ?>">
                </div>
                <div class="field">
                    <label for="s_buffer">Tolerance outside each area (m)</label>
                    <input type="number" id="s_buffer" name="geofence_buffer_m" min="0" max="1000"
                           value="<?php echo (int)($settings['geofence_buffer_m'] ?? 50); ?>">
                    <div class="help">Nobody is treated as having left their area until they
                        are this far past its boundary. A map boundary is exact; a GPS fix is
                        not, so without a tolerance someone working near the edge is reported
                        as leaving and returning repeatedly without moving — and alarmed each
                        time. 50 m suits a normal site; 0 makes the boundary hard.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>App and session</h2>
            </div>
        </div>
        <div class="card-body">
            <div class="grid">
                <div class="field">
                    <label for="s_token">Session length (days)</label>
                    <input type="number" id="s_token" name="token_lifetime_days" min="1" max="365"
                           value="<?php echo (int)$settings['token_lifetime_days']; ?>">
                    <div class="help">How long an employee stays signed in before the app asks again.</div>
                </div>
                <div class="field">
                    <label for="s_photo">Photo size limit (KB)</label>
                    <input type="number" id="s_photo" name="photo_max_kb" min="64" max="8192"
                           value="<?php echo (int)$settings['photo_max_kb']; ?>">
                    <div class="help">The app compresses selfies to this before uploading.</div>
                </div>
                <div class="field">
                    <label for="s_watch">Leaving-the-area check (seconds)</label>
                    <input type="number" id="s_watch" name="geofence_watch_seconds" min="0" max="300"
                           value="<?php echo (int)$settings['geofence_watch_seconds']; ?>">
                    <div class="help">
                        How often the phone checks whether it has left the work area, and so
                        how quickly the employee is warned. This is <strong>separate</strong>
                        from the recording interval above — these checks are not saved as route
                        points, so a low value does not bloat the route history.
                        <br><br>
                        <strong>Battery warning:</strong> 10 seconds means near-continuous GPS.
                        Expect a noticeable drain over a full shift. Raise it to 30–60 s if
                        staff report battery problems; set <code>0</code> to switch the warning
                        off entirely and rely on the recording interval alone.
                    </div>
                </div>

                <div class="check">
                    <input type="checkbox" id="s_capture" name="allow_screen_capture" value="1"
                           <?php echo (int)($settings['allow_screen_capture'] ?? 0) === 1 ? 'checked' : ''; ?>>
                    <div>
                        <label for="s_capture">Allow screenshots and screen recording</label>
                        <div class="help">
                            Off, the app is unrecordable — screenshots fail and the screen is
                            blank in a recording or a screen share. That protects the locations
                            and photos of other people, which is why it is the default. Turn it
                            on when you need to capture the app for support or training; the
                            phone applies it at its next sign-in or config refresh.
                        </div>
                    </div>
                </div>

                <div class="check">
                    <input type="checkbox" id="s_sound" name="alert_sound" value="1"
                           <?php echo (int)$settings['alert_sound'] === 1 ? 'checked' : ''; ?>>
                    <div>
                        <label for="s_sound">Play a sound with the warning</label>
                        <div class="help">
                            The alert is a high-priority notification so it can make a sound and
                            appear over other apps. Needs notifications enabled on the phone.
                        </div>
                    </div>
                </div>

                <div class="check">
                    <input type="checkbox" id="s_live" name="live_board_enabled" value="1"
                           <?php echo (int)($settings['live_board_enabled'] ?? 1) === 1 ? 'checked' : ''; ?>>
                    <div>
                        <label for="s_live">Keep the live board fed outside working hours</label>
                        <div class="help">
                            On, each phone sends its position to the <strong>personal live
                            board only</strong> — roughly every interval below, day and night.
                            This never touches the register, routes or worked hours. Off stops
                            the pings entirely; the board then shows last-known positions.
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label for="s_live_interval">Live board ping every (minutes)</label>
                    <input type="number" id="s_live_interval" name="live_ping_interval_min" min="5" max="120"
                           value="<?php echo max(5, min(120, (int)($settings['live_ping_interval_min'] ?? 15))); ?>">
                    <div class="help">One GPS fix per interval per phone, around the clock. 15 is gentle on batteries.</div>
                </div>

                <div class="field">
                    <label for="s_poll">"Locate now" response time (seconds)</label>
                    <input type="number" id="s_poll" name="command_poll_seconds" min="15" max="600"
                           value="<?php echo (int)$settings['command_poll_seconds']; ?>">
                    <div class="help">
                        How often each phone asks the server whether a location has been
                        requested — so this is also the worst-case wait for the
                        <strong>Locate now</strong> button on the live map. Lower is more
                        responsive but costs a request per employee per interval, all shift:
                        at 45s that is roughly 800 requests per phone per 10-hour day.
                    </div>
                </div>

                <div class="field">
                    <label for="s_version">Minimum app version <span class="opt">(optional)</span></label>
                    <input type="text" id="s_version" name="min_app_version"
                           value="<?php echo e($settings['min_app_version']); ?>" placeholder="e.g. 1.0.0">
                    <div class="help">Older versions are asked to update before they can check in.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Map tiles</h2>
                <p>One setting for every map — this panel and the mobile app both follow it.</p>
            </div>
            <?php if ($attTiles['provider'] === 'google'): ?>
                <span class="badge ok">Google tiles live</span>
            <?php else: ?>
                <span class="badge muted">OpenStreetMap</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($attTiles['fallback_reason']): ?>
                <div class="alert error" style="margin:0 0 16px">
                    <strong>Google tiles are not active.</strong>
                    <?php echo e($attTiles['fallback_reason']); ?>
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="s_provider">Tile source</label>
                <select id="s_provider" name="map_provider">
                    <option value="osm" <?php echo $settings['map_provider'] === 'osm' ? 'selected' : ''; ?>>
                        OpenStreetMap — free, no key required
                    </option>
                    <option value="google" <?php echo $settings['map_provider'] === 'google' ? 'selected' : ''; ?>>
                        Google Maps official tiles — needs an API key and billing
                    </option>
                </select>
                <div class="help">
                    Changing this takes effect on the app at its next sync; no app update is needed.
                </div>
            </div>

            <div id="styleFields">
                <div class="field">
                    <label>Map style <span class="opt">(OpenStreetMap data, drawn differently)</span></label>
                    <div class="style-grid">
                        <?php foreach (attMapStyles() as $key => $style): ?>
                            <?php $checked = ($settings['map_style'] ?? 'google_like') === $key; ?>
                            <label class="style-card <?php echo $checked ? 'chosen' : ''; ?>">
                                <input type="radio" name="map_style" value="<?php echo e($key); ?>"
                                       <?php echo $checked ? 'checked' : ''; ?>>
                                <span class="style-preview" data-style="<?php echo e($key); ?>"
                                      data-url="<?php echo e($style['tile_url']); ?>"
                                      data-filter="<?php echo e($style['filter'] ?? ''); ?>"></span>
                                <strong><?php echo e($style['label']); ?></strong>
                                <small><?php echo e($style['note']); ?></small>
                            </label>
                        <?php endforeach; ?>
                        <?php $customChosen = ($settings['map_style'] ?? '') === 'custom'; ?>
                        <label class="style-card <?php echo $customChosen ? 'chosen' : ''; ?>">
                            <input type="radio" name="map_style" value="custom"
                                   <?php echo $customChosen ? 'checked' : ''; ?>>
                            <span class="style-preview" style="display:flex;align-items:center;
                                  justify-content:center;color:var(--ink-soft);font-size:11px">custom URL</span>
                            <strong>Custom</strong>
                            <small>Use the tile URL and filter set below — for a self-hosted
                                or paid tile server.</small>
                        </label>
                    </div>
                    <div class="help" style="margin-top:10px">
                        Previews are live tiles of Doha. Measured against Google's own palette across
                        four Doha tiles, the Google-like styles average a colour distance of 12.9 and
                        9.4, versus 21.6 for unmodified OpenStreetMap.
                    </div>
                </div>

                <div class="field">
                    <label for="s_filter">Colour transform <span class="opt">(optional override)</span></label>
                    <input type="text" id="s_filter" name="tile_filter_css"
                           value="<?php echo e($settings['tile_filter_css']); ?>"
                           placeholder="leave blank to use the style's own setting">
                    <div class="help">
                        A CSS filter applied to the tile images only, never to the geofences or
                        route lines drawn over them. The tinted-OSM preset uses
                        <code>saturate(0.76) brightness(1.07) contrast(1.02) hue-rotate(2deg)</code>,
                        which was fitted numerically rather than chosen by eye.
                    </div>
                </div>
            </div>

            <div id="googleFields" style="border-left:3px solid var(--brand);padding-left:15px;margin:18px 0">
                <div class="field">
                    <label for="s_gkey">Google Maps Platform API key</label>
                    <input type="text" id="s_gkey" name="google_api_key" autocomplete="off"
                           value="<?php echo e($settings['google_api_key']); ?>"
                           placeholder="AIza…">
                    <div class="help">
                        The project needs the <strong>Map Tiles API</strong> enabled and a billing
                        account attached. This is the licensed way to use Google's tiles inside the
                        map libraries this product uses — pulling tiles from Google's internal
                        endpoints instead would breach their terms and get the key banned.
                    </div>
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="s_gtype">Map style</label>
                        <select id="s_gtype" name="google_map_type">
                            <?php foreach (['roadmap' => 'Roadmap', 'satellite' => 'Satellite', 'terrain' => 'Terrain'] as $v => $l): ?>
                                <option value="<?php echo $v; ?>" <?php echo $settings['google_map_type'] === $v ? 'selected' : ''; ?>>
                                    <?php echo $l; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="s_glang">Label language</label>
                        <input type="text" id="s_glang" name="google_language"
                               value="<?php echo e($settings['google_language']); ?>" placeholder="en">
                        <div class="help">
                            <code>en</code> keeps labels in English over Arabic place names.
                        </div>
                    </div>
                    <div class="field">
                        <label for="s_gregion">Region</label>
                        <input type="text" id="s_gregion" name="google_region" maxlength="2"
                               value="<?php echo e($settings['google_region']); ?>" placeholder="QA">
                    </div>
                </div>

                <?php if ($settings['google_session_expires_at']): ?>
                    <p style="margin:0;font-size:12.5px;color:var(--ink-soft)">
                        Session token valid until
                        <?php echo e(date('d M Y H:i', strtotime($settings['google_session_expires_at']))); ?>.
                        It is renewed automatically; each renewal is one billable request, not one per map load.
                    </p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="s_tile">OpenStreetMap fallback URL</label>
                <input type="text" id="s_tile" name="tile_url" value="<?php echo e($settings['tile_url']); ?>">
                <div class="help">
                    Used when the source above is OpenStreetMap, and automatically if Google's
                    session call fails — so a billing problem degrades the maps instead of blanking them.
                    OSM's public server has a usage policy a company fleet can exceed.
                </div>
            </div>
            <div class="field">
                <label for="s_attr">Fallback attribution</label>
                <input type="text" id="s_attr" name="tile_attribution" value="<?php echo e($settings['tile_attribution']); ?>">
            </div>
        </div>
    </div>

    <script>
    (function () {
        var select = document.getElementById('s_provider');
        var googleFields = document.getElementById('googleFields');
        var styleFields = document.getElementById('styleFields');

        // The style presets only mean anything for OSM; Google's own tiles carry
        // their own cartography.
        function sync() {
            var google = select.value === 'google';
            googleFields.style.display = google ? '' : 'none';
            styleFields.style.display = google ? 'none' : '';
        }
        select.addEventListener('change', sync);
        sync();

        // Live tile previews, so the choice is made by looking rather than reading.
        // One fixed tile of central Doha at z14 — enough to show roads and water.
        var Z = 14, X = 10537, Y = 7002;
        document.querySelectorAll('.style-preview[data-url]').forEach(function (box) {
            var url = box.getAttribute('data-url')
                .replace('{z}', Z).replace('{x}', X).replace('{y}', Y);
            var img = document.createElement('img');
            img.src = url;
            img.alt = '';
            img.width = 128;
            img.height = 128;
            var filter = box.getAttribute('data-filter');
            if (filter) img.style.filter = filter;
            img.onerror = function () {
                box.textContent = 'preview unavailable';
                box.style.cssText += 'display:flex;align-items:center;justify-content:center;' +
                    'font-size:11px;color:var(--ink-soft)';
            };
            box.appendChild(img);
        });

        // Keep the selected card visually marked.
        document.querySelectorAll('input[name="map_style"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                document.querySelectorAll('.style-card').forEach(function (card) {
                    card.classList.toggle('chosen', card.contains(radio) && radio.checked);
                });
            });
        });
    })();
    </script>

    <?php
    $packManifest = attPackManifest();
    $styleInfo = attPackStyleInfo();
    $hasKey = trim((string)($settings['protomaps_api_key'] ?? '')) !== '';
    ?>
    <div class="card" style="margin-top:20px">
        <div class="card-head">
            <div>
                <h2>Offline map</h2>
                <p>So the app still shows a map when a phone has no signal. Position and
                   geofence checks already work offline — this is the picture.</p>
            </div>
        </div>
        <div class="card-body">
            <div class="grid">
                <div class="field">
                    <label for="s_pmkey">Protomaps API key</label>
                    <input type="password" id="s_pmkey" name="protomaps_api_key"
                           autocomplete="off" placeholder="<?php
                               echo $hasKey ? '•••••••• saved — type to replace' : 'paste your key'; ?>">
                    <div class="help">
                        Used once, here on the server, to download the map style.
                        <strong>The app never receives it.</strong>
                        Leave blank to keep the saved key.
                        <?php if ($hasKey): ?>
                            <br><label style="font-weight:500">
                                <input type="checkbox" name="protomaps_clear" value="1"
                                       style="width:14px;height:14px"> remove the saved key
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="field">
                    <label for="s_packfile">Archive file</label>
                    <input type="text" id="s_packfile" name="offline_map_file"
                           value="<?php echo e($settings['offline_map_file'] ?? 'qatar.pmtiles'); ?>">
                    <div class="help">
                        A <code>.pmtiles</code> file in <code>uploads/mappacks/</code>.
                        <?php if ($packManifest['available']): ?>
                            <br><span class="badge ok">installed</span>
                            <?php echo number_format($packManifest['bytes'] / 1048576, 2); ?> MB
                        <?php else: ?>
                            <br><span class="badge bad">not found</span> nothing to serve yet
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="help" style="margin-top:6px">
                Map style:
                <?php if ($styleInfo['available']): ?>
                    <span class="badge ok">downloaded</span>
                    <?php echo number_format($styleInfo['bytes'] / 1024, 1); ?> KB.
                    The app renders the archive with this.
                <?php else: ?>
                    <span class="badge warn">missing</span>
                    Until it is downloaded the app stays on online tiles — the archive
                    cannot be drawn without a style.
                <?php endif; ?>
            </div>
        </div>
    </div>

    <button class="btn" type="submit">Save settings</button>
</form>

<?php if ($attCanManage): ?>
<div class="card" style="margin-top:18px">
    <div class="card-head">
        <div>
            <h2>Download the map style</h2>
            <p>Save the key first, then fetch. Separate from Save because it calls out to
               Protomaps and can be slow.</p>
        </div>
    </div>
    <form method="post" class="card-body">
        <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
        <input type="hidden" name="action" value="fetch_style">
        <div class="grid">
            <div class="field">
                <label for="s_flav">Look</label>
                <select id="s_flav" name="style_flavour">
                    <?php foreach (['light' => 'Light', 'dark' => 'Dark', 'white' => 'White',
                                    'grayscale' => 'Grayscale'] as $v => $l): ?>
                        <option value="<?php echo $v; ?>"><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="s_slang">Label language</label>
                <input type="text" id="s_slang" name="style_language" value="en" maxlength="10">
                <div class="help"><code>en</code> gives English labels over Arabic place names.</div>
            </div>
        </div>
        <button class="btn ghost" type="submit" <?php echo $hasKey ? '' : 'disabled'; ?>>
            Fetch style now
        </button>
        <?php if (!$hasKey): ?>
            <div class="help">Save a Protomaps API key above first.</div>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<div class="card" style="margin-top:22px">
    <div class="card-head"><div><h2>Data held</h2></div></div>
    <div class="card-body">
        <div class="tiles" style="margin:0">
            <div class="tile"><div class="label">App accounts</div><div class="value"><?php echo (int)$counts['accounts']; ?></div></div>
            <div class="tile"><div class="label">Linked devices</div><div class="value"><?php echo (int)$counts['devices']; ?></div></div>
            <div class="tile"><div class="label">Work areas</div><div class="value"><?php echo (int)$counts['geofences']; ?></div></div>
            <div class="tile"><div class="label">Attendance rows</div><div class="value"><?php echo number_format((int)$counts['attendance_rows']); ?></div></div>
            <div class="tile"><div class="label">Route points</div>
                <div class="value"><?php echo number_format((int)$counts['location_rows']); ?></div>
                <div class="hint"><?php echo $oldestLog ? 'since ' . date('d M Y', strtotime($oldestLog)) : 'none yet'; ?></div></div>
            <div class="tile"><div class="label">Outside trips</div><div class="value"><?php echo number_format((int)$counts['trips']); ?></div></div>
        </div>
        <p style="margin:16px 0 0;color:var(--ink-soft);font-size:12.5px">
            Route points are the fastest-growing table: roughly
            <?php echo (int)$counts['accounts']; ?> employees ×
            <?php echo max(1, (int)round(9 * 60 / max(1, (int)$settings['default_interval_min']))); ?> points a day.
            Plan an archival job once you know the real daily volume.
        </p>
    </div>
</div>

<?php include __DIR__ . '/layout_bottom.php'; ?>
