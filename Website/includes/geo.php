<?php
/**
 * Geometry helpers for geofence evaluation.
 *
 * The same two rules are implemented in the Flutter app so a device can decide
 * inside/outside while offline; keep the two in sync when changing anything here.
 */

define('ATT_EARTH_RADIUS_M', 6371000.0);

/** Great-circle distance between two coordinates, in metres. */
function attHaversine($lat1, $lng1, $lat2, $lng2) {
    $phi1 = deg2rad((float)$lat1);
    $phi2 = deg2rad((float)$lat2);
    $dPhi = $phi2 - $phi1;
    $dLambda = deg2rad((float)$lng2 - (float)$lng1);

    $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
    return 2 * ATT_EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
}

/**
 * Decode a geofence row's polygon column into a list of [lat, lng] pairs.
 * Returns an empty array for circles or malformed data.
 */
function attPolygonPoints($geofence) {
    if (empty($geofence['polygon'])) {
        return [];
    }
    $points = is_array($geofence['polygon'])
        ? $geofence['polygon']
        : json_decode($geofence['polygon'], true);

    if (!is_array($points)) {
        return [];
    }

    $clean = [];
    foreach ($points as $point) {
        if (isset($point['lat'], $point['lng'])) {
            $clean[] = [(float)$point['lat'], (float)$point['lng']];
        } elseif (isset($point[0], $point[1])) {
            $clean[] = [(float)$point[0], (float)$point[1]];
        }
    }
    return count($clean) >= 3 ? $clean : [];
}

/**
 * Ray-casting point-in-polygon test. Coordinates are treated as planar, which is
 * accurate at the scale of a work site (a few kilometres at most).
 */
function attPointInPolygon($lat, $lng, array $points) {
    $inside = false;
    $count = count($points);

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        [$latI, $lngI] = $points[$i];
        [$latJ, $lngJ] = $points[$j];

        $straddles = ($lngI > $lng) !== ($lngJ > $lng);
        if ($straddles) {
            $latAtLng = $latI + ($lng - $lngI) * ($latJ - $latI) / ($lngJ - $lngI);
            if ($lat < $latAtLng) {
                $inside = !$inside;
            }
        }
    }
    return $inside;
}

/** Shortest distance from a point to a polygon edge, in metres. */
function attDistanceToPolygon($lat, $lng, array $points) {
    $min = PHP_FLOAT_MAX;
    $count = count($points);

    for ($i = 0; $i < $count; $i++) {
        $a = $points[$i];
        $b = $points[($i + 1) % $count];
        $min = min($min, attDistanceToSegment($lat, $lng, $a[0], $a[1], $b[0], $b[1]));
    }
    return $min === PHP_FLOAT_MAX ? 0.0 : $min;
}

/**
 * Distance from a point to a line segment. The coordinates are projected onto a
 * local metre grid first so the perpendicular-foot maths is not distorted by the
 * fact that a degree of longitude shrinks with latitude.
 */
function attDistanceToSegment($lat, $lng, $aLat, $aLng, $bLat, $bLng) {
    $latScale = ATT_EARTH_RADIUS_M * M_PI / 180.0;
    $lngScale = $latScale * cos(deg2rad((float)$lat));

    $px = ((float)$lng - $aLng) * $lngScale;
    $py = ((float)$lat - $aLat) * $latScale;
    $bx = ($bLng - $aLng) * $lngScale;
    $by = ($bLat - $aLat) * $latScale;

    $segLenSq = $bx * $bx + $by * $by;
    if ($segLenSq <= 0.0) {
        return sqrt($px * $px + $py * $py);
    }

    // Clamp the projection so the nearest point stays on the segment.
    $t = max(0.0, min(1.0, ($px * $bx + $py * $by) / $segLenSq));
    $dx = $px - $t * $bx;
    $dy = $py - $t * $by;
    return sqrt($dx * $dx + $dy * $dy);
}

/**
 * The tolerance ring outside every work area, in metres.
 *
 * A boundary drawn on a map is a sharp line; a position judged against it is accurate
 * to tens of metres. Treating the line as exact means somebody working near the edge is
 * reported as having left, then back, then left again as the fix drifts — with the alarm
 * firing each time, at someone who never moved.
 *
 * Read from settings so it can be tuned without a deploy, and defaulted rather than
 * assumed present so a database that has not run migration 017 still works.
 */
function attGeofenceBufferM() {
    static $buffer = null;
    if ($buffer === null) {
        $settings = attSettings();
        $buffer = isset($settings['geofence_buffer_m'])
            ? max(0, (int)$settings['geofence_buffer_m'])
            : 50;
    }
    return (float)$buffer;
}

/**
 * Evaluate a coordinate against a geofence row.
 *
 * @return array{inside:bool,distance_m:int} distance_m is 0 when inside and the
 *         metres to the nearest boundary point when outside. With no geofence
 *         assigned the point counts as inside, so an unconfigured employee is
 *         never flagged for leaving an area that was never defined.
 */
function attEvaluateGeofence($lat, $lng, $geofence, $bufferM = null) {
    if (empty($geofence)) {
        return ['inside' => true, 'distance_m' => 0];
    }

    $buffer = $bufferM === null ? attGeofenceBufferM() : (float)$bufferM;

    if (($geofence['type'] ?? 'circle') === 'polygon') {
        $points = attPolygonPoints($geofence);
        if (!$points) {
            return ['inside' => true, 'distance_m' => 0];
        }
        if (attPointInPolygon($lat, $lng, $points)) {
            return ['inside' => true, 'distance_m' => 0];
        }
        $out = attDistanceToPolygon($lat, $lng, $points);
        return [
            'inside' => $out <= $buffer,
            // Measured from the boundary, not from the edge of the tolerance ring: the
            // figure an admin reads should be the real distance off site.
            'distance_m' => (int)round($out),
        ];
    }

    if (!isset($geofence['center_lat'], $geofence['center_lng'], $geofence['radius_m'])) {
        return ['inside' => true, 'distance_m' => 0];
    }

    $distance = attHaversine($lat, $lng, $geofence['center_lat'], $geofence['center_lng']);
    $radius = (float)$geofence['radius_m'];
    $out = max(0.0, $distance - $radius);

    return [
        'inside' => $out <= $buffer,
        'distance_m' => (int)round($out),
    ];
}

/**
 * Judge a position against every area an employee is assigned to.
 *
 * "Inside" means inside any one of them. An employee who works across two offices was
 * previously marked as having left their work area whenever they were at the other
 * one, and then asked to explain a trip that was simply their job.
 *
 * When outside all of them the reported distance is to the *nearest*, because that is
 * the only figure that means anything to the person reading it — distance to an area
 * on the other side of the city says nothing about how far off-site they are.
 *
 * Returns inside, distance_m, and which area was matched (or which is nearest), so a
 * multi-site report can say where somebody was rather than merely that they were
 * somewhere they should be.
 */
function attEvaluateAreas($lat, $lng, array $areas) {
    if (!$areas) {
        // No area assigned: nothing to be outside of. Refusing every check-in for an
        // unconfigured employee would punish them for an admin's omission.
        return ['inside' => true, 'distance_m' => 0, 'geofence_id' => null, 'geofence_name' => null];
    }

    $best = null;

    foreach ($areas as $area) {
        $verdict = attEvaluateGeofence($lat, $lng, $area);

        if ($verdict['inside']) {
            return [
                'inside' => true,
                // The real distance from the boundary, which is 0 within the area and up
                // to the tolerance just outside it. Not forced to 0: GeoFence.kt keeps
                // the true figure, and the two must agree or the same position reads
                // differently depending on which side computed it.
                'distance_m' => $verdict['distance_m'],
                'geofence_id' => isset($area['id']) ? (int)$area['id'] : null,
                'geofence_name' => $area['name'] ?? null,
            ];
        }

        if ($best === null || $verdict['distance_m'] < $best['distance_m']) {
            $best = [
                'inside' => false,
                'distance_m' => $verdict['distance_m'],
                'geofence_id' => isset($area['id']) ? (int)$area['id'] : null,
                'geofence_name' => $area['name'] ?? null,
            ];
        }
    }

    return $best;
}

/**
 * How far apart two fixes must be before the difference counts as movement.
 *
 * A stationary phone still reports a slightly different position every minute — GPS
 * moved 14 m between consecutive points on a measured route, the network provider 74 m.
 * Summing those straight from the log turns a person sitting at their desk all morning
 * into someone who walked several kilometres, which is the zig-zag on the route map.
 */
define('ATT_STILL_RADIUS_M', 40);

/** How long inside that radius before it is reported as a stop rather than noise. */
define('ATT_STOP_MIN_MINUTES', 5);

/**
 * Distance actually travelled, plus where the person stopped.
 *
 * Walks the route against an anchor rather than summing every consecutive pair. A fix
 * within ATT_STILL_RADIUS_M of the anchor — or its own margin of error, whichever is
 * larger — is treated as the same place and contributes nothing; only a fix beyond it
 * moves the anchor and adds its distance. Wobble therefore cancels instead of
 * accumulating, while real walking still totals correctly: five 10 m steps do not each
 * clear the deadband, but the 50 m they add up to does.
 *
 * Movement inside a work area is not counted at all. Crossing a yard to a different
 * building is not travel worth reporting, and it is the behaviour asked for: the whole
 * area counts as one place. Time on site is already measured separately, by check-in
 * and check-out, which is the honest source for it.
 *
 * Returns metres travelled and the stops found — each with where, how long, whether it was
 * on site, and the index range of the points it absorbed so the map can draw one place
 * instead of every fix that landed in it.
 */
function attRouteMovement(array $points) {
    $stops = [];
    $total = 0.0;

    if (count($points) < 2) {
        return ['distance_m' => 0, 'stops' => []];
    }

    /**
     * Is this fix on site, for the purpose of not counting distance?
     *
     * Not simply inside_fence. Standing near the edge of a work area — and a 100 m area
     * has a lot of edge — GPS drifts a few metres past the boundary and back, flipping
     * the flag with nobody having moved. Counting those flips is the same phantom
     * distance in a different disguise.
     *
     * So a fix reported outside by less than its own margin of error still counts as on
     * site. That is deliberately the same test attOutsideIsCredible applies before
     * opening a trip: a point judged "still inside" for the review queue must not be
     * judged "left the area" by the odometer. Inlined rather than called so this file
     * keeps no dependency on attendance.php's load order.
     */
    $onSite = function ($p) {
        if ($p['inside_fence'] === true) {
            return true;
        }
        if ($p['inside_fence'] !== false) {
            return false;   // no area assigned, so there is no "on site" to be in
        }
        $accuracy = (float)($p['accuracy_m'] ?? 0);
        if ($accuracy <= 0) {
            return false;   // nothing to judge it by; take the flag at its word
        }

        // No measured distance from a boundary means there is no boundary — an employee
        // with no work area assigned. Their points are outside nothing, and reading a
        // missing distance as "0 m out, therefore on site" would silently zero the
        // odometer for everyone who has no area, which is the opposite of intended.
        $distanceOut = (float)($p['distance_from_fence_m'] ?? 0);
        if ($distanceOut <= 0) {
            return false;
        }

        return $distanceOut < $accuracy;
    };

    $anchor = $points[0];
    $anchorInside = $onSite($anchor);

    // The dwell is tracked separately from the distance anchor. The anchor has to keep
    // advancing across a work area so the leg measured on leaving starts from where the
    // person actually was; the dwell must NOT restart with it, or an hour spent on site
    // reads as a run of one-point dwells and never collapses. Keeping the two apart is
    // what lets on-site jitter be recognised as one place while the odometer stays right.
    $dwellLat = (float)$anchor['lat'];
    $dwellLng = (float)$anchor['lng'];
    $dwellFrom = strtotime($anchor['recorded_at']);
    $dwellTo = $dwellFrom;
    $dwellCount = 1;
    $dwellFirstIndex = 0;
    $dwellLastIndex = 0;
    $dwellInside = $anchorInside;
    // A departure seen once and not yet confirmed by the following fix.
    $pending = false;

    $closeDwell = function () use (
        &$stops, &$dwellLat, &$dwellLng, &$dwellFrom, &$dwellTo, &$dwellCount,
        &$dwellFirstIndex, &$dwellLastIndex, &$dwellInside
    ) {
        $minutes = (int)round(($dwellTo - $dwellFrom) / 60);

        // Two separate questions, and conflating them was leaving jitter on the map.
        //
        // "Is this worth drawing as one place?" is answered by more than one fix having
        // landed in the same spot — two minutes of standing still is already a cluster of
        // scattered points joined into a scribble, and there is nothing to be gained from
        // drawing it. "Is this worth reporting as a stop?" is a different, higher bar:
        // five minutes, because a brief pause is not a finding.
        //
        // So every dwell is returned, and only the long ones are marked reportable. The
        // page collapses all of them and puts a marker on the reportable ones.
        if ($dwellCount < 2) {
            return;
        }

        // Dwells on site are included too, flagged rather than withheld. Without them an
        // hour of standing in a yard is drawn as sixty scattered fixes joined into a star.
        // Whether one deserves a marker is a presentation question — "stopped at the office
        // for eight hours" is not a finding, it is the job — so that judgement is left to
        // the page, which reads the flags.
        $stops[] = [
            'reportable' => $minutes >= ATT_STOP_MIN_MINUTES,
            'lat' => $dwellLat,
            'lng' => $dwellLng,
            'from' => date('Y-m-d H:i:s', $dwellFrom),
            'to' => date('Y-m-d H:i:s', $dwellTo),
            'minutes' => $minutes,
            'points' => $dwellCount,
            'inside' => $dwellInside,
            'first_index' => $dwellFirstIndex,
            'last_index' => $dwellLastIndex,
        ];
    };

    // Starts a fresh dwell at $points[$index].
    $openDwell = function ($index, $point, $time, $inside) use (
        &$dwellLat, &$dwellLng, &$dwellFrom, &$dwellTo, &$dwellCount,
        &$dwellFirstIndex, &$dwellLastIndex, &$dwellInside
    ) {
        $dwellLat = (float)$point['lat'];
        $dwellLng = (float)$point['lng'];
        $dwellFrom = $time;
        $dwellTo = $time;
        $dwellCount = 1;
        $dwellFirstIndex = $index;
        $dwellLastIndex = $index;
        $dwellInside = $inside;
    };

    for ($i = 1; $i < count($points); $i++) {
        $point = $points[$i];
        $time = strtotime($point['recorded_at']);
        $inside = $onSite($point);

        // Both ends on site: one place, no distance, however large the area. The anchor
        // advances so the leg measured on leaving starts from where they were, while the
        // dwell simply continues.
        if ($inside && $anchorInside) {
            $anchor = $point;
            $anchorInside = true;
            $pending = false;
            $dwellTo = $time;
            $dwellCount++;
            $dwellLastIndex = $i;
            continue;
        }

        // The deadband is the larger of the still radius and what the fixes themselves
        // admit to: two positions each accurate to ±100 m say nothing about a 60 m move.
        $margin = max(
            (float)($anchor['accuracy_m'] ?? 0),
            (float)($point['accuracy_m'] ?? 0)
        );
        $deadband = max((float)ATT_STILL_RADIUS_M, $margin);

        $distance = attHaversine($anchor['lat'], $anchor['lng'], $point['lat'], $point['lng']);

        if ($distance < $deadband) {
            // Same place as far as anyone can tell. Any half-finished departure was a
            // spike, not a walk — the next fix came back.
            $pending = false;
            $dwellTo = $time;
            $dwellCount++;
            $dwellLastIndex = $i;
            continue;
        }

        // Beyond the deadband once is not movement. Jitter wider than the deadband —
        // which is what the network provider produces — otherwise throws the anchor out
        // and back, and every hop is counted: a phone standing still for eight hours
        // measured 1.8 km that way. So a departure has to be confirmed by the fix after
        // it, the same rule the geofence breach logic uses before raising an alarm.
        if (!$pending) {
            $pending = true;
            continue;
        }

        $closeDwell();

        $total += $distance;
        $pending = false;
        $anchor = $point;
        $anchorInside = $inside;
        $openDwell($i, $point, $time, $inside);
    }

    $closeDwell();

    return ['distance_m' => (int)round($total), 'stops' => $stops];
}

/** Total distance along an ordered list of points, in metres. */
function attRouteDistance(array $points) {
    return attRouteMovement($points)['distance_m'];
}
