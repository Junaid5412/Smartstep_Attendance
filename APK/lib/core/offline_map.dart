import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The offline map archive: fetching it, and knowing whether we have it.
///
/// The employee's own position and the geofence verdict never needed the network — the
/// phone works those out itself. What broke with no signal was the *picture*: tiles come
/// from a remote server, so the check-in screen showed a grey rectangle. This downloads
/// one Protomaps PMTiles archive covering Qatar so that picture is there regardless.
///
/// Deliberately never blocking. The download runs in the background and the map falls
/// back to online tiles until it lands; a check-in must never wait on a map.
class OfflineMap extends ChangeNotifier {
  OfflineMap({required this.baseUrl, required this.token});

  String baseUrl;
  String? token;

  static const _kVersion = 'offline_map_version';
  static const _kBytes = 'offline_map_bytes';
  static const _kStyleVersion = 'offline_style_version';

  File? _archive;
  String? _version;
  File? _style;
  String? _styleVersion;

  bool _downloading = false;
  double _progress = 0;
  String? _lastError;

  /// The archive on disk, or null when there is none yet.
  File? get archive => _archive;
  /// Both are required. The archive is geometry; the style says how to paint it.
  /// Reporting ready with only one produces a blank map, which is worse than
  /// honestly falling back to online tiles.
  bool get ready => _archive != null && _style != null;

  File? get style => _style;
  bool get downloading => _downloading;

  /// 0..1 while downloading.
  double get progress => _progress;

  String? get lastError => _lastError;

  Future<File> get _target async {
    final dir = await getApplicationSupportDirectory();
    return File('${dir.path}/qatar.pmtiles');
  }

  Future<File> get _styleTarget async {
    final dir = await getApplicationSupportDirectory();
    return File('${dir.path}/map_style.json');
  }

  /// Load whatever was downloaded previously. Called on launch, before any network.
  Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();
    _version = prefs.getString(_kVersion);

    final file = await _target;
    final expected = prefs.getInt(_kBytes) ?? 0;

    // Size is checked, not just existence. A download killed mid-write leaves a
    // truncated file that PMTiles would fail to parse in a far more confusing way
    // than simply not having it.
    if (file.existsSync() && expected > 0 && file.lengthSync() == expected) {
      _archive = file;
    } else if (file.existsSync()) {
      await file.delete();
      _archive = null;
      _version = null;
    }

    final styleFile = await _styleTarget;
    if (styleFile.existsSync() && styleFile.lengthSync() > 0) {
      _style = styleFile;
      _styleVersion = prefs.getString(_kStyleVersion);
    }
    notifyListeners();
  }

  /// Fetch the archive if the server has one we do not.
  ///
  /// Silent about failures by design: with no signal this simply cannot run, and that
  /// is the normal case rather than an error worth putting on screen.
  Future<void> sync() async {
    if (_downloading || token == null) return;

    try {
      final manifest = await http.get(
        Uri.parse('$baseUrl/map-pack?part=manifest'),
        headers: {'Authorization': 'Bearer $token'},
      ).timeout(const Duration(seconds: 20));

      if (manifest.statusCode != 200) return;

      final body = jsonDecode(manifest.body) as Map<String, dynamic>;
      final pack = ((body['data'] as Map?)?['pack'] as Map?)?.cast<String, dynamic>();

      if (pack == null || pack['available'] != true) return;
      if (pack['format'] != 'pmtiles') return;

      final version = pack['version'] as String?;
      final bytes = (pack['bytes'] as num?)?.toInt() ?? 0;
      if (version == null || bytes <= 0) return;

      final styleVersion =
          ((body['data'] as Map?)?['style'] as Map?)?['version'] as String?;

      // The style is small and cheap, so it is checked first: without it the archive
      // cannot be drawn at all, and a phone that has the 13 MB but no style would
      // sit there with a useless file.
      if (styleVersion != null && (_style == null || _styleVersion != styleVersion)) {
        await _downloadStyle(styleVersion);
      }

      if (_archive != null && _version == version) return;

      await _download(version, bytes);
    } catch (e) {
      _lastError = 'Could not check for the offline map.';
    }
  }

  Future<void> _download(String version, int expectedBytes) async {
    _downloading = true;
    _progress = 0;
    _lastError = null;
    notifyListeners();

    // Written to a temporary name and moved into place only once complete, so a failed
    // download can never be mistaken for a usable archive.
    final target = await _target;
    final temp = File('${target.path}.part');

    try {
      final request = http.Request('GET', Uri.parse('$baseUrl/map-pack?part=blob'));
      request.headers['Authorization'] = 'Bearer $token';

      final response = await request.send().timeout(const Duration(minutes: 10));
      if (response.statusCode != 200) {
        throw HttpException('HTTP ${response.statusCode}');
      }

      final sink = temp.openWrite();
      var received = 0;

      await response.stream.forEach((chunk) {
        sink.add(chunk);
        received += chunk.length;
        final next = expectedBytes > 0 ? received / expectedBytes : 0.0;
        // Notified in steps rather than per chunk: a 13 MB download arrives in
        // thousands of chunks and rebuilding the UI for each is pure waste.
        if (next - _progress >= 0.02) {
          _progress = next;
          notifyListeners();
        }
      });

      await sink.flush();
      await sink.close();

      if (temp.lengthSync() != expectedBytes) {
        throw const FormatException('Incomplete download');
      }

      if (target.existsSync()) await target.delete();
      await temp.rename(target.path);

      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_kVersion, version);
      await prefs.setInt(_kBytes, expectedBytes);

      _archive = target;
      _version = version;
      _progress = 1;
    } catch (e) {
      if (temp.existsSync()) {
        await temp.delete();
      }
      _lastError = 'The offline map could not be downloaded.';
    } finally {
      _downloading = false;
      notifyListeners();
    }
  }

  /// Fetch the style JSON. Small enough to hold in memory and write in one go.
  Future<void> _downloadStyle(String version) async {
    try {
      final response = await http.get(
        Uri.parse('$baseUrl/map-pack?part=style'),
        headers: {'Authorization': 'Bearer $token'},
      ).timeout(const Duration(seconds: 45));

      if (response.statusCode != 200 || response.bodyBytes.isEmpty) return;

      // Parsed before it is kept: a truncated or error-page response would otherwise
      // be stored and then fail every time the map opens.
      jsonDecode(utf8.decode(response.bodyBytes));

      final target = await _styleTarget;
      await target.writeAsBytes(response.bodyBytes, flush: true);

      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_kStyleVersion, version);

      _style = target;
      _styleVersion = version;
      notifyListeners();
    } catch (e) {
      // Keep whatever was there; the online fallback still works.
    }
  }

  /// Remove the archive, for a settings screen or to reclaim the space.
  Future<void> clear() async {
    final file = await _target;
    if (file.existsSync()) await file.delete();

    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kVersion);
    await prefs.remove(_kBytes);

    _archive = null;
    _version = null;
    notifyListeners();
  }
}
