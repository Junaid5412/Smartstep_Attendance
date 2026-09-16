import 'package:flutter/services.dart';

/// Dart side of the MethodChannel to the native tracking service.
///
/// The Kotlin service owns background location; this class is how the UI hands it
/// a session and asks what it is doing. Every call is defensive: a channel error
/// must never crash the screen the employee is looking at.
/// A geofence crossing pushed up from the tracking service.
class FenceEvent {
  final bool outside;
  final int distanceM;
  final String areaName;

  const FenceEvent({
    required this.outside,
    required this.distanceM,
    required this.areaName,
  });
}

class Native {
  static const MethodChannel _channel = MethodChannel('com.sst.attendance/native');
  static const EventChannel _events = EventChannel('com.sst.attendance/events');

  /// Crossings as the service detects them.
  ///
  /// The service notices a breach within its watch interval — seconds — but the UI
  /// used to find out only on its own poll, so a warning meant to be immediate could
  /// arrive most of a minute late. Listening here closes that gap.
  static Stream<FenceEvent> fenceEvents() {
    return _events.receiveBroadcastStream().where((event) {
      return event is Map && event['type'] == 'fence';
    }).map((event) {
      final map = (event as Map).cast<String, dynamic>();
      return FenceEvent(
        outside: map['outside'] == true,
        distanceM: (map['distance_m'] as num?)?.toInt() ?? 0,
        areaName: (map['area_name'] as String?) ?? '',
      );
    });
  }

  /// The persistent device fingerprint used for the one-device binding.
  static Future<String?> deviceUid() async {
    try {
      return await _channel.invokeMethod<String>('deviceUid');
    } on PlatformException {
      return null;
    }
  }

  static Future<Map<String, String>> deviceInfo() async {
    try {
      final raw = await _channel.invokeMapMethod<String, String>('deviceInfo');
      return raw ?? <String, String>{};
    } on PlatformException {
      return <String, String>{};
    }
  }

  /// The server address baked in at build time via `-Papi.Base=...`.
  ///
  /// Read from the native side rather than duplicated as a Dart constant, so one
  /// build flag configures both halves of the app and they cannot disagree.
  static Future<String?> defaultApiBase() async {
    try {
      return await _channel.invokeMethod<String>('defaultApiBase');
    } on PlatformException {
      return null;
    }
  }

  static Future<void> setBaseUrl(String url) async {
    try {
      await _channel.invokeMethod('setBaseUrl', {'url': url});
    } on PlatformException {
      // The service falls back to its compiled-in default.
    }
  }

  /// Hands the token and the current rules to the service. Called after sign-in
  /// and after every config refresh, so an admin's change reaches the service
  /// without the employee restarting anything.
  static Future<void> setSession({required String token, required String configJson}) async {
    try {
      await _channel.invokeMethod('setSession', {'token': token, 'config': configJson});
    } on PlatformException {
      // Ignored: tracking simply keeps its previous rules.
    }
  }

  static Future<void> clearSession() async {
    try {
      await _channel.invokeMethod('clearSession');
    } on PlatformException {
      // Ignored.
    }
  }

  static Future<void> startTracking() async {
    try {
      await _channel.invokeMethod('startTracking');
    } on PlatformException {
      // Ignored.
    }
  }

  static Future<void> stopTracking() async {
    try {
      await _channel.invokeMethod('stopTracking');
    } on PlatformException {
      // Ignored.
    }
  }

  /// Whether this build is the genuine, unmodified app.
  ///
  /// Checks the signing certificate, the package name and the debuggable flag. A
  /// failure is not advisory: the app refuses to go further, because a re-signed build
  /// is one whose behaviour cannot be reasoned about at all.
  ///
  /// Fails *closed* on a channel error — if the native side cannot answer, something
  /// is wrong enough that carrying on regardless would defeat the purpose.
  static Future<({bool ok, String? reason})> integrityCheck() async {
    try {
      final raw = await _channel.invokeMapMethod<String, dynamic>('integrityCheck');
      if (raw == null) return (ok: false, reason: 'Security check did not complete.');
      return (ok: raw['ok'] == true, reason: raw['reason'] as String?);
    } on PlatformException {
      return (ok: false, reason: 'Security check could not run.');
    } on MissingPluginException {
      return (ok: false, reason: 'Security check is unavailable in this build.');
    }
  }

  /// Tells the service whether a shift is currently open.
  ///
  /// The service decides on its own whether to record, from the shift hours it was
  /// given — which say nothing about someone who is still on site an hour after
  /// their shift was due to end. This is the register's answer, and it is what lets
  /// recording continue through overtime.
  static Future<void> setShiftOpen(bool open) async {
    try {
      await _channel.invokeMethod('setShiftOpen', {'open': open});
    } on PlatformException {
      // Ignored.
    }
  }

  /// Silences the leaving-the-area alarm for the departure now in progress.
  ///
  /// Called as soon as a reason is submitted. The service also learns this from the
  /// server on its next upload, but that can be minutes away, and an alarm that
  /// sounds again after the employee has already answered reads as a broken app.
  static Future<void> setOutsideReasonGiven(bool given) async {
    try {
      await _channel.invokeMethod('setOutsideReasonGiven', {'given': given});
    } on PlatformException {
      // Ignored.
    }
  }

  /* ---------------- in-app update ---------------- */

  /// Whether the employee has allowed this app to install packages.
  /// Whether uninstall protection is active on this handset.
  /// Re-applies the screenshot/recording policy from the freshly stored config.
  /// Returns whether capture is now allowed.
  static Future<bool> applyScreenCapturePolicy() async {
    try {
      return await _channel.invokeMethod<bool>('applyScreenCapturePolicy') ?? false;
    } catch (_) {
      // A failure here leaves the previous policy in force, which is the blocking one
      // by default — the safe direction to fail in.
      return false;
    }
  }

  static Future<bool> isDeviceAdminActive() async {
    try {
      return await _channel.invokeMethod<bool>('isDeviceAdminActive') ?? false;
    } catch (_) {
      return false;
    }
  }

  /// Opens Android's own grant screen. There is no programmatic path, by design.
  static Future<void> requestDeviceAdmin() async {
    try {
      await _channel.invokeMethod('requestDeviceAdmin');
    } catch (_) {
      // The screen is missing on some heavily modified builds; the UI stays honest
      // about the state because it re-reads it rather than assuming success.
    }
  }

  /// Releases protection so the app can be uninstalled. Used by the admin's remote
  /// release, never offered to the employee.
  static Future<bool> releaseDeviceAdmin() async {
    try {
      return await _channel.invokeMethod<bool>('releaseDeviceAdmin') ?? false;
    } catch (_) {
      return false;
    }
  }

  static Future<bool> canInstallPackages() async {
    try {
      return await _channel.invokeMethod<bool>('canInstallPackages') ?? false;
    } on PlatformException {
      return false;
    }
  }

  /// Opens the system screen where that permission is granted.
  static Future<void> requestInstallPermission() async {
    try {
      await _channel.invokeMethod('requestInstallPermission');
    } on PlatformException {
      // Some builds hide the screen; the installer prompts anyway.
    }
  }

  /// Hands a downloaded APK to the system installer.
  ///
  /// Returns false when the intent could not be launched. It cannot install silently —
  /// Android requires the employee to confirm unless the app is a device owner, which
  /// means MDM provisioning at factory-reset time.
  static Future<bool> installApk(String path) async {
    try {
      return await _channel.invokeMethod<bool>('installApk', {'path': path}) ?? false;
    } on PlatformException {
      return false;
    }
  }

  /// Asks the service to flush its queue now, e.g. after the employee taps sync.
  static Future<void> syncNow() async {
    try {
      await _channel.invokeMethod('syncNow');
    } on PlatformException {
      // Ignored.
    }
  }

  static Future<TrackingStatus> trackingStatus() async {
    try {
      final raw = await _channel.invokeMapMethod<String, dynamic>('trackingStatus');
      return TrackingStatus.fromMap(raw ?? const {});
    } on PlatformException {
      return const TrackingStatus();
    }
  }

  /// True when Android may doze the app, which throttles tracking into gaps.
  static Future<bool> isBatteryOptimised() async {
    try {
      return await _channel.invokeMethod<bool>('isBatteryOptimised') ?? false;
    } on PlatformException {
      return false;
    }
  }

  static Future<void> requestBatteryExemption() async {
    try {
      await _channel.invokeMethod('requestBatteryExemption');
    } on PlatformException {
      // Ignored.
    }
  }

  static Future<void> openAppSettings() async {
    try {
      await _channel.invokeMethod('openAppSettings');
    } on PlatformException {
      // Ignored.
    }
  }

  static Future<void> openLocationSettings() async {
    try {
      await _channel.invokeMethod('openLocationSettings');
    } on PlatformException {
      // Ignored.
    }
  }

  /// Whether the tracking notification will actually be visible.
  ///
  /// Asked of the system rather than of permission_handler, because the
  /// POST_NOTIFICATIONS grant and "notifications are actually shown" are different
  /// states: switching the app's notifications off in settings leaves the
  /// permission granted but silences the channel, and that is what hides the
  /// tracking indicator the app promises the employee.
  ///
  /// Defaults to true on failure so a channel error never produces a false alarm.
  static Future<bool> notificationsEnabled() async {
    try {
      return await _channel.invokeMethod<bool>('notificationsEnabled') ?? true;
    } on PlatformException {
      return true;
    }
  }

  static Future<void> openNotificationSettings() async {
    try {
      await _channel.invokeMethod('openNotificationSettings');
    } on PlatformException {
      // Ignored.
    }
  }
}

/// What the native service reports about itself.
class TrackingStatus {
  final bool running;
  final bool wanted;
  final int pending;
  final int lastFixAtMillis;
  final int lastUploadAtMillis;
  final int intervalMinutes;

  const TrackingStatus({
    this.running = false,
    this.wanted = false,
    this.pending = 0,
    this.lastFixAtMillis = 0,
    this.lastUploadAtMillis = 0,
    this.intervalMinutes = 10,
  });

  factory TrackingStatus.fromMap(Map<String, dynamic> map) => TrackingStatus(
        running: map['running'] == true,
        wanted: map['wanted'] == true,
        pending: (map['pending'] as num?)?.toInt() ?? 0,
        lastFixAtMillis: (map['last_fix_at'] as num?)?.toInt() ?? 0,
        lastUploadAtMillis: (map['last_upload_at'] as num?)?.toInt() ?? 0,
        intervalMinutes: (map['interval_min'] as num?)?.toInt() ?? 10,
      );

  DateTime? get lastFixAt => lastFixAtMillis > 0
      ? DateTime.fromMillisecondsSinceEpoch(lastFixAtMillis)
      : null;

  DateTime? get lastUploadAt => lastUploadAtMillis > 0
      ? DateTime.fromMillisecondsSinceEpoch(lastUploadAtMillis)
      : null;
}
