package com.sst.attendance

import org.json.JSONArray
import org.json.JSONObject
import kotlin.math.abs
import kotlin.math.asin
import kotlin.math.cos
import kotlin.math.min
import kotlin.math.sin
import kotlin.math.sqrt

/**
 * Geofence evaluation, deliberately a mirror of Website/includes/geo.php.
 *
 * The service must be able to decide inside/outside with no network, so the same
 * two rules exist on both sides. If one changes, change the other: the server's
 * verdict is authoritative for records, but a device that disagrees would show the
 * employee a different answer from the one the admin sees.
 */
object GeoFence {

    private const val EARTH_RADIUS_M = 6371000.0

    /**
     * @param areaName which area was matched, or the nearest one when outside them
     *                 all — null when no area is assigned at all.
     */
    data class Result(
        val inside: Boolean,
        val distanceM: Int,
        val areaId: Int? = null,
        val areaName: String? = null
    )

    /** A work area as delivered by /api/v1/app-config. */
    data class Area(
        val id: Int,
        val name: String,
        val type: String,
        val centerLat: Double?,
        val centerLng: Double?,
        val radiusM: Int?,
        val polygon: List<DoubleArray>
    ) {
        companion object {
            fun fromJson(json: JSONObject?): Area? {
                if (json == null || json.optInt("id", 0) == 0) return null

                val ring = mutableListOf<DoubleArray>()
                val raw = json.optJSONArray("polygon") ?: JSONArray()
                for (i in 0 until raw.length()) {
                    val pair = raw.optJSONArray(i) ?: continue
                    if (pair.length() >= 2) {
                        ring.add(doubleArrayOf(pair.optDouble(0), pair.optDouble(1)))
                    }
                }

                return Area(
                    id = json.optInt("id"),
                    name = json.optString("name"),
                    type = json.optString("type", "circle"),
                    centerLat = if (json.isNull("center_lat")) null else json.optDouble("center_lat"),
                    centerLng = if (json.isNull("center_lng")) null else json.optDouble("center_lng"),
                    radiusM = if (json.isNull("radius_m")) null else json.optInt("radius_m"),
                    polygon = ring
                )
            }
        }
    }

    fun haversine(lat1: Double, lng1: Double, lat2: Double, lng2: Double): Double {
        val phi1 = Math.toRadians(lat1)
        val phi2 = Math.toRadians(lat2)
        val dPhi = phi2 - phi1
        val dLambda = Math.toRadians(lng2 - lng1)

        val a = sin(dPhi / 2) * sin(dPhi / 2) +
                cos(phi1) * cos(phi2) * sin(dLambda / 2) * sin(dLambda / 2)
        return 2 * EARTH_RADIUS_M * asin(min(1.0, sqrt(a)))
    }

    /**
     * With no area assigned the point counts as inside, so an unconfigured
     * employee is never told they left an area that was never defined.
     */
    fun evaluate(lat: Double, lng: Double, area: Area?, bufferM: Double = 0.0): Result {
        if (area == null) return Result(true, 0)

        if (area.type == "polygon") {
            if (area.polygon.size < 3) return Result(true, 0)
            if (pointInPolygon(lat, lng, area.polygon)) {
                return Result(true, 0, area.id, area.name)
            }
            val out = distanceToPolygon(lat, lng, area.polygon)
            return Result(out <= bufferM, out.toInt(), area.id, area.name)
        }

        val centerLat = area.centerLat ?: return Result(true, 0)
        val centerLng = area.centerLng ?: return Result(true, 0)
        val radius = area.radiusM ?: return Result(true, 0)

        val distance = haversine(lat, lng, centerLat, centerLng)
        // The distance reported is to the real boundary, not to the edge of the
        // tolerance ring: the tolerance decides the verdict, not what gets shown.
        val out = (distance - radius).coerceAtLeast(0.0)
        return Result(out <= bufferM, out.toInt(), area.id, area.name)
    }

    /**
     * Judge a position against every area the employee is assigned to.
     *
     * Inside any one of them is inside. This must agree with attEvaluateAreas() on the
     * server: the device raises the alarm and the server records the trip, and if they
     * disagree the employee gets warned about a departure the record denies happened.
     *
     * When outside them all, the distance reported is to the nearest.
     */
    fun evaluateAll(lat: Double, lng: Double, areas: List<Area>, bufferM: Double = 0.0): Result {
        if (areas.isEmpty()) return Result(true, 0)

        var nearest: Result? = null

        for (area in areas) {
            val verdict = evaluate(lat, lng, area, bufferM)
            if (verdict.inside) return verdict
            if (nearest == null || verdict.distanceM < nearest.distanceM) nearest = verdict
        }

        return nearest ?: Result(true, 0)
    }

    /** Ray casting. Planar coordinates are accurate enough at work-site scale. */
    private fun pointInPolygon(lat: Double, lng: Double, ring: List<DoubleArray>): Boolean {
        var inside = false
        var j = ring.size - 1

        for (i in ring.indices) {
            val latI = ring[i][0]; val lngI = ring[i][1]
            val latJ = ring[j][0]; val lngJ = ring[j][1]

            if ((lngI > lng) != (lngJ > lng)) {
                val latAtLng = latI + (lng - lngI) * (latJ - latI) / (lngJ - lngI)
                if (lat < latAtLng) inside = !inside
            }
            j = i
        }
        return inside
    }

    private fun distanceToPolygon(lat: Double, lng: Double, ring: List<DoubleArray>): Double {
        var minimum = Double.MAX_VALUE
        for (i in ring.indices) {
            val a = ring[i]
            val b = ring[(i + 1) % ring.size]
            minimum = min(minimum, distanceToSegment(lat, lng, a[0], a[1], b[0], b[1]))
        }
        return if (minimum == Double.MAX_VALUE) 0.0 else minimum
    }

    /**
     * The coordinates are projected onto a local metre grid first, otherwise the
     * perpendicular-foot maths is skewed by longitude degrees being shorter than
     * latitude degrees away from the equator.
     */
    private fun distanceToSegment(
        lat: Double, lng: Double,
        aLat: Double, aLng: Double,
        bLat: Double, bLng: Double
    ): Double {
        val latScale = EARTH_RADIUS_M * Math.PI / 180.0
        val lngScale = latScale * cos(Math.toRadians(lat))

        val px = (lng - aLng) * lngScale
        val py = (lat - aLat) * latScale
        val bx = (bLng - aLng) * lngScale
        val by = (bLat - aLat) * latScale

        val lengthSq = bx * bx + by * by
        if (lengthSq <= 0.0) return sqrt(px * px + py * py)

        val t = (px * bx + py * by) / lengthSq
        val clamped = if (t < 0.0) 0.0 else if (t > 1.0) 1.0 else t

        val dx = px - clamped * bx
        val dy = py - clamped * by
        return sqrt(dx * dx + dy * dy)
    }

    /** Rejects nulls, out-of-range values and the 0,0 "empty fix". */
    fun validCoords(lat: Double?, lng: Double?): Boolean {
        if (lat == null || lng == null) return false
        if (lat.isNaN() || lng.isNaN()) return false
        if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return false
        return !(abs(lat) < 0.00001 && abs(lng) < 0.00001)
    }
}
