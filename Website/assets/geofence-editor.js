/* Geofence drawing for admin/geofences.php.
   Saved areas are shown for context; the shape being drawn is kept in a separate
   layer so editing one area never disturbs the others. */
(function () {
    'use strict';

    var mapEl = document.getElementById('fenceMap');
    if (!mapEl || !window.L) return;

    var fences = window.ATT_FENCES || [];
    var map = AttMap.create('fenceMap');

    /* ---------- saved areas ---------- */
    var savedLayers = [];
    fences.forEach(function (fence) {
        var layer = AttMap.drawGeofence(map, fence, { fillOpacity: 0.08 });
        if (layer) {
            layer.on('click', function () { loadIntoForm(fence.id); });
            savedLayers.push(layer);
        }
    });
    AttMap.fit(map, savedLayers);

    var form = document.getElementById('fenceForm');
    if (!form) return;   // view-only role: the map above is still useful

    /* ---------- drawing ---------- */
    var drawn = new L.FeatureGroup().addTo(map);

    var drawControl = new L.Control.Draw({
        position: 'topleft',
        draw: {
            circle: { shapeOptions: { color: '#c22f38', weight: 2 }, metric: true },
            polygon: { shapeOptions: { color: '#c22f38', weight: 2 }, allowIntersection: false,
                       showArea: true, metric: true },
            marker: false, circlemarker: false, polyline: false, rectangle: false
        },
        edit: { featureGroup: drawn, remove: true }
    });
    map.addControl(drawControl);

    var fType = document.getElementById('f_type');
    var fRadius = document.getElementById('f_radius');
    var fLat = document.getElementById('f_lat');
    var fLng = document.getElementById('f_lng');
    var fPolygon = document.getElementById('f_polygon');
    var radiusField = document.getElementById('radiusField');

    function setShapeMode(type) {
        fType.value = type;
        // A polygon has no single radius, so that input is meaningless there.
        radiusField.style.display = type === 'circle' ? '' : 'none';
    }

    function captureCircle(layer) {
        var center = layer.getLatLng();
        setShapeMode('circle');
        fLat.value = center.lat.toFixed(7);
        fLng.value = center.lng.toFixed(7);
        fRadius.value = Math.round(layer.getRadius());
        fPolygon.value = '';
    }

    function capturePolygon(layer) {
        var ring = layer.getLatLngs()[0] || [];
        var points = ring.map(function (p) { return [+p.lat.toFixed(7), +p.lng.toFixed(7)]; });

        setShapeMode('polygon');
        fPolygon.value = JSON.stringify(points);

        // Show the centroid so the admin can see roughly where it landed.
        var latSum = 0, lngSum = 0;
        points.forEach(function (p) { latSum += p[0]; lngSum += p[1]; });
        fLat.value = (latSum / points.length).toFixed(7);
        fLng.value = (lngSum / points.length).toFixed(7);
    }

    function capture(layer) {
        if (layer instanceof L.Circle) {
            captureCircle(layer);
        } else {
            capturePolygon(layer);
        }
    }

    map.on(L.Draw.Event.CREATED, function (event) {
        // One shape at a time: a new drawing replaces the previous one.
        drawn.clearLayers();
        drawn.addLayer(event.layer);
        capture(event.layer);
    });

    map.on(L.Draw.Event.EDITED, function (event) {
        event.layers.eachLayer(capture);
    });

    map.on(L.Draw.Event.DELETED, function () {
        fLat.value = fLng.value = fPolygon.value = '';
    });

    /* Typing a radius updates the circle, so an exact 200 m is easy to set. */
    fRadius.addEventListener('input', function () {
        var radius = parseInt(fRadius.value, 10);
        if (!radius) return;
        drawn.eachLayer(function (layer) {
            if (layer instanceof L.Circle) layer.setRadius(radius);
        });
    });

    fType.addEventListener('change', function () { setShapeMode(fType.value); });

    document.getElementById('clearDraw').addEventListener('click', function () {
        drawn.clearLayers();
        fLat.value = fLng.value = fPolygon.value = '';
    });

    /* ---------- editing an existing area ---------- */
    function loadIntoForm(id) {
        var fence = fences.filter(function (f) { return f.id === id; })[0];
        if (!fence) return;

        document.getElementById('f_id').value = fence.id;
        document.getElementById('f_name').value = fence.name || '';
        document.getElementById('f_color').value = fence.color || '#2563eb';
        document.getElementById('formHeading').textContent = 'Editing: ' + fence.name;

        drawn.clearLayers();

        if (fence.type === 'polygon' && fence.polygon.length >= 3) {
            var polygon = L.polygon(fence.polygon, { color: '#c22f38', weight: 2 });
            drawn.addLayer(polygon);
            capturePolygon(polygon);
        } else {
            var circle = L.circle([fence.center_lat, fence.center_lng], {
                radius: fence.radius_m, color: '#c22f38', weight: 2
            });
            drawn.addLayer(circle);
            captureCircle(circle);
        }

        AttMap.fit(map, drawn.getLayers());
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    document.querySelectorAll('[data-edit-fence]').forEach(function (button) {
        button.addEventListener('click', function () {
            loadIntoForm(parseInt(button.getAttribute('data-edit-fence'), 10));
        });
    });

    document.getElementById('resetForm').addEventListener('click', function () {
        form.reset();
        document.getElementById('f_id').value = '0';
        document.getElementById('formHeading').textContent = 'New work area';
        drawn.clearLayers();
        setShapeMode('circle');
    });

    setShapeMode('circle');
})();
