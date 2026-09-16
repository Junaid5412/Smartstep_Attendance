# SST Attendance — Android app

Flutter app, package `com.sst.attendance`. Talks to `Attendance/Website/api/v1`.

## Building

**Verified build**: Flutter 3.44.8 / Dart 3.12.2, Gradle 9.1.0, AGP 9.0.1, Kotlin
2.3.20, compileSdk/targetSdk 36, minSdk 24. Produces a 54 MB universal APK.

Toolchain on this machine: Flutter at `C:\src\flutter`, Android SDK at
`%LOCALAPPDATA%\Android\Sdk`, JDK is Android Studio's bundled JBR 21.

### Two gotchas that cost real time

**`kotlin.incremental=false` in `android/gradle.properties` is not optional here.**
With it on, release builds failed with `Could not close incremental caches`, in a
different plugin module each run — the signature of an on-access virus scanner
holding Kotlin's `.tab` cache files while Gradle tries to close them. One run burned
99 minutes retrying before failing. `kotlin.compiler.execution.strategy=in-process`
is set for the same reason: one fewer process competing for those locks.

**Launcher icons must exist before the first release build.** `shrinkResources`
renames them (`res/9w.png`), so `unzip -l | grep ic_launcher` finding nothing is
normal — check `aapt2 dump badging | grep application-icon` instead. The current
icons are generated (brand square + pin + wordmark); the wordmark is upscaled from a
bitmap font and would benefit from a designed replacement.

```bash
cd Attendance/APK
flutter pub get
flutter run                      # debug, on a connected handset
```

Point the app at a server at build time rather than editing Dart:

```bash
flutter build apk --release -Papi.Base=https://your-domain.com/Attendance/Website/api/v1
```

The value lands in `BuildConfig.DEFAULT_API_BASE` (see `android/app/build.gradle.kts`).
Both halves of the app read it from there — Dart asks the native side via
`Native.defaultApiBase()` rather than keeping its own copy, because an earlier build
proved the two could otherwise disagree: `-Papi.Base` configured the service while
the UI still pointed at the placeholder domain. The constant in `lib/main.dart` is
only a last-resort fallback if the channel call fails.

The login screen's **Server settings** dialog overrides it at runtime and persists
per device, which is the quickest way to retarget a already-installed APK.

### Release signing

The build currently falls back to the **debug key**, because `android/key.properties`
does not exist. A debug-signed APK installs and runs fine for testing but must not be
distributed — Play will reject it, and debug keys are not secret.

To sign properly, generate a keystore and create the properties file yourself (both
steps involve passwords, so they are yours to run):

```bash
keytool -genkey -v -keystore sst-attendance.jks -keyalg RSA -keysize 2048 -validity 10000 -alias sst
```

Then copy `android/key.properties.example` to `android/key.properties`, fill in the
four values, and rebuild. Keep the `.jks` backed up — losing it means you can never
ship an update to the same app listing.

Testing against XAMPP on the same machine:

```bash
adb reverse tcp:80 tcp:80
```

then set the server to `http://localhost/sstqa/Attendance/Website/api/v1`. Cleartext
is permitted for `localhost` and `10.0.2.2` only — see
`android/app/src/main/res/xml/network_security_config.xml`. Everything else is
HTTPS-only.

## Why the background work is native

Android freezes and then kills the Flutter engine when the app is backgrounded. A
Dart-side timer stops without any error, so the employee's route comes back full of
holes and nobody finds out until an admin looks at it. Tracking therefore lives in
Kotlin:

| File | Role |
|---|---|
| `TrackingService.kt` | foreground service (`foregroundServiceType=location`), collects fixes, judges the geofence, queues, uploads |
| `LocationQueue.kt` | SQLite buffer; `client_uid` is the server's idempotency key |
| `ApiClient.kt` | HttpURLConnection JSON client, so the service needs no extra dependency |
| `Prefs.kt` | EncryptedSharedPreferences — how the UI hands the service its token and rules |
| `GeoFence.kt` | circle + polygon maths, a mirror of `includes/geo.php` |
| `DeviceIdentity.kt` | the persistent fingerprint behind the one-device rule |
| `BootReceiver.kt` | restarts tracking after reboot or app update |
| `MainActivity.kt` | MethodChannel `com.sst.attendance/native` |

The service also registers a `ConnectivityManager.NetworkCallback` and flushes the
queue the moment a network becomes available, rather than waiting for the next tick.
Without it, a phone regaining signal after a dead spot looks stuck for minutes — and
to an employee watching the pending count, indistinguishable from broken.

## Notifications are a promise, not a nicety

The consent screen tells the employee an "on duty" notification is shown whenever
tracking runs. If they later switch notifications off, tracking continues and that
indicator silently disappears — so the app checks the real state via
`notificationsEnabled()` (which tests both the app-level switch *and* the channel
importance, because either being off hides it) and shows a non-dismissible warning on
the home screen with a button into the notification settings.

Notifications are deliberately **not** part of `allEssentialGranted`: blocking
attendance over a notification toggle would harm the employee more than the missing
indicator does. The consent wording now states this outcome plainly rather than
promising something that can quietly stop being true.

The service reads everything it needs from `Prefs` and never depends on the Flutter
engine being alive. Dart's only job is the UI and handing over the session.

## Screens

`login` → `consent` → `permissions gate` → `home` → `check in/out`, plus
`trip reason` and `history`.

The order is not cosmetic. Consent must be recorded before any location is
collected (Play policy for background location), and the permission gate must pass
before home can offer a check-in it would fail to complete. The gate re-checks on
every resume, because Android lets the employee revoke background location at any
time and a revoked grant stops tracking silently.

## Device identity

`DeviceIdentity.uid()` hashes SSAID (`ANDROID_ID`) together with a UUID minted on
first run and held in encrypted preferences. SSAID survives reinstall and is scoped
to the app signing key; the UUID covers cheap devices that ship a duplicated SSAID
across a batch.

**Known limit:** clearing app data drops the stored UUID, so the composite changes
and the employee needs an admin device reset. That fails in the safe direction — it
refuses a login rather than allowing an unauthorised device swap — but it means
"clear app data" is not a self-service fix. Play Services **Block Store** would
survive that too and is the natural next step if resets become a support burden.

## Geofence maths exists three times

`includes/geo.php`, `GeoFence.kt` and `checkin_screen.dart` each implement the same
haversine-for-circles and ray-casting-for-polygons rules. That is deliberate: the
service must judge inside/outside with no network, and the check-in screen must tell
the employee *before* they take a photo. **Change one, change all three** — the
server's verdict is authoritative for records, so a device that disagrees shows the
employee a different answer from the one the admin sees.

## Three bugs worth remembering

**Capture and upload were on separate clocks.** A committed fix was only uploaded on
a geofence crossing or once the queue reached 200 points; otherwise it waited for the
periodic tick. Since capture and the tick run on independent five-minute cycles,
one or two points sat unsent essentially all the time — and the employee reads
"1 location waiting to upload" as background tracking being broken, when the fix had
in fact been captured perfectly. Every commit now uploads immediately. At a
five-minute interval that is one small request per fix, which is worth paying for:
the queue sits at zero in normal operation, so a non-zero count now means something
is genuinely wrong, and the admin's live map is actually live. Measured on device
with the screen off, upload lag went from 198 seconds to under a second.


**Starting the service must be idempotent.** The home screen polls every 45 seconds
and used to call `startTracking()` each time. Each call re-ran `requestUpdates()` and
`startTicking()`, which cancelled the pending tick and re-registered the providers —
so a five-minute cadence was reset every 45 seconds and could never elapse. The
symptom was subtle and misleading: fixes *were* being captured, but no upload ever
ran, so the app showed an ever-growing "N points waiting to upload" and looked like a
network fault. Diagnosed from `dumpsys activity services`, where `lastStartId=75`
gave it away. `onStartCommand` now returns early when already running unless the
interval actually changed, and the UI only starts the service when it is not running.
If you change either side, check `lastStartId` stays in single digits.

**The first fix to arrive is the worst one.** The network provider answers almost
immediately with 100 m accuracy while GPS is still settling, so committing the first
arrival filled routes with coarse points. An arrival now opens a 25-second settle
window and becomes a candidate; anything more accurate replaces it, and the best is
committed when the window closes (or immediately, if it already meets the employee's
accuracy requirement). Because a 5-minute interval otherwise gives GPS a single
attempt per point, a coarse arrival also actively solicits one GPS fix via
`getCurrentLocation`, so GPS is powered up only when a point is actually due.

Indoors this still yields network-grade accuracy — GPS cannot fix through a roof, and
no amount of code changes that. Validate route quality outdoors.

## Battery reality

Continuous location is expensive, and OEM power managers (Xiaomi, Oppo, Vivo,
Samsung) kill foreground services aggressively. The app asks for the
battery-optimisation exemption and shows a dismissible warning while it is missing,
but on some handsets the employee must also whitelist the app in the vendor's own
battery settings. Gaps in a route with a silent heartbeat are the signature of this;
the admin panel's "no recent report" marker is what surfaces it.

## Not yet built

- iOS. The Dart layer is portable; `TrackingService` would need a
  `CLLocationManager` equivalent with `allowsBackgroundLocationUpdates`, and iOS
  gives far weaker guarantees about interval fidelity.
- Push notifications. `att_devices.fcm_token` exists in the schema and `login`
  accepts the field, but nothing sends yet.
- Offline check-in queueing. Route points buffer offline; a check-in still needs a
  connection, because the server assigns its authoritative timestamp.
