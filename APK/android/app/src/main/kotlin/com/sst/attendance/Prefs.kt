package com.sst.attendance

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import org.json.JSONObject

/**
 * Shared state between the Flutter UI and the native tracking service.
 *
 * The service runs in the same process but outlives the Flutter engine, so it
 * cannot read anything held in Dart memory: the token, the base URL and the
 * current rules are written here by the UI and read from here by the service.
 *
 * Encrypted because the bearer token lives in it. If the keystore-backed file is
 * unreadable (a handful of OEM builds have broken keystores), it falls back to
 * plain preferences rather than leaving the app unable to start — the token is
 * device-bound and revocable, so availability wins over that last increment of
 * secrecy.
 */
object Prefs {

    private const val FILE_SECURE = "sst_attendance_secure"
    private const val FILE_PLAIN = "sst_attendance_plain"

    private const val KEY_TOKEN = "token"
    private const val KEY_BASE_URL = "base_url"
    private const val KEY_DEVICE_UID = "device_uid"
    private const val KEY_INSTALL_UUID = "install_uuid"
    private const val KEY_CONFIG = "config_json"
    private const val KEY_TRACKING_WANTED = "tracking_wanted"
    private const val KEY_LAST_INSIDE = "last_inside"
    private const val KEY_LAST_UPLOAD_AT = "last_upload_at"
    private const val KEY_LAST_FIX_AT = "last_fix_at"
    private const val KEY_ACTIVITY_STILL = "activity_still"
    private const val KEY_ACTIVITY_AT = "activity_at"
    private const val KEY_LAST_REC_LAT = "last_rec_lat"
    private const val KEY_LAST_REC_LNG = "last_rec_lng"
    private const val KEY_SHIFT_OPEN = "shift_open"
    private const val KEY_ALERTED_OUTSIDE = "alerted_outside"
    private const val KEY_REASON_GIVEN = "outside_reason_given"
    private const val KEY_LIVE_SECONDS = "live_seconds"
    private const val KEY_LIVE_UNTIL = "live_until"
    private const val KEY_LAST_LIVE_PING_AT = "last_live_ping_at"

    @Volatile private var secure: SharedPreferences? = null
    @Volatile private var plain: SharedPreferences? = null

    private fun secure(context: Context): SharedPreferences {
        secure?.let { return it }
        synchronized(this) {
            secure?.let { return it }
            val prefs = try {
                val key = MasterKey.Builder(context)
                    .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                    .build()
                EncryptedSharedPreferences.create(
                    context, FILE_SECURE, key,
                    EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                    EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
                )
            } catch (e: Exception) {
                context.getSharedPreferences(FILE_SECURE + "_fallback", Context.MODE_PRIVATE)
            }
            secure = prefs
            return prefs
        }
    }

    private fun plain(context: Context): SharedPreferences {
        plain?.let { return it }
        synchronized(this) {
            val prefs = context.getSharedPreferences(FILE_PLAIN, Context.MODE_PRIVATE)
            plain = prefs
            return prefs
        }
    }

    /* ---------------- session ---------------- */

    fun token(context: Context): String? = secure(context).getString(KEY_TOKEN, null)

    fun setToken(context: Context, token: String?) =
        secure(context).edit().putString(KEY_TOKEN, token).apply()

    fun baseUrl(context: Context): String =
        plain(context).getString(KEY_BASE_URL, BuildConfig.DEFAULT_API_BASE)
            ?: BuildConfig.DEFAULT_API_BASE

    fun setBaseUrl(context: Context, url: String) =
        plain(context).edit().putString(KEY_BASE_URL, url.trimEnd('/')).apply()

    /* ---------------- device identity ---------------- */

    fun deviceUid(context: Context): String? = secure(context).getString(KEY_DEVICE_UID, null)

    fun setDeviceUid(context: Context, uid: String) =
        secure(context).edit().putString(KEY_DEVICE_UID, uid).apply()

    fun installUuid(context: Context): String? = secure(context).getString(KEY_INSTALL_UUID, null)

    fun setInstallUuid(context: Context, uuid: String) =
        secure(context).edit().putString(KEY_INSTALL_UUID, uuid).apply()

    /* ---------------- rules pushed down from the server ---------------- */

    fun config(context: Context): JSONObject {
        val raw = plain(context).getString(KEY_CONFIG, null) ?: return JSONObject()
        return try {
            JSONObject(raw)
        } catch (e: Exception) {
            JSONObject()
        }
    }

    fun setConfig(context: Context, json: String) =
        plain(context).edit().putString(KEY_CONFIG, json).apply()

    fun intervalMinutes(context: Context): Int {
        // The interval lives inside the "settings" object, mirroring the shape the
        // API returns; reading it from the top level would silently yield the default.
        val settings = config(context).optJSONObject("settings")
        val value = settings?.optInt("tracking_interval_min", 10) ?: 10
        // Clamped so a bad server value cannot flatten the battery or stall tracking.
        return value.coerceIn(1, 120)
    }

    fun geofence(context: Context): GeoFence.Area? =
        GeoFence.Area.fromJson(config(context).optJSONObject("geofence"))

    /**
     * Every area the employee is assigned to.
     *
     * Falls back to the single "geofence" object when the server has not sent a list —
     * an app updated before the server, or a config cached from the older shape.
     * Returning an empty list in that case would mean "no area assigned", which the
     * geofence code treats as always-inside, silently disabling breach detection.
     */
    fun geofences(context: Context): List<GeoFence.Area> {
        val raw = config(context).optJSONArray("geofences")
        val areas = mutableListOf<GeoFence.Area>()

        if (raw != null) {
            for (i in 0 until raw.length()) {
                GeoFence.Area.fromJson(raw.optJSONObject(i))?.let { areas.add(it) }
            }
        }

        if (areas.isEmpty()) {
            geofence(context)?.let { areas.add(it) }
        }
        return areas
    }

    /**
     * How often to check whether the employee has left the area, in seconds.
     *
     * Separate from the recording interval on purpose: a route wants a point every
     * few minutes, but a warning that you have left your work area is worthless
     * minutes late. 0 disables breach watching entirely.
     */
    fun watchSeconds(context: Context): Int {
        val settings = config(context).optJSONObject("settings") ?: return 10
        val value = settings.optInt("geofence_watch_seconds", 10)
        if (value <= 0) return 0
        // Floor of 5s: below that the GPS hardware cannot keep up and it is pure
        // battery burn for no extra fidelity.
        return value.coerceIn(5, 300)
    }

    /**
     * How far past a boundary counts as still being on site, in metres.
     *
     * Must match attGeofenceBufferM() on the server. The device raises the alarm and
     * the server records the trip; if they disagree the employee is warned about a
     * departure the record says never happened.
     */
    fun geofenceBufferM(context: Context): Double {
        val settings = config(context).optJSONObject("settings") ?: return 50.0
        return settings.optInt("geofence_buffer_m", 50).coerceIn(0, 1000).toDouble()
    }

    /**
     * Whether simulated positions are acceptable for this employee.
     *
     * Off in normal use — a fake-GPS app would otherwise let someone check in from
     * anywhere. Turned on deliberately for testing, and when it is on it must apply
     * everywhere, not just at check-in, or the app silently ignores the tester's
     * position while appearing to accept it.
     */
    fun allowMockLocation(context: Context): Boolean {
        val settings = config(context).optJSONObject("settings") ?: return false
        return settings.optBoolean("allow_mock_location", false)
    }

    /**
     * How many hours past the scheduled end a shift may still be worked.
     *
     * The service needs this locally: recording must not stop at the rostered end
     * time while someone is still on site and still checked in, or exactly the hours
     * an admin most wants to see would be the ones missing from the route.
     */
    fun maxOvertimeHours(context: Context): Int {
        val settings = config(context).optJSONObject("settings") ?: return 6
        return settings.optInt("max_overtime_hours", 6).coerceIn(0, 18)
    }

    /**
     * Reporting rate, in seconds, while an admin is watching this person live — 0 when
     * nobody is.
     *
     * Held with an expiry rather than as a plain flag. A few-second reporting rate is a
     * real drain on the battery, and if the window could be left set by a lost response
     * or a killed page the device would keep burning power for someone who stopped
     * watching an hour ago. When the expiry passes it reverts to the normal interval on
     * its own, with no message needed from the server.
     */
    fun liveSeconds(context: Context): Int {
        val until = plain(context).getLong(KEY_LIVE_UNTIL, 0L)
        if (until <= System.currentTimeMillis()) return 0
        return plain(context).getInt(KEY_LIVE_SECONDS, 0).coerceIn(0, 60)
    }

    fun setLive(context: Context, seconds: Int, untilMillis: Long) =
        plain(context).edit()
            .putInt(KEY_LIVE_SECONDS, seconds)
            .putLong(KEY_LIVE_UNTIL, untilMillis)
            .apply()

    fun clearLive(context: Context) =
        plain(context).edit().remove(KEY_LIVE_SECONDS).remove(KEY_LIVE_UNTIL).apply()

    /**
     * Whether the phone sends the personal live board a position around the
     * clock — nights, off days, everything. On by default; the Website settings
     * page switches it off. Route recording is unaffected either way: it stays
     * shift-only, and these pings travel a separate channel the register never
     * reads.
     */
    fun liveBoardEnabled(context: Context): Boolean {
        val settings = config(context).optJSONObject("settings") ?: return true
        return settings.optBoolean("live_board_enabled", true)
    }

    /** Minutes between off-shift board pings. One GPS fix each — 15 is gentle. */
    fun livePingIntervalMin(context: Context): Int {
        val settings = config(context).optJSONObject("settings") ?: return 15
        return settings.optInt("live_ping_interval_min", 15).coerceIn(5, 120)
    }

    fun lastLivePingAt(context: Context): Long =
        plain(context).getLong(KEY_LAST_LIVE_PING_AT, 0L)

    fun setLastLivePingAt(context: Context, at: Long) =
        plain(context).edit().putLong(KEY_LAST_LIVE_PING_AT, at).apply()

    /**
     * Whether screenshots and screen recording are permitted.
     *
     * Defaults to false — the restrictive answer — so a phone that has not yet fetched
     * config, or whose config failed to parse, stays unrecordable rather than exposing
     * other people's locations because a network call did not land.
     */
    fun allowScreenCapture(context: Context): Boolean {
        val settings = config(context).optJSONObject("settings") ?: return false
        return settings.optBoolean("allow_screen_capture", false)
    }

    fun alertSound(context: Context): Boolean {
        // Disabled: no alarm sound or ringtune when outside the area
        return false
    }

    fun commandPollSeconds(context: Context): Int {
        val settings = config(context).optJSONObject("settings") ?: return 45
        return settings.optInt("command_poll_seconds", 45).coerceIn(15, 600)
    }

    /* ---------------- tracking state ---------------- */

    /** Whether the employee is signed in and tracking should run when in shift. */
    fun trackingWanted(context: Context): Boolean =
        plain(context).getBoolean(KEY_TRACKING_WANTED, false)

    fun setTrackingWanted(context: Context, wanted: Boolean) =
        plain(context).edit().putBoolean(KEY_TRACKING_WANTED, wanted).apply()

    /**
     * Whether the last accepted fix was inside the area. Persisted rather than kept
     * in memory so a service restart does not re-report a crossing that already
     * happened, which would open a duplicate trip.
     */
    fun lastInside(context: Context): Boolean? {
        if (!plain(context).contains(KEY_LAST_INSIDE)) return null
        return plain(context).getBoolean(KEY_LAST_INSIDE, true)
    }

    fun setLastInside(context: Context, inside: Boolean) =
        plain(context).edit().putBoolean(KEY_LAST_INSIDE, inside).apply()

    /**
     * Whether the employee is checked in with no check-out yet.
     *
     * Written by the UI from what the server reports, because the service has no
     * other way to know: the roster says the shift is over, but the register says the
     * person is still on the clock, and the register is the one that decides whether
     * we should still be recording.
     */
    fun shiftOpen(context: Context): Boolean =
        plain(context).getBoolean(KEY_SHIFT_OPEN, false)

    fun setShiftOpen(context: Context, open: Boolean) =
        plain(context).edit().putBoolean(KEY_SHIFT_OPEN, open).apply()

    /**
     * The breach state most recently announced to the employee, or null if nothing has
     * been announced yet.
     *
     * Persisted, not held in memory. Android restarts this service routinely — the
     * foreground service is START_STICKY and gets recreated after the system reclaims
     * it — and an in-memory flag came back empty each time, so the next fix taken
     * while the employee was still outside re-ran the alarm as though they had only
     * just left. That is the "it keeps warning me" behaviour: not a new crossing, just
     * a service that had forgotten it already spoke.
     */
    fun alertedOutside(context: Context): Boolean? {
        if (!plain(context).contains(KEY_ALERTED_OUTSIDE)) return null
        return plain(context).getBoolean(KEY_ALERTED_OUTSIDE, false)
    }

    fun setAlertedOutside(context: Context, outside: Boolean) =
        plain(context).edit().putBoolean(KEY_ALERTED_OUTSIDE, outside).apply()

    fun clearAlertedOutside(context: Context) =
        plain(context).edit().remove(KEY_ALERTED_OUTSIDE).apply()

    /**
     * Whether the current departure has already been explained.
     *
     * Once the employee has said why they left, there is nothing further to ask them
     * and the alarm is pure nuisance. Cleared when they return inside, because the
     * next departure is a new question.
     */
    fun outsideReasonGiven(context: Context): Boolean =
        plain(context).getBoolean(KEY_REASON_GIVEN, false)

    fun setOutsideReasonGiven(context: Context, given: Boolean) =
        plain(context).edit().putBoolean(KEY_REASON_GIVEN, given).apply()

    fun lastUploadAt(context: Context): Long = plain(context).getLong(KEY_LAST_UPLOAD_AT, 0L)

    fun setLastUploadAt(context: Context, at: Long) =
        plain(context).edit().putLong(KEY_LAST_UPLOAD_AT, at).apply()

    fun lastFixAt(context: Context): Long = plain(context).getLong(KEY_LAST_FIX_AT, 0L)

    /* ---------------- what the accelerometer says about movement ---------------- */

    /** Whether the last activity transition said the phone was still. */
    fun activityStill(context: Context): Boolean =
        plain(context).getBoolean(KEY_ACTIVITY_STILL, false)

    /** When that was reported, so a stale answer can be distrusted. */
    fun activityAt(context: Context): Long = plain(context).getLong(KEY_ACTIVITY_AT, 0L)

    fun setActivityStill(context: Context, still: Boolean) =
        plain(context).edit()
            .putBoolean(KEY_ACTIVITY_STILL, still)
            .putLong(KEY_ACTIVITY_AT, System.currentTimeMillis())
            .apply()

    /**
     * Forgets the activity state.
     *
     * Called when monitoring stops, because a remembered "still" would otherwise keep
     * suppressing points after the thing that reported it has gone away — failing closed in
     * the direction that loses data.
     */
    fun clearActivity(context: Context) =
        plain(context).edit().remove(KEY_ACTIVITY_STILL).remove(KEY_ACTIVITY_AT).apply()

    /**
     * Where the last recorded point was, so the next fix can be judged against it.
     *
     * Persisted rather than held in memory because the service is routinely killed and
     * restarted; a forgotten last position would let the first fix after every restart
     * through the dead-band and put a stray point on the route.
     *
     * Stored as raw double bits — SharedPreferences has no double, and a float would round
     * a latitude to a few metres, which is the same size as the movement being measured.
     */
    fun lastRecordedPosition(context: Context): Pair<Double, Double>? {
        val store = plain(context)
        if (!store.contains(KEY_LAST_REC_LAT) || !store.contains(KEY_LAST_REC_LNG)) return null
        return Pair(
            java.lang.Double.longBitsToDouble(store.getLong(KEY_LAST_REC_LAT, 0L)),
            java.lang.Double.longBitsToDouble(store.getLong(KEY_LAST_REC_LNG, 0L))
        )
    }

    fun setLastRecordedPosition(context: Context, lat: Double, lng: Double) =
        plain(context).edit()
            .putLong(KEY_LAST_REC_LAT, java.lang.Double.doubleToRawLongBits(lat))
            .putLong(KEY_LAST_REC_LNG, java.lang.Double.doubleToRawLongBits(lng))
            .apply()

    fun clearLastRecordedPosition(context: Context) =
        plain(context).edit().remove(KEY_LAST_REC_LAT).remove(KEY_LAST_REC_LNG).apply()

    /**
     * How far the phone must have moved from the last recorded point before another one is
     * worth storing, in metres.
     *
     * This is what turns the route from straight chords between distant samples into
     * something that follows a road: the breach watch already produces a fix every few
     * seconds, and this decides which of them describe actual movement.
     */
    fun moveMinM(context: Context): Double {
        val settings = config(context).optJSONObject("settings") ?: return 25.0
        return settings.optDouble("move_min_m", 25.0).coerceIn(5.0, 500.0)
    }

    /** The floor between two recorded points, in seconds. What bounds the stored volume. */
    fun minGapSeconds(context: Context): Int {
        val settings = config(context).optJSONObject("settings") ?: return 20
        return settings.optInt("min_gap_seconds", 20).coerceIn(5, 600)
    }

    fun setLastFixAt(context: Context, at: Long) =
        plain(context).edit().putLong(KEY_LAST_FIX_AT, at).apply()

    /** Wipe everything session-related on sign-out, keeping the device identity. */
    fun clearSession(context: Context) {
        secure(context).edit().remove(KEY_TOKEN).apply()
        plain(context).edit()
            .remove(KEY_CONFIG)
            .remove(KEY_TRACKING_WANTED)
            .remove(KEY_LAST_INSIDE)
            .remove(KEY_SHIFT_OPEN)
            .remove(KEY_ALERTED_OUTSIDE)
            .remove(KEY_REASON_GIVEN)
            .remove(KEY_LIVE_SECONDS)
            .remove(KEY_LIVE_UNTIL)
            .remove(KEY_LAST_LIVE_PING_AT)
            .apply()
    }
}
