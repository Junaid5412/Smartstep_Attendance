package com.smartstep.attendance

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.messaging.FirebaseMessaging
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import org.json.JSONObject

/**
 * Wake-up push (Google FCM) and the VPN check.
 *
 * Why: when tracking stops during a shift (Force stop, a battery saver, the app swiped away) the server sends a
 * HIGH-priority data message. Google Play services starts [AttendancePushService] for it even when the app is not
 * running, and that starts [TrackingService] again. "Locate now" uses the same push, so the phone answers at once.
 *
 * There is no google-services.json in the app: the server hands out the four Firebase values on the poll
 * (`push` in poll.php) and they are remembered here, so Firebase can be started again when a message arrives while
 * the app is closed. Push stays off until the admin turns it on in the panel (Wake-up & VPN).
 *
 * The phone reports its FCM token, and whether a VPN is on, on its poll and heartbeat.
 */
object AttendancePush {
    private const val TAG = "AttPush"
    private const val PREFS = "att_push"
    private const val MSG_CHANNEL = "sst_messages"

    private fun prefs(ctx: Context) = ctx.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    /** The `push` object from the poll: remember it, start Firebase and ask for a token when push is on. */
    fun applyConfig(ctx: Context, cfg: JSONObject?) {
        if (cfg == null) return
        val enabled = cfg.optBoolean("enabled", false)
        val p = prefs(ctx).edit().putBoolean("enabled", enabled)
        if (enabled) {
            p.putString("app_id", cfg.optString("app_id"))
                .putString("api_key", cfg.optString("api_key"))
                .putString("project_id", cfg.optString("project_id"))
                .putString("sender_id", cfg.optString("sender_id"))
        }
        p.apply()
        if (!enabled) { setStatus(ctx, "off"); return }
        if (!ensureApp(ctx)) return                       // ensureApp keeps the reason
        if (token(ctx) == null) {
            setStatus(ctx, "waiting for token")
            requestToken(ctx)
        } else {
            setStatus(ctx, "ok")
        }
    }

    /** What the panel shows when this phone has no push token: off / waiting / the Firebase error. */
    private fun setStatus(ctx: Context, s: String) {
        prefs(ctx).edit().putString("status", s.take(180)).apply()
    }

    fun status(ctx: Context): String = prefs(ctx).getString("status", "") ?: ""

    /** Start Firebase from the remembered values. False when push is off or never set up. */
    fun ensureApp(ctx: Context): Boolean {
        val app = ctx.applicationContext
        val p = prefs(app)
        if (!p.getBoolean("enabled", false)) return false
        val appId = p.getString("app_id", "") ?: ""
        val apiKey = p.getString("api_key", "") ?: ""
        val projectId = p.getString("project_id", "") ?: ""
        val senderId = p.getString("sender_id", "") ?: ""
        if (appId.isEmpty() || apiKey.isEmpty() || projectId.isEmpty() || senderId.isEmpty()) {
            setStatus(app, "firebase values missing")
            return false
        }
        return try {
            val running = FirebaseApp.getApps(app).firstOrNull { it.name == FirebaseApp.DEFAULT_APP_NAME }
            if (running != null) {
                if (running.options.applicationId == appId && running.options.projectId == projectId) return true
                // The panel moved to another Firebase project: the old app goes, and so does its token.
                running.delete()
                p.edit().remove("token").remove("reported").apply()
            }
            FirebaseApp.initializeApp(app, FirebaseOptions.Builder()
                .setApplicationId(appId).setApiKey(apiKey).setProjectId(projectId).setGcmSenderId(senderId).build())
            true
        } catch (e: Exception) {
            Log.w(TAG, "Firebase could not start: ${e.message}")
            setStatus(app, "firebase error: ${e.javaClass.simpleName}: ${e.message}")
            false
        }
    }

    @Volatile private var lastTokenTry = 0L

    private fun requestToken(ctx: Context) {
        val now = System.currentTimeMillis()
        if (now - lastTokenTry < 60_000L) return
        lastTokenTry = now
        try {
            FirebaseMessaging.getInstance().token
                .addOnSuccessListener { t ->
                    if (!t.isNullOrEmpty()) {
                        saveToken(ctx, t)
                        setStatus(ctx, "ok")
                        sync(ctx, force = true)           // tell the server now, not on the next poll
                    } else {
                        setStatus(ctx, "token error: empty token")
                    }
                }
                .addOnFailureListener { e ->
                    Log.w(TAG, "No FCM token: ${e.message}")
                    val cause = e.cause?.message
                    setStatus(ctx, "token error: ${e.message}" + (if (cause != null && cause != e.message) " ($cause)" else ""))
                    sync(ctx, force = true)
                }
        } catch (e: Exception) {
            Log.w(TAG, "No FCM token: ${e.message}")
            setStatus(ctx, "token error: ${e.javaClass.simpleName}: ${e.message}")
        }
    }

    fun saveToken(ctx: Context, token: String) {
        prefs(ctx).edit().putString("token", token).apply()
    }

    @Volatile private var lastSync = 0L

    /**
     * Report token / VPN / push status and fetch the Firebase settings outside the tracking poll: when the app is
     * opened (so a phone registers without waiting for a shift), when a token arrives, and when a VPN goes on or off.
     */
    fun sync(ctx: Context, force: Boolean = false) {
        val app = ctx.applicationContext
        if (Prefs.token(app) == null) return
        val now = System.currentTimeMillis()
        if (!force && now - lastSync < 30_000L) return
        lastSync = now
        Thread {
            try {
                val payload = JSONObject()
                val reporting = addReport(app, payload)
                val response = ApiClient.post(app, "push", payload)
                val data = response.body?.optJSONObject("data") ?: return@Thread
                if (reporting != null) markReported(app, reporting)
                applyConfig(app, data.optJSONObject("push"))
            } catch (e: Exception) {
                Log.w(TAG, "Push sync failed: ${e.message}")
            }
        }.start()
    }

    private val main by lazy { Handler(Looper.getMainLooper()) }
    private var vpnCallback: ConnectivityManager.NetworkCallback? = null
    @Volatile private var lastVpn: Boolean? = null

    /** While tracking runs: report a VPN going on or off within seconds instead of on the next poll. */
    fun watchVpn(ctx: Context) {
        if (vpnCallback != null) return
        val app = ctx.applicationContext
        val changed = Runnable {
            val on = vpnActive(app)
            if (on != lastVpn) {
                lastVpn = on
                sync(app, force = true)
            }
        }
        val cb = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) { main.removeCallbacks(changed); main.postDelayed(changed, 1500) }
            override fun onLost(network: Network) { main.removeCallbacks(changed); main.postDelayed(changed, 1500) }
        }
        try {
            val cm = app.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            val request = NetworkRequest.Builder()
                .addTransportType(NetworkCapabilities.TRANSPORT_VPN)
                .removeCapability(NetworkCapabilities.NET_CAPABILITY_NOT_VPN)
                .build()
            cm.registerNetworkCallback(request, cb)
            vpnCallback = cb
            lastVpn = vpnActive(app)
        } catch (e: Exception) {
            Log.w(TAG, "VPN watch not available: ${e.message}")
        }
    }

    fun unwatchVpn(ctx: Context) {
        val cb = vpnCallback ?: return
        vpnCallback = null
        try {
            (ctx.applicationContext.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager).unregisterNetworkCallback(cb)
        } catch (e: Exception) {
            // already gone
        }
    }

    fun token(ctx: Context): String? = prefs(ctx).getString("token", null)

    /** The token still to be reported to the server, or null when it already has it. */
    fun tokenToReport(ctx: Context): String? {
        val t = token(ctx) ?: return null
        return if (prefs(ctx).getString("reported", null) == t) null else t
    }

    fun markReported(ctx: Context, token: String) {
        prefs(ctx).edit().putString("reported", token).apply()
    }

    /** Is a VPN on right now (any network with the VPN transport, including "firewall" apps that use the VPN slot)? */
    fun vpnActive(ctx: Context): Boolean {
        return try {
            val cm = ctx.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                @Suppress("DEPRECATION")
                cm.allNetworks.any { n -> cm.getNetworkCapabilities(n)?.hasTransport(NetworkCapabilities.TRANSPORT_VPN) == true }
            } else false
        } catch (e: Exception) {
            false
        }
    }

    /** Fields for a poll / heartbeat payload: the VPN state, and the FCM token when the server does not have it yet. */
    fun addReport(ctx: Context, payload: JSONObject): String? {
        val vpn = vpnActive(ctx)
        payload.put("vpn", vpn)
        lastVpn = vpn
        val status = status(ctx)
        if (status.isNotEmpty()) payload.put("push_status", status)
        val t = tokenToReport(ctx)
        if (t != null) payload.put("fcm_token", t)
        return t
    }

    /** Tracking was stopped and the server wants it back: start the service (it checks for itself that a shift is open). */
    fun wake(ctx: Context, pollNow: Boolean) {
        if (Prefs.token(ctx) == null || !Prefs.trackingWanted(ctx) || !Prefs.shiftOpen(ctx)) {
            Log.i(TAG, "Wake ignored: not signed in / not checked in")
            return
        }
        try {
            TrackingService.start(ctx, pollNow)
        } catch (e: Exception) {
            // Android refused to start it from the background (should not happen for a high-priority push): ask the
            // person to open the app instead.
            Log.w(TAG, "Could not restart tracking: ${e.message}")
            show(ctx, 7301, "Attendance tracking stopped", "Tap to open SST Attendance and continue your shift.")
        }
    }

    fun show(ctx: Context, id: Int, title: String, body: String) {
        try {
            val nm = ctx.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && nm.getNotificationChannel(MSG_CHANNEL) == null) {
                nm.createNotificationChannel(NotificationChannel(MSG_CHANNEL, "Messages", NotificationManager.IMPORTANCE_HIGH))
            }
            val open = ctx.packageManager.getLaunchIntentForPackage(ctx.packageName)?.apply {
                addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            }
            val tap = if (open == null) null else PendingIntent.getActivity(ctx, id, open, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            val b = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) Notification.Builder(ctx, MSG_CHANNEL) else @Suppress("DEPRECATION") Notification.Builder(ctx)
            val n = b.setSmallIcon(android.R.drawable.ic_dialog_info)
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(Notification.BigTextStyle().bigText(body))
                .setAutoCancel(true)
                .setContentIntent(tap)
                .build()
            nm.notify(id, n)
        } catch (e: SecurityException) {
            // notifications not allowed
        } catch (e: Exception) {
            Log.w(TAG, "Notification failed: ${e.message}")
        }
    }
}

/**
 * Started by Google Play services for each push, also when the app is not running.
 *   type = wake    -> start tracking again
 *   type = locate  -> start tracking if needed and check for the admin's "locate" request now
 *   type = notify  -> show the message (title, body)
 */
class AttendancePushService : FirebaseMessagingService() {
    override fun onCreate() {
        super.onCreate()
        AttendancePush.ensureApp(this)          // the app was closed: Firebase has to be started from the remembered values
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val d = message.data
        when (d["type"]) {
            "wake" -> AttendancePush.wake(this, pollNow = true)
            "locate" -> AttendancePush.wake(this, pollNow = true)
            "notify" -> AttendancePush.show(this, (System.currentTimeMillis() % Int.MAX_VALUE).toInt(),
                d["title"] ?: "SST Attendance", d["body"] ?: "")
        }
    }

    override fun onNewToken(token: String) {
        // Reported to the server on the next poll / heartbeat.
        AttendancePush.saveToken(this, token)
    }
}
