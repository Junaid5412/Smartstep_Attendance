<?php
/**
 * Offline map: serving a Protomaps PMTiles archive to the app.
 *
 * PMTiles is a single-file tile archive with its own internal index, designed to be
 * read over HTTP Range requests. That is exactly the shape an offline pack needs, so
 * there is nothing to build here — the operator extracts a regional archive once with
 * the pmtiles CLI and drops it in uploads/mappacks/, and this serves it.
 *
 * An earlier version of this file built a custom raster pack: it fetched tiles one by
 * one from a tile server and concatenated them with a hand-rolled index. That was
 * replaced rather than kept. It duplicated what PMTiles already does properly, and it
 * depended on bulk-downloading from a tile provider, which every provider's terms
 * treat differently from ordinary viewing — the reason the source was a licensing
 * problem rather than a technical one. A Protomaps extract carries a clear OpenStreetMap
 * attribution and no such doubt.
 *
 * The tiles inside are vector (MVT), so the app renders them with a style rather than
 * pasting images. That is why it stays sharp when zoomed past the archive's maximum
 * zoom, and why a 13 MB file covers the whole country.
 */

/** Where archives live. */
function attPackDir() {
    $dir = ATT_UPLOAD_PATH . '/mappacks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * The archive to serve.
 *
 * Named in att_settings so a higher-detail archive can be swapped in without a code
 * change — the operator drops the file in and points the setting at it.
 */
function attPackPath() {
    $settings = attSettings();
    $name = trim((string)($settings['offline_map_file'] ?? 'qatar.pmtiles'));

    // Basename only: this value comes from the settings table, and a path fragment in
    // it must not be able to reach outside the pack directory.
    $name = basename($name);
    if ($name === '' || !preg_match('/\.pmtiles$/i', $name)) {
        $name = 'qatar.pmtiles';
    }
    return attPackDir() . '/' . $name;
}

/**
 * What the app needs to decide whether to download.
 *
 * The version is the file's modification time, so a phone re-downloads only when the
 * operator has replaced the archive — not on every launch.
 */
function attPackManifest() {
    $path = attPackPath();

    if (!is_file($path) || filesize($path) === 0) {
        return ['available' => false];
    }

    return [
        'available' => true,
        'format' => 'pmtiles',
        'file' => basename($path),
        'version' => (string)filemtime($path),
        'bytes' => filesize($path),
        // Carried so the app can label the map and satisfy the licence without having
        // to hard-code an attribution that might not match the archive in use.
        'attribution' => '© OpenStreetMap contributors',
    ];
}

/**
 * The layers a style must know about to draw our archive.
 *
 * Checked after a style is fetched, because the failure mode otherwise is silent: a
 * style built for a different basemap version renders a blank map rather than an error,
 * and "the offline map is empty" is a miserable thing to debug on someone's phone.
 */
function attPackArchiveLayers() {
    return ['earth', 'water', 'roads', 'buildings', 'places', 'landcover', 'landuse'];
}

function attPackStylePath() { return attPackDir() . '/map_style.json'; }

/**
 * Download the map style using the operator's Protomaps key and store it locally.
 *
 * Done server-side so the key lives in one revocable place rather than compiled into
 * every APK. The app fetches the stored style from this server, meaning the style can
 * be replaced without shipping a new build.
 *
 * @return array{ok:bool,message:string}
 */
function attPackFetchStyle($apiKey, $flavour = 'light', $language = 'en') {
    $apiKey = trim((string)$apiKey);
    if ($apiKey === '') {
        return ['ok' => false, 'message' => 'Save a Protomaps API key first.'];
    }

    $flavour = preg_match('/^[a-z]+$/', $flavour) ? $flavour : 'light';
    $language = preg_match('/^[a-zA-Z-]{2,10}$/', $language) ? $language : 'en';

    // Tried newest first: the style schema is versioned, and one built for a different
    // version than the archive would render nothing.
    $errors = [];
    foreach (['v5', 'v4'] as $version) {
        $url = "https://api.protomaps.com/styles/$version/$flavour/$language.json?key=" . urlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status !== 200 || !$body) {
            // The key is deliberately absent from this message: it would otherwise end
            // up in a flash message, the browser history and the log.
            $errors[] = "$version returned HTTP $status";
            continue;
        }

        $style = json_decode($body, true);
        if (!is_array($style) || empty($style['layers'])) {
            $errors[] = "$version was not a usable style document";
            continue;
        }

        // Does it actually reference our archive's layers?
        $referenced = [];
        foreach ($style['layers'] as $layer) {
            if (!empty($layer['source-layer'])) {
                $referenced[$layer['source-layer']] = true;
            }
        }
        $missing = array_values(array_diff(attPackArchiveLayers(), array_keys($referenced)));

        if (count($missing) > 2) {
            $errors[] = "$version does not match the archive (no " . implode(', ', $missing) . ')';
            continue;
        }

        file_put_contents(attPackStylePath(), json_encode($style));

        return [
            'ok' => true,
            'message' => 'Style downloaded (' . $version . ', ' . $flavour . '/' . $language . ') — ' .
                count($style['layers']) . ' layers, ' .
                number_format(strlen($body) / 1024, 1) . ' KB. ' .
                ($missing ? 'Note: the style does not style ' . implode(', ', $missing) . '.'
                          : 'It covers every layer in your archive.'),
        ];
    }

    return ['ok' => false, 'message' => 'Could not fetch a usable style: ' . implode('; ', $errors) . '.'];
}

/** Is a style stored, and how big? */
function attPackStyleInfo() {
    $path = attPackStylePath();
    if (!is_file($path)) {
        return ['available' => false];
    }
    return [
        'available' => true,
        'version' => (string)filemtime($path),
        'bytes' => filesize($path),
    ];
}
