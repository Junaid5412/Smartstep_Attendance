package com.sst.attendance

import android.app.admin.DeviceAdminReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import android.widget.Toast

/**
 * Device administrator, registered for one reason: while it is active Android refuses to
 * uninstall this app from Settings.
 *
 * What this genuinely does, and what it does not:
 *
 *  - The Uninstall button is blocked, and the app cannot be removed by the usual route.
 *  - It does NOT make the app unremovable. The employee can open
 *    Settings > Security > Device admin apps, deactivate this, and then uninstall. That
 *    is three deliberate steps rather than one, and onDisabled() below reports it — so
 *    removal becomes visible and attributable instead of silent, which is the honest
 *    description of the protection on offer.
 *  - Truly unremovable requires Device Owner, which can only be set on a factory-reset
 *    handset via provisioning. See the note in the panel's Devices page.
 *
 * No policy powers are requested: no password rules, no wipe, no camera lock. An
 * attendance app has no business holding them, and every extra policy is another thing
 * an employee is right to object to.
 */
class AttendanceDeviceAdmin : DeviceAdminReceiver() {

    override fun onEnabled(context: Context, intent: Intent) {
        Log.i(TAG, "Device admin enabled — app removal now requires deactivating it first")
        Toast.makeText(context, "Attendance protection is on", Toast.LENGTH_SHORT).show()
        // Reported so the panel can show that the handset is protected without waiting
        // for someone to check the phone.
        DeviceAdminState.report(context, true)
    }

    override fun onDisabled(context: Context, intent: Intent) {
        // The interesting event. Either an admin released it on purpose, or the employee
        // has just cleared the way to uninstall — and the panel should be able to tell
        // the difference by whether a release was issued.
        Log.w(TAG, "Device admin disabled — the app can now be uninstalled")
        DeviceAdminState.report(context, false)
    }

    override fun onDisableRequested(context: Context, intent: Intent): CharSequence {
        // Shown on the confirmation screen when someone tries to turn this off.
        return "Turning this off allows the attendance app to be removed. " +
            "Your administrator is notified when it happens."
    }

    companion object {
        private const val TAG = "SSTAdmin"
    }
}
