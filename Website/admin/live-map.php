<?php
/**
 * Live map: where everyone is right now, and who is outside their work area.
 */

require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Live Map';
$pageSubtitle = 'Latest reported position of every employee, refreshed automatically';
$needsMap = true;
include __DIR__ . '/layout_top.php';
?>

<div class="toolbar">
    <div class="field">
        <label for="showWho">Show</label>
        <select id="showWho">
            <?php /* Everyone by default, including people with no position yet — that is
                     the only way to reach "Locate now" for them, and off shift that is
                     everybody. */ ?>
            <option value="all" selected>Everyone</option>
            <option value="on_shift">On shift now</option>
            <option value="online">Online (Tracking active)</option>
            <option value="offline">Offline (Stale/No report)</option>
            <option value="outside">Outside their area only</option>
        </select>
    </div>
    <div class="field">
        <label for="personType">Role</label>
        <select id="personType">
            <option value="all" selected>All Roles</option>
            <option value="employee">Staff / Office</option>
            <option value="driver">Drivers</option>
            <option value="monitor">Monitors</option>
        </select>
    </div>
    <div class="field">
        <label for="refreshEvery">Refresh</label>
        <?php /* 5s only makes a difference while someone is being followed — a phone
                 on its normal interval has nothing new to report that often. Following
                 selects it automatically. */ ?>
        <select id="refreshEvery">
            <option value="5" selected>Every 5 seconds</option>
            <option value="10">Every 10 seconds</option>
            <option value="30">Every 30 seconds</option>
            <option value="60">Every minute</option>
            <option value="300">Every 5 minutes</option>
            <option value="0">Off</option>
        </select>
    </div>
    <div class="field">
        <label for="followFor">Follow for</label>
        <?php /* The window is renewed from this page while it is open, so closing the
                 tab still ends it early. This is the ceiling, not a commitment. */ ?>
        <select id="followFor">
            <option value="5" selected>5 minutes</option>
            <option value="15">15 minutes</option>
            <option value="60">1 hour</option>
            <option value="240">4 hours</option>
        </select>
    </div>
    <button class="btn ghost" type="button" id="refreshNow">Refresh now</button>
    <div class="spacer"></div>
    <div style="font-size:12.5px;color:var(--ink-soft)" id="liveStatus">Loading…</div>
</div>

<style>
a.tile { text-decoration: none; color: inherit; display: block; transition: all 0.2s; cursor: pointer; }
a.tile:hover { border-color: var(--brand); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(31, 79, 216, 0.1); }
a.tile.active { border-color: var(--brand); border-width: 2px; padding: 14px 15px; } /* Adjust padding for 2px border */
</style>
<div class="tiles" id="liveStats">
    <a class="tile active" data-filter="all">
        <div class="label">Total People</div>
        <div class="value" id="statTotal">—</div>
    </a>
    <a class="tile good" data-filter="online">
        <div class="label">Online</div>
        <div class="value" id="statOnline">—</div>
        <div class="hint">tracking active</div>
    </a>
    <a class="tile attention" data-filter="offline">
        <div class="label">Offline</div>
        <div class="value" id="statOffline">—</div>
        <div class="hint">no recent report</div>
    </a>
    <a class="tile alert" data-filter="outside">
        <div class="label">Outside Zone</div>
        <div class="value" id="statOutside">—</div>
        <div class="hint">currently outside</div>
    </a>
</div>

<div class="split">
    <div class="card" style="margin:0">
        <div class="card-head"><div><h2>People</h2><p id="peopleCount">—</p></div></div>
        <div class="card-body tight people-list" id="peopleList">
            <div class="empty">Loading…</div>
        </div>
    </div>

    <div class="card" style="margin:0">
        <div class="card-body tight">
            <div id="liveMap" class="map"></div>
        </div>
        <div class="map-legend">
            <span><span class="dot inside"></span> inside work area</span>
            <span><span class="dot outside"></span> outside work area</span>
            <span><span class="dot stale"></span> no recent report</span>
            <span><span class="dot mock"></span> mock location detected</span>
        </div>
    </div>
</div>

<?php
$footExtra = '<script src="' . ATT_ASSETS_URL . '/live-map.js?v=' . time() . '"></script>';
include __DIR__ . '/layout_bottom.php';
?>
