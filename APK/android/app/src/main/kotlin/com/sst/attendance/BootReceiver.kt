package com.sst.attendance

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * Restarts tracking after a reboot or an app update.
 *
 * Without this, an employee who restarts their phone at lunchtime would stop being
 * tracked for the rest of the shift and nobody would know until the route came
 * back half-empty. The receiver only starts the service when a session exists and
 * tracking was wanted; the service itself decides whether the shift is running.
 */
class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val action = intent.action ?: return

        val relevant = action in listOf(
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_MY_PACKAGE_REPLACED,
            "android.intent.action.QUICKBOOT_POWERON"
        )
        if (!relevant) return

        if (Prefs.token(context) == null || !Prefs.trackingWanted(context)) {
            Log.i("SSTBoot", "No active session; tracking not restarted.")
            return
        }

        Log.i("SSTBoot", "Restarting tracking after $action")
        TrackingService.start(context)
    }
}
