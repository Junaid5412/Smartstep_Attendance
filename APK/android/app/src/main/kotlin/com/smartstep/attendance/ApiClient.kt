package com.smartstep.attendance

import android.content.Context
import android.util.Log
import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL

/**
 * Minimal JSON client for the native service.
 *
 * The Flutter side has its own client; this one exists because the service must be
 * able to upload while the Dart engine is not running. Deliberately built on
 * HttpURLConnection so the service pulls in no extra dependency.
 */
object ApiClient {

    private const val TAG = "SSTApi"
    private const val CONNECT_TIMEOUT_MS = 15000
    private const val READ_TIMEOUT_MS = 30000

    data class Response(val code: Int, val body: JSONObject?) {
        val ok: Boolean get() = code in 200..299 && body?.optBoolean("success", false) == true

        /** Machine-readable error code from the server envelope. */
        val errorCode: String? get() = body?.optString("code", null)

        /**
         * True when the server has invalidated this session: the token was revoked,
         * expired, or an admin released the device. The service must stop and the UI
         * must send the employee back to sign-in.
         */
        val sessionDead: Boolean
            get() = code == 401 || errorCode in listOf(
                "INVALID_TOKEN", "TOKEN_REVOKED", "TOKEN_EXPIRED",
                "DEVICE_RESET", "ACCOUNT_DISABLED", "UNAUTHENTICATED"
            )
    }

    /** Addresses that mean "unconfigured" and must never be sent a token. */
    private fun configured(base: String): Boolean {
        val host = try { URL(base).host } catch (e: Exception) { "" }
        return host.isNotEmpty() && host != "your-domain.com" && host != "example.com"
    }

    fun post(context: Context, path: String, payload: JSONObject): Response {
        val token = Prefs.token(context)
        val base = Prefs.baseUrl(context)

        // The service posts locations and polls unattended, so a placeholder address
        // here would quietly ship the employee's token and their positions to whoever
        // owns that domain. Refused rather than trusted to be right.
        if (!configured(base)) {
            Log.w(TAG, "No server address configured — request not sent")
            return Response(0, null)
        }

        val url = base + "/" + path.trimStart('/')

        var connection: HttpURLConnection? = null
        return try {
            connection = (URL(url).openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                connectTimeout = CONNECT_TIMEOUT_MS
                readTimeout = READ_TIMEOUT_MS
                doOutput = true
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
                setRequestProperty("Accept", "application/json")
                if (token != null) {
                    setRequestProperty("Authorization", "Bearer $token")
                    // Some shared hosts strip Authorization; the API accepts this too.
                    setRequestProperty("X-App-Token", token)
                }
            }

            connection.outputStream.use { it.write(payload.toString().toByteArray(Charsets.UTF_8)) }

            val code = connection.responseCode
            val stream = if (code in 200..299) connection.inputStream else connection.errorStream
            val text = stream?.bufferedReader()?.use(BufferedReader::readText) ?: ""

            val json = try {
                if (text.isNotBlank()) JSONObject(text) else null
            } catch (e: Exception) {
                // An HTML error page from the web server rather than an API response.
                // The opening of it is logged too: without it this reads as a network
                // fault, when it is usually a PHP notice printed ahead of the JSON, and
                // the message naming the file and line is the whole diagnosis. Only the
                // unparseable case is logged, and only its first 400 characters — a
                // successful response carries the employee's own data and is never logged.
                Log.w(TAG, "Non-JSON response from $path (HTTP $code): " +
                    text.take(400).replace('\n', ' '))
                null
            }

            Response(code, json)
        } catch (e: Exception) {
            // No connectivity, DNS failure, timeout: the caller keeps the queue intact.
            Log.d(TAG, "POST $path failed: ${e.message}")
            Response(0, null)
        } finally {
            connection?.disconnect()
        }
    }
}
