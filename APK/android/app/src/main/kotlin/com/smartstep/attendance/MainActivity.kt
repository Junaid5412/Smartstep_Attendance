package com.smartstep.attendance

import android.app.NotificationManager
import android.content.BroadcastReceiver
import android.content.Context
import android.app.admin.DevicePolicyManager
import android.content.Intent
import android.content.IntentFilter
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.EventChannel
import io.flutter.plugin.common.MethodChannel

/**
 * Bridge between the Flutter UI and the native tracking service.
 *
 * The UI owns the session; the service owns the background work. Everything the
 * service needs is handed across this channel and written into Prefs, from where
 * the service reads it — so the service never depends on the UI being alive.
 */
class MainActivity : FlutterActivity() {

    override fun onCreate(savedInstanceState: android.os.Bundle?) {
        super.onCreate(savedInstanceState)
        applyScreenCapturePolicy()
    }

    override fun onResume() {
        super.onResume()
        // Re-applied on every return to the foreground, so a change made in the panel
        // takes effect once the phone has refreshed its config — without this the app
        // would have to be force-stopped for the setting to bite.
        applyScreenCapturePolicy()
    }

    /**
     * Allow or block screenshots and screen recording, per the server's setting.
     *
     * Blocking is prevention rather than detection: enumerating capture apps cannot work —
     * Android restricts package visibility and the tools rename themselves — but
     * FLAG_SECURE makes the window unrecordable at the compositor, so it does not matter
     * what is installed. It also keeps the app out of the recent-apps thumbnail.
     *
     * It is a setting because the same flag that protects other people's locations also
     * stops the operator photographing their own screen to report a problem, which is how
     * most support requests start.
     */
    private fun applyScreenCapturePolicy() {
        // FLAG_SECURE is not set at all any more: screenshots and screen recording always
        // work. Removed on request — being unable to photograph the screen made problems
        // impossible to report, and a bug nobody can show you is worse than a screenshot
        // nobody should have taken. Prefs.allowScreenCapture and the server setting are
        // left in place so this can be reinstated by restoring the flag here.
        window.clearFlags(android.view.WindowManager.LayoutParams.FLAG_SECURE)
        android.util.Log.i("SSTCapture", "Screen capture allowed (FLAG_SECURE removed)")
    }

    private companion object {
        const val CHANNEL = "com.smartstep.attendance/native"
        const val EVENTS = "com.smartstep.attendance/events"
    }

    private var eventSink: EventChannel.EventSink? = null
    private var fenceReceiver: BroadcastReceiver? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        // Push channel from the service to the UI.
        //
        // The service detects a crossing within seconds, but the UI used to learn
        // about it only on its own 45-second poll — so a warning that is supposed to
        // be immediate could arrive most of a minute late. This forwards the
        // service's broadcast straight into Dart.
        EventChannel(flutterEngine.dartExecutor.binaryMessenger, EVENTS).setStreamHandler(
            object : EventChannel.StreamHandler {
                override fun onListen(arguments: Any?, sink: EventChannel.EventSink?) {
                    eventSink = sink
                    registerFenceReceiver()
                }

                override fun onCancel(arguments: Any?) {
                    unregisterFenceReceiver()
                    eventSink = null
                }
            }
        )

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {

                    /* ---------- device identity ---------- */

                    "deviceUid" -> result.success(DeviceIdentity.uid(this))

                    "deviceInfo" -> result.success(DeviceIdentity.describe())

                    /**
                     * Integrity verdict. Re-run on every resume, not only at launch:
                     * people turn things off to get past a startup check and back on
                     * once they are in.
                     */
                    "integrityCheck" -> {
                        val verdict = Integrity.check(this)
                        result.success(mapOf("ok" to verdict.ok, "reason" to verdict.reason))
                    }

                    // Single source of truth for the server address: the value baked
                    // in by -Papi.Base at build time. Without this the Dart layer
                    // would keep its own hardcoded default and the two halves of the
                    // app could point at different servers.
                    "defaultApiBase" -> result.success(BuildConfig.DEFAULT_API_BASE)

                    /* ---------- session handover ---------- */

                    "setBaseUrl" -> {
                        val url = call.argument<String>("url")
                        if (url.isNullOrBlank()) {
                            result.error("BAD_ARGS", "url is required", null)
                        } else {
                            Prefs.setBaseUrl(this, url)
                            result.success(true)
                        }
                    }

                    "setSession" -> {
                        // Called after sign-in and after every config refresh, so the
                        // service always has the current token and rules.
                        Prefs.setToken(this, call.argument<String>("token"))
                        call.argument<String>("config")?.let { Prefs.setConfig(this, it) }
                        result.success(true)
                    }

                    "clearSession" -> {
                        Prefs.setTrackingWanted(this, false)
                        TrackingService.stop(this)
                        Prefs.clearSession(this)
                        result.success(true)
                    }

                    /* ---------- tracking control ---------- */

                    "startTracking" -> {
                        // A service may only be started for a confirmed open shift.
                        if (!Prefs.shiftOpen(this)) {
                            Prefs.setTrackingWanted(this, false)
                            TrackingService.stop(this)
                            result.success(false)
                        } else {
                            Prefs.setTrackingWanted(this, true)
                            TrackingService.start(this)
                            result.success(true)
                        }
                    }

                    "stopTracking" -> {
                        Prefs.setTrackingWanted(this, false)
                        TrackingService.stop(this)
                        result.success(true)
                    }

                    /**
                     * Whether a shift is open, from the server's own view of the
                     * register. The service uses it to keep recording past the
                     * rostered end while the employee is still clocked in.
                     */
                    "setShiftOpen" -> {
                        val open = call.argument<Boolean>("open") ?: false
                        Prefs.setShiftOpen(this, open)
                        if (!open) {
                            // Checkout is a hard boundary: revoke restart authority and
                            // tear down the service before any stale poll can run.
                            Prefs.setTrackingWanted(this, false)
                            TrackingService.stop(this)
                        }
                        result.success(true)
                    }

                    /**
                     * Silences the leaving-the-area alarm for the current departure.
                     *
                     * Set the moment the employee submits a reason, rather than waiting
                     * for the next upload to report it back: the gap between answering
                     * and the service finding out was long enough for the alarm to go
                     * off again, which is precisely the complaint.
                     */
                    "setOutsideReasonGiven" -> {
                        val given = call.argument<Boolean>("given") ?: false
                        Prefs.setOutsideReasonGiven(this, given)
                        result.success(true)
                    }

                    /**
                     * Hand a downloaded APK to the system installer.
                     *
                     * Cannot install silently: Android requires the user to confirm unless
                     * the app is a device owner, which means MDM provisioning at
                     * factory-reset time. This gets them to the confirmation in one tap.
                     */
                    "installApk" -> {
                        val path = call.argument<String>("path")
                        if (path.isNullOrBlank()) {
                            result.error("BAD_ARGS", "path is required", null)
                        } else {
                            result.success(installApk(path))
                        }
                    }

                    /** Whether the employee has allowed this app to install packages. */
                    "canInstallPackages" -> result.success(canInstallPackages())

                    // Called after a config refresh so the change lands immediately
                    // rather than at the next foreground.
                    "applyScreenCapturePolicy" -> {
                        runOnUiThread { applyScreenCapturePolicy() }
                        result.success(Prefs.allowScreenCapture(this))
                    }

                    "isScreenCaptureAllowed" -> result.success(Prefs.allowScreenCapture(this))

                    "isDeviceAdminActive" -> result.success(DeviceAdminState.isActive(this))

                    "requestDeviceAdmin" -> {
                        // Android insists the user grants this on its own screen; there
                        // is no programmatic path, by design. The explanation shown there
                        // is the only chance to say why, so it is written for the
                        // employee reading it rather than for the person deploying.
                        val intent = Intent(DevicePolicyManager.ACTION_ADD_DEVICE_ADMIN).apply {
                            putExtra(
                                DevicePolicyManager.EXTRA_DEVICE_ADMIN,
                                DeviceAdminState.component(this@MainActivity)
                            )
                            putExtra(
                                DevicePolicyManager.EXTRA_ADD_EXPLANATION,
                                "This stops the attendance app being removed from this " +
                                    "phone by accident. It does not give access to your " +
                                    "photos, messages, or files, and it cannot lock or " +
                                    "erase the phone."
                            )
                        }
                        startActivity(intent)
                        result.success(true)
                    }

                    "releaseDeviceAdmin" -> result.success(DeviceAdminState.release(this))

                    "requestInstallPermission" -> {
                        requestInstallPermission()
                        result.success(true)
                    }

                    "syncNow" -> {
                        TrackingService.syncNow(this)
                        result.success(true)
                    }

                    "trackingStatus" -> result.success(
                        mapOf(
                            "running" to TrackingService.isRunning,
                            "wanted" to Prefs.trackingWanted(this),
                            "pending" to pendingCount(),
                            "last_fix_at" to Prefs.lastFixAt(this),
                            "last_upload_at" to Prefs.lastUploadAt(this),
                            "interval_min" to Prefs.intervalMinutes(this)
                        )
                    )

                    /* ---------- battery optimisation ---------- */

                    "isBatteryOptimised" -> result.success(isBatteryOptimised())

                    "requestBatteryExemption" -> {
                        requestBatteryExemption()
                        result.success(true)
                    }

                    "openAppSettings" -> {
                        startActivity(
                            Intent(
                                Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                                Uri.fromParts("package", packageName, null)
                            )
                        )
                        result.success(true)
                    }

                    "openLocationSettings" -> {
                        startActivity(Intent(Settings.ACTION_LOCATION_SOURCE_SETTINGS))
                        result.success(true)
                    }

                    /* ---------- notifications ---------- */

                    // Asked of the system rather than of permission_handler: the
                    // POST_NOTIFICATIONS grant and "are notifications actually
                    // shown" are different things. A user can switch the app's
                    // notifications off in settings, which leaves the permission
                    // granted but the channel silenced — and that is exactly the
                    // state that hides the tracking notification.
                    "notificationsEnabled" -> {
                        result.success(notificationsUsable())
                    }

                    "openNotificationSettings" -> {
                        openNotificationSettings()
                        result.success(true)
                    }

                    "openUrl" -> {
                        val url = call.argument<String>("url")
                        if (!url.isNullOrBlank()) {
                            try {
                                val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url)).apply {
                                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                }
                                startActivity(intent)
                                result.success(true)
                            } catch (e: Exception) {
                                result.success(false)
                            }
                        } else {
                            result.success(false)
                        }
                    }

                    else -> result.notImplemented()
                }
            }
    }

    private fun registerFenceReceiver() {
        if (fenceReceiver != null) return

        val receiver = object : BroadcastReceiver() {
            override fun onReceive(context: Context?, intent: Intent?) {
                if (intent?.action != TrackingService.BROADCAST_FENCE) return
                eventSink?.success(
                    mapOf(
                        "type" to "fence",
                        "outside" to intent.getBooleanExtra("outside", false),
                        "distance_m" to intent.getIntExtra("distance_m", 0),
                        "area_name" to (intent.getStringExtra("area_name") ?: "")
                    )
                )
            }
        }

        val filter = IntentFilter(TrackingService.BROADCAST_FENCE)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(receiver, filter, Context.RECEIVER_NOT_EXPORTED)
        } else {
            registerReceiver(receiver, filter)
        }
        fenceReceiver = receiver
    }

    private fun unregisterFenceReceiver() {
        fenceReceiver?.let {
            try {
                unregisterReceiver(it)
            } catch (e: Exception) {
                // Never registered.
            }
        }
        fenceReceiver = null
    }

    override fun onDestroy() {
        unregisterFenceReceiver()
        super.onDestroy()
    }

    private fun pendingCount(): Int = try {
        LocationQueue(this).count()
    } catch (e: Exception) {
        0
    }

    /**
     * True when the tracking notification will actually be visible.
     *
     * Checks both the app-level switch and the specific channel, because either one
     * being off is enough to hide it — and the app promises the employee a visible
     * indicator whenever tracking runs.
     */
    private fun notificationsUsable(): Boolean {
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager
            ?: return false

        if (!manager.areNotificationsEnabled()) return false

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = manager.getNotificationChannel("sst_tracking")
            // A null channel means the service has not started yet, which is not a
            // fault; only an explicitly silenced channel counts as blocked.
            if (channel != null && channel.importance == NotificationManager.IMPORTANCE_NONE) {
                return false
            }
        }
        return true
    }

    private fun openNotificationSettings() {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                startActivity(
                    Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                        .putExtra(Settings.EXTRA_APP_PACKAGE, packageName)
                )
            } else {
                startActivity(
                    Intent(
                        Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                        Uri.fromParts("package", packageName, null)
                    )
                )
            }
        } catch (e: Exception) {
            // Fall back to the app's own settings page.
            try {
                startActivity(
                    Intent(
                        Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                        Uri.fromParts("package", packageName, null)
                    )
                )
            } catch (ignored: Exception) {
                // Nothing further we can do from here.
            }
        }
    }

    /**
     * True when the OS is allowed to doze the app. Under doze the tracking service
     * is throttled and the route develops gaps, so the UI nags until this is false.
     */
    /* ---------------- in-app update ---------------- */

    private fun canInstallPackages(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            packageManager.canRequestPackageInstalls()
        } else {
            true
        }
    }

    private fun requestInstallPermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        try {
            startActivity(
                Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES)
                    .setData(Uri.parse("package:$packageName"))
            )
        } catch (e: Exception) {
            // Some builds hide this screen; the installer will prompt anyway.
        }
    }

    private fun installApk(path: String): Boolean {
        return try {
            val file = java.io.File(path)
            if (!file.exists() || file.length() == 0L) return false

            val uri = androidx.core.content.FileProvider.getUriForFile(
                this, "$packageName.fileprovider", file
            )

            startActivity(
                Intent(Intent.ACTION_VIEW)
                    .setDataAndType(uri, "application/vnd.android.package-archive")
                    .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
            true
        } catch (e: Exception) {
            false
        }
    }

    private fun isBatteryOptimised(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return false
        val power = getSystemService(Context.POWER_SERVICE) as PowerManager
        return !power.isIgnoringBatteryOptimizations(packageName)
    }

    private fun requestBatteryExemption() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return
        try {
            // Sends the user to the system dialog. Play policy allows asking for this
            // directly for an app whose core function is continuous location.
            startActivity(
                Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS)
                    .setData(Uri.parse("package:$packageName"))
            )
        } catch (e: Exception) {
            // Some OEM builds hide that dialog; fall back to the battery settings list.
            try {
                startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
            } catch (ignored: Exception) {
                // Nothing further we can do from here.
            }
        }
    }
}
