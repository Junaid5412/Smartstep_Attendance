package com.sst.attendance

import android.Manifest
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import com.google.android.gms.location.ActivityRecognition
import com.google.android.gms.location.ActivityTransition
import com.google.android.gms.location.ActivityTransitionRequest
import com.google.android.gms.location.ActivityTransitionResult
import com.google.android.gms.location.DetectedActivity

/**
 * Whether the phone is actually moving, asked of the accelerometer rather than inferred
 * from coordinates.
 *
 * This is the mechanism delivery and ride-hailing apps use, and its absence was the root of
 * the jitter. Every other approach in this codebase tried to answer "did they move?" by
 * comparing positions — but indoors two consecutive fixes are routinely tens or hundreds of
 * metres apart, so position can never settle the question. The motion coprocessor can: it
 * reports STILL from accelerometer data, at a battery cost low enough that the platform runs
 * it continuously anyway.
 *
 * The transition API is used rather than periodic sampling, so the app is woken only when
 * the state actually changes rather than polling for an answer that is usually the same.
 */
object ActivityMonitor {

    private const val TAG = "SSTActivity"
    private const val REQUEST_CODE = 5120

    /**
     * How long a STILL report is trusted, in milliseconds.
     *
     * A transition may be the last event for hours, so it cannot simply expire — but neither
     * should a stale report from this morning silence tracking now. Long enough to cover a
     * genuine sit-down, short enough that a missed EXIT event cannot suppress a whole shift.
     */
    private const val TRUST_MS = 30 * 60 * 1000L

    fun hasPermission(context: Context): Boolean {
        // Below Android 10 the platform permission does not exist; Play Services gates it on
        // its own permission, which is install-time and therefore already granted.
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) return true
        return context.checkSelfPermission(Manifest.permission.ACTIVITY_RECOGNITION) ==
            PackageManager.PERMISSION_GRANTED
    }

    /** True when the accelerometer last reported the phone as still, recently enough to trust. */
    fun isStill(context: Context): Boolean {
        if (!Prefs.activityStill(context)) return false
        val age = System.currentTimeMillis() - Prefs.activityAt(context)
        return age in 0 until TRUST_MS
    }

    private fun pendingIntent(context: Context): PendingIntent {
        val intent = Intent(context.applicationContext, ActivityReceiver::class.java)
            .setAction(ACTION)
        // Mutable is required: Play Services writes the transition result into the intent.
        val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
        } else {
            PendingIntent.FLAG_UPDATE_CURRENT
        }
        return PendingIntent.getBroadcast(context.applicationContext, REQUEST_CODE, intent, flags)
    }

    fun start(context: Context) {
        if (!hasPermission(context)) {
            Log.i(TAG, "No activity recognition permission; falling back to speed-based stillness")
            return
        }

        // STILL is what the recording gate needs. The moving states are registered too so
        // that leaving STILL is reported promptly from either direction — relying on the
        // EXIT of one state alone means a missed event leaves the gate stuck shut.
        val transitions = mutableListOf<ActivityTransition>()
        for (activity in listOf(
            DetectedActivity.STILL,
            DetectedActivity.WALKING,
            DetectedActivity.ON_FOOT,
            DetectedActivity.RUNNING,
            DetectedActivity.ON_BICYCLE,
            DetectedActivity.IN_VEHICLE
        )) {
            for (type in listOf(
                ActivityTransition.ACTIVITY_TRANSITION_ENTER,
                ActivityTransition.ACTIVITY_TRANSITION_EXIT
            )) {
                transitions.add(
                    ActivityTransition.Builder()
                        .setActivityType(activity)
                        .setActivityTransition(type)
                        .build()
                )
            }
        }

        try {
            ActivityRecognition.getClient(context)
                .requestActivityTransitionUpdates(
                    ActivityTransitionRequest(transitions), pendingIntent(context)
                )
                .addOnSuccessListener { Log.i(TAG, "Activity transitions requested") }
                .addOnFailureListener { Log.w(TAG, "Activity transitions refused: ${it.message}") }
        } catch (e: Exception) {
            Log.w(TAG, "Activity recognition unavailable: ${e.message}")
        }
    }

    fun stop(context: Context) {
        try {
            ActivityRecognition.getClient(context)
                .removeActivityTransitionUpdates(pendingIntent(context))
        } catch (e: Exception) {
            // Nothing registered, or Play Services is gone. Either way there is nothing to do.
        }
        Prefs.clearActivity(context)
    }

    const val ACTION = "com.sst.attendance.ACTIVITY_TRANSITION"

    /**
     * Receives the transitions and records the one fact the tracker needs.
     *
     * Deliberately does nothing else — no location work, no upload. A broadcast receiver has
     * a few milliseconds to run, and the recording gate reads this from Prefs when it next
     * has a fix to judge.
     */
    class ActivityReceiver : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            if (!ActivityTransitionResult.hasResult(intent)) return
            val result = ActivityTransitionResult.extractResult(intent) ?: return

            // Events arrive oldest first, so the last one is the current state.
            for (event in result.transitionEvents) {
                val entering = event.transitionType == ActivityTransition.ACTIVITY_TRANSITION_ENTER
                val still = event.activityType == DetectedActivity.STILL

                // Entering STILL means stopped. Entering anything else, or leaving STILL,
                // means moving. Leaving a moving state says nothing on its own — the next
                // ENTER will — so it is ignored rather than guessed at.
                when {
                    still && entering -> Prefs.setActivityStill(context, true)
                    still && !entering -> Prefs.setActivityStill(context, false)
                    entering -> Prefs.setActivityStill(context, false)
                }
            }

            Log.i(TAG, "Activity now ${if (Prefs.activityStill(context)) "STILL" else "moving"}")
        }
    }
}
