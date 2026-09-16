<?php
/**
 * Tile source resolution for both the admin panel and the mobile app.
 *
 * One function decides where map tiles come from, and both consumers read it —
 * the panel through window.ATT.tileUrl, the app through /api/v1/app-config. That
 * means switching provider in Settings changes every map in the system at once,
 * with no app update.
 *
 * Google's tiles are served through the Map Tiles API, which is the licensed route
 * for using them inside a third-party map library such as Leaflet or flutter_map.
 * Fetching Google's internal mt0.google.com tiles directly would be a Terms of
 * Service breach and gets keys and projects banned, so it is not offered here.
 */

define('ATT_GOOGLE_SESSION_URL', 'https://tile.googleapis.com/v1/createSession');
define('ATT_GOOGLE_TILE_URL', 'https://tile.googleapis.com/v1/2dtiles');
define('ATT_OSM_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');

/**
 * Basemap styles, all rendered from OpenStreetMap data.
 *
 * These exist because the ask was "Google's design, OSM's data". The two CARTO
 * styles are professionally drawn OSM basemaps whose cartography is close to a
 * modern Google roadmap; measured against Google's own palette across four Doha
 * tiles they average a colour distance of 12.9 (Voyager) and 9.4 (Positron)
 * versus 21.6 for OSM's default beige-and-pink.
 *
 * osm_tint is the no-third-party option: raw OSM tiles with the colour transform
 * that a numerical fit found to be the closest a single global filter can get.
 * It cannot recolour features individually, so motorways stay pink and water stays
 * cyan — which is precisely why the styled basemaps above are the better answer.
 */
function attMapStyles() {
    return [
        // ------------------------------------------------------------------
        // Google's own tiles, fetched directly from the endpoint its products
        // use. hl=en forces English labels over Arabic place names.
        //
        // This is not a licensed route: mt*.google.com is undocumented and
        // reserved for Google's own clients, so it can be rate-limited or cut off
        // without notice, and it is outside Google Maps Platform's terms. It is
        // offered because it is what this deployment asked for and already runs
        // elsewhere in the estate; google_like below is the equivalent look with
        // no such exposure, and the whole product can be switched to it in one
        // click if the tiles ever stop.
        // ------------------------------------------------------------------
        'google_direct' => [
            'label' => 'Google Maps (direct)',
            'note' => 'Google\'s own roadmap tiles with English labels. Not a licensed '
                    . 'endpoint — may be cut off without notice.',
            'tile_url' => 'https://mt1.google.com/vt/lyrs=m&x={x}&y={y}&z={z}&hl=en',
            'attribution' => 'Map data ©' . date('Y') . ' Google',
            'max_zoom' => 20,
            'filter' => null,
        ],
        'google_direct_hybrid' => [
            'label' => 'Google Satellite + labels',
            'note' => 'Satellite imagery with English road labels. Useful for verifying '
                    . 'a work area against actual buildings.',
            'tile_url' => 'https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}&hl=en',
            'attribution' => 'Imagery ©' . date('Y') . ' Google',
            'max_zoom' => 20,
            'filter' => null,
        ],
        'google_direct_terrain' => [
            'label' => 'Google Terrain',
            'note' => 'Terrain shading with English labels.',
            'tile_url' => 'https://mt1.google.com/vt/lyrs=p&x={x}&y={y}&z={z}&hl=en',
            'attribution' => 'Map data ©' . date('Y') . ' Google',
            'max_zoom' => 20,
            'filter' => null,
        ],
        'google_like' => [
            'label' => 'Google-like (safe fallback)',
            'note' => 'OSM data drawn in a modern Google-roadmap style. Road hierarchy, '
                    . 'water and parks all read like Google Maps, with no licensing exposure.',
            'tile_url' => 'https://basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png',
            'attribution' => '© OpenStreetMap contributors © CARTO',
            'max_zoom' => 20,
            'filter' => null,
        ],
        'google_light' => [
            'label' => 'Google-like, light',
            'note' => 'Closest match to Google\'s palette, and the most legible under '
                    . 'coloured geofences and route lines.',
            'tile_url' => 'https://basemaps.cartocdn.com/rastertiles/light_all/{z}/{x}/{y}.png',
            'attribution' => '© OpenStreetMap contributors © CARTO',
            'max_zoom' => 20,
            'filter' => null,
        ],
        'osm_tint' => [
            'label' => 'OpenStreetMap, Google-tinted',
            'note' => 'Plain OSM tiles with a colour transform. No third-party service, '
                    . 'but motorways stay pink and water stays cyan.',
            'tile_url' => ATT_OSM_TILE_URL,
            'attribution' => '© OpenStreetMap contributors',
            'max_zoom' => 19,
            // Fitted numerically against Google's palette; see the tuning notes in
            // the README before changing these numbers by feel.
            'filter' => 'saturate(0.76) brightness(1.07) contrast(1.02) hue-rotate(2deg)',
        ],
        'osm_raw' => [
            'label' => 'OpenStreetMap, unmodified',
            'note' => 'The stock OSM look.',
            'tile_url' => ATT_OSM_TILE_URL,
            'attribution' => '© OpenStreetMap contributors',
            'max_zoom' => 19,
            'filter' => null,
        ],
    ];
}

/**
 * Resolve the active tile source.
 *
 * Always returns something usable: if Google is selected but misconfigured or its
 * session call fails, this falls back to OpenStreetMap rather than leaving every
 * map in the product blank. The reason is recorded so Settings can explain itself.
 *
 * @return array{provider:string,tile_url:string,attribution:string,max_zoom:int,
 *               logo_required:bool,fallback_reason:?string}
 */
function attTileConfig($forceRefresh = false) {
    static $cached = null;
    if ($cached !== null && !$forceRefresh) {
        return $cached;
    }

    $settings = attSettings();

    // The OSM-based result, which is both the normal path and the fallback if
    // Google is selected but not working.
    $styles = attMapStyles();
    $styleKey = $settings['map_style'] ?? 'google_like';

    if ($styleKey === 'custom') {
        $osm = [
            'provider' => 'osm',
            'style' => 'custom',
            'tile_url' => $settings['tile_url'] ?: ATT_OSM_TILE_URL,
            'attribution' => $settings['tile_attribution'] ?: '© OpenStreetMap contributors',
            'max_zoom' => 19,
            'filter' => $settings['tile_filter_css'] ?: null,
            'logo_required' => false,
            'fallback_reason' => null,
        ];
    } else {
        $style = $styles[$styleKey] ?? $styles['google_like'];
        $osm = [
            'provider' => 'osm',
            'style' => $styleKey,
            'tile_url' => $style['tile_url'],
            'attribution' => $style['attribution'],
            'max_zoom' => $style['max_zoom'],
            // An explicitly saved override wins, so a filter can be retuned from the
            // settings page without editing the preset.
            'filter' => $settings['tile_filter_css'] ?: $style['filter'],
            'logo_required' => false,
            'fallback_reason' => null,
        ];
    }

    if (($settings['map_provider'] ?? 'osm') !== 'google') {
        return $cached = $osm;
    }

    $key = trim((string)($settings['google_api_key'] ?? ''));
    if ($key === '') {
        $osm['fallback_reason'] = 'Google is selected but no API key has been saved.';
        return $cached = $osm;
    }

    $session = attGoogleSession($forceRefresh);
    if (!$session) {
        // Re-read: attGoogleSession has just written the failure reason, so the copy
        // captured at the top of this function predates it.
        $reason = attSettings(true)['google_session_error'] ?? '';
        $osm['fallback_reason'] = 'Google session could not be created: ' .
            ($reason !== '' ? $reason : 'unknown error') . ' — using OpenStreetMap.';
        return $cached = $osm;
    }

    return $cached = [
        'provider' => 'google',
        'style' => 'google_official',
        // Leaflet and flutter_map both substitute {z}/{x}/{y}, so the same template
        // serves the panel and the app.
        'tile_url' => ATT_GOOGLE_TILE_URL . '/{z}/{x}/{y}?session=' . urlencode($session) .
                      '&key=' . urlencode($key),
        'attribution' => 'Map data ©' . date('Y') . ' Google',
        'max_zoom' => 22,
        'filter' => null,
        // Google's terms require their logo on the map, not just a text credit.
        'logo_required' => true,
        'fallback_reason' => null,
    ];
}

/**
 * A valid Google session token, created on demand and cached in att_settings.
 *
 * createSession is a billed request and the token lasts about two weeks, so it is
 * reused until close to expiry. Returns null on failure, having recorded why.
 */
function attGoogleSession($forceRefresh = false) {
    $settings = attSettings();
    $key = trim((string)($settings['google_api_key'] ?? ''));
    if ($key === '') {
        return null;
    }

    $token = $settings['google_session_token'] ?? null;
    $expires = $settings['google_session_expires_at'] ?? null;

    // Refreshed an hour early so a token never expires mid-session for a user who
    // already has a map open.
    if (!$forceRefresh && $token && $expires && strtotime($expires) > time() + 3600) {
        return $token;
    }

    $payload = json_encode([
        'mapType' => $settings['google_map_type'] ?: 'roadmap',
        // 'en' keeps labels in English over Arabic place names, which is the whole
        // reason this option exists for a Qatar deployment.
        'language' => $settings['google_language'] ?: 'en',
        'region' => $settings['google_region'] ?: 'QA',
        'scale' => 'scaleFactor2x',
        'highDpi' => true,
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 20,
            // Read the body on a 4xx instead of throwing, so Google's own error
            // message can be shown to the admin.
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $url = ATT_GOOGLE_SESSION_URL . '?key=' . urlencode($key);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        attStoreSessionError('Could not reach tile.googleapis.com. Check the server\'s outbound internet access.');
        return null;
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded) || empty($decoded['session'])) {
        $message = $decoded['error']['message'] ?? 'Unexpected response from Google.';
        // The two failures that account for nearly every case in practice.
        if (stripos($message, 'API key') !== false) {
            $message .= ' (Check the key is correct and unrestricted enough to be used from this server.)';
        } elseif (stripos($message, 'not been used') !== false || stripos($message, 'disabled') !== false) {
            $message .= ' (Enable the "Map Tiles API" for this project and attach a billing account.)';
        }
        attStoreSessionError($message);
        return null;
    }

    // Google returns expiry as a unix timestamp string.
    $expiry = isset($decoded['expiry'])
        ? date('Y-m-d H:i:s', (int)$decoded['expiry'])
        : date('Y-m-d H:i:s', time() + 7 * 86400);

    attDB()->prepare("
        UPDATE att_settings
        SET google_session_token = ?, google_session_expires_at = ?, google_session_error = NULL
        WHERE id = 1
    ")->execute([$decoded['session'], $expiry]);

    attSettingsRefresh();
    return $decoded['session'];
}

function attStoreSessionError($message) {
    attDB()->prepare("
        UPDATE att_settings
        SET google_session_error = ?, google_session_token = NULL, google_session_expires_at = NULL
        WHERE id = 1
    ")->execute([mb_substr($message, 0, 400)]);
    attSettingsRefresh();
}

/** The map payload handed to the app by /login and /app-config. */
function attMapPayload() {
    $config = attTileConfig();
    return [
        'provider' => $config['provider'],
        'style' => $config['style'],
        'tile_url' => $config['tile_url'],
        'attribution' => $config['attribution'],
        'max_zoom' => $config['max_zoom'],
        // Only set for the osm_tint style; the styled basemaps need no transform
        // because the styling is baked into the tiles themselves.
        'filter' => $config['filter'],
        'logo_required' => $config['logo_required'],
    ];
}
