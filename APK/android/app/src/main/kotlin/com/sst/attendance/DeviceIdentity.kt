package com.sst.attendance

import android.annotation.SuppressLint
import android.content.Context
import android.os.Build
import android.provider.Settings
import java.security.MessageDigest
import java.util.UUID

/**
 * The persistent device fingerprint behind the one-device login rule.
 *
 * The requirement is an identifier that does not change on its own, because every
 * change forces an admin to reset the binding. Android gives no perfect answer, so
 * this composes two imperfect ones:
 *
 *  - SSAID (Settings.Secure.ANDROID_ID). Since Android 8 this is scoped to the app
 *    signing key and the user, and it survives app reinstall and cache clearing.
 *    It changes on factory reset — which is the behaviour we want, since a factory
 *    reset is a genuinely different device state.
 *  - A UUID minted on first run and kept in encrypted preferences. This covers the
 *    handful of devices that report a null or duplicated SSAID (some cheap tablets
 *    ship with the same value flashed across a batch).
 *
 * The two are hashed together, so neither is transmitted in the clear, and the
 * result is stable for the life of the installation.
 *
 * Known limit worth being honest about: clearing app data drops the stored UUID, so
 * the composite changes and the employee needs an admin reset. That is the
 * conservative failure direction — it refuses a login rather than allowing a device
 * swap — but it does mean "clear data" is not a self-service fix. Google Play
 * Services Block Store would survive that too, and is the natural next step if
 * resets become a support burden.
 */
object DeviceIdentity {

    @SuppressLint("HardwareIds")
    fun uid(context: Context): String {
        Prefs.deviceUid(context)?.let { return it }

        val ssaid = try {
            Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID) ?: ""
        } catch (e: Exception) {
            ""
        }

        val installUuid = Prefs.installUuid(context) ?: UUID.randomUUID().toString().also {
            Prefs.setInstallUuid(context, it)
        }

        // The model is folded in as a weak tie-break for batches of devices that
        // share a flashed SSAID; it never changes for a given handset.
        val material = listOf(ssaid, installUuid, Build.MODEL ?: "", Build.MANUFACTURER ?: "")
            .joinToString("|")

        val uid = sha256(material)
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
