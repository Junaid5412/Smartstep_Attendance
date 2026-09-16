<?php
/**
 * GET /api/v1/app-version?app_version=1.0.0
 *
 * What the app asks on launch: is there a newer build, and is mine still allowed?
 *
 * Deliberately reachable without a token. An install that has been forced out cannot
 * authenticate — that is the whole point of forcing it — so if this needed a token the
 * app would be locked out with no way to discover the update that would fix it.
 * Nothing here is sensitive: it is a version number and the address of an APK that is
 * handed to every employee anyway.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

$release = attReleaseInfo();
$current = attNormaliseVersion(attParam('app_version'));

$mustUpdate = $current !== null && attAppVersionTooOld($current, $release['min_version']);
// Same version counts as offerable, not just a lower one: re-publishing 1.0.1 to
// replace a bad build is a normal thing to need, and refusing to offer it would
// leave everyone stuck on the broken one with no route forward.
//
// The consequence: version alone can no longer tell the app whether it has this
// build already, so on an equal version it must compare published_at against the
// last one it installed or dismissed. Without that it would prompt forever.
$canUpdate = $release['latest_version'] !== null && $current !== null &&
    version_compare($current, $release['latest_version'], '<=');

attOk([
    'current_version' => $current,
    'latest_version' => $release['latest_version'],
    'min_version' => $release['min_version'],
    // Forced: the app must not be usable until it updates.
    'must_update' => $mustUpdate,
    // Optional: worth offering, safe to dismiss.
    'update_available' => $canUpdate,
    // True when the offer is a re-release of the version already installed. The app
    // uses published_at to decide whether it is actually new.
    'same_version' => $canUpdate && $current === $release['latest_version'],
    'download_url' => $release['download_url'],
    'bytes' => $release['bytes'],
    'notes' => $release['notes'],
    'published_at' => $release['published_at'],
    'server_time' => date('c'),
]);
