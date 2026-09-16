<?php
/**
 * WhatsApp: connect a device by QR and manage who receives notifications.
 *
 * The heavy lifting lives in the operator's Node connector (Baileys). This page talks to
 * it twice over: PHP calls its REST API for sending and lifecycle, and the browser opens
 * its WebSocket for the QR — the QR is only ever pushed, never polled.
 */

require_once __DIR__ . '/bootstrap.php';

$action = $_POST['action'] ?? null;

if ($action) {
    attCheckCsrf();
    attRequireManage();

    if ($action === 'save_settings') {
        $url = trim($_POST['whatsapp_server_url'] ?? '');
        // http on localhost is legitimate (connector on the same box); anything remote
        // should be https, but that is the operator's call, not a hard refusal.
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            attFlash('error', 'The server URL must start with http:// or https://');
            attRedirect('whatsapp.php');
        }
        $session = trim($_POST['whatsapp_session_id'] ?? 'sst-attendance');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $session)) {
            $session = 'sst-attendance';
        }

        attDB()->prepare("
            UPDATE att_settings
            SET whatsapp_enabled = ?, whatsapp_server_url = ?, whatsapp_session_id = ?
            WHERE id = 1
        ")->execute([isset($_POST['whatsapp_enabled']) ? 1 : 0, $url ?: null, $session]);
        attSettingsRefresh();

        attAudit('whatsapp_settings_saved', 'att_settings', 1,
            ['enabled' => isset($_POST['whatsapp_enabled'])], 'admin', (int)$attAdmin['id']);
        attFlash('success', 'WhatsApp settings saved.');
        attRedirect('whatsapp.php');
    }

    if ($action === 'add_number') {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        $label = trim($_POST['label'] ?? '');

        // 8 digits is the shortest national number in use; 15 is the E.164 ceiling.
        // The number must carry its country code — the connector sends to exactly
        // what is stored, and "5551234" reaches nobody.
        if (strlen($phone) < 8 || strlen($phone) > 15) {
            attFlash('error', 'Enter the full number with country code, digits only — e.g. 97455XXXXXX.');
            attRedirect('whatsapp.php');
        }

        try {
            attDB()->prepare("
                INSERT INTO att_whatsapp_numbers (phone, label, created_by) VALUES (?, ?, ?)
            ")->execute([$phone, $label ?: null, (int)$attAdmin['id']]);
            attAudit('whatsapp_number_added', 'att_whatsapp_number', (int)attDB()->lastInsertId(),
                ['phone' => $phone, 'label' => $label], 'admin', (int)$attAdmin['id']);
            attFlash('success', 'Number added. It starts switched on.');
        } catch (PDOException $e) {
            attFlash('error', 'That number is already on the list.');
        }
        attRedirect('whatsapp.php');
    }

    if ($action === 'toggle_number') {
        $id = (int)($_POST['id'] ?? 0);
        attDB()->prepare("
            UPDATE att_whatsapp_numbers SET is_active = 1 - is_active WHERE id = ?
        ")->execute([$id]);
        attAudit('whatsapp_number_toggled', 'att_whatsapp_number', $id, null, 'admin', (int)$attAdmin['id']);
        attRedirect('whatsapp.php');
    }

    if ($action === 'delete_number') {
        $id = (int)($_POST['id'] ?? 0);
        $row = attDB()->prepare("SELECT phone FROM att_whatsapp_numbers WHERE id = ?");
        $row->execute([$id]);
        $phone = $row->fetchColumn();
        attDB()->prepare("DELETE FROM att_whatsapp_numbers WHERE id = ?")->execute([$id]);
        attAudit('whatsapp_number_deleted', 'att_whatsapp_number', $id,
            ['phone' => $phone], 'admin', (int)$attAdmin['id']);
        attFlash('success', 'Number removed.');
        attRedirect('whatsapp.php');
    }

    if ($action === 'send_test') {
        $sent = attWaNotify('Test message', [
            'Sent by' => trim(($attAdmin['first_name'] ?? '') . ' ' . ($attAdmin['last_name'] ?? '')) ?: $attAdmin['username'],
            'If you can read this, notifications are working.',
        ]);
        attFlash($sent > 0 ? 'success' : 'error', $sent > 0
            ? "Test sent to $sent number" . ($sent === 1 ? '' : 's') . '.'
            : 'Nothing sent — check the device is connected, notifications are enabled, and at least one number is switched on.');
        attRedirect('whatsapp.php');
    }
}

$config = attWaConfig();
$numbers = attDB()->query("
    SELECT n.*, u.username AS added_by
    FROM att_whatsapp_numbers n
    LEFT JOIN users u ON u.id = n.created_by
    ORDER BY n.id
")->fetchAll(PDO::FETCH_ASSOC);

$activeCount = count(array_filter($numbers, fn($n) => (int)$n['is_active'] === 1));

$pageTitle = 'WhatsApp';
$pageSubtitle = 'Connect a device and choose who receives notifications';
include __DIR__ . '/layout_top.php';
?>

<div class="tiles">
    <div class="tile <?php echo $config['enabled'] ? 'good' : ''; ?>">
        <div class="label">Notifications</div>
        <div class="value" style="font-size:22px"><?php echo $config['enabled'] ? 'On' : 'Off'; ?></div>
    </div>
    <div class="tile"><div class="label">Device</div>
        <div class="value" style="font-size:22px" id="deviceTile">—</div>
        <div class="hint" id="deviceTileHint">checking…</div>
    </div>
    <div class="tile <?php echo $activeCount ? 'good' : 'attention'; ?>">
        <div class="label">Receiving numbers</div>
        <div class="value"><?php echo $activeCount; ?></div>
        <div class="hint">of <?php echo count($numbers); ?> on the list</div>
    </div>
</div>

<?php if ($attCanManage): ?>
<div class="card">
    <div class="card-head"><div>
        <h2>Connector</h2>
        <p>The Node WhatsApp service this panel sends through. It keeps the signed-in
           session on its own disk, so a panel deploy never signs the device out.</p>
    </div></div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
            <input type="hidden" name="action" value="save_settings">
            <div class="grid">
                <div class="field">
                    <label for="wa_url">Server URL</label>
                    <input type="text" id="wa_url" name="whatsapp_server_url"
                           value="<?php echo e($config['url']); ?>" placeholder="https://wa.example.com">
                    <div class="help">Must be reachable from this browser too — the QR arrives over
                        its WebSocket. On an HTTPS panel that means an https:// URL.</div>
                </div>
                <div class="field">
                    <label for="wa_session">Session name</label>
                    <input type="text" id="wa_session" name="whatsapp_session_id"
                           value="<?php echo e($config['session']); ?>">
                    <div class="help">This panel's own session on the connector. Leave it alone unless
                        the connector also serves other systems and the name collides.</div>
                </div>
            </div>
            <div class="check">
                <input type="checkbox" id="wa_enabled" name="whatsapp_enabled" value="1"
                       <?php echo $config['enabled'] ? 'checked' : ''; ?>>
                <div><label for="wa_enabled">Send notifications</label>
                    <div class="help">The master switch. Off, nothing is sent to anybody, whatever
                        the per-number switches say.</div></div>
            </div>
            <button class="btn" type="submit">Save</button>
        </form>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-head"><div>
        <h2>Device</h2>
        <p>Scan the QR with the WhatsApp account that should send the notifications —
           WhatsApp &rarr; Linked devices &rarr; Link a device. Once linked, the session is saved
           on the connector and reconnects by itself.</p>
    </div></div>
    <div class="card-body" id="deviceBody">
        <div id="waStatus" style="font-size:13px;color:var(--ink-soft)">Connecting to the connector…</div>
        <div id="waQrWrap" style="display:none;margin-top:14px">
            <img id="waQr" alt="Scan with WhatsApp" style="width:260px;height:260px;border:1px solid var(--line);border-radius:10px">
            <div class="help" style="margin-top:6px">The code refreshes by itself; scan whichever is showing.</div>
        </div>
        <div id="waConnected" style="display:none;margin-top:8px">
            <span class="badge ok">connected</span>
            <strong id="waPhone" style="margin-left:8px"></strong>
        </div>
        <div class="action-row" style="margin-top:14px;display:flex;gap:8px">
            <button class="btn ghost" type="button" id="waRestart">Restart session</button>
            <button class="btn danger" type="button" id="waLogout">Sign device out</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:20px">
    <div class="card-head"><div>
        <h2>Notification numbers</h2>
        <p>Everyone here receives every notification while their switch is on: left work
           area, at home on duty, impossible movement, and test messages.</p>
    </div>
    <?php if ($attCanManage): ?>
    <form method="post" style="margin-left:auto">
        <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
        <input type="hidden" name="action" value="send_test">
        <button class="btn ghost" type="submit">Send a test</button>
    </form>
    <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($attCanManage): ?>
        <form method="post" class="grid" style="align-items:end;margin-bottom:16px">
            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
            <input type="hidden" name="action" value="add_number">
            <div class="field" style="margin:0">
                <label for="n_phone">Phone (with country code, digits only)</label>
                <input type="text" id="n_phone" name="phone" placeholder="97455XXXXXX" required>
            </div>
            <div class="field" style="margin:0">
                <label for="n_label">Label <span class="opt">(optional)</span></label>
                <input type="text" id="n_label" name="label" placeholder="e.g. Operations manager">
            </div>
            <div><button class="btn" type="submit">Add number</button></div>
        </form>
        <?php endif; ?>

        <?php if (!$numbers): ?>
            <div class="empty">No numbers yet. Notifications go nowhere until one is added.</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Phone</th><th>Label</th><th>Status</th><th>Added by</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($numbers as $n): ?>
                <tr>
                    <td><code>+<?php echo e($n['phone']); ?></code></td>
                    <td><?php echo e($n['label'] ?: '—'); ?></td>
                    <td><span class="badge <?php echo $n['is_active'] ? 'ok' : 'muted'; ?>">
                        <?php echo $n['is_active'] ? 'receiving' : 'muted'; ?></span></td>
                    <td><small><?php echo e($n['added_by'] ?: '—'); ?></small></td>
                    <td style="white-space:nowrap">
                        <?php if ($attCanManage): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                            <input type="hidden" name="action" value="toggle_number">
                            <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                            <button class="btn ghost small" type="submit">
                                <?php echo $n['is_active'] ? 'Mute' : 'Unmute'; ?></button>
                        </form>
                        <form method="post" style="display:inline"
                              data-confirm="Remove +<?php echo e($n['phone']); ?> from the list?">
                            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                            <input type="hidden" name="action" value="delete_number">
                            <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                            <button class="btn danger small" type="submit">Remove</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($attCanManage): ?>
<script>
(function () {
    var serverUrl = <?php echo json_encode($config['url']); ?>;
    var sessionId = <?php echo json_encode($config['session']); ?>;
    var statusEl = document.getElementById('waStatus');
    var qrWrap = document.getElementById('waQrWrap');
    var qrImg = document.getElementById('waQr');
    var connectedEl = document.getElementById('waConnected');
    var phoneEl = document.getElementById('waPhone');
    var tile = document.getElementById('deviceTile');
    var tileHint = document.getElementById('deviceTileHint');

    if (!serverUrl) {
        statusEl.textContent = 'Enter the connector URL above and save first.';
        tile.textContent = '—'; tileHint.textContent = 'no connector configured';
        return;
    }

    function show(state, phone) {
        qrWrap.style.display = 'none';
        connectedEl.style.display = 'none';
        if (state === 'connected') {
            connectedEl.style.display = 'block';
            phoneEl.textContent = '+' + (phone || '');
            statusEl.textContent = 'This device sends the notifications.';
            tile.textContent = 'Linked'; tileHint.textContent = '+' + (phone || '');
        } else if (state === 'qr') {
            qrWrap.style.display = 'block';
            statusEl.textContent = 'Waiting for a scan…';
            tile.textContent = 'Scan QR'; tileHint.textContent = 'waiting for a scan';
        } else {
            statusEl.textContent = 'Status: ' + state;
            tile.textContent = state === 'connecting' ? '…' : 'Offline';
            tileHint.textContent = state;
        }
    }

    // The QR is pushed over the connector's WebSocket; there is no REST way to get it.
    // Reconnects with a delay: the connector restarting must not strand the page.
    var socket = null;
    function connect() {
        var wsUrl = serverUrl.replace(/^http/i, 'ws') + '/ws?session=' + encodeURIComponent(sessionId);
        try {
            socket = new WebSocket(wsUrl);
        } catch (e) {
            statusEl.textContent = 'Could not reach the connector at ' + serverUrl;
            tile.textContent = 'Offline'; tileHint.textContent = 'unreachable';
            return;
        }

        socket.onmessage = function (event) {
            var msg;
            try { msg = JSON.parse(event.data); } catch (e) { return; }
            if (msg.type === 'qr') {
                qrImg.src = msg.qr;
                show('qr');
            } else if (msg.type === 'status') {
                show(msg.status, msg.phoneNumber);
            }
        };
        socket.onclose = function () {
            tile.textContent = 'Offline'; tileHint.textContent = 'connector unreachable';
            statusEl.textContent = 'Lost the connector — retrying…';
            setTimeout(connect, 5000);
        };
    }
    connect();

    // Lifecycle calls go straight to the connector (it allows cross-origin requests).
    document.getElementById('waRestart').addEventListener('click', function () {
        fetch(serverUrl + '/session/' + encodeURIComponent(sessionId) + '/restart', { method: 'POST' })
            .then(function () { statusEl.textContent = 'Restarting…'; })
            .catch(function () { statusEl.textContent = 'The connector did not answer.'; });
    });

    document.getElementById('waLogout').addEventListener('click', function () {
        if (!confirm('Sign the WhatsApp device out? A QR scan will be needed to reconnect.')) return;
        fetch(serverUrl + '/session/' + encodeURIComponent(sessionId), { method: 'DELETE' })
            .then(function () { statusEl.textContent = 'Signed out.'; show('disconnected'); })
            .catch(function () { statusEl.textContent = 'The connector did not answer.'; });
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/layout_bottom.php'; ?>
