<?php
/**
 * App releases: publish a new APK, and see who is running what.
 *
 * Its own page rather than a card in Settings because the APK is tens of megabytes and
 * the upload needs a progress bar — buried in a long settings form, an admin would sit
 * watching a frozen page with no idea whether anything was happening.
 */

require_once __DIR__ . '/bootstrap.php';

// Posted by fetch/XHR so the browser can report upload progress. Answers JSON rather
// than redirecting, because a redirect mid-upload gives the page nothing to show.
if (($_POST['action'] ?? null) === 'publish_release') {
    header('Content-Type: application/json; charset=utf-8');
    attCheckCsrf();

    if (!attCanManage()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your role cannot publish releases.']);
        exit;
    }

    $result = attPublishRelease(
        $_FILES['apk'] ?? [],
        $_POST['release_version'] ?? '',
        $_POST['release_notes_text'] ?? '',
        isset($_POST['force_update']),
        (int)$attAdmin['id']
    );

    echo json_encode(['success' => $result['ok'], 'message' => $result['message']]);
    exit;
}

$release = attReleaseInfo();
$report = attInstallReport();

$pageTitle = 'App Version';
$pageSubtitle = 'Publish a build, and see which phones have taken it';
include __DIR__ . '/layout_top.php';
?>

<div class="tiles">
    <div class="tile"><div class="label">Published</div>
        <div class="value" style="font-size:22px">
            <?php echo e($release['latest_version'] ?: '—'); ?>
        </div>
        <div class="hint">
            <?php echo $release['available']
                ? number_format($release['bytes'] / 1048576, 1) . ' MB'
                : 'no APK uploaded'; ?>
        </div>
    </div>
    <div class="tile"><div class="label">Minimum allowed</div>
        <div class="value" style="font-size:22px"><?php echo e($release['min_version'] ?: 'none'); ?></div>
        <div class="hint">older installs are refused</div>
    </div>
    <div class="tile good"><div class="label">Up to date</div>
        <div class="value"><?php echo $report['summary']['current']; ?></div></div>
    <?php if ($report['summary']['outdated'] > 0): ?>
        <div class="tile attention"><div class="label">Behind</div>
            <div class="value"><?php echo $report['summary']['outdated']; ?></div>
            <div class="hint">still working</div></div>
    <?php endif; ?>
    <?php if ($report['summary']['blocked_out'] > 0): ?>
        <div class="tile alert"><div class="label">Locked out</div>
            <div class="value"><?php echo $report['summary']['blocked_out']; ?></div>
            <div class="hint">below the minimum</div></div>
    <?php endif; ?>
    <?php if ($report['summary']['unknown'] > 0): ?>
        <div class="tile"><div class="label">Not reported</div>
            <div class="value"><?php echo $report['summary']['unknown']; ?></div>
            <div class="hint">never opened the app</div></div>
    <?php endif; ?>
</div>

<?php if ($attCanManage): ?>
<div class="card">
    <div class="card-head">
        <div>
            <h2>Publish a build</h2>
            <p>Sign it with the same keystore, or phones will refuse the update.</p>
        </div>
    </div>
    <div class="card-body">
        <form id="publishForm">
            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
            <input type="hidden" name="action" value="publish_release">

            <div class="grid">
                <div class="field">
                    <label for="r_ver">Version</label>
                    <input type="text" id="r_ver" name="release_version" placeholder="1.0.1" required>
                    <div class="help">Digits and dots. Re-using the current version is allowed —
                        that is how a bad build gets replaced.</div>
                </div>
                <div class="field">
                    <label for="r_apk">APK file</label>
                    <input type="file" id="r_apk" name="apk" accept=".apk" required>
                </div>
            </div>

            <div class="field">
                <label for="r_notes">What changed</label>
                <input type="text" id="r_notes" name="release_notes_text" maxlength="1000"
                       placeholder="Shown to the employee before they update">
            </div>

            <div class="check">
                <input type="checkbox" id="r_force" name="force_update" value="1">
                <div>
                    <label for="r_force">Required update — older versions stop working</label>
                    <div class="help">Sets the minimum to this version, so every older install is
                        refused by the server until it updates. Leave unticked to offer it as
                        optional.</div>
                </div>
            </div>

            <button class="btn" type="submit" id="publishBtn">Publish version</button>
        </form>

        <!-- Hidden until an upload starts. A 56 MB post with no feedback looks like a
             hung page, and the natural response is to press the button again. -->
        <div id="uploadProgress" style="display:none;margin-top:16px">
            <div style="height:8px;background:#eef1f6;border-radius:999px;overflow:hidden">
                <div id="uploadBar" style="height:100%;width:0;background:var(--brand);
                     transition:width .2s"></div>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:7px;
                 font-size:12.5px;color:var(--ink-soft)">
                <span id="uploadPct">0%</span>
                <span id="uploadBytes"></span>
            </div>
            <div id="uploadNote" style="font-size:12.5px;margin-top:5px"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:20px">
    <div class="card-head">
        <div>
            <h2>Who has updated</h2>
            <p>Version reported by each phone at sign-in and on every heartbeat.</p>
        </div>
    </div>
    <div class="card-body tight">
        <div class="table-wrap">
        <table class="data">
            <thead>
                <tr><th>Employee</th><th>Device</th><th>App version</th>
                    <th>Status</th><th>Last seen</th></tr>
            </thead>
            <tbody>
            <?php foreach ($report['rows'] as $row): ?>
                <tr>
                    <td class="who-cell">
                        <strong><?php echo e($row['name']); ?></strong>
                        <small><?php echo e($row['employee_code']); ?></small>
                    </td>
                    <td><small><?php echo e($row['device_model'] ?: 'no device linked'); ?></small></td>
                    <td><code><?php echo e($row['app_version'] ?: '—'); ?></code></td>
                    <td>
                        <?php
                        // Wording matters here: "behind" still works, "locked out" does not.
                        // Collapsing them into one label would hide who cannot work today.
                        [$class, $label] = match ($row['update_state']) {
                            'current' => ['ok', 'Up to date'],
                            'outdated' => ['warn', 'Behind'],
                            'blocked_out' => ['bad', 'Locked out'],
                            default => ['muted', 'Not reported'],
                        };
                        ?>
                        <span class="badge <?php echo $class; ?>"><?php echo $label; ?></span>
                    </td>
                    <td><small><?php echo $row['last_seen_at']
                        ? e(date('d M, H:i', strtotime($row['last_seen_at'])))
                        : '—'; ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php if ($attCanManage): ?>
<script>
(function () {
    var form = document.getElementById('publishForm');
    if (!form) return;

    var btn = document.getElementById('publishBtn');
    var box = document.getElementById('uploadProgress');
    var bar = document.getElementById('uploadBar');
    var pct = document.getElementById('uploadPct');
    var bytesEl = document.getElementById('uploadBytes');
    var note = document.getElementById('uploadNote');

    function mb(n) { return (n / 1048576).toFixed(1) + ' MB'; }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var file = document.getElementById('r_apk').files[0];
        if (!file) return;

        btn.disabled = true;
        btn.textContent = 'Uploading…';
        box.style.display = 'block';
        note.textContent = '';
        note.style.color = '';

        // XHR rather than fetch: fetch still has no upload-progress event, and progress
        // is the entire reason this page exists.
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'app-version.php');

        xhr.upload.addEventListener('progress', function (e) {
            if (!e.lengthComputable) return;
            var share = e.loaded / e.total;
            bar.style.width = (share * 100).toFixed(1) + '%';
            pct.textContent = (share * 100).toFixed(0) + '%';
            bytesEl.textContent = mb(e.loaded) + ' of ' + mb(e.total);
        });

        // The gap between the last byte leaving and the server answering is where the
        // file is moved into place. Saying so stops a full bar looking stuck.
        xhr.upload.addEventListener('load', function () {
            pct.textContent = '100%';
            note.textContent = 'Upload finished — the server is storing it…';
        });

        xhr.addEventListener('load', function () {
            btn.disabled = false;
            btn.textContent = 'Publish version';
            var payload;
            try {
                payload = JSON.parse(xhr.responseText);
            } catch (e) {
                note.style.color = '#c22f38';
                note.textContent = 'The server returned an unexpected response. ' +
                    'The file may be larger than it accepts.';
                return;
            }
            note.style.color = payload.success ? '#12855c' : '#c22f38';
            note.textContent = payload.message;
            // Reloaded on success so the tiles and the table reflect the new release.
            if (payload.success) setTimeout(function () { location.reload(); }, 1400);
        });

        xhr.addEventListener('error', function () {
            btn.disabled = false;
            btn.textContent = 'Publish version';
            note.style.color = '#c22f38';
            note.textContent = 'The upload failed. Check the connection and try again.';
        });

        xhr.send(new FormData(form));
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/layout_bottom.php'; ?>
