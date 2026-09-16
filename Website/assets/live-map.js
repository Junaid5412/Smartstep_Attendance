/* Live map for admin/live-map.php. Polls admin/data.php?what=live and moves the
   markers in place rather than rebuilding the layer, so the map does not flicker
   and the popup the admin is reading stays open across a refresh. */
(function () {
    'use strict';

    if (!document.getElementById('liveMap') || !window.L) return;

    var map = AttMap.create('liveMap');
    var markers = {};          // att_employee_id -> marker
    var fenceLayers = {};      // geofence id -> layer, drawn once
    var people = [];
    var timer = null;
    var firstLoad = true;

    var showWho = document.getElementById('showWho');
    var refreshEvery = document.getElementById('refreshEvery');
    var statusEl = document.getElementById('liveStatus');
    var listEl = document.getElementById('peopleList');
    var countEl = document.getElementById('peopleCount');

    /** Employee ids with an outstanding locate request. */
    var locating = {};

    /** Employee ids currently being followed, as reported by the server. */
    var following = {};

    /** Whose follow window this page is responsible for keeping alive. */
    var followTimer = null;
    var followingId = null;

    /**
     * Ask one person's phone to report every few seconds while we watch.
     *
     * The window is deliberately short and renewed from here, so it lapses on its own
     * the moment this page stops asking — closed tab, navigated away, laptop shut. A
     * few-second reporting rate is a real drain on the employee's battery, and nobody
     * should be left transmitting because an admin forgot to switch something off.
     *
     * Only one person is followed at a time: following several at once multiplies the
     * battery cost across people nobody is actually looking at.
     */
    /** How long each renewal asks for, from the toolbar. */
    function followMinutes() {
        var el = document.getElementById('followFor');
        return el ? el.value : '5';
    }

    function startFollow(employeeId, name) {
        stopFollow(true);
        followingId = String(employeeId);

        var renew = function () {
            var body = new FormData();
            body.append('csrf', window.ATT.csrf);
            body.append('employee', followingId);
            body.append('seconds', '8');
            body.append('minutes', followMinutes());

            fetch('data.php?what=follow', {
                method: 'POST', credentials: 'same-origin', body: body
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload.success) {
                        statusEl.textContent = payload.message || 'Could not follow.';
                        stopFollow();
                        return;
                    }
                    statusEl.textContent = 'Following ' + name + ' — reporting every ' +
                        (payload.seconds || 8) + 's. Expect the first update in ' +
                        'up to a minute.';
                })
                .catch(function () { stopFollow(); });
        };

        renew();
        // Renewed well inside the server's window so a single failed request does not
        // interrupt the follow, and speed up the map while we are watching.
        followTimer = setInterval(renew, 90 * 1000);
        refreshEvery.value = '5';
        startTimer();
        render();
    }

    function stopFollow(silent) {
        if (followTimer) { clearInterval(followTimer); followTimer = null; }
        if (!followingId) return;

        var body = new FormData();
        body.append('csrf', window.ATT.csrf);
        body.append('employee', followingId);
        // Told to stop rather than left to lapse: the phone should go back to its
        // normal interval now, not in five minutes.
        fetch('data.php?what=unfollow', {
            method: 'POST', credentials: 'same-origin', body: body, keepalive: true
        }).catch(function () { /* the window expires by itself anyway */ });

        followingId = null;
        if (!silent) {
            statusEl.textContent = 'Stopped following.';
            refreshEvery.value = '30';
            startTimer();
            render();
        }
    }

    // A closed tab must not leave somebody transmitting every few seconds.
    window.addEventListener('pagehide', function () { stopFollow(true); });

    /**
     * Ask a phone for its position now.
     *
     * The device collects this on its next poll, so the wait is up to
     * poll_seconds — the button says so rather than implying it is instant, and the
     * map refreshes faster for a while so the answer appears without a manual reload.
     */
    function requestLocate(employeeId, name) {
        var body = new FormData();
        body.append('csrf', window.ATT.csrf);
        body.append('employee', employeeId);

        locating[employeeId] = true;
        render();

        fetch('data.php?what=locate', {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                statusEl.textContent = payload.message ||
                    (payload.success ? 'Location requested.' : 'Could not request a location.');

                if (!payload.success) {
                    delete locating[employeeId];
                    render();
                    return;
                }

                // Poll hard for a short while so the new fix shows up on its own.
                var checks = 0;
                var chase = setInterval(function () {
                    checks++;
                    load();
                    if (checks >= 12) clearInterval(chase);
                }, 5000);
            })
            .catch(function () {
                delete locating[employeeId];
                render();
                statusEl.innerHTML = '<span style="color:#c22f38">Could not reach the server.</span>';
            });
    }

    function colourFor(person) {
        if (person.is_mock) return '#7c3aed';
        if (person.stale) return '#98a4b8';
        return person.inside_fence === false ? '#c22f38' : '#12855c';
    }

    function visible() {
        var mode = showWho.value;
        var roleEl = document.getElementById('personType');
        var role = roleEl ? roleEl.value : 'all';
        
        return people.filter(function (person) {
            if (role !== 'all' && person.person_type !== role) return false;
            
            if (mode === 'on_shift') return person.on_shift;
            if (mode === 'outside') return person.inside_fence === false;
            if (mode === 'online') return person.has_position && !person.stale;
            if (mode === 'offline') return !person.has_position || person.stale;
            return true;
        });
    }

    function popupHtml(person) {
        var esc = AttMap.escapeHtml;
        var rows = [];

        rows.push('<strong>' + esc(person.name) + '</strong><br>');
        rows.push('<small>' + esc(person.employee_code) +
                  (person.position ? ' · ' + esc(person.position) : '') + '</small><hr style="margin:6px 0">');

        if (person.inside_fence === false) {
            rows.push('<span style="color:#c22f38"><strong>Outside work area</strong> — ' +
                      person.distance_from_fence_m.toLocaleString() + ' m away</span><br>');
        } else if (person.geofence) {
            rows.push('Inside <strong>' + esc(person.geofence.name) + '</strong><br>');
        } else {
            rows.push('<em>No work area assigned</em><br>');
        }

        rows.push('Reported: ' + esc(person.recorded_at) +
                  ' <small>(' + person.age_minutes + ' min ago)</small><br>');

        if (person.checked_in) {
            rows.push('Checked in ' + esc((person.check_in_at || '').substring(11, 16)) +
                      (person.checked_out ? ' · checked out' : '') + '<br>');
        } else {
            rows.push('<span style="color:#a86400">Not checked in today</span><br>');
        }

        if (person.accuracy_m != null) rows.push('<small>Accuracy ±' + Math.round(person.accuracy_m) + ' m</small><br>');
        if (person.battery_pct != null) rows.push('<small>Battery ' + person.battery_pct + '%</small><br>');
        if (person.is_mock) rows.push('<small style="color:#7c3aed"><strong>Mock location reported</strong></small><br>');
        if (person.stale) rows.push('<small style="color:#98a4b8">Tracking may have stopped on this device</small><br>');

        if (window.ATT.canManage) {
            rows.push('<div style="margin-top:8px">' + liveButtons(person) + '</div>');
            rows.push('<small style="color:#5d6b83">Following reports every ~8s and uses ' +
                      'their battery</small><br>');
        }

        rows.push('<div style="margin-top:8px"><a href="routes.php?employee=' + person.att_employee_id +
                  '&date=' + new Date().toISOString().slice(0, 10) + '">View today\'s route →</a></div>');

        return rows.join('');
    }

    function render() {
        var shown = visible();
        var seen = {};

        shown.forEach(function (person) {
            // Somebody who has never reported a position has nothing to pin. They still
            // belong in the list, where they can be asked to report one.
            if (!person.has_position) return;

            seen[person.att_employee_id] = true;

            // Draw each work area once; several employees often share one.
            if (person.geofence && !fenceLayers[person.geofence.id]) {
                fenceLayers[person.geofence.id] = AttMap.drawGeofence(map, person.geofence, { fillOpacity: 0.07 });
            }

            var existing = markers[person.att_employee_id];
            if (existing) {
                existing.setLatLng([person.lat, person.lng]);
                existing.setStyle({ fillColor: colourFor(person) });
                existing.setPopupContent(popupHtml(person));
            } else {
                var marker = AttMap.dot([person.lat, person.lng], colourFor(person), 8)
                    .addTo(map)
                    .bindPopup(popupHtml(person))
                    .bindTooltip(person.name, { direction: 'top' });
                markers[person.att_employee_id] = marker;
            }
        });

        // Remove anyone the current filter excludes.
        Object.keys(markers).forEach(function (id) {
            if (!seen[id]) {
                map.removeLayer(markers[id]);
                delete markers[id];
            }
        });

        renderList(shown);

        // Keyed off the markers actually placed, not the list length: fitting to an
        // empty set of bounds throws and would take the whole refresh down with it.
        if (firstLoad && Object.keys(markers).length) {
            AttMap.fit(map, Object.keys(markers).map(function (id) { return markers[id]; }));
            firstLoad = false;
        }

        var roleEl = document.getElementById('personType');
        var role = roleEl ? roleEl.value : 'all';

        var total = 0, online = 0, offline = 0, outside = 0;
        people.forEach(function (person) {
            if (role !== 'all' && person.person_type !== role) return;

            total++;
            if (person.has_position && !person.stale) online++;
            else offline++;
            if (person.inside_fence === false) outside++;
        });

        var elTotal = document.getElementById('statTotal');
        if (elTotal) {
            elTotal.textContent = total;
            document.getElementById('statOnline').textContent = online;
            document.getElementById('statOffline').textContent = offline;
            document.getElementById('statOutside').textContent = outside;

            var tiles = document.querySelectorAll('#liveStats .tile');
            tiles.forEach(function(t) {
                if (t.getAttribute('data-filter') === showWho.value) {
                    t.classList.add('active');
                } else {
                    t.classList.remove('active');
                }
            });
        }
    }

    function renderList(shown) {
        countEl.textContent = shown.length + ' shown of ' + people.length;

        if (!shown.length) {
            listEl.innerHTML = '<div class="empty">Nobody matches this filter.</div>';
            return;
        }

        // Outside first, then stale, then the rest, then people with no position at
        // all: worst news at the top, and nothing pressing at the bottom.
        var sorted = shown.slice().sort(function (a, b) {
            var rank = function (p) {
                if (!p.has_position) return 3;
                return p.inside_fence === false ? 0 : (p.stale ? 1 : 2);
            };
            return rank(a) - rank(b) || a.name.localeCompare(b.name);
        });

        var esc = AttMap.escapeHtml;
        listEl.innerHTML = sorted.map(function (person) {
            var id = person.att_employee_id;

            // No position: no marker to focus, so the row carries the controls itself.
            // A plain div, not a button — nesting buttons is invalid and makes the inner
            // ones unclickable in some browsers.
            if (!person.has_position) {
                var label = person.has_device
                    ? '<span class="badge muted">no position yet</span>'
                    : '<span class="badge muted">no device linked</span>';

                var actions = '';
                if (window.ATT.canManage && person.has_device) {
                    actions = '<div style="margin-top:7px">' + liveButtons(person) + '</div>';
                }

                return '<div class="person" style="cursor:default">' +
                       '<strong>' + esc(person.name) + '</strong>' +
                       '<small>' + esc(person.employee_code) + ' · ' + label + '</small>' +
                       actions + '</div>';
            }

            var note;
            if (person.inside_fence === false) {
                note = '<span class="badge bad">' + person.distance_from_fence_m.toLocaleString() + ' m outside</span>';
            } else if (person.stale) {
                note = '<span class="badge muted">' + person.age_minutes + ' min silent</span>';
            } else {
                note = '<span class="badge ok">in area</span>';
            }
            return '<button class="person" type="button" data-focus="' + id + '">' +
                   '<strong>' + esc(person.name) + '</strong>' +
                   '<small>' + esc(person.employee_code) + ' · ' + note + '</small></button>';
        }).join('');
    }

    /**
     * Locate / follow controls, shared by the map popup and the list row.
     *
     * One source for both: when these lived only in the popup, anyone without a marker
     * — which off shift is everybody — had no way to be asked for a position.
     */
    function liveButtons(person) {
        var esc = AttMap.escapeHtml;
        var id = person.att_employee_id;
        var out = [];

        out.push(locating[id]
            ? '<span style="color:#a86400;font-size:12px"><strong>Waiting for the phone…</strong></span>'
            : '<button type="button" class="btn small" data-locate="' + id + '" ' +
              'data-name="' + esc(person.name) + '">Locate now</button>');

        if (String(followingId) === String(id)) {
            out.push(' <button type="button" class="btn small danger" data-unfollow="1">' +
                     'Stop following</button> <small style="color:#12855c">live</small>');
        } else if (following[id]) {
            out.push(' <small style="color:#a86400">followed by another user</small>');
        } else {
            out.push(' <button type="button" class="btn small" data-follow="' + id + '" ' +
                     'data-name="' + esc(person.name) + '">Follow live</button>');
        }

        return out.join('');
    }

    listEl.addEventListener('click', function (event) {
        var locate = event.target.closest('[data-locate]');
        if (locate) {
            event.stopPropagation();
            requestLocate(locate.getAttribute('data-locate'), locate.getAttribute('data-name'));
            return;
        }

        // Follow controls now appear on list rows as well, for people with no marker.
        if (event.target.closest('[data-unfollow]')) {
            event.stopPropagation();
            stopFollow();
            return;
        }

        var follow = event.target.closest('[data-follow]');
        if (follow) {
            event.stopPropagation();
            startFollow(follow.getAttribute('data-follow'), follow.getAttribute('data-name'));
            return;
        }

        var button = event.target.closest('[data-focus]');
        if (!button) return;
        var marker = markers[button.getAttribute('data-focus')];
        if (marker) {
            map.setView(marker.getLatLng(), 16);
            marker.openPopup();
        }
    });

    // Popups are attached to the map pane, not the list, so they need their own
    // listener.
    map.getContainer().addEventListener('click', function (event) {
        if (event.target.closest('[data-unfollow]')) { stopFollow(); return; }

        var follow = event.target.closest('[data-follow]');
        if (follow) {
            startFollow(follow.getAttribute('data-follow'), follow.getAttribute('data-name'));
            return;
        }

        var locate = event.target.closest('[data-locate]');
        if (!locate) return;
        requestLocate(locate.getAttribute('data-locate'), locate.getAttribute('data-name'));
    });

    function load() {
        statusEl.textContent = 'Refreshing…';

        fetch('data.php?what=live', { credentials: 'same-origin' })
            .then(function (response) {
                // A session that expired mid-poll returns the login page, not JSON.
                if (response.redirected || !response.ok) throw new Error('Session expired');
                return response.json();
            })
            .then(function (payload) {
                people = payload.people || [];

                // The server is the authority on which requests are still open, so a
                // reload or a second admin's request is reflected here too.
                locating = {};
                (payload.locating || []).forEach(function (id) { locating[id] = true; });

                following = {};
                (payload.following || []).forEach(function (id) { following[id] = true; });

                render();

                // Counted separately from the roster: "12 people" while the map shows
                // three dots is the kind of number that gets trusted and shouldn't be.
                var located = people.filter(function (p) { return p.has_position; }).length;
                statusEl.textContent = 'Updated ' + new Date().toLocaleTimeString() +
                    ' · ' + located + ' position' + (located === 1 ? '' : 's') +
                    ' of ' + people.length;
            })
            .catch(function () {
                statusEl.innerHTML = '<span style="color:#c22f38">Could not refresh — ' +
                    'your session may have expired. <a href="login.php">Sign in again</a></span>';
                stopTimer();
            });
    }

    function stopTimer() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    function startTimer() {
        stopTimer();
        var seconds = parseInt(refreshEvery.value, 10);
        if (seconds > 0) timer = setInterval(load, seconds * 1000);
    }

    showWho.addEventListener('change', render);
    var personTypeEl = document.getElementById('personType');
    if (personTypeEl) personTypeEl.addEventListener('change', render);
    refreshEvery.addEventListener('change', startTimer);
    document.getElementById('refreshNow').addEventListener('click', load);

    var statTiles = document.querySelectorAll('#liveStats .tile');
    statTiles.forEach(function (tile) {
        tile.addEventListener('click', function (e) {
            e.preventDefault();
            var filter = tile.getAttribute('data-filter');
            if (filter) {
                showWho.value = filter;
                render();
            }
        });
    });

    // Polling while the tab is hidden wastes requests on a page nobody is watching.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stopTimer(); } else { load(); startTimer(); }
    });

    load();
    startTimer();
})();
