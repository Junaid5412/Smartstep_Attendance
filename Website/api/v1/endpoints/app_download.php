<?php
/**
 * GET /api/v1/app-download
 *
 * Streams the published APK.
 *
 * Not served as a static file because hosts routinely refuse .apk by configuration —
 * Hostinger's LiteSpeed returns 403 for it, and the app received an HTML error page
 * where it expected an APK. Reading it through PHP means the web server never sees a
 * request for a .apk URL at all.
 *
 * Deliberately reachable without a token, for the same reason as app-version: an install
 * that has been forced out cannot authenticate, so requiring one would leave it unable
 * to fetch the very build that would fix it. Nothing here is secret — this is the APK
 * handed to every employee.
 *
 * Range requests are honoured so an interrupted download resumes rather than restarting,
 * which matters for 56 MB on a phone connection.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

$settings = attSettings();
$file = $settings['latest_apk_file'] ?? null;
$path = $file ? attApkDir() . '/' . basename($file) : null;

if (!$path || !is_file($path) || filesize($path) === 0) {
    attFail('NO_RELEASE', 'No app version has been published yet.', 404);
}

$size = filesize($path);
$start = 0;
$end = $size - 1;
$partial = false;

$range = $_SERVER['HTTP_RANGE'] ?? '';
if (preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
    if ($m[1] !== '') $start = (int)$m[1];
    if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    if ($start > $end || $start >= $size) {
        header('HTTP/1.1 416 Range Not Satisfiable');
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $partial = true;
}

$length = $end - $start + 1;

header($partial ? 'HTTP/1.1 206 Partial Content' : 'HTTP/1.1 200 OK');
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
if ($partial) {
    header("Content-Range: bytes $start-$end/$size");
}
header('ETag: "' . filemtime($path) . '"');

// Any buffering has to go, or PHP tries to hold 56 MB in memory before sending a byte.
while (ob_get_level() > 0) {
    ob_end_clean();
}

$handle = fopen($path, 'rb');
fseek($handle, $start);

$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(262144, $remaining));
    if ($chunk === false) break;
    echo $chunk;
    flush();
    $remaining -= strlen($chunk);
}
fclose($handle);
exit;
