package com.sst.attendance

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.ServiceInfo
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.provider.Settings
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.BatteryManager
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import android.os.SystemClock
import android.util.Log
import org.json.JSONObject
import java.util.Calendar
import kotlin.math.abs
import kotlin.math.sqrt

/**
 * Foreground service that records the employee's position through their shift.
 *
 * Why native rather than a Dart timer: Android freezes and then kills the Flutter
 * engine when the app is backgrounded, so a Dart-side timer stops without warning
 * and the route comes back full of holes. A foreground service with
 * foregroundServiceType=location is the only mechanism Android sanctions for
 * continuous background location, and it survives the UI being swept away.
 *
 * The service is self-sufficient: it reads its rules from Prefs, judges the
 * geofence itself via GeoFence, queues to SQLite, and uploads with ApiClient. It
 * never needs the Flutter engine to be alive.
 */
class TrackingService : Service() {

    companion object {
        private const val TAG = "SSTTracking"

        const val ACTION_START = "com.sst.attendance.START"
        const val ACTION_STOP = "com.sst.attendance.STOP"
        const val ACTION_SYNC_NOW = "com.sst.attendance.SYNC_NOW"

        /** Broadcast the UI listens to so it can show live status. */
        const val BROADCAST_STATUS = "com.sst.attendance.STATUS"

        /**
         * Fired the moment a fence crossing is detected, so the UI can warn the
         * employee and ask for a reason without waiting for its own poll.
         */
        const val BROADCAST_FENCE = "com.sst.attendance.FENCE"

        private const val ALERT_CHANNEL_ID = "sst_alerts"
        private const val ALERT_NOTIFICATION_ID = 4712

        /** Tamper warning: a simulated position on an account not permitted one. */
        private const val TAMPER_NOTIFICATION_ID = 4713

        /**
         * How many queued points force a send even when the point was movement-driven.
         *
         * Low enough that route detail is never more than a few minutes behind, high enough
         * that a drive does not turn into one request per point. At a 20-second cadence this
         * is a request every two minutes.
         */
        private const val UPLOAD_WHEN_QUEUED = 6

        /**
         * Below this speed the phone is treated as stationary, in metres per second.
         *
         * 0.5 m/s is 1.8 km/h — slower than any walk, so nothing real is discarded, while a
         * phone on a desk reporting 0.0 is filtered out however far its coordinates wander.
         */
        private const val STILL_SPEED_MS = 0.5f

        /**
         * How often a stationary phone still stores a point, in milliseconds.
         *
         * The heartbeat exists so that keeping still is never indistinguishable from the app
         * having died: the live map's "last seen" keeps moving, and "was on site all
         * afternoon" stays provable by evidence rather than by absence of it. One minute is
         * frequent enough for both and slow enough that a shift's worth adds up to a few
         * dozen points instead of a few thousand.
         */
        private const val STILL_HEARTBEAT_MS = 60_000L

        /**
         * The worst accuracy a fix may have and still contribute a point to the route, in
         * metres.
         *
         * Deliberately far stricter than the ingest cap of 200 m, which exists so that an
         * indoor check-in is never refused. Those two limits answer different questions:
         * "is this good enough to prove somebody was at work" tolerates a vague position,
         * "is this good enough to draw where they walked" does not.
         */
        private const val ROUTE_ACCURACY_CEILING_M = 50f

        /** How long the leaving-the-area tone sounds for. */
        private const val ALERT_SOUND_SECONDS = 5L

        /**
         * How long an outside departure must be continuously sustained before alarming or asking
         * for a reason (2.5 minutes, in the 2-3 minute window).
         */
        private const val OUTSIDE_CONFIRMATION_WAIT_MS = 150_000L

        /**
         * Minimum consecutive credible outside fixes required over the wait window.
         */
        private const val MIN_OUTSIDE_CHECKS = 5

        // v2 because Android freezes a channel's importance at creation: lowering it in
        // code does nothing to a channel that already exists on the handset. A new id is
        // the only way an existing install picks up the quieter setting.
        private const val CHANNEL_ID = "sst_tracking_v2"
        private const val NOTIFICATION_ID = 4711

        /**
         * How long to keep collecting after the first arrival of a sample before
         * committing the most accurate one. Long enough for GPS to overtake the
         * network provider's instant-but-coarse answer, short enough that the point
         * is still stamped close to its due time.
         */
        private const val SETTLE_WINDOW_MS = 25_000L

        /**
         * A fix this coarse is never recorded, whatever the employee's setting says.
         *
         * Measured on real routes: GPS fixes land within 1-9 m and move about 14 m
         * between consecutive points, while network fixes report 25-100 m and move 74 m
         * on average — up to 371 m. A point that could be anywhere inside a 200 m circle
         * is not a position, and drawing it on the map invents movement that never
         * happened.
         */
        private const val NEVER_RECORD_ABOVE_M = 200f

        /**
         * A fix older than this is discarded.
         *
         * Providers hand back cached positions, and recording one stamped "now" puts the
         * employee back where the phone was minutes ago — a jump out of the work area
         * and straight back in, from nothing that actually moved.
         */
        private const val STALE_FIX_MS = 120_000L

        /** How often to retry an upload while anything is still queued. */
        private const val RETRY_SECONDS = 5L

        @Volatile var isRunning: Boolean = false
            private set

        fun start(context: Context) {
            val intent = Intent(context, TrackingService::class.java).setAction(ACTION_START)
            // startForegroundService is mandatory from Oreo, and the service then has
            // a few seconds to call startForeground() or the system kills it.
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        fun stop(context: Context) {
            context.startService(Intent(context, TrackingService::class.java).setAction(ACTION_STOP))
        }

        fun syncNow(context: Context) {
            if (!isRunning) return
            context.startService(
                Intent(context, TrackingService::class.java).setAction(ACTION_SYNC_NOW)
            )
        }
    }

    /** A single accepted position report. */
    data class Fix(
        val lat: Double,
        val lng: Double,
        val accuracy: Double?,
        val altitude: Double?,
        val speedKmh: Double?,
        val heading: Double?,
        val batteryPct: Int?,
        val isCharging: Boolean,
        val isMock: Boolean,
        val provider: String?,
        val timeMillis: Long,
        val insideFence: Boolean,
        val distanceM: Int,
        /**
         * How many consecutive credible outside observations the fast watch had made
         * when this point was taken. The server uses it to confirm a departure in
         * seconds instead of waiting for its own sparse recorded points.
         */
        val outsideStreak: Int = 0,
        /**
         * Where the phone genuinely was, when [isMock] is true and a raw provider could
         * still answer honestly. Null far more often than not: most fake-GPS apps
         * override every provider, and then there is nothing genuine left to read.
         */
        val realLat: Double? = null,
        val realLng: Double? = null
    )

    private lateinit var locationManager: LocationManager
    private lateinit var queue: LocationQueue
    private var wakeLock: PowerManager.WakeLock? = null

    private val handler = Handler(Looper.getMainLooper())
    private var tickRunnable: Runnable? = null

    private var lastStatus: String = "Starting…"
    private var lastFixAt: Long = 0L
    private var pendingCount: Int = 0

    /**
     * Best fix seen so far in the current settle window, or null when no window is
     * open. See handleLocation for why arrivals are not committed immediately.
     */
    private var candidate: Location? = null

    /** Network watcher, so a regained connection flushes the queue at once. */
    private var networkCallback: ConnectivityManager.NetworkCallback? = null

    /**
     * Fast retry while points are waiting.
     *
     * Runs every RETRY_SECONDS, but only while the queue is non-empty, and stops the
     * moment it drains. A timer that ticked every five seconds regardless would burn
     * battery all shift to discover there is nothing to send - the connectivity
     * callback already handles the common case of the network simply returning. This
     * covers the cases it does not: a connection that is up but not yet usable, a
     * captive portal, or a server that was briefly unreachable.
     */
    private var retryRunnable: Runnable? = null

    /**
     * Interval the providers are currently registered at. Used to tell a repeated
     * start (ignore it) from a real settings change (re-register).
     */
    private var registeredIntervalMin: Int = 0

    /** Command poll loop, separate from the slower tick. */
    private var pollRunnable: Runnable? = null
    private var pollSeconds: Int = 45

    /**
     * Fast breach watch. Its fixes are judged against the fence and then discarded —
     * only the recording listener writes points — so raising its frequency costs
     * battery but does not inflate the route table.
     */
    private var watchListener: LocationListener? = null
    private var watchSeconds: Int = 0


    /**
     * Consecutive credible outside fixes seen by the fast watch.
     *
     * The watch runs every geofence_watch_seconds (10s by default), so it can establish
     * that a departure is real within seconds. The server needs the same confirmation
     * before it opens a trip, but its own evidence is the recorded points, which arrive
     * at the recording interval - five minutes apart. That is why the alarm was instant
     * while the reason prompt took minutes to appear: the trip it attaches to did not
     * exist yet. Reporting the watch's count lets the server accept evidence the device
     * has already gathered, rather than slowly re-deriving it.
     */
    private var outsideStreak: Int = 0

    /** Held while the leaving-the-area tone plays, so it can be stopped on time. */
    private var alertPlayer: MediaPlayer? = null

    /**
     * Set when an admin has asked for a position now, so the next fix bypasses the
     * interval throttle instead of being discarded as "not due yet".
     */
    @Volatile private var forceFix = false

    /**
     * Whether the employee has been warned about a simulated position this session.
     * In memory rather than Prefs: if the service is restarted while a fake-GPS app is
     * still running, warning once more is the correct behaviour.
     */
    @Volatile private var mockAlerted = false

    /** Rate limit for the dead-band explanation, so it informs rather than floods. */
    @Volatile private var lastDeadbandLogAt = 0L

    /**
     * Whether the outstanding forced fix was asked for by a person.
     *
     * An admin pressing Locate wants an answer even if the phone has not moved — that is the
     * whole point of the button. The geofence logic forcing a fix is different: it wants the
     * crossing on the record, and if the accelerometer says nobody has moved then the
     * "crossing" is GPS drift across a boundary, which is exactly the knot of points that
     * appeared at every fence edge.
     */
    @Volatile private var forcedByAdmin = false

    /**
     * Screen-off can suspend passive updates on some OEM builds; re-registering on
     * unlock is a cheap safeguard against silently losing the location stream.
     */
    private val screenReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.action == Intent.ACTION_USER_PRESENT && isRunning) {
                requestUpdates()
            }
        }
    }

    /** True while fused updates are registered, so they get deregistered the same way. */
    @Volatile private var fusedRegistered = false

    private var fusedWatchClient: com.google.android.gms.location.FusedLocationProviderClient? = null
    private var fusedWatchCallback: com.google.android.gms.location.LocationCallback? = null

    /* ---------------- hardware sensor motion & spike detection ---------------- */

    private var sensorManager: SensorManager? = null
    private var accelerometer: Sensor? = null
    private var gyroscope: Sensor? = null

    @Volatile private var lastSensorMotionTime: Long = 0L
    private var lastAccelX: Float = 0f
    private var lastAccelY: Float = 0f
    private var lastAccelZ: Float = 0f
    private var hasLastAccel: Boolean = false

    private var lastInsideLocation: Location? = null
    @Volatile private var outsideFirstSeenAt = 0L
    @Volatile private var outsideCandidateCount = 0

    private val motionSensorListener = object : SensorEventListener {
        override fun onSensorChanged(event: SensorEvent?) {
            if (event == null) return
            val now = SystemClock.elapsedRealtime()
            when (event.sensor.type) {
                Sensor.TYPE_ACCELEROMETER -> {
                    val x = event.values[0]
                    val y = event.values[1]
                    val z = event.values[2]
                    val mag = sqrt((x * x + y * y + z * z).toDouble()).toFloat()
                    val deltaGravity = abs(mag - SensorManager.GRAVITY_EARTH)

                    var deltaJerk = 0f
                    if (hasLastAccel) {
                        val dx = x - lastAccelX
                        val dy = y - lastAccelY
                        val dz = z - lastAccelZ
                        deltaJerk = sqrt((dx * dx + dy * dy + dz * dz).toDouble()).toFloat()
                    }
                    lastAccelX = x
                    lastAccelY = y
                    lastAccelZ = z
                    hasLastAccel = true

                    // Dynamic movement from footsteps, vehicle motion, or handling
                    if (deltaGravity > 0.8f || deltaJerk > 1.2f) {
                        lastSensorMotionTime = now
                    }
                }
                Sensor.TYPE_GYROSCOPE -> {
                    val wx = event.values[0]
                    val wy = event.values[1]
                    val wz = event.values[2]
                    val rotRate = sqrt((wx * wx + wy * wy + wz * wz).toDouble()).toFloat()
                    // Angular velocity > 0.4 rad/s (~23 deg/s) indicates orientation change or body turning
                    if (rotRate > 0.4f) {
                        lastSensorMotionTime = now
                    }
                }
            }
        }

        override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) {}
    }

    private fun startMotionSensors() {
        try {
            if (sensorManager == null) {
                sensorManager = getSystemService(Context.SENSOR_SERVICE) as? SensorManager
            }
            val sm = sensorManager ?: return
            accelerometer = sm.getDefaultSensor(Sensor.TYPE_ACCELEROMETER)
            gyroscope = sm.getDefaultSensor(Sensor.TYPE_GYROSCOPE)

            accelerometer?.let {
                sm.registerListener(motionSensorListener, it, SensorManager.SENSOR_DELAY_NORMAL)
            }
            gyroscope?.let {
                sm.registerListener(motionSensorListener, it, SensorManager.SENSOR_DELAY_NORMAL)
            }
            Log.i(TAG, "Hardware motion sensors registered (accel=${accelerometer != null}, gyro=${gyroscope != null})")
        } catch (e: Exception) {
            Log.w(TAG, "Could not register motion sensors: ${e.message}")
        }
    }

    private fun stopMotionSensors() {
        try {
            sensorManager?.unregisterListener(motionSensorListener)
        } catch (e: Exception) {
            // Nothing to unregister
        }
    }

    /**
     * Verifies if the phone has physically moved using GPS Doppler speed, hardware
     * accelerometer/gyroscope events, or activity recognition.
     */
    private fun isDevicePhysicallyMoving(location: Location): Boolean {
        // 1. Doppler speed from GPS
        if (location.hasSpeed() && location.speed >= 0.8f) {
            return true
        }
        // 2. Hardware motion from accelerometer or gyroscope within the last 75 seconds
        val now = SystemClock.elapsedRealtime()
        if (now - lastSensorMotionTime < 75_000L) {
            return true
        }
        // 3. Motion coprocessor / Activity Recognition
        if (!ActivityMonitor.isStill(this)) {
            return true
        }
        return false
    }

    /**
     * Rejects sudden coordinate jumps (> 70m in < 20s) when the mobile sensors indicate
     * the handset has remained physically stationary (indoor multipath reflection/drift).
     */
    private fun isSuddenJumpSpike(location: Location): Boolean {
        val last = lastInsideLocation ?: return false
        val dist = GeoFence.haversine(last.latitude, last.longitude, location.latitude, location.longitude)
        val timeDiffSec = if (last.time > 0 && location.time >= last.time) {
            (location.time - last.time) / 1000.0
        } else {
            10.0
        }
        if (dist > 70.0 && timeDiffSec < 20.0 && !isDevicePhysicallyMoving(location)) {
            return true
        }
        return false
    }

    /**
     * Fused arrivals. They go through exactly the same handleLocation path as raw
     * provider arrivals, so there is one place where a fix is judged and recorded
     * regardless of where it came from.
     */
    private val fusedCallback = object : com.google.android.gms.location.LocationCallback() {
        override fun onLocationResult(result: com.google.android.gms.location.LocationResult) {
            result.lastLocation?.let { handleLocation(it) }
        }
    }

    private val locationListener = object : LocationListener {
        override fun onLocationChanged(location: Location) = handleLocation(location)

        // Deprecated on newer APIs but still required by the interface on older ones.
        override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}

        override fun onProviderEnabled(provider: String) {
            updateStatus("Location on — tracking")
        }

        override fun onProviderDisabled(provider: String) {
            // The employee turned GPS off. The notification says so, and the gap in
            // the route is visible to the admin, which is the intended consequence.
            updateStatus("Location is switched off")
        }
    }

    override fun onCreate() {
        super.onCreate()
        locationManager = getSystemService(Context.LOCATION_SERVICE) as LocationManager
        queue = LocationQueue(this)
        createChannel()
        createAlertChannel()

        val filter = IntentFilter(Intent.ACTION_USER_PRESENT)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            // Qualified rather than inherited: Kotlin does not reliably bring Java
            // static constants into a subclass's unqualified scope.
            registerReceiver(screenReceiver, filter, Context.RECEIVER_NOT_EXPORTED)
        } else {
            registerReceiver(screenReceiver, filter)
        }

        watchConnectivity()
        startMotionSensors()
    }

    /**
     * Flush the queue as soon as the network comes back.
     *
     * Without this the buffer sits untouched until the next tick, so a phone that
     * regains signal after a dead spot can look stuck for minutes — and to the
     * employee watching "N points waiting to upload", indistinguishable from broken.
     */
    /**
     * Try again in RETRY_SECONDS, and keep trying until the queue is empty.
     *
     * Self-cancelling: it re-arms only while something is still waiting, so a drained
     * queue costs nothing. Idempotent, so repeated failures do not stack up timers.
     */
    private fun scheduleRetry() {
        handler.post {
            if (retryRunnable != null) return@post

            val runnable = object : Runnable {
                override fun run() {
                    retryRunnable = null
                    if (!isRunning) return

                    val waiting = try { queue.count() } catch (e: Exception) { 0 }
                    if (waiting <= 0) return

                    Thread {
                        upload()
                        // upload() calls back into scheduleRetry() when it fails, so the
                        // loop continues without this needing to know the outcome.
                    }.start()
                }
            }
            retryRunnable = runnable
            handler.postDelayed(runnable, RETRY_SECONDS * 1000L)
        }
    }

    private fun watchConnectivity() {
        if (networkCallback != null) return

        val manager = getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return

        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                if (!isRunning) return
                if (Prefs.token(this@TrackingService) == null) return
                Log.i(TAG, "Network available — flushing queue")
                Thread { upload() }.start()
            }
        }

        val request = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .build()

        try {
            manager.registerNetworkCallback(request, callback)
            networkCallback = callback
        } catch (e: Exception) {
            // Some OEM builds cap the number of concurrent callbacks; the periodic
            // tick still covers retries, so this is not fatal.
            Log.w(TAG, "Could not register network callback: ${e.message}")
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_STOP -> {
                shutdown()
                return START_NOT_STICKY
            }
            ACTION_SYNC_NOW -> {
                if (isTrackingSessionActive()) Thread { upload() }.start()
                return if (isTrackingSessionActive()) START_STICKY else START_NOT_STICKY
            }
        }

        // The service must not run without a session: an upload with no token would
        // only produce 401s, and tracking someone who signed out is not acceptable.
        if (Prefs.token(this) == null || !Prefs.trackingWanted(this) || !Prefs.shiftOpen(this)) {
            Log.i(TAG, "No active session; not starting.")
            shutdown()
            return START_NOT_STICKY
        }

        // A start on an already-running service must be near enough a no-op.
        //
        // The UI calls startTracking() on every poll — roughly every 45 seconds —
        // and originally each of those re-ran requestUpdates() and startTicking().
        // That cancelled the pending tick and re-registered the providers each
        // time, so a five-minute cadence was reset before it could ever elapse:
        // no fixes were delivered and no upload ever ran. Starting is idempotent
        // now, and only a genuine change in the interval re-registers anything.
        if (isRunning) {
            val interval = Prefs.intervalMinutes(this)
            if (interval != registeredIntervalMin) {
                Log.i(TAG, "Interval changed $registeredIntervalMin -> $interval min; re-registering")
                requestUpdates()
                startTicking()
                startWatching()
            }
            // A check-in or check-out changes whether watching applies at all.
            syncWatchState()
            refreshNotification()
            return START_STICKY
        }

        startInForeground()
        acquireWakeLock()
        isRunning = true
        requestUpdates()
        startTicking()
        startPolling()
        startWatching()
        // START_STICKY asks Android to recreate the service if it is killed for
        // memory; combined with the boot receiver this is what makes tracking
        // survive the phone doing its worst.
        return START_STICKY
    }

    /* ---------------- foreground notification ---------------- */

    private fun createChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val channel = NotificationChannel(
            CHANNEL_ID,
            "Attendance tracking",
            // MIN: this keeps the mandatory foreground-service notification
            // out of the status bar and at the bottom of the shade under "Silent".
            // Android will not let it be removed entirely - a location foreground
            // service must show one - so this is the quietest it can legally be.
            NotificationManager.IMPORTANCE_MIN
        ).apply {
            description = "Shows while your working-hours location is being recorded."
            setShowBadge(false)
        }

        (getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager)
            .createNotificationChannel(channel)

        // Tidy away the pre-v2 channel, otherwise it sits in the system settings list
        // forever offering to re-enable a notification style we deliberately dropped.
        try {
            (getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager)
                .deleteNotificationChannel("sst_tracking")
        } catch (e: Exception) {
            // Nothing to remove.
        }
    }

    private fun buildNotification(): Notification {
        val open = PendingIntent.getActivity(
            this, 0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Notification.Builder(this, CHANNEL_ID)
        } else {
            @Suppress("DEPRECATION")
            Notification.Builder(this)
        }

        return builder
            // Just the app name. Android will not let this notification be removed —
            // a location foreground service must show one, and it refused even
            // IMPORTANCE_MIN, forcing LOW — so the only thing left to control is what
            // it says. No interval, no status, no counts.
            .setContentTitle("SST Attendance")
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setContentIntent(open)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .build()
    }

    private fun startInForeground() {
        val notification = buildNotification()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(
                NOTIFICATION_ID, notification,
                ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
            )
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    private fun refreshNotification() {
        if (!isRunning) return
        (getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager)
            .notify(NOTIFICATION_ID, buildNotification())
    }

    private fun updateStatus(status: String) {
        lastStatus = status
        pendingCount = try { queue.count() } catch (e: Exception) { pendingCount }
        refreshNotification()
        broadcastStatus()
    }

    private fun broadcastStatus() {
        val intent = Intent(BROADCAST_STATUS).apply {
            setPackage(packageName)
            putExtra("status", lastStatus)
            putExtra("last_fix_at", lastFixAt)
            putExtra("pending", pendingCount)
            putExtra("running", isRunning)
        }
        sendBroadcast(intent)
    }

    /* ---------------- location ---------------- */

    /**
     * Stop taking fixes without stopping the service.
     *
     * The distinction matters off shift: shutting down entirely left nothing to answer
     * an admin's locate or follow request, while keeping GPS registered all night would
     * flatten the battery for nothing. registeredIntervalMin = 0 is the flag that says
     * the receiver is off, so the tick knows to bring it back when a window opens.
     */
    private fun releaseLocationUpdates() {
        try {
            locationManager.removeUpdates(locationListener)
        } catch (e: Exception) {
            // Never registered.
        }

        if (fusedRegistered) {
            try {
                com.google.android.gms.location.LocationServices
                    .getFusedLocationProviderClient(this)
                    .removeLocationUpdates(fusedCallback)
            } catch (e: Exception) {
                // Already gone.
            }
            fusedRegistered = false
        }

        stopWatching()
        registeredIntervalMin = 0
        candidate = null
    }

    private fun requestUpdates() {
        // Registered here so it follows the service's own lifetime: knowing whether the phone
        // is moving is only useful while there is tracking to gate, and it must not outlive it.
        ActivityMonitor.start(this)

        val intervalMinutes = Prefs.intervalMinutes(this)
        // Follows the live window when one is open, so the providers are actually asked
        // for fixes every few seconds instead of being polled hopefully by handleLocation.
        val intervalMs = reportIntervalMs()
        registeredIntervalMin = intervalMinutes

        try {
            locationManager.removeUpdates(locationListener)
        } catch (e: Exception) {
            // Nothing was registered yet.
        }

        // Fused first. It combines GPS, wifi, cell and the motion sensors into one
        // position and filters it, which is the job this service was doing badly by hand.
        var registered = requestFusedUpdates(intervalMs)

        if (!registered) {
            // No Play Services on this handset. Both raw providers then, as before: GPS
            // is accurate but useless indoors, network keeps a coarse trail alive.
            Log.i(TAG, "Fused location unavailable — falling back to raw providers")
            for (provider in listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)) {
                if (!locationManager.allProviders.contains(provider)) continue
                try {
                    locationManager.requestLocationUpdates(
                        provider,
                        intervalMs,
                        0f,      // no distance filter: a stationary employee must still report
                        locationListener,
                        Looper.getMainLooper()
                    )
                    registered = true
                } catch (e: SecurityException) {
                    Log.w(TAG, "Location permission missing for $provider")
                } catch (e: Exception) {
                    Log.w(TAG, "Could not register $provider: ${e.message}")
                }
            }
        }

        updateStatus(
            if (registered) "Tracking every $intervalMinutes min"
            else "Waiting for location permission"
        )
    }

    /**
     * Register for fused updates. Returns false when Play Services is not usable, so the
     * caller can fall back rather than leaving the employee untracked.
     *
     * PRIORITY_HIGH_ACCURACY asks for the best available, which is what an attendance
     * record judged against a boundary needs. The minimum update interval is set well
     * below the reporting interval so the settle window has several fixes to choose the
     * best from, rather than one arrival taken on trust.
     */
    private fun requestFusedUpdates(intervalMs: Long): Boolean {
        return try {
            val client = com.google.android.gms.location.LocationServices
                .getFusedLocationProviderClient(this)

            val request = com.google.android.gms.location.LocationRequest.Builder(
                com.google.android.gms.location.Priority.PRIORITY_HIGH_ACCURACY,
                intervalMs
            )
                .setMinUpdateIntervalMillis(maxOf(5_000L, intervalMs / 4))
                // Waiting for a batch would hold a breach back until the batch closed.
                .setMaxUpdateDelayMillis(0)
                .setWaitForAccurateLocation(false)
                .build()

            client.requestLocationUpdates(request, fusedCallback, Looper.getMainLooper())
            fusedRegistered = true
            true
        } catch (e: SecurityException) {
            Log.w(TAG, "Location permission missing for fused updates")
            false
        } catch (e: Exception) {
            // Play Services missing, disabled, or too old.
            Log.w(TAG, "Fused updates unavailable: ${e.message}")
            false
        }
    }

    /**
     * Accept a raw provider update.
     *
     * Both providers report independently, and the network provider almost always
     * answers first with something like 100 m accuracy while GPS is still settling.
     * Committing that first arrival — which is what the original throttle did —
     * meant the good GPS fix landing seconds later was discarded as "too soon", and
     * the recorded route ended up made almost entirely of coarse network fixes.
     *
     * So an arrival does not commit immediately. It opens a short settle window and
     * becomes the candidate; anything more accurate arriving inside that window
     * replaces it, and the best of them is committed when the window closes.
     */
    /**
     * How often a position should actually be recorded right now.
     *
     * Normally the employee's configured interval in minutes. While an admin is
     * watching them on the live map it drops to a few seconds — the route is not the
     * point then; seeing them move is. The follow window carries its own expiry, so
     * this returns to the normal interval by itself.
     */
    private fun reportIntervalMs(): Long {
        val live = Prefs.liveSeconds(this)
        if (live > 0) return live * 1000L
        return Prefs.intervalMinutes(this) * 60_000L
    }

    private fun handleLocation(location: Location) {
        if (!isTrackingSessionActive()) return
        if (!withinShift() && !forceFix) {
            return
        }

        if (!GeoFence.validCoords(location.latitude, location.longitude)) return

        // Cached fixes are worse than no fix: they are stamped with the current time but
        // describe where the phone was minutes ago, which shows up as a jump away and
        // back with nothing having moved.
        val fixAge = System.currentTimeMillis() - location.time
        if (location.time > 0 && fixAge > STALE_FIX_MS) {
            Log.d(TAG, "Ignoring a fix ${fixAge / 1000}s old from ${location.provider}")
            return
        }

        val intervalMs = reportIntervalMs()
        val now = System.currentTimeMillis()
        val since = now - Prefs.lastFixAt(this)

        // A stationary phone stores a heartbeat, not a stream.
        //
        // This is the path a live-follow window drives, and it samples every few seconds so
        // the admin's map is genuinely live. That is right for a phone in a vehicle and
        // wrong for one sitting on a table: at +/-30 m indoors each of those fixes lands
        // somewhere different, and storing them all is what drew the scribble the route map
        // was reported for. Movement is still recorded at the full live rate, because speed
        // tells the two apart.
        //
        // A heartbeat still gets through, so "last seen" stays fresh and an employee cannot
        // disappear from the live map by keeping still. An admin's explicit locate request
        // (forceFix) is never suppressed: it was asked for.
        // Whether the phone is moving, decided by the accelerometer where possible.
        //
        // This is the measurement everything else was standing in for. Comparing coordinates
        // cannot answer it — indoors two consecutive fixes are routinely tens or hundreds of
        // metres apart — and Doppler speed is missing exactly on the wifi-derived fixes that
        // land furthest away. The motion coprocessor reports STILL directly, which is what
        // delivery apps gate on, and it overrides a forced fix from the geofence logic:
        // somebody who has not moved has not crossed a boundary, whatever the coordinates
        // claim. An explicit request from a person is still honoured.
        val stillByMotion = ActivityMonitor.isStill(this)

        if (since in 1 until STILL_HEARTBEAT_MS &&
            ((stillByMotion && !forcedByAdmin) || (!forceFix && isStationary(location)))
        ) {
            return
        }

        // Not yet due for the next sample, and no window is open: ignore. An admin's
        // locate request overrides this — the whole point of it is to get a position
        // between scheduled samples.
        if (!forceFix && Prefs.lastFixAt(this) > 0 && since < intervalMs * 0.8 && candidate == null) return

        val existing = candidate
        if (existing != null) {
            // A window is open. Keep whichever fix is more accurate; a fix with no
            // accuracy at all never displaces one that has it.
            val newAcc = if (location.hasAccuracy()) location.accuracy else Float.MAX_VALUE
            val oldAcc = if (existing.hasAccuracy()) existing.accuracy else Float.MAX_VALUE
            if (newAcc < oldAcc) {
                candidate = location
                updateStatus("Improving fix — ±${newAcc.toInt()} m")
            }
            return
        }

        candidate = location

        // Close the window early once the fix is already good enough; there is
        // nothing to gain from waiting for GPS that has evidently already arrived.
        val goodEnough = location.hasAccuracy() && location.accuracy <= maxAccuracyM()

        if (!goodEnough && location.provider != LocationManager.GPS_PROVIDER) {
            // The network provider has answered with something coarse. Rather than
            // just hoping the periodic GPS sample lands inside the window, ask GPS
            // directly — at a 5-minute interval GPS otherwise gets one attempt per
            // point, and a single cold start that fails to fix costs the whole
            // interval. Soliciting here means GPS is only powered up at the moment a
            // point is actually due.
            solicitGpsFix()
        }

        handler.postDelayed(
            { commitCandidate() },
            if (goodEnough) 0L else SETTLE_WINDOW_MS
        )
    }

    /**
     * Record a watch fix if it represents movement worth storing.
     *
     * Three gates, and each exists for a symptom seen on a real map:
     *
     *  - **Moved far enough.** A fix within `move_min_m` of the last recorded point is the
     *    same place; storing it is what produced the star of criss-crossing lines around a
     *    stationary employee.
     *  - **Bigger than the error.** A 20 m step means nothing when both fixes admit to
     *    ±50 m, so the threshold is the larger of the two. Without this the "movement"
     *    being recorded is the receiver's noise.
     *  - **Not too often.** `min_gap_seconds` is the floor that bounds how much is stored;
     *    without it a fast road would record every three seconds.
     *
     * The periodic `tracking_interval_min` sample is untouched and still runs. It is the
     * heartbeat: a stationary employee still produces the occasional point, so "was on site
     * all afternoon" stays provable rather than being an absence of evidence.
     */
    /**
     * Is the phone standing still?
     *
     * Speed rather than displacement, because displacement cannot answer this indoors: at
     * +/-40 m two consecutive fixes from a phone on a table are routinely 40 m apart, so any
     * distance threshold is cleared by noise. Speed comes from Doppler shift on the carrier
     * rather than from comparing positions, so a stationary receiver reports approximately
     * zero even while its coordinates wander.
     *
     * A fix with no speed is treated as stationary, which is the opposite of what this used
     * to do. The reasoning changed with the evidence: indoors the fused provider falls back
     * to wifi and cell positioning, and those fixes carry no speed AND land hundreds of
     * metres away. Treating "no speed" as movement therefore admitted precisely the least
     * trustworthy positions — the ones that put a stationary employee three streets over.
     * Nothing is lost by refusing them: a real journey produces GPS fixes with speed, and
     * the heartbeat records presence regardless.
     */
    private fun isStationary(location: Location): Boolean =
        !location.hasSpeed() || location.speed < STILL_SPEED_MS

    private fun considerForTrack(location: Location) {
        // A scheduled sample is mid-settle. Committing over it would throw away the
        // accuracy contest that window exists to run.
        if (candidate != null) return
        if (forceFix) return
        if (!GeoFence.validCoords(location.latitude, location.longitude)) return

        // Off shift this listener should not be running at all, and fixes are dropped.
        if (!withinShift()) return

        val now = System.currentTimeMillis()
        val sinceLast = now - Prefs.lastFixAt(this)
        if (Prefs.lastFixAt(this) > 0 && sinceLast < Prefs.minGapSeconds(this) * 1000L) return

        // The receiver's own verdict on whether the phone is moving, which is the one
        // measurement that survives indoors.
        //
        // Distance alone is not enough and this was the gap that left jitter on the map: a
        // phone sitting on a table indoors reports fixes ±40 m apart, so any threshold built
        // from displacement and reported accuracy is cleared by noise — and reported accuracy
        // understates real indoor error, so raising it does not close the hole either. A
        // stationary phone, though, reports a speed of essentially zero even while its
        // coordinates wander, because speed is derived from Doppler shift rather than from
        // comparing positions. So speed is what decides "is this movement", and displacement
        // only decides "is it far enough to be worth a point".
        if (isStationary(location)) {
            return
        }

        // A fix cannot demonstrate a 25 m move when it is itself only good to 80 m. These
        // are the wifi and cell-derived positions, and drawing a route through them is what
        // put a stationary phone on the far side of a ring road. They are still recorded by
        // the heartbeat — presence is worth knowing even when the position is vague — but
        // they never contribute a movement point.
        val accuracyM = if (location.hasAccuracy()) location.accuracy else Float.MAX_VALUE
        if (accuracyM > ROUTE_ACCURACY_CEILING_M) {
            if (System.currentTimeMillis() - lastDeadbandLogAt > 30_000L) {
                lastDeadbandLogAt = System.currentTimeMillis()
                Log.d(TAG, "Not recorded: +/-${accuracyM.toInt()}m is too coarse to plot " +
                    "movement (ceiling ${ROUTE_ACCURACY_CEILING_M.toInt()}m)")
            }
            return
        }

        val previous = Prefs.lastRecordedPosition(this)
        if (previous != null) {
            val moved = GeoFence.haversine(
                previous.first, previous.second, location.latitude, location.longitude
            )
            val accuracy = if (location.hasAccuracy()) location.accuracy.toDouble() else 0.0

            // 1.5x the error, not 1x. At the same order of magnitude as its own uncertainty
            // a displacement is as likely to be noise as movement, and recording it is how
            // a stationary phone drew a path.
            val threshold = maxOf(Prefs.moveMinM(this), accuracy * 1.5)

            if (moved < threshold) {
                // Logged, rate-limited, because "why is there still jitter" is otherwise
                // unanswerable from the outside: this turns it into a measurement.
                if (now - lastDeadbandLogAt > 30_000L) {
                    lastDeadbandLogAt = now
                    Log.d(TAG, "Not recorded: moved ${moved.toInt()}m, needs " +
                        "${threshold.toInt()}m (+/-${accuracy.toInt()}m, " +
                        "speed ${if (location.hasSpeed()) "%.1f".format(location.speed) else "?"} m/s)")
                }
                return
            }
        }

        // Committed straight away rather than through the settle window: the whole point is
        // that this fix is current, and the watch will offer another in a few seconds if
        // this one is poor.
        candidate = location
        commitCandidate(uploadNow = false)
    }

    /**
     * Ask GPS for one fix now. Anything it returns goes through handleLocation, so
     * it competes for the open settle window on accuracy like any other arrival.
     */
    private fun solicitGpsFix() {
        // Fused first: one call, and it uses whatever gives the best answer here rather
        // than insisting on the GPS chip, which indoors returns nothing at all.
        try {
            com.google.android.gms.location.LocationServices
                .getFusedLocationProviderClient(this)
                .getCurrentLocation(
                    com.google.android.gms.location.CurrentLocationRequest.Builder()
                        .setPriority(
                            com.google.android.gms.location.Priority.PRIORITY_HIGH_ACCURACY
                        )
                        .setMaxUpdateAgeMillis(0)     // a fresh fix, not the cached one
                        .setDurationMillis(20_000L)
                        .build(),
                    null
                )
                .addOnSuccessListener { location -> location?.let { handleLocation(it) } }
            return
        } catch (e: SecurityException) {
            // Permission revoked mid-shift; the periodic registration reports it.
            return
        } catch (e: Exception) {
            Log.w(TAG, "Fused one-shot unavailable, trying GPS directly: ${e.message}")
        }

        if (!locationManager.allProviders.contains(LocationManager.GPS_PROVIDER)) return

        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                locationManager.getCurrentLocation(
                    LocationManager.GPS_PROVIDER,
                    null,
                    mainExecutor
                ) { location ->
                    // Null means GPS could not fix in time — normal indoors, and the
                    // coarse candidate already in hand is then the best available.
                    if (location != null) handleLocation(location)
                }
            } else {
                @Suppress("DEPRECATION")
                locationManager.requestSingleUpdate(
                    LocationManager.GPS_PROVIDER,
                    locationListener,
                    Looper.getMainLooper()
                )
            }
        } catch (e: SecurityException) {
            // Permission revoked mid-shift; the periodic registration reports it.
        } catch (e: Exception) {
            Log.w(TAG, "Could not solicit a GPS fix: ${e.message}")
        }
    }

    /**
     * Live board ping has been disabled. No location requests are made outside
     * active shift attendance.
     */
    private fun maybeLivePing() {
        // Disabled: Live board testing feature removed. No off-shift pings.
    }

    private fun requestLivePingFix() {
        // Disabled: No live ping fix requests.
    }

    /** A live-board fix goes straight to the server, never to the route queue. */
    private fun postLivePing(location: Location) {
        if (!GeoFence.validCoords(location.latitude, location.longitude)) return

        val isMock = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            location.isMock
        } else {
            @Suppress("DEPRECATION")
            location.isFromMockProvider
        }

        val now = System.currentTimeMillis()
        val payload = JSONObject()
            .put("lat", location.latitude)
            .put("lng", location.longitude)
            .put("battery", batteryPercent())
            .put("is_mock", isMock)
            .put("recorded_at", livePingStamp(if (location.time > 0 && kotlin.math.abs(now - location.time) <= STALE_FIX_MS) location.time else now))
        if (location.hasAccuracy()) {
            payload.put("accuracy", location.accuracy.toDouble())
        }

        Thread {
            val response = ApiClient.post(this, "live-ping", payload)
            if (response.ok) {
                Prefs.setLastLivePingAt(this, System.currentTimeMillis())
            } else if (response.sessionDead) {
                Prefs.setTrackingWanted(this, false)
                Prefs.setToken(this, null)
                handler.post { shutdown() }
            }
        }.start()
    }

    private fun livePingStamp(at: Long): String {
        return java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US)
            .format(java.util.Date(at))
    }

    /**
     * Immediate board copy of a fix, while somebody watches live.
     *
     * Fire-and-forget on its own thread: the commit path runs on the main
     * thread and must never block on the network. Writes only to live-ping
     * (one row per employee, always overwritten) — no new stored history.
     */
    private fun mirrorFixToBoard(fix: Fix) {
        val payload = JSONObject()
            .put("lat", fix.lat)
            .put("lng", fix.lng)
            .put("is_mock", fix.isMock)
            .put("recorded_at", livePingStamp(fix.timeMillis))
        fix.accuracy?.let { payload.put("accuracy", it) }
        fix.batteryPct?.let { payload.put("battery", it) }
        Thread {
            ApiClient.post(this, "live-ping", payload)
        }.start()
    }

    /** The accuracy the employee's rules demand, defaulting to 50 m. */
    private fun maxAccuracyM(): Float {
        val settings = Prefs.config(this).optJSONObject("settings") ?: return 50f
        return settings.optInt("max_accuracy_m", 50).toFloat()
    }

    /** Write the best fix seen in the settle window to the queue. */
    /**
     * @param uploadNow whether to send immediately. True for the scheduled sample, which is
     *   rare enough that one request per point is the right trade. False for the movement
     *   driven points: at a 20-second cadence uploading each one would be thirty times the
     *   requests, on a phone that is often on mobile data. They ride along with the next
     *   crossing, the next scheduled point, or the periodic tick — a few minutes of latency
     *   on route detail nobody is watching in real time.
     */
    private fun commitCandidate(uploadNow: Boolean = true) {
        if (!isTrackingSessionActive()) {
            candidate = null
            return
        }
        val location = candidate ?: return
        candidate = null

        if (!withinShift() && !forceFix) return

        // The accuracy ceiling, actually enforced.
        //
        // It used to decide only *when* to commit — a fix at or under it committed at
        // once, anything worse committed after the settle window anyway. So the setting
        // read like a quality standard while nothing was ever rejected, and the coarse
        // network fixes that arrive first ended up in the route beside GPS fixes forty
        // times more precise. Rather than commit one, drop it: the next arrival opens a
        // fresh window and GPS gets another attempt.
        // Only genuinely useless fixes are refused now.
        //
        // The previous version rejected anything worse than the configured ceiling and
        // rejected non-GPS fixes outright while GPS was alive. Both were aimed at the
        // wrong layer: the complaint they were meant to fix was wobble on the map, and
        // what they actually produced was "not getting location" — indoors, where GPS is
        // absent and every fix is coarse, almost nothing was recorded.
        //
        // Accuracy is not a reason to throw a position away. It is the thing that decides
        // how much weight the position carries, and the two places that matters — the
        // inside/outside verdict and the distance travelled — already use it. The fused
        // provider now does the filtering that this code was attempting badly.
        val accuracy = if (location.hasAccuracy()) location.accuracy else null
        val hardCap = maxOf(NEVER_RECORD_ABOVE_M, maxAccuracyM())
        if (accuracy != null && accuracy > hardCap && !forceFix) {
            Log.d(TAG, "Discarding a ±${accuracy.toInt()} m fix — beyond any use")
            return
        }

        val now = System.currentTimeMillis()
        // Judged against every assigned area: inside any of them is on site.
        val verdict = GeoFence.evaluateAll(
            location.latitude, location.longitude,
            Prefs.geofences(this), Prefs.geofenceBufferM(this)
        )

        val isMock = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            location.isMock
        } else {
            @Suppress("DEPRECATION")
            location.isFromMockProvider
        }

        // A second opinion on where the phone really is, taken only when the reported
        // fix is simulated. The raw providers are asked for their last known position;
        // one that is itself not simulated and reasonably fresh is treated as genuine.
        // This works against fake-GPS apps that override only the fused provider - the
        // common case - and fails silently against ones that override everything, which
        // is why the fields are nullable rather than promised.
        var realLat: Double? = null
        var realLng: Double? = null
        if (isMock) {
            for (provider in listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)) {
                try {
                    val candidate2 = locationManager.getLastKnownLocation(provider) ?: continue
                    val candidateMock = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                        candidate2.isMock
                    } else {
                        @Suppress("DEPRECATION")
                        candidate2.isFromMockProvider
                    }
                    val age = now - candidate2.time
                    if (!candidateMock && age in 0..STALE_FIX_MS &&
                        GeoFence.validCoords(candidate2.latitude, candidate2.longitude)
                    ) {
                        realLat = candidate2.latitude
                        realLng = candidate2.longitude
                        break
                    }
                } catch (e: SecurityException) {
                    break
                } catch (e: Exception) {
                    // Provider missing; try the next.
                }
            }
        }

        val fix = Fix(
            lat = location.latitude,
            lng = location.longitude,
            accuracy = if (location.hasAccuracy()) location.accuracy.toDouble() else null,
            altitude = if (location.hasAltitude()) location.altitude else null,
            speedKmh = if (location.hasSpeed()) location.speed * 3.6 else null,
            heading = if (location.hasBearing()) location.bearing.toDouble() else null,
            batteryPct = batteryPercent(),
            isCharging = isCharging(),
            isMock = isMock,
            provider = location.provider,
            // The moment the fix describes, not the moment it was committed. Those differ
            // by up to the settle window, and stamping the later time stretched every leg's
            // duration and understated its speed — a 25 s error is a quarter of the gap
            // between points now that recording follows movement. A clock that is obviously
            // wrong is ignored; the server clamps timestamps anyway, so the device's clock
            // is never the authority for attendance.
            timeMillis = if (location.time > 0 && kotlin.math.abs(now - location.time) <= STALE_FIX_MS) {
                location.time
            } else {
                now
            },
            insideFence = verdict.inside,
            distanceM = verdict.distanceM,
            // Only meaningful on an outside point; inside always resets it.
            outsideStreak = if (verdict.inside) 0 else outsideStreak,
            realLat = realLat,
            realLng = realLng
        )

        queue.enqueue(fix)
        Prefs.setLastFixAt(this, now)
        Prefs.setLastRecordedPosition(this, location.latitude, location.longitude)
        lastFixAt = now
        forceFix = false
        forcedByAdmin = false

        // Board-follow mirror: while an admin watches this person live, every
        // fix also goes straight to the board channel. The queue batches and
        // holds points back minutes; this post is immediate. Main flow untouched
        // — the fix still rides the normal route path below.
        if (Prefs.liveSeconds(this) > 0) {
            mirrorFixToBoard(fix)
        }

        // One line per recorded point. Accuracy complaints are the hardest thing to
        // diagnose from the outside — "the location is jumping" could be the provider,
        // the filtering, or the boundary — and this turns the answer into a measurement
        // instead of a guess. Coordinates are deliberately not logged; provider and
        // accuracy are what the question is ever about.
        Log.i(TAG, "Recorded ${location.provider} +/-${accuracy?.toInt() ?: -1}m " +
            "${if (verdict.inside) "inside" else "outside"} ${verdict.distanceM}m")

        // A crossing is worth uploading immediately: the admin's "outside area now"
        // tile is only useful if it is actually current.
        val previous = Prefs.lastInside(this)
        val crossed = previous != null && previous != verdict.inside
        Prefs.setLastInside(this, verdict.inside)

        updateStatus(
            // Names whichever area matched, which is the point of allowing several.
            if (verdict.inside) "Inside ${verdict.areaName ?: "work area"}"
            else "Outside work area — ${verdict.distanceM} m"
        )

        // Upload straight away for a scheduled point or a crossing.
        //
        // This used to wait for a fence crossing or a full 200-point batch, and
        // otherwise leave the point for the periodic tick. Because capture and the
        // tick run on independent cycles, that left one or two points sitting in the
        // queue essentially all the time — which reads to the employee as "waiting to
        // upload", i.e. as though background tracking were broken, when in fact the fix
        // had been captured perfectly well. So the scheduled point still goes at once:
        // it keeps the queue at zero in normal operation, so a non-zero count means
        // something is genuinely wrong, and it keeps the admin's live map actually live.
        //
        // Movement-driven points are the exception. They arrive as often as every twenty
        // seconds while somebody is driving, and one request each would be thirty times
        // the traffic for detail that is reviewed hours later. They wait — but never
        // long: a crossing, the next scheduled point, or a queue worth sending flushes
        // them, and the alarm path does not depend on an upload at all.
        val queued = queue.count()
        if (uploadNow || crossed || queued >= UPLOAD_WHEN_QUEUED) {
            Thread { upload() }.start()
        }

        if (crossed) {
            Log.i(TAG, "Fence crossing committed; uploaded immediately")
        }
    }

    /* ---------------- shift window ---------------- */

    /**
     * Mirrors attTrackingActive() on the server, including the 30-minute lead-in so an
     * employee arriving early is already being recorded.
     *
     * The window extends past the rostered end while the employee is still checked in.
     * Without that the service stood down half an hour after the shift was scheduled
     * to finish, so anyone working late had their overtime recorded as a blank — the
     * roster's opinion of when work ended overruled the fact that they were still
     * clocked in and still on site.
     */
    private fun withinShift(): Boolean {
        val config = Prefs.config(this)
        val settings = config.optJSONObject("settings") ?: return false

        val start = parseTime(settings.optString("shift_start", "08:00")) ?: return false
        val scheduledEnd = parseTime(settings.optString("shift_end", "17:00")) ?: return false
        val overnight = settings.optBoolean("overnight_shift", false) || scheduledEnd <= start

        // Kept in minutes-of-day arithmetic like the rest of this function; the
        // comparisons below already handle a value that runs past 24 h.
        val end = if (Prefs.shiftOpen(this)) {
            scheduledEnd + Prefs.maxOvertimeHours(this) * 60
        } else {
            scheduledEnd
        }

        val now = Calendar.getInstance()
        val minuteOfDay = now.get(Calendar.HOUR_OF_DAY) * 60 + now.get(Calendar.MINUTE)
        val lead = 30

        // Calendar.MONDAY is 2; the server uses ISO days where Monday is 1.
        val isoToday = ((now.get(Calendar.DAY_OF_WEEK) + 5) % 7) + 1
        val isoYesterday = if (isoToday == 1) 7 else isoToday - 1

        val workDays = mutableListOf<Int>()
        val raw = settings.optJSONArray("work_days")
        if (raw != null) {
            for (i in 0 until raw.length()) workDays.add(raw.optInt(i))
        }
        if (workDays.isEmpty()) workDays.addAll(listOf(1, 2, 3, 4, 5, 6))

        return if (!overnight) {
            workDays.contains(isoToday) &&
                minuteOfDay >= (start - lead) && minuteOfDay <= (end + lead)
        } else {
            // An overnight shift spans midnight, so the evening half belongs to today
            // and the morning half to yesterday's working day.
            (workDays.contains(isoToday) && minuteOfDay >= (start - lead)) ||
                (workDays.contains(isoYesterday) && minuteOfDay <= (end + lead))
        }
    }

    private fun parseTime(value: String): Int? {
        return try {
            val parts = value.split(":")
            parts[0].toInt() * 60 + parts[1].toInt()
        } catch (e: Exception) {
            null
        }
    }

    /* ---------------- upload ---------------- */

    /** True only while a checked-in shift is still open and this service is wanted. */
    private fun isTrackingSessionActive(): Boolean =
        isRunning && Prefs.trackingWanted(this) && Prefs.shiftOpen(this) && withinShift()


    @Synchronized
    private fun upload() {
        if (!isTrackingSessionActive()) return
        val token = Prefs.token(this) ?: return

        val (ids, points) = try {
            queue.peekBatch()
        } catch (e: Exception) {
            Log.w(TAG, "Could not read queue: ${e.message}")
            return
        }

        if (ids.isEmpty()) {
            sendHeartbeat()
            return
        }

        val payload = JSONObject().put("points", points)
        val response = ApiClient.post(this, "locations/batch", payload)

        when {
            response.ok -> {
                queue.delete(ids)
                Prefs.setLastUploadAt(this, System.currentTimeMillis())

                val data = response.body?.optJSONObject("data")
                // The server is the authority on the current interval: an admin can
                // change it while the shift is running.
                data?.optInt("tracking_interval_min", 0)?.let { serverInterval ->
                    if (serverInterval > 0 && serverInterval != Prefs.intervalMinutes(this)) {
                        val config = Prefs.config(this)
                        config.optJSONObject("settings")
                            ?.put("tracking_interval_min", serverInterval)
                        Prefs.setConfig(this, config.toString())
                        requestUpdates()
                    }
                }

                // An upload is also a chance to learn that somebody started watching,
                // without waiting out a whole poll cycle.
                data?.let { handler.post { applyLiveWindow(it) } }

                // The server is also the authority on whether the current departure
                // has been explained: the employee may have answered on a screen this
                // service knows nothing about, and it must not keep alarming them.
                val outside = data?.optJSONObject("currently_outside")
                if (outside != null) {
                    Prefs.setOutsideReasonGiven(this, outside.optBoolean("has_reason", false))
                } else {
                    // No open departure, so the next one is a fresh question.
                    Prefs.setOutsideReasonGiven(this, false)

                    // And forget having announced a departure at all.
                    //
                    // alertedOutside is a latch that stops the alarm repeating while
                    // somebody stays outside, and it is only cleared by *observing* a
                    // return inside. If tracking stops while they are still outside —
                    // end of shift, check-out, the app killed, the phone off — nothing
                    // ever observes that return, so the latch stays stuck at "outside"
                    // and every later departure is treated as a state it has already
                    // announced. No sound, and no reason prompt either, because the
                    // broadcast that raises it sits behind the same check.
                    //
                    // The server is the authority on whether a departure is open — but a
                    // departure it has not CONFIRMED YET is not one that is over. A trip
                    // only opens after several credible outside points, and in the window
                    // between the alarm sounding and the trip opening the server honestly
                    // answers "no open departure". Clearing the latch on that answer
                    // re-armed the alarm seconds after it rang, and it rang again, and
                    // again, until the trip finally opened. So the stale-latch release
                    // now also requires the device's own last verdict to not be
                    // "outside": a latch is only stale when nobody is standing past the
                    // fence any more.
                    if (Prefs.lastInside(this) != false) {
                        Prefs.clearAlertedOutside(this)
                    }
                }

                // Cleared on success. Leaving the failure text in place is why the
                // notification kept saying "Waiting for a connection" minutes after
                // everything had uploaded - stating the opposite of the truth in the
                // one place meant to reassure the employee.
                updateStatus(if (withinShift()) "On duty" else "Off shift")
                handler.post { broadcastStatus() }

                // More waiting than one batch holds: keep going while the link is up.
                if (queue.count() > 0) upload()
            }

            response.sessionDead -> {
                // An admin released the device, or the session expired. Stop rather
                // than hammer the server with a token that will never work again.
                Log.i(TAG, "Session no longer valid (${response.errorCode}); stopping.")
                Prefs.setTrackingWanted(this, false)
                Prefs.setToken(this, null)
                updateStatus("Signed out — open the app")
                shutdown()
            }

            response.code == 0 -> {
                // Offline. The queue is intact; the fast retry below drains it as soon as
                // a connection exists. No count is shown — how many points are waiting is
                // the app's problem to solve, not something to hand the employee.
                updateStatus("Waiting for a connection")
                scheduleRetry()
            }

            else -> {
                // A 4xx/5xx that is not an auth problem: keep the points, do not spin.
                Log.w(TAG, "Upload rejected: HTTP ${response.code} ${response.errorCode}")
                updateStatus("Retrying")
                scheduleRetry()
            }
        }
    }

    /**
     * Sent when there is nothing to upload, so the server can still tell a genuinely
     * stationary employee apart from a device whose service has been killed.
     */
    private fun sendHeartbeat() {
        if (!isTrackingSessionActive()) return
        val payload = JSONObject()
            .put("app_version", BuildConfig.VERSION_NAME)
            .put("battery", batteryPercent())
            .put("is_charging", isCharging())
            .put("tracking_running", true)

        val response = ApiClient.post(this, "heartbeat", payload)
        if (response.sessionDead) {
            Prefs.setTrackingWanted(this, false)
            Prefs.setToken(this, null)
            shutdown()
        }
    }

    /* ---------------- periodic tick ---------------- */

    /**
     * A slow watchdog. Its job is not to collect locations — the provider does that —
     * but to notice that the shift has ended, that a queued batch needs retrying, or
     * that the provider has gone quiet and needs re-registering.
     */
    private fun startTicking() {
        tickRunnable?.let { handler.removeCallbacks(it) }

        val runnable = object : Runnable {
            override fun run() {
                if (!isRunning) return

                if (!Prefs.trackingWanted(this@TrackingService)) {
                    shutdown()
                    return
                }

                // Off shift: GPS is immediately deregistered so zero location data is collected.
                // No route recording, no geofencing, and no background pings outside working hours.
                if (!withinShift()) {
                    Thread {
                        upload()
                        handler.post {
                            if (registeredIntervalMin != 0) {
                                releaseLocationUpdates()
                                updateStatus("Off shift")
                            }
                            // Kept ticking so the poll loop resumes automatically on the next shift.
                            tickRunnable?.let { handler.postDelayed(it, tickIntervalMs()) }
                        }
                    }.start()
                    return
                }

                if (registeredIntervalMin == 0) {
                    requestUpdates()
                }

                val silentFor = System.currentTimeMillis() - Prefs.lastFixAt(this@TrackingService)
                val intervalMs = Prefs.intervalMinutes(this@TrackingService) * 60_000L
                if (Prefs.lastFixAt(this@TrackingService) > 0 && silentFor > intervalMs * 3) {
                    // The provider has stopped delivering; re-register it.
                    Log.w(TAG, "No fix for ${silentFor / 60000} min — re-registering providers")
                    requestUpdates()
                }

                syncWatchState()

                Thread { upload() }.start()
                handler.postDelayed(this, tickIntervalMs())
            }
        }

        tickRunnable = runnable
        handler.postDelayed(runnable, tickIntervalMs())
    }

    /** Ticks at the ping interval, but never more often than every two minutes. */
    private fun tickIntervalMs(): Long =
        maxOf(2L, Prefs.intervalMinutes(this).toLong()) * 60_000L

    /* ---------------- fast breach watch ---------------- */

    /**
     * Watch for leaving the work area on a much shorter cycle than recording.
     *
     * Recording every few seconds would bloat the route table thirty-fold for no
     * analytical gain, so this listener's fixes are used only to judge inside vs
     * outside and are then dropped. When a crossing is confirmed it forces a real
     * recorded point, alerts the employee, and uploads at once — the admin's map and
     * the employee's warning both need to be immediate, not five minutes late.
     */
    /**
     * Whether leaving the area is worth watching for at this moment.
     *
     * The decisive condition is that the employee has actually checked in. The roster
     * says when they are *expected*; the register says whether they are *at work*. On
     * a day off, before they arrive, or after they have clocked off, telling somebody
     * they have left their work area is nonsense — they are not at work, and being
     * asked to justify their own free time is worse than useless.
     */
    private fun shouldWatch(): Boolean =
        Prefs.watchSeconds(this) > 0 &&
            Prefs.shiftOpen(this) &&
            withinShift() &&
            Prefs.geofences(this).isNotEmpty()

    /**
     * Bring the watch into line with whether it should be running.
     *
     * Called whenever the state it depends on can have changed — a check-in, a
     * check-out, a new tick — because the listener is registered once and would
     * otherwise keep burning GPS after the employee has gone home.
     */
    private fun syncWatchState() {
        val wanted = shouldWatch()
        if (wanted && watchListener == null) {
            startWatching()
        } else if (!wanted && watchListener != null) {
            stopWatching()
            clearAlert()
            stopAlertSound()
            // Nothing is being watched, so nothing has been announced. Without this a
            // check-in the next morning starts with a stale "already told them they
            // are outside" and the first genuine departure passes in silence.
            Prefs.clearAlertedOutside(this)
            Log.i(TAG, "Breach watch stood down — not checked in, or off shift")
        }
    }

    private fun startWatching() {
        stopWatching()

        watchSeconds = Prefs.watchSeconds(this)
        if (watchSeconds <= 0) {
            Log.i(TAG, "Breach watching disabled by settings")
            return
        }
        if (Prefs.geofences(this).isEmpty()) {
            // Nothing to be outside of.
            return
        }
        if (!Prefs.shiftOpen(this)) {
            // Not checked in: there is no shift to be absent from.
            return
        }

        val listener = object : LocationListener {
            override fun onLocationChanged(location: Location) = evaluateBreach(location)
            override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
            override fun onProviderEnabled(provider: String) {}
            override fun onProviderDisabled(provider: String) {}
        }

        // Both providers, not GPS alone.
        //
        // Restricting this to GPS on the grounds that a ±100 m network fix cannot
        // resolve a fence boundary left the watch completely blind indoors, where raw
        // GPS returns nothing at all — so the warning never fired for someone sitting
        // in a building kilometres from their site. Being far outside is trivially
        // detectable with a coarse fix; the near-boundary case is handled by the
        // credibility rule in evaluateBreach, which is where it belongs.
        var registered = false

        // Fused first, and this is the important part.
        //
        // When the recording path moved to the fused provider this watch was left on the
        // raw ones, and the two do not see the same fixes. Everything the service records
        // arrives from "flp" — fused — while the watch sat listening to GPS and NETWORK,
        // which on this handset deliver nothing. So the log said "Breach watch active",
        // the map showed the employee 123 m outside, and no alarm ever fired because
        // evaluateBreach was never called with anything. A fake-GPS app makes it worse:
        // most override only the fused provider, so the raw ones stay silent by design.
        try {
            fusedWatchClient = com.google.android.gms.location.LocationServices
                .getFusedLocationProviderClient(this)

            val request = com.google.android.gms.location.LocationRequest.Builder(
                com.google.android.gms.location.Priority.PRIORITY_HIGH_ACCURACY,
                watchSeconds * 1000L
            )
                // The desired rate stays at watchSeconds, but updates may arrive as
                // fast as every 3s when the position is genuinely changing. With the
                // floor at the full interval, the second confirmation of a departure
                // could not arrive sooner than 10s after the first, so the alarm
                // trailed the crossing by 10-20s depending on where in the cycle it
                // happened - which read as "sometimes instant, sometimes not".
                .setMinUpdateIntervalMillis(3_000L)
                .setMaxUpdateDelayMillis(0)
                .build()

            fusedWatchCallback = object : com.google.android.gms.location.LocationCallback() {
                override fun onLocationResult(result: com.google.android.gms.location.LocationResult) {
                    result.lastLocation?.let {
                        evaluateBreach(it)
                        // These fixes used to be thrown away, which is why a route was a
                        // set of straight chords between distant samples: at a 10-minute
                        // interval a vehicle covers kilometres between recorded points and
                        // no drawing can put the corners back. The watch is already
                        // acquiring a position every few seconds and the battery is already
                        // being spent on it, so keeping the ones that represent real
                        // movement is close to free.
                        considerForTrack(it)
                    }
                }
            }

            fusedWatchClient?.requestLocationUpdates(
                request, fusedWatchCallback!!, Looper.getMainLooper()
            )
            registered = true
        } catch (e: SecurityException) {
            Log.w(TAG, "No permission for the fused breach watch")
        } catch (e: Exception) {
            Log.w(TAG, "Fused breach watch unavailable: ${e.message}")
        }

        // The raw providers as well, not instead: on a handset without Play Services they
        // are all there is, and where both work the extra fixes only sharpen the verdict.
        for (provider in listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)) {
            if (!locationManager.allProviders.contains(provider)) continue
            try {
                locationManager.requestLocationUpdates(
                    provider,
                    watchSeconds * 1000L,
                    0f,
                    listener,
                    Looper.getMainLooper()
                )
                registered = true
            } catch (e: SecurityException) {
                Log.w(TAG, "No permission for the breach watch on $provider")
            } catch (e: Exception) {
                Log.w(TAG, "Could not watch $provider: ${e.message}")
            }
        }

        if (registered) {
            watchListener = listener
            Log.i(TAG, "Breach watch active every ${watchSeconds}s")

            // Evaluate immediately from the last known position rather than waiting a
            // full cycle: if the employee is already outside when tracking starts,
            // they should be told now.
            evaluateLastKnown()
        }
    }

    /**
     * Judge the most recent cached position, best-accuracy first.
     *
     * Used at start-up so an employee who is already outside their area is warned
     * straight away instead of after the first live fix, which indoors may never come.
     */
    private fun evaluateLastKnown() {
        try {
            val candidates = listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
                .filter { locationManager.allProviders.contains(it) }
                .mapNotNull { locationManager.getLastKnownLocation(it) }
                // Ignore anything too old to describe where they are now.
                .filter { System.currentTimeMillis() - it.time < 5 * 60_000L }

            val best = candidates.minByOrNull {
                if (it.hasAccuracy()) it.accuracy else Float.MAX_VALUE
            }
            if (best != null) evaluateBreach(best)
        } catch (e: SecurityException) {
            // Permission revoked; the registration above already reported it.
        } catch (e: Exception) {
            Log.w(TAG, "Could not read the last known position: ${e.message}")
        }
    }

    private fun stopWatching() {
        // Released as well as the raw listener; leaving this registered would keep the
        // alarm evaluating after check-out and drain the battery at the watch interval.
        fusedWatchCallback?.let { callback ->
            try {
                fusedWatchClient?.removeLocationUpdates(callback)
            } catch (e: Exception) {
                // Already gone.
            }
        }
        fusedWatchCallback = null
        fusedWatchClient = null

        watchListener?.let {
            try {
                locationManager.removeUpdates(it)
            } catch (e: Exception) {
                // Already gone.
            }
        }
        watchListener = null
        outsideFirstSeenAt = 0L
        outsideCandidateCount = 0
    }

    /**
     * Judge one watch fix against the fence and act if the state has changed.
     *
     * Enhanced Geofence Accuracy & Verification:
     * 1. Accuracy Filter: Coarse fixes (> 40m) or fixes within the error margin of the boundary are discarded.
     * 2. Physical Sensor Motion Check: Verifies movement via Accelerometer & Gyroscope hardware sensors.
     * 3. Sudden Jump Spike Filter: Detects indoor multipath leaps (> 70m in < 20s) while stationary.
     * 4. Stationary Drift Filter: Rejects near-boundary drift (< 100m) from stationary phones indoors.
     * 5. Sustained 2 to 3 Minute Verification Window: Requires sustained outside fixes for 2.5 minutes (150s)
     *    and at least 5 consecutive credible checks before alerting the employee or asking for a reason.
     * 6. Instant Reset on Inside Fix: Any fix returning inside immediately cancels pending outside candidates.
     */
    private fun evaluateBreach(location: Location) {
        if (!withinShift()) return
        // Not checked in - a day off, before arrival, or already clocked off. There is
        // no work area to have left.
        if (!Prefs.shiftOpen(this)) return

        val areas = Prefs.geofences(this)
        if (areas.isEmpty()) return
        if (!GeoFence.validCoords(location.latitude, location.longitude)) return

        val isMock = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            location.isMock
        } else {
            @Suppress("DEPRECATION")
            location.isFromMockProvider
        }
        if (isMock && !Prefs.allowMockLocation(this)) {
            if (!mockAlerted) {
                mockAlerted = true
                alertMockDetected()
                playAlertSound()
            }
            Log.i(TAG, "Simulated location on an account not permitted one — employee warned")
            return
        }

        // A genuine fix means whatever was running has stopped; re-arm the warning.
        if (!isMock && mockAlerted) {
            mockAlerted = false
            try {
                (getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager)
                    .cancel(TAMPER_NOTIFICATION_ID)
            } catch (e: Exception) {
                // Nothing showing.
            }
        }

        val verdict = GeoFence.evaluateAll(
            location.latitude, location.longitude, areas, Prefs.geofenceBufferM(this)
        )
        val accuracy = if (location.hasAccuracy()) location.accuracy else null

        // 1. Accuracy & Sensor Drift/Jump Filtering
        if (!verdict.inside) {
            // Error margin check: coarse fixes (> 40m) or fixes where distance outside is within error radius
            if (accuracy != null && (accuracy > 40.0f || verdict.distanceM < accuracy * 1.25f)) {
                Log.d(TAG, "Outside fix rejected: coarse accuracy (±${accuracy.toInt()}m) or near boundary (${verdict.distanceM}m)")
                return
            }

            val moving = isDevicePhysicallyMoving(location)

            // Sudden Jump Filter: coordinates jumped sharply while sensors say stationary
            if (!moving && isSuddenJumpSpike(location)) {
                Log.d(TAG, "Outside fix rejected: sudden jump spike without physical motion")
                return
            }

            // Stationary Phone Drift Filter: phone on a desk drifting < 100m outside
            if (!moving && verdict.distanceM < 100.0) {
                Log.d(TAG, "Outside fix rejected: stationary phone near boundary (${verdict.distanceM}m) without motion")
                return
            }
        }

        // 2. User is INSIDE: Reset any pending outside candidate immediately
        if (verdict.inside) {
            lastInsideLocation = location
            outsideStreak = 0

            if (outsideFirstSeenAt != 0L) {
                Log.i(TAG, "Outside candidate cancelled: phone returned inside after $outsideCandidateCount fixes before confirmation window.")
                outsideFirstSeenAt = 0L
                outsideCandidateCount = 0
            }

            val alreadyAnnounced = Prefs.alertedOutside(this)
            if (alreadyAnnounced == true) {
                // Return inside after confirmed departure
                Prefs.setAlertedOutside(this, false)
                clearAlert()
                Prefs.setOutsideReasonGiven(this, false)
                updateStatus("Back inside ${verdict.areaName ?: "your work area"}")
                broadcastFence(false, 0, verdict.areaName ?: "your work area")

                // Force this crossing into the record immediately, and push it
                forceFix = true
                handler.post { handleLocation(location) }
            }
            return
        }

        // 3. User is OUTSIDE and passed accuracy/motion gates:
        // Must sustain outside state for 2 to 3 minutes (150 seconds) and >= 5 checks before announcing!
        outsideCandidateCount++
        val now = SystemClock.elapsedRealtime()

        if (outsideFirstSeenAt == 0L) {
            outsideFirstSeenAt = now
            Log.i(TAG, "Outside departure candidate detected at ${verdict.distanceM}m. Waiting 2.5 minutes and multiple checks before alerting...")
            return
        }

        val elapsedMs = now - outsideFirstSeenAt
        val waitRequiredMet = elapsedMs >= OUTSIDE_CONFIRMATION_WAIT_MS
        val checksMet = outsideCandidateCount >= MIN_OUTSIDE_CHECKS

        if (!waitRequiredMet || !checksMet) {
            Log.d(TAG, "Outside departure pending: ${elapsedMs / 1000}s / ${OUTSIDE_CONFIRMATION_WAIT_MS / 1000}s, fixes: $outsideCandidateCount / $MIN_OUTSIDE_CHECKS")
            return
        }

        // 4. Confirmed departure after 2 to 3 minutes:
        outsideStreak = outsideCandidateCount

        val alreadyAnnounced = Prefs.alertedOutside(this)
        if (alreadyAnnounced == true) {
            return // Already announced this departure
        }

        Prefs.setAlertedOutside(this, true)
        Log.i(TAG, "Outside departure CONFIRMED after ${elapsedMs / 1000}s and $outsideCandidateCount fixes. Alerting employee and prompting for reason.")

        if (!Prefs.outsideReasonGiven(this)) {
            alertLeftArea(verdict.areaName ?: "your work area", verdict.distanceM)
            // Ringtune alarm disabled: no audio alarm when outside the area.
        }

        broadcastFence(true, verdict.distanceM, verdict.areaName ?: "your work area")

        // Force this confirmed crossing into the record immediately, and push it
        forceFix = true
        handler.post { handleLocation(location) }
    }

    private fun broadcastFence(outside: Boolean, distanceM: Int, areaName: String) {
        sendBroadcast(Intent(BROADCAST_FENCE).apply {
            setPackage(packageName)
            putExtra("outside", outside)
            putExtra("distance_m", distanceM)
            putExtra("area_name", areaName)
        })
    }

    /* ---------------- alerts ---------------- */

    private fun createAlertChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (manager.getNotificationChannel(ALERT_CHANNEL_ID) != null) return

        // A separate channel from the ongoing tracking notice, at HIGH importance so
        // it can make a sound and appear as a heads-up. The employee needs to notice
        // this one; the tracking notice is deliberately silent.
        val channel = NotificationChannel(
            ALERT_CHANNEL_ID,
            "Leaving your work area",
            NotificationManager.IMPORTANCE_DEFAULT
        ).apply {
            description = "Informs you when leaving your assigned work area."
            setSound(null, null)
            enableVibration(false)
            setShowBadge(true)
        }
        manager.createNotificationChannel(channel)
    }

    private fun alertLeftArea(areaName: String, distanceM: Int) {
        updateStatus("Outside $areaName — $distanceM m")

        val open = PendingIntent.getActivity(
            this, 1,
            Intent(this, MainActivity::class.java).setAction(Intent.ACTION_MAIN),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Notification.Builder(this, ALERT_CHANNEL_ID)
        } else {
            @Suppress("DEPRECATION")
            Notification.Builder(this).setPriority(Notification.PRIORITY_DEFAULT)
        }

        builder
            .setContentTitle("You have left your work area")
            .setContentText("$distanceM m outside $areaName. Tap to give a reason.")
            .setStyle(
                Notification.BigTextStyle().bigText(
                    "You are $distanceM m outside $areaName. Tap to record why — " +
                        "this time is not counted as worked hours until it is approved."
                )
            )
            .setSmallIcon(android.R.drawable.ic_dialog_alert)
            .setContentIntent(open)
            .setAutoCancel(true)

        try {
            (getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager)
                .notify(ALERT_NOTIFICATION_ID, builder.build())
        } catch (e: Exception) {
            Log.w(TAG, "Could not post the area alert: ${e.message}")
        }
    }

    /**
     * Tell the employee their position is being faked.
     *
     * Deliberately blunt and not dismissible by swiping: while this is true, nothing they
     * do in the app counts — check-ins are refused and no route is recorded — and an
     * employee who does not know that will assume the app is broken.
     */
    private fun alertMockDetected() {
        val open = PendingIntent.getActivity(
            this, 2,
            Intent(this, MainActivity::class.java).setAction(Intent.ACTION_MAIN),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Notification.Builder(this, ALERT_CHANNEL_ID)
        } else {
            @Suppress("DEPRECATION")
            Notification.Builder(this)
        }

        builder
            .setContentTitle("Simulated location detected")
            .setContentText("Turn off any fake GPS app. Your attendance is not being recorded.")
            .setStyle(
                Notification.BigTextStyle().bigText(
                    "This phone is reporting a simulated position. Attendance and route " +
                        "recording are suspended until it stops, and your administrator " +
                        "has been notified. If you are not using a fake GPS app, open " +
                        "Developer options and clear the mock location app setting."
                )
            )
            .setSmallIcon(android.R.drawable.stat_sys_warning)
            .setContentIntent(open)
            .setOngoing(true)
            .setAutoCancel(false)

        try {
            (getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager)
                .notify(TAMPER_NOTIFICATION_ID, builder.build())
        } catch (e: Exception) {
            Log.w(TAG, "Could not post the tamper warning: ${e.message}")
        }

        updateStatus("Simulated location — attendance suspended")
    }

    /**
     * Alarm sound disabled per user requirement: no ringtone or audible alarm
     * when an employee moves outside the geofenced area.
     */
    private fun playAlertSound() {
        stopAlertSound()
    }

    private fun stopAlertSound() {
        alertPlayer?.let { player ->
            try {
                if (player.isPlaying) player.stop()
            } catch (e: Exception) {
                // Already stopped.
            }
            try {
                player.release()
            } catch (e: Exception) {
                // Already released.
            }
        }
        alertPlayer = null
    }

    private fun clearAlert() {
        stopAlertSound()
        try {
            (getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager)
                .cancel(ALERT_NOTIFICATION_ID)
        } catch (e: Exception) {
            // Nothing showing.
        }
    }

    /* ---------------- command polling ---------------- */

    /**
     * Ask the server whether anything has been requested for this device.
     *
     * The server cannot reach a phone, so an admin's "locate now" is parked
     * server-side and collected here. Deliberately a separate, much faster loop than
     * the tick: the tick's job is to move data on the tracking interval, this one's
     * is responsiveness, and conflating them would mean either a sluggish button or
     * pointless uploads every 45 seconds.
     */
    private fun startPolling() {
        pollRunnable?.let { handler.removeCallbacks(it) }

        val runnable = object : Runnable {
            override fun run() {
                if (!isRunning) return

                Thread {
                    val response = ApiClient.post(this@TrackingService, "poll", JSONObject())

                    if (response.sessionDead) {
                        Prefs.setTrackingWanted(this@TrackingService, false)
                        Prefs.setToken(this@TrackingService, null)
                        handler.post { shutdown() }
                        return@Thread
                    }

                    val data = response.body?.optJSONObject("data")
                    if (data != null) {
                        pollSeconds = data.optInt("poll_seconds", pollSeconds).coerceIn(15, 600)
                        applyLiveWindow(data)
                        handleCommands(data.optJSONArray("commands"))
                    }
                }.start()

                handler.postDelayed(this, pollSeconds * 1000L)
            }
        }

        pollRunnable = runnable
        handler.postDelayed(runnable, pollSeconds * 1000L)
    }

    /**
     * Adopt, or drop, the live-reporting window the server is advertising.
     *
     * The expiry is computed from the device's own clock plus the server's remaining
     * seconds, rather than trusting a server timestamp: a phone whose clock is off by
     * hours would otherwise either ignore the window entirely or keep reporting every
     * few seconds indefinitely. Getting this wrong costs the employee their battery.
     *
     * Re-registers the providers when the rate actually changes, since the interval
     * they were requested at no longer matches what is wanted.
     */
    private fun applyLiveWindow(data: JSONObject) {
        if (!withinShift()) {
            Prefs.clearLive(this)
            return
        }
        val seconds = data.optInt("live_seconds", 0)
        val before = Prefs.liveSeconds(this)

        if (seconds > 0) {
            // The window is refreshed by whoever is watching, so it only ever needs to
            // outlive one poll cycle plus a margin.
            val holdMs = maxOf(pollSeconds * 2, 60) * 1000L
            Prefs.setLive(this, seconds, System.currentTimeMillis() + holdMs)
        } else if (before > 0) {
            Prefs.clearLive(this)
        }

        val after = Prefs.liveSeconds(this)
        if (after != before) {
            Log.i(TAG, "Live reporting ${if (after > 0) "on at ${after}s" else "off"}")
            handler.post {
                requestUpdates()
                // Take one now rather than waiting out the new interval, so the admin
                // sees a position immediately instead of after the first cycle. Counted as
                // admin-initiated: somebody has opened the live map and is waiting.
                forceFix = true
                forcedByAdmin = true
                refreshNotification()
            }
        }
    }

    /** Act on whatever the server handed back, then acknowledge it. */
    private fun handleCommands(commands: org.json.JSONArray?) {
        if (commands == null || commands.length() == 0) return

        for (i in 0 until commands.length()) {
            val command = commands.optJSONObject(i) ?: continue
            val id = command.optInt("id", 0)

            when (command.optString("command")) {
                "locate" -> {
                    Log.i(TAG, "Locate requested by admin; forcing a fix")
                    forceFix = true
                    forcedByAdmin = true
                    // Off shift the receiver is deregistered, so without this the request
                    // would wait for a fix that never arrives. The next tick releases it
                    // again once there is no window open.
                    if (registeredIntervalMin == 0) {
                        handler.post { requestUpdates() }
                    }
                    // Straight to GPS rather than waiting for the periodic sample —
                    // an on-demand position is worthless if it arrives five minutes
                    // late, and this is the one case where the extra power is earned.
                    handler.post { solicitGpsFix() }
                }
                "sync" -> Thread { upload() }.start()

                // The panel has authorised removal. Protection comes off; nothing else
                // changes, so tracking carries on until the phone is actually wiped or
                // the app uninstalled — releasing is permission to remove, not a
                // shutdown.
                "release_admin" -> {
                    val released = DeviceAdminState.release(this)
                    Log.i(TAG, "Uninstall protection release requested by admin: $released")
                    DeviceAdminState.report(this, DeviceAdminState.isActive(this))
                }
                "signout" -> {
                    Prefs.setTrackingWanted(this, false)
                    Prefs.setToken(this, null)
                    handler.post { shutdown() }
                }
            }

            if (id > 0) {
                // Acknowledged on the next poll rather than in its own request.
                Thread {
                    ApiClient.post(this, "poll", JSONObject().put("done", id))
                }.start()
            }
        }
    }

    /* ---------------- device state ---------------- */

    private fun batteryPercent(): Int? {
        return try {
            val manager = getSystemService(Context.BATTERY_SERVICE) as BatteryManager
            val level = manager.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
            if (level in 0..100) level else null
        } catch (e: Exception) {
            null
        }
    }

    private fun isCharging(): Boolean {
        return try {
            val manager = getSystemService(Context.BATTERY_SERVICE) as BatteryManager
            manager.isCharging
        } catch (e: Exception) {
            false
        }
    }

    private fun acquireWakeLock() {
        if (wakeLock?.isHeld == true) return
        try {
            val power = getSystemService(Context.POWER_SERVICE) as PowerManager
            wakeLock = power.newWakeLock(
                PowerManager.PARTIAL_WAKE_LOCK, "SSTAttendance::tracking"
            ).apply {
                setReferenceCounted(false)
                // A timeout so a bug can never hold the CPU awake indefinitely; the
                // tick re-acquires it while the shift is still running.
                acquire(12 * 60 * 60 * 1000L)
            }
        } catch (e: Exception) {
            Log.w(TAG, "Could not acquire wake lock: ${e.message}")
        }
    }

    /* ---------------- teardown ---------------- */

    private fun shutdown() {
        isRunning = false
        Prefs.setTrackingWanted(this, false)
        queue.clear()
        broadcastStatus()

        tickRunnable?.let { handler.removeCallbacks(it) }
        tickRunnable = null
        pollRunnable?.let { handler.removeCallbacks(it) }
        pollRunnable = null
        retryRunnable?.let { handler.removeCallbacks(it) }
        retryRunnable = null
        stopWatching()
        stopMotionSensors()
        ActivityMonitor.stop(this)
        clearAlert()
        stopAlertSound()
        // The announced state deliberately survives a stop. It is what stops a restart
        // from re-alarming an employee who is still outside and has already explained.

        // Drop any half-settled fix rather than committing it after the service has
        // been told to stop.
        handler.removeCallbacksAndMessages(null)
        candidate = null

        try {
            locationManager.removeUpdates(locationListener)
        } catch (e: Exception) {
            // Already removed.
        }

        networkCallback?.let { callback ->
            try {
                (getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager)
                    ?.unregisterNetworkCallback(callback)
            } catch (e: Exception) {
                // Never registered, or already gone.
            }
        }
        networkCallback = null

        wakeLock?.let { if (it.isHeld) it.release() }
        wakeLock = null

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            stopForeground(Service.STOP_FOREGROUND_REMOVE)
        } else {
            @Suppress("DEPRECATION")
            stopForeground(true)
        }
        stopSelf()
    }

    override fun onDestroy() {
        stopMotionSensors()
        outsideFirstSeenAt = 0L
        outsideCandidateCount = 0
        try {
            unregisterReceiver(screenReceiver)
        } catch (e: Exception) {
            // Never registered.
        }
        isRunning = false
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null
}
