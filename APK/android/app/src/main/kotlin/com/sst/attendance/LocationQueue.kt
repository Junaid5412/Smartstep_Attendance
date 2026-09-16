package com.sst.attendance

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

/**
 * On-device buffer for route points.
 *
 * Every fix is written here first and only deleted once the server has confirmed
 * it. That is what makes a shift in a basement or a dead-zone site still produce a
 * complete route: the points simply upload later. client_uid is generated here and
 * is the server's idempotency key, so a batch that times out after the server
 * already stored it does not create duplicates on retry.
 */
class LocationQueue(context: Context) :
    SQLiteOpenHelper(context.applicationContext, DB_NAME, null, DB_VERSION) {

    companion object {
        private const val DB_NAME = "sst_attendance_queue.db"
        // 2: added outside_streak. onUpgrade drops the table, which is acceptable here
        // and only here — the queue holds points not yet uploaded, and the alternative
        // is shipping a schema mismatch that makes every insert fail silently. Anything
        // already uploaded is safe on the server; at worst a few minutes of unsent route
        // is lost on the single upgrade.
        private const val DB_VERSION = 3
        private const val TABLE = "queued_points"

        /** Ceiling matching ATT_MAX_BATCH_POINTS on the server. */
        const val BATCH_SIZE = 200

        /**
         * Hard cap on the buffer. At a 5-minute interval this is over a month of
         * continuous tracking; past it the oldest points are dropped so a phone
         * that has been offline for weeks cannot fill its storage.
         */
        private const val MAX_ROWS = 10000

        private val TIMESTAMP: SimpleDateFormat
            get() = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
    }

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            """
            CREATE TABLE $TABLE (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_uid TEXT NOT NULL UNIQUE,
                lat REAL NOT NULL,
                lng REAL NOT NULL,
                accuracy REAL,
                altitude REAL,
                speed REAL,
                heading REAL,
                battery INTEGER,
                is_charging INTEGER,
                is_mock INTEGER NOT NULL DEFAULT 0,
                provider TEXT,
                recorded_at TEXT NOT NULL,
                inside_fence INTEGER,
                distance_m INTEGER,
                -- Consecutive credible outside observations the device had made when this
                -- point was taken. See TrackingService.outsideStreak.
                outside_streak INTEGER NOT NULL DEFAULT 0,
                -- Where the phone genuinely was when the reported fix was simulated,
                -- when a raw provider could still supply a non-simulated answer.
                real_lat REAL,
                real_lng REAL
            )
            """.trimIndent()
        )
        db.execSQL("CREATE INDEX idx_recorded ON $TABLE (recorded_at)")
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        if (oldVersion < 3) {
            // ALTER, not drop: this table is the offline buffer, and dropping it on
            // upgrade would throw away any points recorded while out of signal.
            try {
                db.execSQL("ALTER TABLE $TABLE ADD COLUMN real_lat REAL")
                db.execSQL("ALTER TABLE $TABLE ADD COLUMN real_lng REAL")
            } catch (e: Exception) {
                // Columns already exist (a reinstall over a newer schema).
            }
        }
    }

    fun enqueue(point: TrackingService.Fix) {
        val values = ContentValues().apply {
            put("client_uid", UUID.randomUUID().toString())
            put("lat", point.lat)
            put("lng", point.lng)
            put("accuracy", point.accuracy)
            put("altitude", point.altitude)
            put("speed", point.speedKmh)
            put("heading", point.heading)
            put("battery", point.batteryPct)
            put("is_charging", if (point.isCharging) 1 else 0)
            put("is_mock", if (point.isMock) 1 else 0)
            put("provider", point.provider)
            put("recorded_at", TIMESTAMP.format(Date(point.timeMillis)))
            put("inside_fence", if (point.insideFence) 1 else 0)
            put("distance_m", point.distanceM)
            put("outside_streak", point.outsideStreak)
            put("real_lat", point.realLat)
            put("real_lng", point.realLng)
        }

        writableDatabase.insertWithOnConflict(
            TABLE, null, values, SQLiteDatabase.CONFLICT_IGNORE
        )
        trim()
    }

    /** Oldest first, so the server sees crossings in the order they happened. */
    fun peekBatch(limit: Int = BATCH_SIZE): Pair<List<Long>, JSONArray> {
        val ids = mutableListOf<Long>()
        val array = JSONArray()

        readableDatabase.query(
            TABLE, null, null, null, null, null, "recorded_at ASC, id ASC", limit.toString()
        ).use { cursor ->
            while (cursor.moveToNext()) {
                ids.add(cursor.getLong(cursor.getColumnIndexOrThrow("id")))

                val point = JSONObject()
                point.put("client_uid", cursor.getString(cursor.getColumnIndexOrThrow("client_uid")))
                point.put("lat", cursor.getDouble(cursor.getColumnIndexOrThrow("lat")))
                point.put("lng", cursor.getDouble(cursor.getColumnIndexOrThrow("lng")))
                point.put("recorded_at", cursor.getString(cursor.getColumnIndexOrThrow("recorded_at")))
                point.put("is_mock", cursor.getInt(cursor.getColumnIndexOrThrow("is_mock")) == 1)
                point.put("outside_streak", cursor.getInt(cursor.getColumnIndexOrThrow("outside_streak")))

                putIfPresent(cursor, point, "accuracy", "accuracy")
                putIfPresent(cursor, point, "real_lat", "real_lat")
                putIfPresent(cursor, point, "real_lng", "real_lng")
                putIfPresent(cursor, point, "altitude", "altitude")
                putIfPresent(cursor, point, "speed", "speed")
                putIfPresent(cursor, point, "heading", "heading")

                val batteryIndex = cursor.getColumnIndexOrThrow("battery")
                if (!cursor.isNull(batteryIndex)) point.put("battery", cursor.getInt(batteryIndex))

                val chargingIndex = cursor.getColumnIndexOrThrow("is_charging")
                if (!cursor.isNull(chargingIndex)) {
                    point.put("is_charging", cursor.getInt(chargingIndex) == 1)
                }

                val providerIndex = cursor.getColumnIndexOrThrow("provider")
                if (!cursor.isNull(providerIndex)) point.put("provider", cursor.getString(providerIndex))

                array.put(point)
            }
        }
        return Pair(ids, array)
    }

    private fun putIfPresent(
        cursor: android.database.Cursor,
        target: JSONObject,
        column: String,
        key: String
    ) {
        val index = cursor.getColumnIndexOrThrow(column)
        if (!cursor.isNull(index)) target.put(key, cursor.getDouble(index))
    }

    /** Called only after the server has acknowledged the batch. */
    fun delete(ids: List<Long>) {
        if (ids.isEmpty()) return
        val placeholders = ids.joinToString(",") { "?" }
        writableDatabase.delete(
            TABLE, "id IN ($placeholders)", ids.map { it.toString() }.toTypedArray()
        )
    }

    fun count(): Int {
        readableDatabase.rawQuery("SELECT COUNT(*) FROM $TABLE", null).use { cursor ->
            return if (cursor.moveToFirst()) cursor.getInt(0) else 0
        }
    }

    private fun trim() {
        val excess = count() - MAX_ROWS
        if (excess <= 0) return
        writableDatabase.execSQL(
            "DELETE FROM $TABLE WHERE id IN (SELECT id FROM $TABLE ORDER BY recorded_at ASC LIMIT ?)",
            arrayOf(excess.toString())
        )
    }

    fun clear() {
        writableDatabase.delete(TABLE, null, null)
    }
}
