/* Route replay for admin/routes.php.

   The polyline is split by inside/outside so the map itself shows where someone
   left their area, and by time gap so a period when the tracking service was dead
   is drawn as a break instead of an invented straight line. */
(function () {
    'use strict';

    var request = window.ATT_ROUTE_REQUEST;
    if (!request || !document.getElementById('routeMap') || !window.L) return;

    var map = AttMap.create('routeMap');
    var esc = AttMap.escapeHtml;
    var points = [];
    var marker = null;
    var playTimer = null;

    /* Everything the route draws lives here so a redraw can clear it in one step
       instead of stacking a second copy of the line on top of the first. */
    var routeLayer = L.layerGroup().addTo(map);

    /* The full response, kept so the stride can be changed without refetching. */
    var raw = null;

    var minGapSelect = document.getElementById('minGap');

    /* Markers currently on the map, so the eraser can recolour them. */
    var drawnDots = [];

    /**
     * Thin a recorded route down to a minimum spacing, for display only.
     *
     * A one-minute log is the right thing to *store* — it is the only way to see
     * where someone actually went — but it is hard to read across a whole shift. This
     * drops intermediate points without touching the record.
     *
     * Two points are never dropped: the ends of each segment, and any point where
     * inside/outside changes. Plain time-based thinning would happily delete a short
     * excursion entirely and leave the map claiming the employee never left, which
     * would make this filter actively misleading rather than merely coarse.
     */
    /* MySQL hands back "2026-08-06 20:15:42". Date.parse tolerates the space in most
       browsers but not all, so the separator is normalised rather than trusted. */
    function stamp(value) {
        return Date.parse(String(value || '').replace(' ', 'T'));
    }

    function thin(segments, gapSeconds) {
        if (!gapSeconds) return segments;

        return segments.map(function (segment) {
            if (segment.length <= 2) return segment;

            var kept = [segment[0]];
            var lastAt = stamp(segment[0].recorded_at);

            for (var i = 1; i < segment.length - 1; i++) {
                var point = segment[i];
                var at = stamp(point.recorded_at);
                var crossing = point.inside_fence !== kept[kept.length - 1].inside_fence;

                if (crossing || isNaN(at) || isNaN(lastAt) || (at - lastAt) >= gapSeconds * 1000) {
                    kept.push(point);
                    if (!isNaN(at)) lastAt = at;
                }
            }

            kept.push(segment[segment.length - 1]);
            return kept;
        });
    }

    var scrub = document.getElementById('scrub');
    var scrubLabel = document.getElementById('scrubLabel');
    var playBtn = document.getElementById('playBtn');

    function minutes(value) {
        if (value == null) return '—';
        var h = Math.floor(value / 60), m = value % 60;
        return h > 0 ? h + 'h ' + m + 'm' : m + 'm';
    }

    function tile(id, value, note) {
        document.getElementById(id).textContent = value;
        if (note !== undefined) {
            var el = document.getElementById(id + 'Note');
            if (el) el.textContent = note;
        }
    }

    fetch('data.php?what=route&employee=' + request.employee + '&date=' + request.date,
          { credentials: 'same-origin' })
        .then(function (response) {
            if (!response.ok) throw new Error('load failed');
            return response.json();
        })
        .then(function (data) {
            raw = data;
            render();
        })
        .catch(function () {
            scrubLabel.textContent = 'Could not load the route.';
        });

    /**
     * Draw the route at the stride currently selected.
     *
     * Thinning happens here rather than server-side so changing the stride is instant
     * and never risks the displayed route disagreeing with the stored one.
     */
    function render() {
        if (!raw) return;

        var gap = parseInt(minGapSelect ? minGapSelect.value : 0, 10) || 0;
        var segments = thin(raw.route.segments || [], gap);
        var flat = segments.reduce(function (all, segment) {
            return all.concat(segment);
        }, []);

        draw(Object.assign({}, raw, {
            route: Object.assign({}, raw.route, {
                segments: segments,
                points: flat,
                // The stored total, not the thinned one: the distance travelled is a
                // fact about the day, not about how coarsely it is being viewed.
                shown_count: flat.length
            })
        }));
    }

    if (minGapSelect) minGapSelect.addEventListener('change', render);

    function draw(data) {
        var layers = [];

        routeLayer.clearLayers();
        // The scrub marker lived in the group that was just emptied; keeping the
        // reference would leave showAt() moving a marker no longer on the map.
        marker = null;

        var fence = AttMap.drawGeofence(routeLayer, data.geofence, { fillOpacity: 0.08 });
        if (fence) layers.push(fence);

        points = data.route.points || [];

        /* ---------- polyline, one per run of inside/outside, dwells collapsed ---------- */
        //
        // Previously this drew a separate two-point polyline per leg — about 540 layers for
        // a nine-hour day at one-minute sampling — and drew every fix, so an hour spent
        // standing in one place became a star of criss-crossing lines. Two changes fix the
        // look: a dwell contributes a single vertex however many fixes landed in it, and
        // consecutive legs on the same side of the fence become one polyline.
        //
        // smoothFactor is Leaflet's own Douglas-Peucker simplification, applied per zoom
        // level at render time. Doing it that way rather than simplifying the coordinates
        // ourselves keeps the stored geometry untouched and stays correct as the operator
        // zooms in — a fixed tolerance computed once would either over-smooth close up or
        // do nothing far out.
        (data.route.segments || []).forEach(function (segment) {
            var vertices = [];
            var lastDwell = null;

            segment.forEach(function (point) {
                // Left out of the line, still drawn as a dot below: joining a fix that is
                // only accurate to a few hundred metres to its neighbours invents travel.
                if (point.imprecise) return;

                if (point.dwell != null) {
                    // One vertex for the whole stop. The circle drawn later carries the
                    // detail, including how many readings it stands for.
                    if (point.dwell === lastDwell) return;
                    lastDwell = point.dwell;
                } else {
                    lastDwell = null;
                }
                vertices.push(point);
            });

            var run = [];
            var runOutside = null;

            function flush(outside) {
                if (run.length > 1) {
                    layers.push(L.polyline(run.map(function (p) { return [p.lat, p.lng]; }), {
                        color: outside ? '#c22f38' : '#12855c',
                        weight: 4,
                        opacity: 0.85,
                        smoothFactor: 2,
                        // Rounded joins stop a long run of short legs looking like a saw
                        // blade where the direction changes slightly.
                        lineJoin: 'round',
                        lineCap: 'round'
                    }).addTo(routeLayer));
                }
                run = [];
            }

            vertices.forEach(function (point) {
                var outside = point.inside_fence === false;

                if (run.length === 0) {
                    run.push(point);
                    runOutside = outside;
                    return;
                }
                if (outside === runOutside) {
                    run.push(point);
                    return;
                }

                // A crossing. The original rule was that a leg counts as outside if
                // either end is outside, so the red section covers the whole excursion
                // rather than half of it — preserved here by deciding which run the
                // boundary leg belongs to, and by starting the next run at the same point
                // so the line stays continuous instead of showing a gap at the fence.
                if (outside) {
                    var previous = run[run.length - 1];
                    flush(runOutside);
                    run = [previous, point];
                    runOutside = true;
                } else {
                    run.push(point);
                    flush(true);
                    run = [point];
                    runOutside = false;
                }
            });

            flush(runOutside);
        });

        /* ---------- individual points ---------- */
        var dwellShown = {};
        drawnDots = [];
        points.forEach(function (point, index) {
            // Imprecise fixes are shown small and pale rather than hidden. They are real
            // readings and an admin comparing the map with the point count is entitled to
            // see them; they are simply not evidence of a position worth drawing a line to.
            if (point.imprecise) {
                AttMap.dot([point.lat, point.lng], '#94a3b8', 2.5)
                    .addTo(routeLayer)
                    .bindTooltip('±' + Math.round(point.accuracy_m) + ' m — too imprecise to plot a path',
                                 { direction: 'top' });
                return;
            }

            // Inside a stop only the first reading gets a dot; the rest are what made the
            // scribble. The stop's own circle reports how many there were.
            if (point.dwell != null) {
                if (dwellShown[point.dwell]) return;
                dwellShown[point.dwell] = true;
            }

            var colour = point.is_mock ? '#7c3aed' : (point.inside_fence === false ? '#c22f38' : '#12855c');

            // Where the phone genuinely was while claiming to be here. Drawn as a grey
            // dot joined by a dashed line, so the deception and the truth sit on the
            // same map instead of one hiding the other.
            var realNote = '';
            if (point.real_lat != null && point.real_lng != null) {
                var line = L.polyline(
                    [[point.lat, point.lng], [point.real_lat, point.real_lng]],
                    { color: '#475569', weight: 1.5, dashArray: '4 5', opacity: 0.8 }
                ).addTo(routeLayer);
                var realDot = AttMap.dot([point.real_lat, point.real_lng], '#475569', 5)
                    .addTo(routeLayer)
                    .bindTooltip('Genuine position while faking', { direction: 'top' });
                layers.push(line, realDot);
                realNote = '<span style="color:#475569"><strong>Genuine position captured</strong>' +
                    ' — the grey dot</span><br>';
            }

            var dot = AttMap.dot([point.lat, point.lng], colour, 4).addTo(routeLayer);
            // Remembered so the eraser can highlight this exact marker rather than drawing a
            // second set on top, which would leave the admin guessing which layer is which.
            if (point.id != null) drawnDots.push({ point: point, marker: dot, colour: colour });
            dot.bindPopup(
                    '<strong>' + esc(point.recorded_at) + '</strong><br>' +
                    (point.inside_fence === false
                        ? '<span style="color:#c22f38">Outside area — ' +
                          Number(point.distance_from_fence_m).toLocaleString() + ' m</span>'
                        : 'Inside area') + '<br>' +
                    (point.accuracy_m != null ? 'Accuracy ±' + Math.round(point.accuracy_m) + ' m<br>' : '') +
                    (point.speed_kmh != null ? 'Speed ' + Math.round(point.speed_kmh) + ' km/h<br>' : '') +
                    (point.battery_pct != null ? 'Battery ' + point.battery_pct + '%<br>' : '') +
                    (point.is_mock ? '<span style="color:#7c3aed">Mock location</span><br>' : '') +
                    realNote +
                    '<small>point ' + (index + 1) + ' of ' + points.length + '</small>'
                );
        });

        /* ---------- stops ---------- */
        // A person standing still still reports a new position every minute, and drawn
        // as a line that reads as pacing back and forth. One circle saying "stopped here
        // for 49 minutes" is both quieter and more truthful about what happened.
        (data.route.stops || []).forEach(function (stop) {
            // Short dwells are collapsed in the geometry above but get no marker: a
            // two-minute pause is not worth annotating, and a map peppered with circles
            // for every time somebody stood still is its own kind of noise.
            if (stop.reportable === false) return;

            // Stops on site are drawn quietly. Standing in your own yard for an hour is
            // the job, not a finding, so it gets a muted ring rather than the amber one
            // that means "stopped somewhere they were not expected to be" — but it is
            // still drawn, because otherwise the collapsed readings would look like
            // missing data.
            var onSite = stop.inside === true;
            var shade = onSite ? '#64748b' : '#a86400';

            var circle = L.circle([stop.lat, stop.lng], {
                radius: Math.max(25, stop.minutes >= 30 ? 45 : 30),
                color: shade,
                weight: onSite ? 1 : 2,
                dashArray: onSite ? '3 4' : null,
                fillColor: shade,
                fillOpacity: onSite ? 0.06 : 0.12
            }).addTo(routeLayer);

            circle.bindTooltip((onSite ? 'On site ' : 'Stopped ') + stop.minutes + ' min',
                               { direction: 'top' })
                  .bindPopup('<strong>' + (onSite ? 'Stayed on site' : 'Stopped here') + '</strong><br>' +
                      stop.minutes + ' minutes<br>' +
                      esc(String(stop.from).substring(11, 16)) + ' – ' +
                      esc(String(stop.to).substring(11, 16)) + '<br>' +
                      '<small>' + stop.points + ' readings in one place — the spread ' +
                      'between them is GPS error, not movement</small>');
            layers.push(circle);
        });

        /* ---------- check in / out and farthest points ---------- */
        var attendance = data.attendance;
        if (attendance && attendance.check_in_lat != null) {
            layers.push(L.marker([attendance.check_in_lat, attendance.check_in_lng])
                .addTo(routeLayer)
                .bindPopup('<strong>Check in</strong><br>' + esc(attendance.check_in_at) +
                    (attendance.check_in_photo_url
                        ? '<br><img src="' + esc(attendance.check_in_photo_url) +
                          '" style="width:160px;margin-top:6px;border-radius:6px">' : '')));
        }
        if (attendance && attendance.check_out_lat != null) {
            layers.push(L.marker([attendance.check_out_lat, attendance.check_out_lng])
                .addTo(routeLayer)
                .bindPopup('<strong>Check out</strong><br>' + esc(attendance.check_out_at) +
                    (attendance.check_out_photo_url
                        ? '<br><img src="' + esc(attendance.check_out_photo_url) +
                          '" style="width:160px;margin-top:6px;border-radius:6px">' : '')));
        }

        (data.trips || []).forEach(function (trip) {
            if (trip.farthest_lat == null) return;
            AttMap.dot([trip.farthest_lat, trip.farthest_lng], '#c22f38', 9)
                .addTo(routeLayer)
                .bindTooltip('Furthest point: ' + Number(trip.max_distance_m).toLocaleString() + ' m out')
                .bindPopup('<strong>Furthest point of trip</strong><br>' +
                    Number(trip.max_distance_m).toLocaleString() + ' m outside the area<br>' +
                    'Left ' + esc(trip.exit_at) + '<br>' +
                    'Away ' + minutes(trip.duration_min) + '<br>' +
                    (trip.employee_reason ? 'Reason: ' + esc(trip.employee_reason) : '<em>no reason given</em>'));
        });

        AttMap.fit(map, layers.concat(points.length
            ? [L.marker([points[0].lat, points[0].lng])] : []));

        /* ---------- tiles ---------- */
        if (attendance) {
            tile('tIn', (attendance.check_in_at || '—').substring(11, 16), '');
            tile('tOut', (attendance.check_out_at || '—').substring(11, 16), '');
            document.getElementById('tWorked').textContent = minutes(attendance.worked_minutes);
            document.getElementById('tOutside').textContent = minutes(attendance.outside_minutes);
            if (attendance.outside_minutes > 0) {
                document.getElementById('tileOutside').classList.add('alert');
            }
        }
        document.getElementById('tTrips').textContent =
            (data.trips || []).length + ' trip(s) outside';
        document.getElementById('tDistance').textContent =
            (data.route.distance_m / 1000).toFixed(2) + ' km';

        // Says out loud what the figure excludes. A distance that quietly ignores time
        // on site would be read as a total and be wrong.
        // Only the reportable ones are counted. Every dwell is returned now, including the
        // brief ones that exist purely so the line can be collapsed, and counting those
        // would claim somebody stopped nine times when they paused at three traffic lights.
        var stops = (data.route.stops || []).filter(function (stop) {
            return stop.reportable !== false;
        }).length;
        document.getElementById('tStops').textContent = stops
            ? stops + ' stop(s) · movement on site not counted'
            : 'movement on site not counted';
        // Always name the stored count. Showing only the thinned figure would make it
        // look as though fewer points had been recorded than actually were.
        var shown = data.route.shown_count;
        // Imprecise fixes are named for the same reason: the line skipping them must not
        // look like points having gone missing.
        var vague = data.route.imprecise_count;
        document.getElementById('tPoints').textContent =
            data.route.point_count + ' points recorded' +
            (shown != null && shown < data.route.point_count ? ' · ' + shown + ' shown' : '') +
            (vague ? ' · ' + vague + ' too imprecise to plot (over ±' +
                     data.route.accuracy_limit_m + ' m)' : '') +
            ' · ' + (data.route.segments || []).length + ' segment(s)';

        if ((data.route.segments || []).length > 1) {
            document.getElementById('gapNote').innerHTML =
                '<span style="color:var(--warn)">Gaps in the line mean the app stopped ' +
                'reporting for a while</span>';
        }

        renderTrips(data.trips || []);
        renderPhotos(attendance);
        setupScrub();
    }

    function renderTrips(trips) {
        var body = document.getElementById('tripsBody');
        document.getElementById('tripsNote').textContent = trips.length + ' recorded on this date';

        if (!trips.length) {
            body.innerHTML = '<tr><td colspan="7" class="empty">Stayed inside the work area all day.</td></tr>';
            return;
        }

        var verdict = { pending: 'info', approved: 'ok', rejected: 'bad' };

        body.innerHTML = trips.map(function (trip) {
            return '<tr>' +
                '<td>' + esc((trip.exit_at || '').substring(11, 16)) + '</td>' +
                '<td>' + (trip.entry_at
                    ? esc(trip.entry_at.substring(11, 16))
                    : '<span class="badge bad">still outside</span>') + '</td>' +
                '<td class="num">' + minutes(trip.duration_min) + '</td>' +
                '<td class="num">' + Number(trip.max_distance_m).toLocaleString() + ' m</td>' +
                '<td>' + (trip.category !== 'unspecified'
                    ? '<span class="badge info">' + esc(trip.category.replace(/_/g, ' ')) + '</span>'
                    : '<span class="badge muted">not given</span>') + '</td>' +
                '<td>' + (trip.employee_reason
                    ? esc(trip.employee_reason)
                    : '<span class="badge muted">no reason submitted</span>') + '</td>' +
                '<td><span class="badge ' + (verdict[trip.review_status] || 'muted') + '">' +
                    esc(trip.review_status) + '</span></td>' +
                '</tr>';
        }).join('');
    }

    function renderPhotos(attendance) {
        var box = document.getElementById('photoBox');
        var shots = [];

        if (attendance && attendance.check_in_photo_url) {
            shots.push(['Check in ' + (attendance.check_in_at || '').substring(11, 16),
                        attendance.check_in_photo_url]);
        }
        if (attendance && attendance.check_out_photo_url) {
            shots.push(['Check out ' + (attendance.check_out_at || '').substring(11, 16),
                        attendance.check_out_photo_url]);
        }

        if (!shots.length) {
            box.innerHTML = '<span style="color:var(--ink-soft)">No photos recorded for this day.</span>';
            return;
        }

        box.innerHTML = '<div style="display:flex;gap:18px;flex-wrap:wrap">' + shots.map(function (shot) {
            return '<figure style="margin:0">' +
                '<img src="' + esc(shot[1]) + '" data-zoom="' + esc(shot[1]) + '" ' +
                'style="width:190px;border-radius:8px;border:1px solid var(--line);cursor:zoom-in">' +
                '<figcaption style="font-size:12px;color:var(--ink-soft);margin-top:5px">' +
                esc(shot[0]) + '</figcaption></figure>';
        }).join('') + '</div>';
    }

    /* ---------- playback ---------- */
    function setupScrub() {
        if (!points.length) {
            scrubLabel.textContent = 'No route points recorded.';
            playBtn.disabled = true;
            scrub.disabled = true;
            return;
        }

        scrub.max = points.length - 1;
        showAt(0);

        scrub.addEventListener('input', function () {
            stopPlay();
            showAt(parseInt(scrub.value, 10));
        });

        playBtn.addEventListener('click', function () {
            if (playTimer) { stopPlay(); return; }

            // Restart from the beginning when it is already at the end.
            if (parseInt(scrub.value, 10) >= points.length - 1) showAt(0);

            playBtn.textContent = '❚❚ Pause';
            playTimer = setInterval(function () {
                var next = parseInt(scrub.value, 10) + 1;
                if (next > points.length - 1) { stopPlay(); return; }
                showAt(next);
            }, 400);
        });
    }

    function stopPlay() {
        if (playTimer) { clearInterval(playTimer); playTimer = null; }
        playBtn.textContent = '▶ Play';
    }

    function showAt(index) {
        var point = points[index];
        if (!point) return;

        scrub.value = index;
        scrubLabel.textContent = point.recorded_at.substring(11, 16) +
            ' · ' + (index + 1) + '/' + points.length +
            (point.inside_fence === false ? ' · outside' : '');

        var latlng = [point.lat, point.lng];
        if (!marker) {
            marker = L.circleMarker(latlng, {
                radius: 10, color: '#101a2f', weight: 3,
                fillColor: '#ffd166', fillOpacity: 1
            }).addTo(routeLayer);
        } else {
            marker.setLatLng(latlng);
        }
        marker.bringToFront();
    }


    /* ---------------- eraser ---------------- */
    //
    // Draw a shape, see exactly which recorded positions fall inside it, then delete those.
    // The selection is by point id, so what is deleted is precisely what was highlighted —
    // the shape is a way of choosing, never a query the server re-runs and re-interprets.
    //
    // Uses leaflet-draw, which the panel already vendors and loads for every map page, so
    // this costs nothing extra.
    (function eraser() {
        var areaBtn = document.getElementById('eraseArea');
        var circleBtn = document.getElementById('eraseCircle');
        var clearBtn = document.getElementById('eraseClear');
        var countEl = document.getElementById('eraseCount');
        var form = document.getElementById('eraseForm');
        var idsField = document.getElementById('erasePointIds');

        if (!areaBtn || !form || !window.L || !L.Draw) return;

        var shapeLayer = L.layerGroup().addTo(map);
        var handler = null;
        var selected = [];

        /* Ray casting. Good to well under a metre at the scale of a city, and it avoids
           pulling in a geometry library for one function. */
        function insidePolygon(lat, lng, ring) {
            var inside = false;
            for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
                var yi = ring[i].lat, xi = ring[i].lng;
                var yj = ring[j].lat, xj = ring[j].lng;
                if (((yi > lat) !== (yj > lat)) &&
                    (lng < (xj - xi) * (lat - yi) / ((yj - yi) || 1e-12) + xi)) {
                    inside = !inside;
                }
            }
            return inside;
        }

        /* The newest position is never offered for deletion: it is what the live map and
           "last seen" read, and erasing it makes somebody look as though they stopped
           reporting. The server enforces this too — this only keeps the map honest about
           what the button will do. */
        function newestId() {
            var newest = null, at = -1;
            drawnDots.forEach(function (entry) {
                var t = stamp(entry.point.recorded_at);
                if (!isNaN(t) && t > at) { at = t; newest = entry.point.id; }
            });
            return newest;
        }

        function applySelection(test) {
            var keep = newestId();
            selected = [];

            drawnDots.forEach(function (entry) {
                var hit = test(entry.point);
                if (hit && entry.point.id === keep) hit = false;

                if (hit) {
                    selected.push(entry.point.id);
                    entry.marker.setStyle({ color: '#111827', fillColor: '#111827', fillOpacity: 1 });
                } else {
                    entry.marker.setStyle({
                        color: entry.colour, fillColor: entry.colour, fillOpacity: 0.9
                    });
                }
            });

            idsField.value = selected.join(',');
            form.style.display = selected.length ? '' : 'none';
            clearBtn.style.display = '';
            countEl.textContent = selected.length
                ? selected.length + ' position' + (selected.length === 1 ? '' : 's') + ' selected'
                : 'Nothing inside that shape.';
        }

        function finish(layer) {
            shapeLayer.clearLayers();
            layer.setStyle && layer.setStyle({
                color: '#111827', weight: 2, dashArray: '5 4', fillOpacity: 0.06
            });
            shapeLayer.addLayer(layer);

            if (layer instanceof L.Circle) {
                var centre = layer.getLatLng(), radius = layer.getRadius();
                applySelection(function (p) {
                    return centre.distanceTo(L.latLng(p.lat, p.lng)) <= radius;
                });
            } else {
                var ring = layer.getLatLngs()[0] || [];
                applySelection(function (p) { return insidePolygon(p.lat, p.lng, ring); });
            }
        }

        function start(kind) {
            if (handler) handler.disable();
            handler = kind === 'circle'
                ? new L.Draw.Circle(map, { shapeOptions: { color: '#111827' } })
                : new L.Draw.Polygon(map, { allowIntersection: false,
                                            shapeOptions: { color: '#111827' } });
            handler.enable();
            countEl.textContent = kind === 'circle'
                ? 'Click the centre and drag out.'
                : 'Click each corner, then click the first point again to close.';
        }

        map.on(L.Draw.Event.CREATED, function (event) {
            if (handler) { handler.disable(); handler = null; }
            finish(event.layer);
        });

        areaBtn.addEventListener('click', function () { start('polygon'); });
        circleBtn.addEventListener('click', function () { start('circle'); });

        clearBtn.addEventListener('click', function () {
            if (handler) { handler.disable(); handler = null; }
            shapeLayer.clearLayers();
            applySelection(function () { return false; });
            countEl.textContent = '';
            clearBtn.style.display = 'none';
        });
    })();

})();
