package com.smartstep.attendance

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
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
        if (enabled && ensureApp(ctx) && token(ctx) == null) requestToken(ctx)
    }

    /** Start Firebase from the remembered values. False when push is off or never set up. */
    fun ensureApp(ctx: Context): Boolean {
        val app = ctx.applicationContext
        val p = prefs(app)
        if (!p.getBoolean("enabled", false)) return false
        val appId = p.getString("app_id", "") ?: ""
        val apiKey = p.getString("api_key", "") ?: ""
        val projectId = p.getString("project_id", "") ?: ""
        val senderId = p.getString("sender_id", "") ?: ""
        if (appId.isEmpty() || apiKey.isEmpty() || projectId.isEmpty() || senderId.isEmpty()) return false
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
            false
        }
    }

    private fun requestToken(ctx: Context) {
        try {
            FirebaseMessaging.getInstance().token.addOnSuccessListener { t ->
                if (!t.isNullOrEmpty()) saveToken(ctx, t)
            }
        } catch (e: Exception) {
            Log.w(TAG, "No FCM token: ${e.message}")
        }
    }

    fun saveToken(ctx: Context, token: String) {
        prefs(ctx).edit().putString("token", token).apply()
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
        payload.put("vpn", vpnActive(ctx))
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
