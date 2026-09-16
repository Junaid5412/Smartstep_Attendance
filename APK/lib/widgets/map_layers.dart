import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:pmtiles/pmtiles.dart';
import 'package:vector_map_tiles/vector_map_tiles.dart';
import 'package:vector_map_tiles_pmtiles/vector_map_tiles_pmtiles.dart';
// Prefixed: this package also exports Theme and TileLayer, which collide with
// Flutter's and flutter_map's names of the same thing.
import 'package:vector_tile_renderer/vector_tile_renderer.dart' as vtr;

import '../core/offline_map.dart';

/// The base layer for every map in the app.
///
/// Prefers the downloaded Protomaps archive, and falls back to online raster tiles
/// until it is there. The fallback matters more than it looks: the archive arrives
/// asynchronously on first run, and a check-in screen that showed nothing until a
/// 13 MB download finished would be worse than one that shows online tiles.
///
/// Building the vector layer is asynchronous — the archive header has to be read — so
/// this resolves it once and holds it, rather than reopening the file on every rebuild
/// of a screen that rebuilds on every GPS fix.
class BaseMapLayer extends StatefulWidget {
  const BaseMapLayer({
    super.key,
    required this.offline,
    required this.fallbackUrl,
  });

  final OfflineMap offline;

  /// Online raster tiles, used until the archive is available.
  final String fallbackUrl;

  @override
  State<BaseMapLayer> createState() => _BaseMapLayerState();
}

class _BaseMapLayerState extends State<BaseMapLayer> {
  TileProviders? _providers;
  vtr.Theme? _theme;
  String? _openedPath;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    widget.offline.addListener(_onOfflineChanged);
    unawaited(_openArchive());
  }

  @override
  void dispose() {
    widget.offline.removeListener(_onOfflineChanged);
    super.dispose();
  }

  void _onOfflineChanged() {
    // The archive can appear while a map is on screen, on first run.
    final path = widget.offline.archive?.path;
    if (path != _openedPath) unawaited(_openArchive());
  }

  Future<void> _openArchive() async {
    final file = widget.offline.archive;
    if (file == null) {
      if (mounted) setState(() { _providers = null; _openedPath = null; });
      return;
    }

    try {
      // The style has to come first: without one matching the archive's schema there
      // is nothing to draw with, and rendering Protomaps tiles through a theme written
      // for the OpenMapTiles schema produces a near-empty map - worse than the online
      // fallback, and misleading because it looks like a working map of nowhere.
      final theme = await _loadTheme();
      if (theme == null) {
        if (mounted) setState(() { _providers = null; _openedPath = null; });
        return;
      }

      final archive = await PmTilesArchive.from(file.path);
      final provider = PmTilesVectorTileProvider.fromArchive(archive);

      if (!mounted) return;
      setState(() {
        // 'protomaps' must match the source name used inside the style.
        _providers = TileProviders({'protomaps': provider});
        _theme = theme;
        _openedPath = file.path;
        _failed = false;
      });
    } catch (e) {
      // A corrupt or truncated archive must not take the map down with it; the online
      // fallback is still perfectly usable.
      if (mounted) setState(() { _providers = null; _failed = true; });
    }
  }

  /// The map style, read from the copy downloaded alongside the archive.
  ///
  /// Returns null when there is none, which keeps the app on online tiles rather than
  /// drawing an empty vector map. It comes from the server rather than the APK so the
  /// style can be changed - a different look, different label language - without
  /// shipping a new build, and so the Protomaps key that fetches it never leaves the
  /// server.
  Future<vtr.Theme?> _loadTheme() async {
    try {
      final file = widget.offline.style;
      if (file == null || !file.existsSync()) return null;

      final raw = await file.readAsString();
      return vtr.ThemeReader().read(jsonDecode(raw) as Map<String, dynamic>);
    } catch (e) {
      return null;
    }
  }

  @override
  Widget build(BuildContext context) {
    final providers = _providers;
    final theme = _theme;

    if (providers != null && theme != null) {
      return VectorTileLayer(
        theme: theme,
        tileProviders: providers,
        // The archive stops at zoom 14, but vector geometry scales cleanly, so it is
        // rendered beyond that rather than refusing to draw. This is the whole reason
        // a 13 MB file covers a country and still looks sharp at street level.
        maximumZoom: 18,
        fileCacheTtl: const Duration(days: 30),
      );
    }

    return TileLayer(
      urlTemplate: widget.fallbackUrl,
      userAgentPackageName: 'com.sst.attendance',
      maxZoom: 19,
    );
  }

  /// Whether the archive was present but unreadable, so a screen can say so.
  bool get failed => _failed;
}
