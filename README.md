# SST Attendance

Geofenced attendance with photo verification, one-device login and working-hours
route tracking. Two parts:

- `APK/` — Android app (Flutter), package `com.sst.attendance`
- `Website/` — REST API for the app + admin panel for HR/management

The module lives inside the existing SmartStep ERP install and shares its database
and credentials. Every app account links to an `hr_employees` row, so attendance
data is directly usable by the HR and payroll modules.

**Status:** the website and API are built and tested end to end against the live
XAMPP install. The Android app builds and produces a working release APK
(54 MB universal, currently **debug-signed** — see `APK/README.md` for how to sign
it properly). It has not yet been run on a physical handset.

## Setup

1. Import the schema (adds `att_*` tables to the existing ERP database). The
   charset flag is not optional — without it the MySQL CLI transcodes the file as
   cp1252 and the `©` in the default attribution is stored as a literal `?`:

```bash
mysql -u root --default-character-set=utf8mb4 u304363898_sstqa < Attendance/Website/database/schema.sql
```

Then apply the migrations in order:

```bash
mysql -u root --default-character-set=utf8mb4 u304363898_sstqa < Attendance/Website/database/migrations/002_google_tiles.sql
```

2. On a live server, create `Website/config/config.local.php` and override the
   defaults — at minimum the token secret, which must not stay at its shipped value:

```php
<?php
define('ATT_ERP_URL', 'https://your-domain.com');
define('ATT_TOKEN_SECRET', '<64 random hex characters>');
```

3. Confirm the API answers:

```bash
curl https://your-domain.com/Attendance/Website/api/v1/
```

## Admin panel

Open `/Attendance/Website/` — it redirects to the dashboard when signed in, or to
the attendance login page when not. Credentials are the ERP's own `users`
accounts; an ERP session already open in the browser is adopted automatically, so
there is no second sign-in.

| Page | What it does |
|---|---|
| `admin/index.php` | today's tiles, who is outside their area right now, trips awaiting review, setup gaps |
| `admin/live-map.php` | latest position of everyone, auto-refreshing, filterable by on-shift / outside |
| `admin/register.php` | the daily register with photos, late/outside minutes, CSV export, delete |
| `admin/routes.php` | route replay for one employee-day, with a playback scrubber |
| `admin/trips.php` | review queue: approve as company work, or reject |
| `admin/employees.php` | enrol accounts, set work area / shift / ping interval, reset password or device |
| `admin/geofences.php` | draw circle or polygon work areas on the map |
| `admin/devices.php` | one-device bindings, release or block a handset, reset history |
| `admin/settings.php` | app branding, defaults for new accounts, map tile source, minimum app version |
| `admin/data.php` | JSON feed for the panel's own maps (admin session, not app tokens) |

Roles from the `roles` table: `super_admin`, `it_administrator`, `hr_office` and
`hr` can change data; `manager` and `hr_executive` get the same pages read-only.
Anyone else is refused.

Leaflet 1.9.4 and Leaflet.draw 1.0.4 are vendored under `assets/vendor/`, so the
panel needs no CDN — only the tile server is fetched remotely.

## Database

| Table | Holds |
|---|---|
| `att_employees` | app login accounts, linked to `hr_employees` |
| `att_employee_settings` | assigned geofence, shift hours, ping interval, rules |
| `att_geofences` | circle or polygon work areas |
| `att_devices` | the one-device binding, one active row per employee |
| `att_device_resets` | audit of admin device releases |
| `att_tokens` | app sessions, device-bound |
| `att_attendance` | the daily register (one row per employee per work date) |
| `att_location_logs` | route points captured during the shift |
| `att_geofence_events` | trips outside the area, with reason and admin verdict |
| `att_settings` | module defaults (interval, tiles, token lifetime) |
| `att_audit_logs` | all admin and employee actions |

## API v1

Base: `/Attendance/Website/api/v1`. All responses share one envelope:

```json
{ "success": true, "data": {}, "message": "", "code": "OK" }
```

Authenticate with `Authorization: Bearer <token>` (or `X-App-Token` on hosts that
strip the Authorization header).

| Method | Route | Purpose |
|---|---|---|
| GET | `/` | health check |
| POST | `/login` | sign in; binds the device on first success |
| POST | `/logout` | end the session (keeps the device binding) |
| GET | `/me` | profile, bound device, today's state |
| GET | `/app-config` | geofence, shift, ping interval, tiles |
| POST | `/consent` | record the background-location disclosure |
| POST | `/change-password` | change own password |
| POST | `/check-in` | multipart; photo + position |
| POST | `/check-out` | multipart; photo + position |
| POST | `/locations/batch` | push buffered route points |
| POST | `/geofence-reason` | explain a trip outside the area |
| GET | `/attendance-history` | own register for a date range |
| POST | `/heartbeat` | liveness + permission state of the tracking service |

The route is named `/app-config`, not `/config`, because the ERP's root
`.htaccess` denies any request whose last path segment is `config`, `includes` or
`database`.

### Error codes the app branches on

`INVALID_CREDENTIALS`, `ACCOUNT_DISABLED`, `DEVICE_ALREADY_BOUND`,
`DEVICE_BLOCKED`, `DEVICE_RESET`, `TOKEN_EXPIRED`, `TOKEN_REVOKED`,
`CONSENT_REQUIRED`, `PHOTO_REQUIRED`, `PHOTO_TYPE`, `PHOTO_TOO_LARGE`,
`LOCATION_ACCURACY`, `MOCK_LOCATION`, `OUTSIDE_GEOFENCE`, `NOT_WORK_DAY`,
`ALREADY_CHECKED_IN`, `ALREADY_CHECKED_OUT`, `NOT_CHECKED_IN`.

These are a contract with the installed app: add new codes freely, but do not
change what an existing one means without shipping a matching app update.

## Outside trips, and why they need a noise guard

A trip is opened when an employee's tracked position leaves their assigned area and
closed when they return, carrying the duration, the furthest point reached, the
employee's stated reason and the admin's verdict. Time outside is subtracted from
worked hours, so this feeds attendance status — it is not merely a report.

Originally a *single* outside fix opened a trip. That is unsafe: indoor fixes run at
±100 m, and against a fence of comparable radius GPS drift alone can place a
stationary employee outside. The result is trips that never happened, employees asked
to explain journeys they never took, and a review queue whose noise hides the real
cases. Two guards now apply, both server-side in `locations/batch`:

1. **Credibility** — a fix only counts as outside if the distance beyond the fence is
   at least as large as the fix's own accuracy figure. Reported 30 m out with ±100 m
   accuracy proves nothing. Non-credible fixes are still stored (the route map should
   show what was measured) but cannot open a trip, and they reset the run.
2. **Confirmation** — `att_settings.outside_confirm_points` consecutive credible
   outside fixes are required, default 2. The counter is seeded from stored history,
   not just the current batch, because the app uploads one point per request and a
   per-batch counter could never reach two.

The response reports `uncertain_outside` so a site whose fences are too tight for its
signal quality is diagnosable rather than merely puzzling.

## "Locate now" — on-demand position

The server cannot reach a phone, so a request is parked in `att_device_commands` and
collected on the device's next poll (`GET /api/v1/poll`, every
`command_poll_seconds`, default 45). On a `locate` the service sets `forceFix`,
which bypasses the interval throttle, and solicits GPS directly.

Measured end to end on device: **28 seconds**, returning a **1 m GPS fix**.

Requests expire after `ATT_COMMAND_TTL_SECONDS` (180) — answering a stale request
would report a position nobody is waiting for any more, which is misleading rather
than helpful. Every request is written to the audit log with the admin who made it:
asking for a person's live position is a monitoring action, not a neutral read.

The trade-off is a request per employee per interval all shift — roughly 800 per
phone per 10-hour day at 45 s. Tunable in Settings. Firebase push would remove that
cost entirely and give ~2–5 s; the schema (`att_devices.fcm_token`) already
anticipates it if the polling cost becomes a problem.

## Design notes

**One-device login.** `att_devices` carries a stored generated column that equals
the employee id while the row is `active` and `NULL` otherwise, under a unique
key — so a second active device is refused by the database, not only by code.
Signing out does not release the binding; only an admin reset does, and a reset
revokes the old handset's tokens so it is logged out without needing a push.

**Idempotent route upload.** The app buffers points in local SQLite and may retry
a batch. `client_uid` is the idempotency key: a duplicate point is counted and
skipped without re-running the fence-crossing logic, which would otherwise
double-count time spent outside.

**Server time is authoritative.** Check-in and check-out use the server clock, so
changing the device clock cannot back-date attendance. Device timestamps are used
only to order route points, and points dated more than 5 minutes in the future are
rejected.

**Geofence maths is duplicated on purpose.** `includes/geo.php` (haversine for
circles, ray casting for polygons) is mirrored in the Flutter app so a device can
judge inside/outside while offline. Keep the two implementations in step.

**Time outside does not count as worked time.** `attFinaliseDay()` subtracts
`outside_minutes` before deciding present / late / half-day.

**Deleting attendance is logged, not silent.** `attDeleteAttendance()` writes the
entire row into `att_audit_logs` as JSON — inside the same transaction as the
delete, so a record can never disappear without the entry explaining it — then
removes the photo files. That audit entry is the only recovery route; there is no
undo. Route history is kept by default and merely detached, because
`att_location_logs.attendance_id` has no foreign key and deleting the parent would
otherwise leave it pointing at nothing. Photo deletion is `realpath`-contained to
the uploads directory: the path comes from our own database, but a delete driven by
a stored string is exactly where a traversal bug turns destructive.

## The Android app

Flutter UI plus a native Kotlin tracking service, because Android kills the Flutter
engine when the app is backgrounded and a Dart timer would stop silently. Full
detail in `APK/README.md`; the parts that matter to the server are:

- The service talks to `/locations/batch` and `/heartbeat` on its own, using its own
  copy of the token in EncryptedSharedPreferences. It does not need the UI running.
- `client_uid` on every queued point is the idempotency key, so a retried batch
  never double-counts time outside the fence.
- Geofence maths is implemented three times — `includes/geo.php`, `GeoFence.kt` and
  `checkin_screen.dart`. Change one, change all three.

## Branding

The app's logo and display name come from `Settings → App branding` and reach the
phone through `/api/v1/app-config`, so changing them needs no app update.

Resolution order is `att_settings.app_logo` → `company_settings.company_logo` →
built-in mark, and the same for the name. Leaving the attendance fields empty means
the ERP stays the single place a company's identity is maintained — nobody uploads
the same logo twice.

The logo appears on the login screen, the dashboard header and the in-app loading
screen. It deliberately does **not** appear on the Android system splash: that is
drawn before any app code runs, so it can only show a bundled resource. The native
splash uses a vector pin on the brand colour (`values-v31/styles.xml` for the
Android 12+ SplashScreen API, `drawable/launch_background.xml` for older releases),
and the Dart loading screen that follows it carries the real logo.

## Map styles

One setting — `att_settings.map_style` — drives every map in the product. The panel
reads it through `window.ATT`, the app through `/api/v1/app-config`, so switching
style needs no app update: it applies at the phone's next sync.

| Style | Source | Notes |
|---|---|---|
| `google_direct` | `mt1.google.com/vt?lyrs=m&hl=en` | **Currently active.** Google's own roadmap tiles, English labels |
| `google_direct_hybrid` | `lyrs=y` | satellite + English labels |
| `google_direct_terrain` | `lyrs=p` | terrain + English labels |
| `google_like` | CARTO Voyager (OSM data) | Google-roadmap look, no licensing exposure |
| `google_light` | CARTO Positron (OSM data) | closest measured match to Google's palette |
| `osm_tint` | OSM + colour transform | no third party at all |
| `osm_raw` | OSM | stock look |
| `custom` | `tile_url` + `tile_filter_css` | self-hosted or paid provider |

**`google_direct` is not a licensed endpoint.** `mt*.google.com` is undocumented and
reserved for Google's own clients; it sits outside Google Maps Platform's terms and
can be rate-limited or blocked without warning. It is the active setting because
this deployment asked for it and already uses it elsewhere. If the tiles ever stop,
switch to `google_like` in Settings — one click, no redeploy, and the look barely
changes. For a fully licensed route, the Map Tiles API path is also implemented:
set the tile source to Google in Settings and supply an API key with billing.

### How the styles were chosen

Not by eye. `osm_tint`'s transform was fitted by hill-climbing CSS filter
parameters against Google's palette; the best achievable is
`saturate(0.76) brightness(1.07) contrast(1.02) hue-rotate(2deg)`, and the residuals
show why a filter alone is not enough — motorways stay pink (Δ65) and water stays
cyan (Δ29), because a global transform cannot recolour features independently.

The styled basemaps were then measured on real pixels: the same four Doha tiles
(waterfront, city centre, parkland, highway) fetched from each source, quantised,
and each dominant colour scored against its nearest Google palette entry. Averages:
CARTO Positron 9.4, CARTO Voyager 12.9, unmodified OSM 21.6.

Note when doing this yourself: OSM serves **indexed** PNGs, so `imagecolorat()`
returns a palette index rather than a packed RGB. Call `imagepalettetotruecolor()`
first or every channel reads as zero.

## Notes for deployment

- If you move off `google_direct`, OpenStreetMap's public tile server has a usage
  policy a company fleet can exceed — prefer a styled provider or self-hosting.
- Background location requires an in-app disclosure and consent for Play Store
  review; `/consent` records it and the app must block tracking until it succeeds.
- `att_location_logs` is the high-volume table. Plan an archival job once daily
  row counts are known.
