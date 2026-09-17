package com.smartstep.attendance

import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.util.Log
import org.json.JSONObject

/**
 * Whether uninstall protection is on, and telling the server about it.
 *
 * Kept out of TrackingService because the admin receiver fires whether or not the service
 * is running — including the case that matters most, an employee deactivating protection
 * with the app closed.
 */
object DeviceAdminState {

    private const val TAG = "SSTAdmin"

    fun component(context: Context): ComponentName =
        ComponentName(context.applicationContext, AttendanceDeviceAdmin::class.java)

    fun manager(context: Context): DevicePolicyManager =
        context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager

    fun isActive(context: Context): Boolean = try {
        manager(context).isAdminActive(component(context))
    } catch (e: Exception) {
        false
    }

    /**
     * Release protection so the app can be uninstalled.
     *
     * Called both from the panel's "allow uninstall" command and by the employee's own
     * Settings action; removeActiveAdmin is idempotent, so a duplicate is harmless.
     */
    fun release(context: Context): Boolean = try {
        if (isActive(context)) {
            manager(context).removeActiveAdmin(component(context))
        }
        true
    } catch (e: Exception) {
        Log.w(TAG, "Could not release device admin: ${e.message}")
        false
    }

    /**
     * Tell the server the protection state changed.
     *
     * Fire-and-forget on a background thread: this runs inside a broadcast receiver,
     * which must not block, and a failed report is not worth retrying — the next poll
     * carries the state anyway.
     */
    fun report(context: Context, active: Boolean) {
        val applicationContext = context.applicationContext
        Thread {
            try {
                ApiClient.post(
                    applicationContext,
                    "device-admin",
                    JSONObject().put("active", active)
                )
            } catch (e: Exception) {
                Log.d(TAG, "Admin state report failed: ${e.message}")
            }
        }.start()
    }
}
