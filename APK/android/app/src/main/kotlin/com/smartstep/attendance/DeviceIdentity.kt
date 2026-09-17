package com.smartstep.attendance

import android.annotation.SuppressLint
import android.content.Context
import android.os.Build
import android.provider.Settings
import java.security.MessageDigest

/**
 * The persistent device fingerprint behind the one-device login rule.
 *
 * Uses a fixed, deterministic hardware fingerprint composed of the hardware
 * attributes and Settings.Secure.ANDROID_ID (SSAID).
 *
 * This identifier does NOT change upon uninstalling and reinstalling the app on
 * the same physical phone. It binds strictly to the handset, preventing account
 * sharing across different phones while allowing seamless re-installation on
 * the same device without triggering "DEVICE_ALREADY_BOUND".
 */
object DeviceIdentity {

    @SuppressLint("HardwareIds")
    fun uid(context: Context): String {
        val ssaid = try {
            Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID)
                ?.trim()
                ?.lowercase() ?: ""
        } catch (e: Exception) {
            ""
        }

        // Fixed, deterministic hardware fingerprint:
        // No random UUIDs so it remains consistent across app uninstall and reinstall on the same device.
        val material = listOf(
            ssaid.ifEmpty { "no_ssaid" },
            (Build.MANUFACTURER ?: "").trim().lowercase(),
            (Build.BRAND ?: "").trim().lowercase(),
            (Build.MODEL ?: "").trim().lowercase(),
            (Build.DEVICE ?: "").trim().lowercase(),
            (Build.BOARD ?: "").trim().lowercase(),
            (Build.HARDWARE ?: "").trim().lowercase()
        ).joinToString("|")

        val uid = sha256("sst_fixed_device|$material")
        Prefs.setDeviceUid(context, uid)
        return uid
    }

    private fun sha256(value: String): String {
        val digest = MessageDigest.getInstance("SHA-256").digest(value.toByteArray(Charsets.UTF_8))
        return digest.joinToString("") { "%02x".format(it) }
    }

    /** Metadata the admin panel shows next to the binding. */
    fun describe(): Map<String, String> = mapOf(
        "model" to (Build.MODEL ?: "unknown"),
        "brand" to (Build.MANUFACTURER ?: "unknown"),
        "os_version" to "Android ${Build.VERSION.RELEASE} (API ${Build.VERSION.SDK_INT})"
    )
}
