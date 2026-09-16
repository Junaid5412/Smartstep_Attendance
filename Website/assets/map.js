/* Leaflet helpers shared by the geofence editor, live map and route replay.
   Tiles come from window.ATT.tileUrl (OpenStreetMap by default). */
window.AttMap = (function () {
    'use strict';

    var DEFAULT_CENTER = [25.2854, 51.5310];  // Doha
    var DEFAULT_ZOOM = 12;

    // Leaflet resolves its marker images relative to the CSS by default, which
    // breaks under the module's URL layout; point it at the vendored copies.
    function fixIcons() {
        if (!window.L || !L.Icon || !L.Icon.Default) return;
        L.Icon.Default.prototype.options.imagePath = window.ATT.url + '/assets/vendor/leaflet/images/';
    }

    /**
     * Inject the tile colour transform once per page.
     *
     * The filter is applied to the tile images only, never to the map container —
     * filtering the container would drag the geofence outlines, route lines and
     * markers through the same transform and wash out the very colours that carry
     * the meaning.
     */
    function applyTileFilter() {
        var filter = window.ATT.tileFilter;
        if (!filter || document.getElementById('att-tile-filter')) return;

        var style = document.createElement('style');
        style.id = 'att-tile-filter';
        style.textContent = '.att-tinted-tiles { filter: ' + filter + '; }';
        document.head.appendChild(style);
    }

    function create(elementId, options) {
        fixIcons();
        options = options || {};

        var map = L.map(elementId, {
            center: options.center || DEFAULT_CENTER,
            zoom: options.zoom || DEFAULT_ZOOM,
            zoomControl: true
        });

        L.tileLayer(window.ATT.tileUrl, {
            // Styled basemaps go deeper than OSM's 19, so the cap comes from the
            // server rather than being hardcoded.
            maxZoom: window.ATT.tileMaxZoom || 19,
            attribution: window.ATT.tileAttribution,
            // Only the tinted-OSM style sets a filter; the styled basemaps already
            // carry their cartography, so the class is applied but stays a no-op.
            className: window.ATT.tileFilter ? 'att-tinted-tiles' : ''
        }).addTo(map);

        applyTileFilter();

        // Google's terms require their logo on the map itself, not just a text
        // credit in the corner. Leaflet's attribution control only renders text, so
        // the logo is added as its own control.
        if (window.ATT.tileProvider === 'google') {
            var logo = L.control({ position: 'bottomleft' });
            logo.onAdd = function () {
                var div = L.DomUtil.create('div', 'google-logo');
                div.innerHTML =
                    '<img src="https://maps.gstatic.com/mapfiles/api-3/images/google_gray.png" ' +
                    'alt="Google" height="16" style="display:block">';
                div.style.margin = '0 0 4px 4px';
                return div;
            };
            logo.addTo(map);
        }

        return map;
    }

    /** Draw a geofence (circle or polygon) and return the Leaflet layer. */
    function drawGeofence(map, fence, options) {
        if (!fence) return null;
        options = options || {};

        var style = {
            color: fence.color || '#2563eb',
            weight: 2,
            fillOpacity: options.fillOpacity != null ? options.fillOpacity : 0.12,
            interactive: options.interactive !== false
        };

        var layer;
        if (fence.type === 'polygon' && fence.polygon && fence.polygon.length >= 3) {
            layer = L.polygon(fence.polygon, style);
        } else if (fence.center_lat != null && fence.radius_m) {
            layer = L.circle([fence.center_lat, fence.center_lng], Object.assign({ radius: fence.radius_m }, style));
        } else {
            return null;
        }

        layer.addTo(map);
        if (fence.name && options.interactive !== false) {
            layer.bindTooltip(fence.name, { sticky: true });
        }
        return layer;
    }

    /** A small coloured circle marker; used for people and route points. */
    function dot(latlng, colour, radius) {
        return L.circleMarker(latlng, {
            radius: radius || 7,
            color: '#fff',
            weight: 2,
            fillColor: colour,
            fillOpacity: 1
        });
    }

    /** Fit the view to whatever was drawn, tolerating an empty set. */
    function fit(map, layers, fallbackCenter) {
        var bounds = L.latLngBounds([]);
        layers.forEach(function (layer) {
            if (!layer) return;
            if (layer.getBounds) {
                bounds.extend(layer.getBounds());
            } else if (layer.getLatLng) {
                bounds.extend(layer.getLatLng());
            }
        });

        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 17 });
        } else {
            map.setView(fallbackCenter || DEFAULT_CENTER, DEFAULT_ZOOM);
        }
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    return {
        create: create,
        drawGeofence: drawGeofence,
        dot: dot,
        fit: fit,
        escapeHtml: escapeHtml,
        DEFAULT_CENTER: DEFAULT_CENTER
    };
})();
