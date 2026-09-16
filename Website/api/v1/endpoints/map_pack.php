<?php
/**
 * GET /api/v1/map-pack?part=manifest|blob
 *
 * Serves the offline map archive to a signed-in device.
 *
 *   manifest — small JSON: is an archive installed, which version, how big. The app
 *              asks on launch and downloads only when the version has changed.
 *   blob     — the PMTiles archive itself, with Range support so a download that dies
 *              at 10 MB resumes instead of restarting. That matters: this feature
 *              exists for people whose connection is unreliable.
 *
 * No index part. PMTiles carries its own index inside the file, which is the whole
 * reason it replaced the custom pack format that used to live here.
 *
 * Behind authentication because it is served from the operator's own bandwidth, and an
 * open endpoint handing out a multi-megabyte file is an easy way to have that
 * bandwidth spent for you.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

$auth = attAuthenticate();

$part = (string)(attParam('part') ?? 'manifest');

if ($part === 'manifest') {
    attOk([
        'pack' => attPackManifest(),
        // Both are needed to draw anything: the archive is geometry, the style says how
        // to paint it. The app reports "offline map ready" only when it has both.
        'style' => attPackStyleInfo(),
        'server_time' => date('c'),
    ]);
}

if ($part === 'style') {
    $stylePath = attPackStylePath();
    if (!is_file($stylePath)) {
        attFail('NO_STYLE', 'No map style has been downloaded on the server yet.', 404);
    }
    // Served raw: it is already JSON, and the app hands it straight to the renderer.
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=86400');
    header('ETag: "' . filemtime($stylePath) . '"');
    header('Content-Length: ' . filesize($stylePath));
    readfile($stylePath);
    exit;
}

if ($part !== 'blob') {
    attFail('BAD_PART', 'part must be manifest, style or blob.', 422);
}

$path = attPackPath();
if (!is_file($path) || filesize($path) === 0) {
    attFail('NO_PACK', 'No offline map archive is installed on the server.', 404);
}

$size = filesize($path);
$start = 0;
$end = $size - 1;
$partial = false;

// Range support, so a dropped download resumes from where it stopped — and so a client
// that reads PMTiles directly over HTTP can fetch just the directory it needs.
$range = $_SERVER['HTTP_RANGE'] ?? '';
if (preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
    if ($m[1] !== '') {
        $start = (int)$m[1];
    }
    if ($m[2] !== '') {
        $end = min((int)$m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) {
        header('HTTP/1.1 416 Range Not Satisfiable');
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $partial = true;
}

$length = $end - $start + 1;

header($partial ? 'HTTP/1.1 206 Partial Content' : 'HTTP/1.1 200 OK');
header('Content-Type: application/octet-stream');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
if ($partial) {
    header("Content-Range: bytes $start-$end/$size");
}
header('ETag: "' . filemtime($path) . '"');

$handle = fopen($path, 'rb');
fseek($handle, $start);

// Streamed in chunks: reading a 13 MB file into memory per request would put a
// needless ceiling on how many phones can download at once.
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(262144, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    flush();
    $remaining -= strlen($chunk);
}
fclose($handle);
exit;
