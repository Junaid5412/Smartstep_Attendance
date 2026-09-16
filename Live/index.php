<?php
/**
 * Personal live board: every active account on one map.
 *
 * Same login as the Website admin panel. Inside there are no restrictions —
 * all person types, all projects, with or without a position — because this
 * page exists for one person to see everything at a glance.
 */

require_once __DIR__ . '/../Website/admin/bootstrap.php';

$type = $_GET['type'] ?? 'all';
if (!in_array($type, ['all', 'employee', 'monitor', 'driver'], true)) {
    $type = 'all';
}
$projectFilter = (int)($_GET['project'] ?? 0) ?: null;

$projects = attDB()->query("
    SELECT DISTINCT p.id, p.project_name
    FROM projects p
    JOIN att_person emp ON emp.project_id = p.id
    JOIN att_employees e ON e.person_type = emp.person_type
      AND emp.person_id = COALESCE(e.person_ref, e.employee_id, e.monitor_id, e.driver_id)
    WHERE e.is_active = 1
    ORDER BY p.project_name
")->fetchAll(PDO::FETCH_ASSOC);

$counts = ['employee' => 0, 'monitor' => 0, 'driver' => 0];
foreach (attDB()->query("SELECT person_type, COUNT(*) AS n FROM att_employees WHERE is_active = 1 GROUP BY person_type")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    if (isset($counts[$c['person_type']])) {
        $counts[$c['person_type']] = (int)$c['n'];
    }
}

$pageTitle = 'Live Board';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Live Board · SST Attendance</title>
<link rel="stylesheet" href="../Website/assets/vendor/leaflet/leaflet.css">
<style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; background: #eef1f6; color: #16233a; }
    header { background: #1d4ed8; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    header h1 { font-size: 17px; margin: 0 12px 0 0; }
    header .tabs { display: flex; gap: 6px; flex-wrap: wrap; }
    header a.tab { color: #fff; text-decoration: none; padding: 6px 12px; border-radius: 999px; border: 1px solid rgba(255,255,255,.45); font-size: 13px; }
    header a.tab.on { background: #fff; color: #1d4ed8; font-weight: 700; }
    header select, header input { padding: 6px 9px; border-radius: 8px; border: 1px solid rgba(255,255,255,.45); font-size: 13px; }
    #stats { background: #fff; border-bottom: 1px solid #dde3ec; padding: 8px 16px; font-size: 13px; color: #5b6b82; display: flex; gap: 18px; flex-wrap: wrap; }
    #stats b { color: #16233a; }
    #wrap { display: flex; height: calc(100vh - 118px); min-height: 420px; }
    #map { flex: 1; min-width: 0; }
    #side { width: 320px; background: #fff; border-left: 1px solid #dde3ec; overflow-y: auto; }
    #side .row { padding: 9px 13px; border-bottom: 1px solid #eef1f6; cursor: pointer; font-size: 13px; }
    #side .row:hover { background: #f4f6fa; }
    #side .row .nm { font-weight: 700; }
    #side .row .sub { color: #5b6b82; font-size: 12px; }
    .dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 7px; }
    #err { display: none; background: #fdeAEC; color: #b4232a; padding: 9px 16px; font-size: 13px; }
    @media (max-width: 800px) {
        #wrap { flex-direction: column; height: auto; }
        #map { height: 55vh; }
        #side { width: 100%; border-left: 0; max-height: 40vh; }
    }
</style>
</head>
<body>
<header>
    <h1>Live Board</h1>
    <div class="tabs">
        <a class="tab<?php echo $type === 'all' ? ' on' : ''; ?>" href="?">All (<?php echo array_sum($counts); ?>)</a>
        <a class="tab<?php echo $type === 'employee' ? ' on' : ''; ?>" href="?type=employee<?php echo $projectFilter ? '&project=' . $projectFilter : ''; ?>">Employees (<?php echo $counts['employee']; ?>)</a>
        <a class="tab<?php echo $type === 'monitor' ? ' on' : ''; ?>" href="?type=monitor<?php echo $projectFilter ? '&project=' . $projectFilter : ''; ?>">Monitors (<?php echo $counts['monitor']; ?>)</a>
        <a class="tab<?php echo $type === 'driver' ? ' on' : ''; ?>" href="?type=driver<?php echo $projectFilter ? '&project=' . $projectFilter : ''; ?>">Drivers (<?php echo $counts['driver']; ?>)</a>
    </div>
    <form method="get" style="display:flex;gap:8px;align-items:center;margin-left:auto">
        <input type="hidden" name="type" value="<?php echo e($type); ?>">
        <select name="project" onchange="this.form.submit()">
            <option value="">All projects</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>"<?php echo $projectFilter === (int)$p['id'] ? ' selected' : ''; ?>><?php echo e($p['project_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" id="q" placeholder="Search name or code" style="min-width:170px">
    </form>
</header>
<div id="err"></div>
<div id="stats"><span id="livePill" style="display:none;background:#059669;color:#fff;font-weight:700;border-radius:999px;padding:2px 10px">● LIVE</span><span>Showing <b id="stN">…</b></span><span>Live position <b id="stLive">…</b></span><span>Stale (&gt;30 min) <b id="stStale">…</b></span><span>No position yet <b id="stNone">…</b></span><span><label for="followSecs" style="font-size:12px">Follow every</label>
<select id="followSecs" style="font-size:12px;padding:2px 5px;border-radius:6px;border:1px solid #dde3ec">
<option value="1">1s (battery killer)</option>
<option value="2">2s</option>
<option value="3" selected>3s</option>
<option value="5">5s</option>
<option value="10">10s</option>
</select></span><span style="margin-left:auto" id="stTime"></span></div>
<div id="wrap">
    <div id="map"></div>
    <div id="side"></div>
</div>

<script src="../Website/assets/vendor/leaflet/leaflet.js"></script>
<script>
(function () {
    var TYPE_COLORS = { employee: '#2563eb', monitor: '#d97706', driver: '#059669' };
    var FEED = 'data.php?type=<?php echo $type; ?><?php echo $projectFilter ? '&project=' . $projectFilter : ''; ?>';

    var map = L.map('map').setView([25.2854, 51.5310], 10);
    L.tileLayer(<?php echo json_encode($attTiles['tile_url']); ?>, {
        attribution: <?php echo json_encode($attTiles['attribution']); ?>,
        maxZoom: <?php echo (int)$attTiles['max_zoom']; ?>
    }).addTo(map);

    var fenceLayer = L.layerGroup().addTo(map);
    var markLayer = L.layerGroup().addTo(map);
    var byId = {};
    var firstFit = false;
    var query = '';

    document.getElementById('q').addEventListener('input', function (e) {
        query = e.target.value.trim().toLowerCase();
        renderList();
    });

    function ageText(m) {
        if (m == null) return 'never';
        return m < 1 ? 'just now' : (m < 60 ? m + ' min ago' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm ago');
    }

    var staff = [];
    function matches(s) {
        return !query || (s.name + ' ' + s.code).toLowerCase().indexOf(query) !== -1;
    }

    function renderList() {
        var side = document.getElementById('side');
        side.innerHTML = '';
        staff.filter(matches).forEach(function (s) {
            var div = document.createElement('div');
            div.className = 'row';
            var color = s.has_position ? (TYPE_COLORS[s.type] || '#555') : '#94a3b8';
            div.innerHTML = '<span class="dot" style="background:' + color + '"></span>' +
                '<span class="nm">' + escapeHtml(s.name) + '</span><br>' +
                '<span class="sub">' + escapeHtml(s.code + (s.project ? ' · ' + s.project : '') +
                    (s.has_position ? ' · ' + ageText(s.age_min) : ' · no position')) + '</span>';
            div.onclick = function () {
                if (s.has_position) map.setView([s.lat, s.lng], Math.max(map.getZoom(), 14));
                if (byId[s.id]) byId[s.id].openPopup();
            };
            side.appendChild(div);
        });
    }

    function escapeHtml(t) {
        return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function popupHtml(s) {
        return '<b>' + escapeHtml(s.name) + '</b><br>' +
            escapeHtml(s.code + ' · ' + s.type + (s.project ? ' · ' + s.project : '')) + '<br>' +
            (s.shift ? 'Shift ' + escapeHtml(s.shift) + '<br>' : '') +
            'Today: ' + (s.checked_in ? (s.checked_out ? 'done' : 'checked in') : 'not started') + '<br>' +
            (s.has_position
                ? (s.source === 'live' ? 'Live board position<br>' : 'Shift route position<br>') +
                  'Seen ' + ageText(s.age_min) + (s.stale ? ' (stale)' : '') + '<br>' +
                  (s.battery_pct != null ? 'Battery ' + s.battery_pct + '%<br>' : '') +
                  (s.is_mock ? '<b style="color:#b4232a">Simulated position</b>' :
                    (s.inside_fence === false ? '<b style="color:#b4232a">Outside work area</b>' : 'Inside work area'))
                : 'No position recorded yet') +
            (s.device ? '<br>' + escapeHtml(s.device) : '');
    }

    function drawFences(geofences) {
        fenceLayer.clearLayers();
        geofences.forEach(function (g) {
            if (g.type === 'polygon' && g.polygon && g.polygon.length) {
                L.polygon(g.polygon.map(function (p) { return [p[0], p[1]]; }), {
                    color: g.color || '#2563eb', weight: 1.5, fillOpacity: 0.06
                }).bindTooltip(g.name).addTo(fenceLayer);
            } else if (g.center_lat != null && g.radius_m) {
                L.circle([g.center_lat, g.center_lng], {
                    radius: g.radius_m, color: g.color || '#2563eb', weight: 1.5, fillOpacity: 0.06
                }).bindTooltip(g.name).addTo(fenceLayer);
            }
        });
    }

    function drawMarkers() {
        markLayer.clearLayers();
        byId = {};
        staff.forEach(function (s) {
            if (!s.has_position) return;
            var m = L.circleMarker([s.lat, s.lng], {
                radius: s.stale ? 6 : 8,
                color: '#fff', weight: 2,
                fillColor: s.stale ? '#94a3b8' : (TYPE_COLORS[s.type] || '#555'),
                fillOpacity: s.stale ? 0.55 : 0.95
            }).bindPopup(popupHtml(s)).addTo(markLayer);
            byId[s.id] = m;
        });
        if (!firstFit && staff.some(function (s) { return s.has_position; })) {
            firstFit = true;
            map.fitBounds(markLayer.getBounds().pad(0.15));
        }
    }

    function refresh() {
        // A slow first query is normal (cold database), not an expired session —
        // so failures retry quietly twice before the banner earns its text.
        attemptFetch(0);
    }

    function attemptFetch(tries) {
        fetch(FEED, { credentials: 'same-origin', redirect: 'manual' }).then(function (r) {
            // An actual login redirect means the session really is gone. Anything
            // else that is not OK is the network or a slow server: retry.
            if (r.type === 'opaqueredirect' || r.status === 401 || r.status === 403) {
                sessionDead();
                return;
            }
            if (!r.ok) throw 0;
            return r.json();
        }).then(function (data) {
            if (!data) return;
            if (data.error) throw 0;
            document.getElementById('err').style.display = 'none';
            document.getElementById('err').innerHTML = '';
            staff = data.staff || [];
            var live = staff.filter(function (s) { return s.has_position && !s.stale; }).length;
            var stale = staff.filter(function (s) { return s.stale; }).length;
            document.getElementById('stN').textContent = staff.length;
            document.getElementById('stLive').textContent = live;
            document.getElementById('stStale').textContent = stale;
            document.getElementById('stNone').textContent = staff.length - live - stale;
            document.getElementById('stTime').textContent = 'Updated ' + new Date().toLocaleTimeString();
            drawFences(data.geofences || []);
            drawMarkers();
            renderList();
            // Start the follow as soon as there is anyone to follow, rather
            // than waiting out the first 20s heartbeat.
            if (staff.length && document.getElementById('livePill').style.display === 'none') {
                watchHeartbeat();
            }
        }).catch(function () {
            // The map keeps showing the last good data while reconnecting —
            // blanking the screen over one slow response would be worse.
            if (tries < 2) {
                setTimeout(function () { attemptFetch(tries + 1); }, 3000);
            } else {
                var bar = document.getElementById('err');
                bar.innerHTML = 'Reconnecting… the server is taking too long. ' +
                    '<a href="" onclick="location.reload();return false">reload</a>';
                bar.style.display = 'block';
            }
        });
    }

    function sessionDead() {
        var bar = document.getElementById('err');
        bar.innerHTML = 'Session expired — <a href="../Website/admin/login.php">sign in again</a>, then come back.';
        bar.style.display = 'block';
    }

    function followSeconds() {
        return parseInt(document.getElementById('followSecs').value, 10) || 3;
    }

    // Board refresh tracks the follow rate: no point repainting slower than the
    // phones report, and never faster than every 2s — the query behind this is
    // cheap but not free.
    function refreshMs() {
        return Math.max(2000, followSeconds() * 1000);
    }

    // Tells the phones on screen to report fast while this board is open.
    // Heartbeated, so closing the board (or the laptop sleeping) ends every
    // follow by expiry — nothing to switch off, nowhere to forget.
    var watchTimer = null;
    function watchHeartbeat() {
        if (document.hidden) return;
        var ids = staff.map(function (s) { return s.id; });
        if (!ids.length) return;
        fetch('watch.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: ids, seconds: followSeconds() })
        }).then(function (r) { return r.json(); }).then(function (d) {
            document.getElementById('livePill').style.display =
                (d && d.watching > 0) ? 'inline' : 'none';
        }).catch(function () {});
    }

    function armWatch() {
        if (watchTimer) clearInterval(watchTimer);
        watchHeartbeat();
        watchTimer = setInterval(watchHeartbeat, 20000);
    }

    // Best-effort stand-down on close. The 90s window expiry is the backstop,
    // so a missed beacon only costs a minute of fast reporting.
    window.addEventListener('pagehide', function () {
        var ids = staff.map(function (s) { return s.id; });
        if (!ids.length || !navigator.sendBeacon) return;
        navigator.sendBeacon('watch.php', JSON.stringify({ action: 'stop', ids: ids }));
    });

    var refreshTimer = null;
    function armRefresh() {
        if (refreshTimer) clearInterval(refreshTimer);
        refreshTimer = setInterval(function () { if (!document.hidden) refresh(); }, refreshMs());
    }
    document.getElementById('followSecs').addEventListener('change', armRefresh);

    refresh();
    armRefresh();
    armWatch();
})();
</script>
</body>
</html>
