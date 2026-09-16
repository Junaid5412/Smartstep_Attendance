import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart';

import 'native.dart';

/// Foreground location and the permission ladder.
///
/// Background collection is the native service's job; this covers what the UI
/// needs: a good fix for check-in, and the permission state to show the gate.
class LocationHelper {
  /// A single fix good enough to check in with.
  ///
  /// [requiredAccuracy] comes from the employee's own rules; the server rejects a
  /// worse fix anyway, so the app waits here rather than sending one it knows will
  /// bounce. Waiting is better than failing: GPS typically tightens from 40 m to
  /// under 10 m within a few seconds of the first fix.
  static Future<LocationOutcome> currentFix({
    required int requiredAccuracy,
    Duration timeout = const Duration(seconds: 25),
  }) async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return LocationOutcome.failure(
        LocationProblem.serviceOff,
        'Location is switched off. Turn it on to check in.',
      );
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied) {
      return LocationOutcome.failure(
        LocationProblem.denied,
        'Location permission is required to check in.',
      );
    }
    if (permission == LocationPermission.deniedForever) {
      return LocationOutcome.failure(
        LocationProblem.deniedForever,
        'Location permission was permanently denied. Enable it in app settings.',
      );
    }

    try {
      // timeLimit is used rather than an outer .timeout() so the platform request
      // is actually cancelled, instead of being left running behind a Dart timeout.
      final position = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.best,
        timeLimit: timeout,
      );

      return LocationOutcome.success(position);
    } catch (_) {
      // No fix in time — usually indoors. Fall back to the last known position so
      // the employee at least sees a map, but flag it as stale so the caller can
      // insist on a fresh one before allowing a check-in.
      final last = await Geolocator.getLastKnownPosition();
      if (last != null) {
        return LocationOutcome(
          position: last,
          stale: true,
          problem: LocationProblem.timedOut,
          message: 'Could not get a fresh GPS fix. Move outdoors and try again.',
        );
      }
      return LocationOutcome.failure(
        LocationProblem.timedOut,
        'No GPS signal. Move outdoors or near a window and try again.',
      );
    }
  }

  /// A live stream for the check-in screen, so the accuracy circle tightens on
  /// screen while the employee waits.
  static Stream<Position> watch() => Geolocator.getPositionStream(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.best,
          distanceFilter: 0,
        ),
      );

  /* ---------------- permission state for the gate ---------------- */

  static Future<PermissionState> state() async {
    final serviceOn = await Geolocator.isLocationServiceEnabled();
    final whenInUse = await Permission.locationWhenInUse.status;
    // Android 11+ will not grant this in the same dialog as foreground location;
    // it has to be asked for separately, after foreground is already granted.
    final always = await Permission.locationAlways.status;
    final camera = await Permission.camera.status;
    final notifications = await Permission.notification.status;
    // The permission alone is not enough: the app-level switch or the channel can
    // still be off, which silences the tracking notification.
    final notificationsVisible = await Native.notificationsEnabled();

    return PermissionState(
      serviceOn: serviceOn,
      foreground: whenInUse.isGranted,
      background: always.isGranted,
      camera: camera.isGranted,
      notifications: notifications.isGranted && notificationsVisible,
      foregroundPermanentlyDenied: whenInUse.isPermanentlyDenied,
      backgroundPermanentlyDenied: always.isPermanentlyDenied,
      cameraPermanentlyDenied: camera.isPermanentlyDenied,
      notificationsPermanentlyDenied: notifications.isPermanentlyDenied,
    );
  }

  static Future<bool> requestForeground() async =>
      (await Permission.locationWhenInUse.request()).isGranted;

  /// Must be called only after foreground location is granted, otherwise Android
  /// silently denies it without showing anything to the user.
  static Future<bool> requestBackground() async =>
      (await Permission.locationAlways.request()).isGranted;

  static Future<bool> requestCamera() async =>
      (await Permission.camera.request()).isGranted;

  static Future<bool> requestNotifications() async =>
      (await Permission.notification.request()).isGranted;
}

enum LocationProblem { none, serviceOff, denied, deniedForever, timedOut }

class LocationOutcome {
  final Position? position;
  final bool stale;
  final LocationProblem problem;
  final String message;

  const LocationOutcome({
    this.position,
    this.stale = false,
    this.problem = LocationProblem.none,
    this.message = '',
  });

  factory LocationOutcome.success(Position position) =>
      LocationOutcome(position: position);

  factory LocationOutcome.failure(LocationProblem problem, String message) =>
      LocationOutcome(problem: problem, message: message);

  bool get ok => position != null && !stale;

  /// geolocator surfaces the OS mock flag, so no native check is needed.
  bool get isMocked => position?.isMocked ?? false;
}

class PermissionState {
  final bool serviceOn;
  final bool foreground;
  final bool background;
  final bool camera;
  final bool notifications;
  final bool foregroundPermanentlyDenied;
  final bool backgroundPermanentlyDenied;
  final bool cameraPermanentlyDenied;
  final bool notificationsPermanentlyDenied;

  const PermissionState({
    required this.serviceOn,
    required this.foreground,
    required this.background,
    required this.camera,
    required this.notifications,
    this.foregroundPermanentlyDenied = false,
    this.backgroundPermanentlyDenied = false,
    this.cameraPermanentlyDenied = false,
    this.notificationsPermanentlyDenied = false,
  });

  /// Notifications are excluded from the hard gate because tracking still works
  /// without them, and blocking attendance over a notification toggle would be a
  /// worse outcome for the employee than the missing indicator.
  ///
  /// They are not simply shrugged off, though: the consent screen promises a
  /// visible indicator whenever tracking runs, so [notifications] being false is
  /// surfaced as a standing warning on the home screen rather than ignored.
  bool get allEssentialGranted => serviceOn && foreground && background && camera;

  bool get anyPermanentlyDenied =>
      foregroundPermanentlyDenied || backgroundPermanentlyDenied || cameraPermanentlyDenied;
}
