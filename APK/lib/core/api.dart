import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';

import 'package:http/http.dart' as http;

import 'session.dart';

/// The server's response envelope, matching Website/includes/api.php.
///
/// [code] is the contract the UI branches on — DEVICE_ALREADY_BOUND drives the
/// "contact admin" screen, OUTSIDE_GEOFENCE drives the distance warning — so it is
/// surfaced rather than collapsed into a boolean.
class ApiResult {
  final bool success;
  final String code;
  final String message;
  final dynamic data;
  final int statusCode;

  const ApiResult({
    required this.success,
    required this.code,
    required this.message,
    this.data,
    this.statusCode = 0,
  });

  Map<String, dynamic> get map =>
      data is Map<String, dynamic> ? data as Map<String, dynamic> : <String, dynamic>{};

  /// No connectivity, DNS failure or timeout — distinct from a rejection, because
  /// the app retries these silently instead of showing an error.
  bool get isNetworkFailure => statusCode == 0 && code == 'NETWORK';

  /// The session is gone: revoked, expired, or the admin released the device.
  bool get sessionDead => const {
        'UNAUTHENTICATED',
        'INVALID_TOKEN',
        'TOKEN_REVOKED',
        'TOKEN_EXPIRED',
        'DEVICE_RESET',
        'ACCOUNT_DISABLED',
      }.contains(code);

  /// This build is below the minimum the server still accepts.
  ///
  /// Kept out of sessionDead deliberately: signing the employee out would lose the
  /// session for a problem that has nothing to do with their credentials, and they
  /// would then be unable to sign back in either — the refusal applies to every
  /// request, including login.
  bool get upgradeRequired => code == 'UPGRADE_REQUIRED';

  /// Where to get the build that would fix it. Carried on the refusal itself,
  /// because an app locked out this way has no other way to find out.
  String? get upgradeUrl => (data?['download_url'] as String?);
  String? get upgradeVersion => (data?['latest_version'] as String?);
  String? get upgradeNotes => (data?['notes'] as String?);

  factory ApiResult.network(String message) =>
      ApiResult(success: false, code: 'NETWORK', message: message);
}

/// HTTP client for the app API.
class Api {
  Api(this.session);

  final Session session;

  static const Duration _timeout = Duration(seconds: 30);
  static const Duration _uploadTimeout = Duration(seconds: 60);

  Uri _uri(String path, [Map<String, String>? query]) =>
      Uri.parse('${session.baseUrl}/${path.replaceFirst(RegExp(r'^/'), '')}')
          .replace(queryParameters: query);

  Map<String, String> _headers({bool json = true}) {
    final headers = <String, String>{'Accept': 'application/json'};
    if (json) headers['Content-Type'] = 'application/json; charset=utf-8';

    final token = session.token;
    if (token != null) {
      headers['Authorization'] = 'Bearer $token';
      // Some shared hosts strip Authorization; the API accepts this fallback.
      headers['X-App-Token'] = token;
    }
    return headers;
  }

  ApiResult _decode(http.Response response) {
    try {
      final body = jsonDecode(response.body);
      if (body is! Map<String, dynamic>) {
        return ApiResult(
          success: false,
          code: 'BAD_RESPONSE',
          message: 'Unexpected response from the server.',
          statusCode: response.statusCode,
        );
      }
      return ApiResult(
        success: body['success'] == true,
        code: (body['code'] as String?) ?? 'UNKNOWN',
        message: (body['message'] as String?) ?? '',
        data: body['data'],
        statusCode: response.statusCode,
      );
    } catch (_) {
      // An HTML error page from the web server rather than an API response. Logged,
      // truncated, because "unreadable response" on its own gives nobody anything to
      // act on — the PHP notice inside it names the file and line.
      debugPrint('Non-JSON response (HTTP ${response.statusCode}): '
          '${response.body.substring(0, response.body.length.clamp(0, 400))}');
      return ApiResult(
        success: false,
        code: 'BAD_RESPONSE',
        message: 'The server returned an unreadable response (HTTP ${response.statusCode}).',
        statusCode: response.statusCode,
      );
    }
  }

  /// Hosts that mean "nobody filled this in", which must never receive a request.
  ///
  /// A wrong address is an inconvenience; a wrong address that arrives with the
  /// employee's bearer token attached is a disclosure. Refused here, before the
  /// request is built, rather than relying on every default staying correct.
  static const _placeholderHosts = {'your-domain.com', 'example.com'};

  bool get _serverConfigured {
    final host = Uri.tryParse(session.baseUrl)?.host ?? '';
    return host.isNotEmpty && !_placeholderHosts.contains(host);
  }

  Future<ApiResult> _guard(Future<http.Response> Function() send) async {
    if (!_serverConfigured) {
      return const ApiResult(
        success: false,
        code: 'SERVER_NOT_SET',
        message: 'No server address is set for this app. Ask your administrator to '
            'enter it on the sign-in screen.',
      );
    }
    try {
      return _decode(await send());
    } on SocketException {
      return ApiResult.network('No internet connection.');
    } on HttpException {
      return ApiResult.network('Could not reach the server.');
    } on HandshakeException {
      return ApiResult.network('Secure connection to the server failed.');
    } catch (_) {
      return ApiResult.network('The server did not respond. Try again.');
    }
  }

  Future<ApiResult> get(String path, [Map<String, String>? query]) => _guard(
        () => http.get(_uri(path, query), headers: _headers(json: false)).timeout(_timeout),
      );

  Future<ApiResult> post(String path, [Map<String, dynamic>? body]) => _guard(
        () => http
            .post(_uri(path), headers: _headers(), body: jsonEncode(body ?? {}))
            .timeout(_timeout),
      );

  /// Multipart POST for check-in and check-out, where a photo rides along.
  Future<ApiResult> postMultipart(
    String path,
    Map<String, String> fields, {
    File? photo,
    String photoField = 'photo',
  }) async {
    try {
      final request = http.MultipartRequest('POST', _uri(path))
        ..headers.addAll(_headers(json: false))
        ..fields.addAll(fields);

      if (photo != null) {
        request.files.add(await http.MultipartFile.fromPath(photoField, photo.path));
      }

      // A longer timeout than plain JSON: this carries an image over what is often
      // a weak connection at a work site.
      final streamed = await request.send().timeout(_uploadTimeout);
      return _decode(await http.Response.fromStream(streamed));
    } on SocketException {
      return ApiResult.network('No internet connection.');
    } catch (_) {
      return ApiResult.network('Upload failed. Try again.');
    }
  }

  /* ---------------- endpoints ---------------- */

  Future<ApiResult> login({
    required String loginCode,
    required String password,
    required String deviceUid,
    required Map<String, String> device,
    required String appVersion,
  }) =>
      post('login', {
        'login_code': loginCode,
        'password': password,
        'device_uid': deviceUid,
        'device_model': device['model'],
        'device_brand': device['brand'],
        'os_version': device['os_version'],
        'app_version': appVersion,
        'platform': 'android',
      });

  Future<ApiResult> logout() => post('logout');

  Future<ApiResult> me() => get('me');

  Future<ApiResult> appConfig() => get('app-config');

  /// The full history of departures from the work area, with each one's verdict.
  ///
  /// Kept out of `me()` deliberately: that is polled every fifteen seconds and only
  /// needs what is outstanding, while this is read when the employee opens their
  /// notifications.
  Future<ApiResult> trips({int limit = 50, int offset = 0}) =>
      get('trips?limit=$limit&offset=$offset');

  Future<ApiResult> acceptConsent() => post('consent', {'accepted': true});

  Future<ApiResult> changePassword(String current, String next) =>
      post('change-password', {'current_password': current, 'new_password': next});

  Future<ApiResult> submitTripReason({
    required int eventId,
    required String category,
    required String reason,
  }) =>
      post('geofence-reason', {
        'event_id': eventId,
        'category': category,
        'reason': reason,
      });

  Future<ApiResult> history({String? from, String? to}) => get('attendance-history', {
        if (from != null) 'from': from,
        if (to != null) 'to': to,
      });
}
