package com.smartstep.attendance

import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import java.security.MessageDigest

/**
 * Startup integrity checks.
 *
 * The point is to make a modified or cloned build refuse to run. The signature check is
 * the load-bearing one: tools like MT Manager and App Cloner must re-sign an APK to
 * change it, and they cannot reproduce this certificate without the keystore — which
 * exists only on the operator's machine. Everything else here is cheap corroboration.
 *
 * What this is NOT: proof against a determined attacker. On a rooted device with a
 * runtime instrumentation framework, every check below can be patched out. That is a
 * property of running on hardware someone else controls, not a flaw to be fixed by
 * adding more checks. It raises the cost and stops casual tampering; the defences that
 * actually hold are on the server — impossible-travel detection in particular, which is
 * derived from stored data rather than reported by the phone.
 *
 * Deliberately absent: enumerating installed "capture apps" by package name. Android
 * restricts package visibility, and App Cloner's whole purpose is renaming packages, so
 * a blocklist would pass on the exact tool it is meant to catch — worse than no check,
 * because it would be trusted.
 */
object Integrity {

    /**
     * SHA-256 of the release signing certificate.
     *
     * Read from the signed APK with apksigner, so the private key is never involved.
     * If the app is ever re-keyed this must be updated in the same commit, or every
     * install will refuse to start.
     */
    private const val RELEASE_CERT_SHA256 =
        "e02f484fb4fc02d6d1fa5c4c9d36ad58d8b3059c46c0612db3d4b4df86b93f1c"

    private const val EXPECTED_PACKAGE = "com.smartstep.attendance"

    /** A failed check, with something specific enough to act on. */
    data class Result(val ok: Boolean, val reason: String? = null)

    fun check(context: Context): Result {
        // App Cloner renames the package; that is how it installs a second copy.
        if (context.packageName != EXPECTED_PACKAGE) {
            return Result(false, "This is not the official app (package ${context.packageName}).")
        }

        val signature = certificateSha256(context)
            ?: return Result(false, "The app signature could not be read.")

        val matchesDirectCert = signature.equals(RELEASE_CERT_SHA256, ignoreCase = true)
        val isPlayInstall = try {
            val installer = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                context.packageManager.getInstallSourceInfo(context.packageName).installingPackageName
            } else {
                @Suppress("DEPRECATION")
                context.packageManager.getInstallerPackageName(context.packageName)
            }
            installer == "com.android.vending" || installer == "com.google.android.feedback"
        } catch (_: Exception) {
            false
        }

        if (!matchesDirectCert && !isPlayInstall) {
            return Result(false, "This copy of the app has been modified or re-signed.")
        }

        // A debuggable release build means someone rebuilt it from source.
        val debuggable = (context.applicationInfo.flags and
            android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE) != 0
        if (debuggable) {
            return Result(false, "This is a debug build, not the released app.")
        }

        return Result(true)
    }

    /**
     * The hex SHA-256 of the app's own signing certificate.
     *
     * Uses the v2+ signing block on modern Android; the deprecated path is kept for
     * older handsets, where it is the only option available.
     */
    private fun certificateSha256(context: Context): String? {
        return try {
            val manager = context.packageManager
            val bytes: ByteArray = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                val info = manager.getPackageInfo(
                    context.packageName, PackageManager.GET_SIGNING_CERTIFICATES
                )
                // The first entry is the current signer. A rotated key would add more,
                // which this app does not use.
                info.signingInfo?.apkContentsSigners?.firstOrNull()?.toByteArray()
                    ?: return null
            } else {
                @Suppress("DEPRECATION")
                val info = manager.getPackageInfo(
                    context.packageName, PackageManager.GET_SIGNATURES
                )
                @Suppress("DEPRECATION")
                info.signatures?.firstOrNull()?.toByteArray() ?: return null
            }

            MessageDigest.getInstance("SHA-256").digest(bytes)
                .joinToString("") { "%02x".format(it) }
        } catch (e: Exception) {
            null
        }
    }

}
