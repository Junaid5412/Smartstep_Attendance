import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'native.dart';

/// Everything the UI needs to know about who is signed in and what rules apply.
///
/// The token lives in secure storage; the rules are cached in plain preferences so
/// the home screen can render its geofence and shift immediately on a cold start
/// rather than showing a spinner until the network answers.
class Session extends ChangeNotifier {
  Session({required this.defaultBaseUrl});

  /// Compiled-in fallback. The real value is normally whatever the operator set,
  /// so a staging build needs no code change.
  final String defaultBaseUrl;

  static const _secure = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  static const _kToken = 'token';
  static const _kBaseUrl = 'base_url';
  static const _kEmployee = 'employee_json';
  static const _kConfig = 'config_json';
  static const _kLogoPath = 'logo_path';
  static const _kLogoUrlCached = 'logo_url_cached';
  static const _kLastMe = 'last_me_json';

  String? _token;
  String _baseUrl = '';
  Map<String, dynamic> _employee = {};
  Map<String, dynamic> _config = {};

  /// The last successful /me response.
  ///
  /// Kept so the dashboard can show the real state of the day when the phone has no
  /// connection. Without it a cold start offline fell back to an empty day and told a
  /// checked-in employee they had "Not started", offering a Check In button - which
  /// invites either a duplicate check-in or the belief that their check-in was lost.
  Map<String, dynamic> _lastMe = {};

  Map<String, dynamic> get lastMe => _lastMe;

  String? get token => _token;
  bool get isSignedIn => _token != null;
  String get baseUrl => _baseUrl.isEmpty ? defaultBaseUrl : _baseUrl;

  Map<String, dynamic> get employee => _employee;
  Map<String, dynamic> get config => _config;

  String get employeeName => (_employee['name'] as String?) ?? '';
  String get employeeCode => (_employee['employee_code'] as String?) ?? '';
  String? get photoUrl => _employee['photo_url'] as String?;

  Map<String, dynamic> get settings =>
      (_config['settings'] as Map?)?.cast<String, dynamic>() ?? const {};

  Map<String, dynamic>? get geofence =>
      (_config['geofence'] as Map?)?.cast<String, dynamic>();

  String get geofenceName => (geofence?['name'] as String?) ?? 'No work area assigned';

  /// Every area this employee may be in.
  ///
  /// Falls back to the single [geofence] when the server has not sent a list, so a
  /// config cached from an older server keeps working. An empty list would read as
  /// "no area assigned", which the geofence code treats as always-inside.
  List<Map<String, dynamic>> get geofences {
    final list = ((_config['geofences'] as List?) ?? [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList();
    if (list.isNotEmpty) return list;
    final single = geofence;
    return single == null ? const [] : [single];
  }

  int get intervalMinutes => (settings['tracking_interval_min'] as num?)?.toInt() ?? 10;
  bool get requirePhoto => settings['require_photo'] == true;
  bool get enforceGeofence => settings['enforce_geofence'] == true;

  /// Whether this employee must be inside a work area to check *out*.
  ///
  /// Separate from check-in and normally off, so somebody who finishes away from
  /// site can still clock off rather than leaving the shift open.
  bool get enforceGeofenceCheckout =>
      settings['enforce_geofence_checkout'] == true;
  int get maxAccuracy => (settings['max_accuracy_m'] as num?)?.toInt() ?? 50;
  bool get allowMockLocation => settings['allow_mock_location'] == true;

  String get shiftLabel {
    final start = settings['shift_start'] ?? '--:--';
    final end = settings['shift_end'] ?? '--:--';
    return '$start – $end';
  }

  /// Shifts per day for this account: 1 full-time, 2 split shift (part-time).
  int get sessionsPerDay =>
      (settings['sessions_per_day'] as num?)?.toInt() == 2 ? 2 : 1;

  /// Second-shift hours, when configured. Null means "reuse the morning hours".
  String? get shift2Start => settings['shift2_start'] as String?;
  String? get shift2End => settings['shift2_end'] as String?;

  /// "08:00 – 12:00 + 13:00 – 17:00" for a configured split shift, else the
  /// single shift label. Shown in the work-area footer so a part-time monitor
  /// sees both halves of their day.
  String get fullShiftLabel {
    if (sessionsPerDay < 2) return shiftLabel;
    final s2 = (shift2Start?.isNotEmpty == true && shift2End?.isNotEmpty == true)
        ? '$shift2Start – $shift2End'
        : '2nd shift uses morning hours';
    return '$shiftLabel + $s2';
  }

  String get tileUrl =>
      (_config['map'] as Map?)?['tile_url'] as String? ??
      'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

  String get tileAttribution =>
      (_config['map'] as Map?)?['attribution'] as String? ?? '© OpenStreetMap contributors';

  Map<String, dynamic> get branding =>
      (_config['branding'] as Map?)?.cast<String, dynamic>() ?? const {};

  /// Company name for the header, falling back to the product name so the app
  /// never shows an empty title on a first run with no config yet.
  String get companyName =>
      (branding['company_name'] as String?)?.trim().isNotEmpty == true
          ? branding['company_name'] as String
          : 'SST Attendance';

  String? get logoUrl {
    final url = branding['logo_url'] as String?;
    return (url != null && url.isNotEmpty) ? url : null;
  }

  /// How long the branded loading screen should stay up, as a minimum.
  Duration get splashDuration {
    final seconds = (branding['splash_seconds'] as num?)?.toDouble() ?? 3.0;
    return Duration(milliseconds: (seconds.clamp(0.0, 10.0) * 1000).round());
  }

  /// Locally cached copy of the logo.
  ///
  /// The splash screen is the first thing drawn, so fetching the logo over the
  /// network there would mean a blank tile for the first moment of every launch —
  /// and nothing at all on a cold start with no signal. Caching the bytes means it
  /// renders instantly from disk instead.
  File? get logoFile {
    final path = _logoPath;
    if (path == null) return null;
    final file = File(path);
    return file.existsSync() ? file : null;
  }

  String? _logoPath;

  /// Download the logo if the URL has changed since the last time it was cached.
  ///
  /// Failures are silent by design: a missing cache falls back to the network
  /// image, and a missing network image falls back to the built-in mark, so the
  /// screen is never blocked on this.
  Future<void> _cacheLogo() async {
    final url = logoUrl;
    if (url == null) return;

    final prefs = await SharedPreferences.getInstance();
    if (prefs.getString(_kLogoUrlCached) == url && logoFile != null) {
      return; // Already have this exact logo on disk.
    }

    try {
      final response = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 20));
      if (response.statusCode != 200 || response.bodyBytes.isEmpty) return;

      final dir = await getApplicationSupportDirectory();
      final file = File('${dir.path}/brand_logo');
      await file.writeAsBytes(response.bodyBytes, flush: true);

      _logoPath = file.path;
      await prefs.setString(_kLogoPath, file.path);
      await prefs.setString(_kLogoUrlCached, url);
      notifyListeners();
    } catch (_) {
      // Keep whatever was cached before.
    }
  }

  /// True while tracking should be running according to the server's own clock.
  bool get trackingActiveNow =>
      (_config['today'] as Map?)?['tracking_active_now'] == true;

  Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();

    // Precedence: what the operator set on this device, then the address baked in
    // at build time, then the compiled-in fallback. Asking the native side rather
    // than trusting the Dart constant keeps the UI and the tracking service on the
    // same server.
    _baseUrl = prefs.getString(_kBaseUrl) ??
        await Native.defaultApiBase() ??
        defaultBaseUrl;

    try {
      _token = await _secure.read(key: _kToken);
    } catch (_) {
      // A broken keystore on some OEM builds: treat it as signed out rather than
      // crashing on launch.
      _token = null;
    }

    _employee = _decodeMap(prefs.getString(_kEmployee));
    _config = _decodeMap(prefs.getString(_kConfig));
    _logoPath = prefs.getString(_kLogoPath);
    _lastMe = _decodeMap(prefs.getString(_kLastMe));

    // The native service reads the base URL from its own store.
    await Native.setBaseUrl(_baseUrl);
    notifyListeners();
  }

  Map<String, dynamic> _decodeMap(String? raw) {
    if (raw == null || raw.isEmpty) return {};
    try {
      final decoded = jsonDecode(raw);
      return decoded is Map<String, dynamic> ? decoded : {};
    } catch (_) {
      return {};
    }
  }

  Future<void> setBaseUrl(String url) async {
    _baseUrl = url.replaceAll(RegExp(r'/+$'), '');
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kBaseUrl, _baseUrl);
    await Native.setBaseUrl(_baseUrl);
    notifyListeners();
  }

  /// Store the result of a successful sign-in.
  Future<void> signIn({
    required String token,
    required Map<String, dynamic> employee,
    required Map<String, dynamic> config,
  }) async {
    _token = token;
    _employee = employee;
    _config = config;

    try {
      await _secure.write(key: _kToken, value: token);
    } catch (_) {
      // Held in memory for this run even if secure storage refused.
    }

    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kEmployee, jsonEncode(employee));
    await prefs.setString(_kConfig, jsonEncode(config));

    await _pushToNative();
    // The native side reads the policy out of the config it was just handed, so this has
    // to come after _pushToNative — otherwise it re-applies the previous value.
    await Native.applyScreenCapturePolicy();
    notifyListeners();
    unawaited(_cacheLogo());
  }

  /// Remember the last successful /me, so an offline start can render the real day.
  Future<void> rememberMe(Map<String, dynamic> me) async {
    _lastMe = me;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kLastMe, jsonEncode(me));
  }

  /// Replace the cached rules after a /app-config refresh.
  Future<void> updateConfig(Map<String, dynamic> config) async {
    _config = config;

    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kConfig, jsonEncode(config));

    await _pushToNative();
    notifyListeners();
    unawaited(_cacheLogo());
  }

  /// The service needs the token and rules in its own store, because it runs when
  /// the Dart engine does not.
  Future<void> _pushToNative() async {
    final token = _token;
    if (token == null) return;
    await Native.setSession(token: token, configJson: jsonEncode(_config));
  }

  Future<void> signOut() async {
    _token = null;
    _employee = {};
    _config = {};
    _lastMe = {};

    try {
      await _secure.delete(key: _kToken);
    } catch (_) {
      // Nothing stored.
    }

    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kEmployee);
    await prefs.remove(_kConfig);
    await prefs.remove(_kLastMe);

    // Stops the service and clears its copy of the session, so tracking cannot
    // outlive the sign-out.
    await Native.clearSession();
    notifyListeners();
  }
}
