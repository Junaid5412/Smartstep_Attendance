import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image/image.dart' as img;
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';
import 'package:latlong2/latlong.dart';
import 'package:path_provider/path_provider.dart';

import '../core/api.dart';
import '../core/location.dart';
import '../core/offline_map.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/map_layers.dart';

/// Check in or check out: a live GPS fix, a compulsory camera photo, and the
/// geofence verdict shown before the employee commits.
///
/// The screen deliberately refuses to submit what the server would reject — a weak
/// fix, a mock location, a position outside the fence — so the employee gets a
/// clear reason on the spot rather than a failed request after taking a photo.
class CheckInScreen extends StatefulWidget {
  const CheckInScreen({
    super.key,
    required this.session,
    required this.api,
    required this.offlineMap,
    required this.checkingOut,
  });

  final Session session;
  final Api api;
  final OfflineMap offlineMap;
  final bool checkingOut;

  @override
  State<CheckInScreen> createState() => _CheckInScreenState();
}

class _CheckInScreenState extends State<CheckInScreen> {
  final _picker = ImagePicker();
  final _mapController = MapController();

  StreamSubscription<Position>? _positionSub;
  Position? _position;
  File? _photo;

  bool _submitting = false;
  bool _mapReady = false;
  String? _error;
  String? _locationProblem;

  @override
  void initState() {
    super.initState();
    _startWatching();
  }

  @override
  void dispose() {
    _positionSub?.cancel();
    super.dispose();
  }

  Future<void> _startWatching() async {
    final outcome = await LocationHelper.currentFix(
      requiredAccuracy: widget.session.maxAccuracy,
    );

    if (!mounted) return;

    if (outcome.position == null) {
      setState(() => _locationProblem = outcome.message);
      return;
    }

    setState(() {
      _position = outcome.position;
      _locationProblem = outcome.stale ? outcome.message : null;
    });
    _centreMap();

    // Keep watching: accuracy usually tightens from tens of metres to under ten
    // within a few seconds, and the employee should see that happen.
    _positionSub = LocationHelper.watch().listen((position) {
      if (!mounted) return;
      setState(() {
        _position = position;
        _locationProblem = null;
      });
      _centreMap();
    }, onError: (_) {
      // Stream errors are transient; the last good fix stays on screen.
    });
  }

  void _centreMap() {
    final position = _position;
    if (!_mapReady || position == null) return;
    _mapController.move(LatLng(position.latitude, position.longitude), 17);
  }

  /* ---------------- geofence verdict, computed on device ---------------- */

  /// The best verdict across every assigned area: inside any of them is inside.
  ///
  /// Must agree with attEvaluateAreas() on the server and GeoFence.evaluateAll() in
  /// the tracking service. If they disagree the screen refuses a check-in the server
  /// would have accepted, or vice versa.
  ({bool inside, int distance, String? area})? get _verdict {
    final areas = widget.session.geofences;
    if (areas.isEmpty) return null;

    ({bool inside, int distance, String? area})? nearest;

    for (final area in areas) {
      final verdict = _verdictFor(area);
      if (verdict == null) continue;
      if (verdict.inside) return verdict;
      if (nearest == null || verdict.distance < nearest.distance) nearest = verdict;
    }
    return nearest;
  }

  ({bool inside, int distance, String? area})? _verdictFor(Map<String, dynamic> fence) {
    final position = _position;
    if (position == null) return null;
    final name = fence['name'] as String?;

    if (fence['type'] == 'polygon') {
      final ring = ((fence['polygon'] as List?) ?? [])
          .map((p) => LatLng((p[0] as num).toDouble(), (p[1] as num).toDouble()))
          .toList();
      if (ring.length < 3) return null;

      if (_pointInPolygon(position.latitude, position.longitude, ring)) {
        return (inside: true, distance: 0, area: name);
      }
      final out = _distanceToRing(position, ring);
      // Same tolerance the service and the server apply. If check-in used a hard
      // boundary while the tracker allowed 50 m, someone standing in one spot would be
      // refused a check-in and simultaneously reported as being on site.
      return (inside: out <= _bufferM, distance: out, area: name);
    }

    final centreLat = (fence['center_lat'] as num?)?.toDouble();
    final centreLng = (fence['center_lng'] as num?)?.toDouble();
    final radius = (fence['radius_m'] as num?)?.toDouble();
    if (centreLat == null || centreLng == null || radius == null) return null;

    final metres = const Distance().as(
      LengthUnit.Meter,
      LatLng(centreLat, centreLng),
      LatLng(position.latitude, position.longitude),
    );

    final out = (metres - radius) < 0 ? 0.0 : metres - radius;
    return (inside: out <= _bufferM, distance: out.round(), area: name);
  }

  /// Metres past a boundary that still count as being on site.
  ///
  /// Comes from the server so all three implementations of this test — here, the native
  /// service, and the API — reach the same verdict for the same position.
  double get _bufferM =>
      ((widget.session.settings['geofence_buffer_m'] as num?)?.toDouble() ?? 50)
          .clamp(0, 1000);

  /// Ray casting, matching includes/geo.php and GeoFence.kt.
  bool _pointInPolygon(double lat, double lng, List<LatLng> ring) {
    var inside = false;
    var j = ring.length - 1;

    for (var i = 0; i < ring.length; i++) {
      final latI = ring[i].latitude, lngI = ring[i].longitude;
      final latJ = ring[j].latitude, lngJ = ring[j].longitude;

      if ((lngI > lng) != (lngJ > lng)) {
        final latAtLng = latI + (lng - lngI) * (latJ - latI) / (lngJ - lngI);
        if (lat < latAtLng) inside = !inside;
      }
      j = i;
    }
    return inside;
  }

  /// Approximate: distance to the nearest corner rather than the nearest edge. It
  /// is only used to word a message ("about 120 m away"); the server computes the
  /// exact edge distance for the record.
  int _distanceToRing(Position position, List<LatLng> ring) {
    final here = LatLng(position.latitude, position.longitude);
    const distance = Distance();
    var nearest = double.maxFinite;
    for (final corner in ring) {
      final metres = distance.as(LengthUnit.Meter, corner, here);
      if (metres < nearest) nearest = metres;
    }
    return nearest.round();
  }

  /* ---------------- readiness ---------------- */

  bool get _accuracyOk {
    final accuracy = _position?.accuracy;
    if (accuracy == null) return false;
    return accuracy <= widget.session.maxAccuracy;
  }

  bool get _mockBlocked =>
      (_position?.isMocked ?? false) && !widget.session.allowMockLocation;

  /// Whether being outside the area blocks *this* action for *this* employee.
  ///
  /// Check-in and check-out are governed separately. Check-out defaults to allowed
  /// from anywhere, because refusing it only creates false "still working" days —
  /// but some roles need it enforced, so it is a per-employee setting rather than a
  /// rule fixed in the app.
  bool get _enforcedHere => widget.checkingOut
      ? widget.session.enforceGeofenceCheckout
      : widget.session.enforceGeofence;

  bool get _geofenceBlocked {
    if (!_enforcedHere) return false;
    final verdict = _verdict;
    return verdict != null && !verdict.inside;
  }

  bool get _photoOk => !widget.session.requirePhoto || _photo != null;

  bool get _canSubmit =>
      _position != null && _accuracyOk && !_mockBlocked && !_geofenceBlocked && _photoOk;

  /* ---------------- photo ---------------- */

  Future<void> _takePhoto() async {
    try {
      // ImageSource.camera only: the employee cannot substitute a gallery picture,
      // which is the entire point of the photo requirement.
      final shot = await _picker.pickImage(
        source: ImageSource.camera,
        preferredCameraDevice: CameraDevice.front,
        imageQuality: 85,
        maxWidth: 1280,
      );
      if (shot == null) return;

      final compressed = await _compress(File(shot.path));
      if (!mounted) return;
      setState(() {
        _photo = compressed;
        _error = null;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _error = 'Could not open the camera. Check the camera permission.');
    }
  }

  /// Shrinks the shot to the server's size limit. Work sites often have poor
  /// upload bandwidth, and a 4 MB selfie is the difference between a check-in that
  /// completes and one that times out.
  Future<File> _compress(File original) async {
    try {
      const limitKb = 1024;
      if (await original.length() <= limitKb * 1024) return original;

      final decoded = img.decodeImage(await original.readAsBytes());
      if (decoded == null) return original;

      final resized = decoded.width > 1080
          ? img.copyResize(decoded, width: 1080)
          : decoded;

      final directory = await getTemporaryDirectory();
      final target = File(
        '${directory.path}/checkin_${DateTime.now().millisecondsSinceEpoch}.jpg',
      );
      await target.writeAsBytes(img.encodeJpg(resized, quality: 80));
      return target;
    } catch (_) {
      // If anything about re-encoding fails, send the original rather than nothing.
      return original;
    }
  }

  /* ---------------- submit ---------------- */

  Future<void> _submit() async {
    final position = _position;
    if (position == null) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    final result = await widget.api.postMultipart(
      widget.checkingOut ? 'check-out' : 'check-in',
      {
        'lat': position.latitude.toString(),
        'lng': position.longitude.toString(),
        'accuracy': position.accuracy.toString(),
        'is_mock': position.isMocked.toString(),
      },
      photo: _photo,
    );

    if (!mounted) return;

    if (result.success) {
      Navigator.of(context).pop(true);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(result.message), backgroundColor: AppTheme.ok),
      );
      return;
    }

    setState(() {
      _submitting = false;
      // ALREADY_CHECKED_IN / ALREADY_CHECKED_OUT mean the home screen is stale —
      // usually a double tap or a retry after a timeout that actually succeeded.
      _error = result.isNetworkFailure
          ? 'No connection. Your check-${widget.checkingOut ? 'out' : 'in'} was not '
              'recorded — try again once you have signal.'
          : result.message;
    });

    if (result.code == 'ALREADY_CHECKED_IN' || result.code == 'ALREADY_CHECKED_OUT') {
      await Future<void>.delayed(const Duration(seconds: 2));
      if (mounted) Navigator.of(context).pop(true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final action = widget.checkingOut ? 'Check out' : 'Check in';

    return Scaffold(
      appBar: AppBar(title: Text(action)),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  _mapCard(),
                  _statusCard(),
                  if (widget.session.requirePhoto) _photoCard(),
                  if (_error != null) _errorCard(),
                ],
              ),
            ),
            Container(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
              decoration: const BoxDecoration(
                color: Colors.white,
                border: Border(top: BorderSide(color: Color(0xFFE2E7F0))),
              ),
              child: FilledButton.icon(
                style: widget.checkingOut
                    ? FilledButton.styleFrom(backgroundColor: AppTheme.bad)
                    : null,
                icon: _submitting
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                      )
                    : Icon(widget.checkingOut ? Icons.logout : Icons.login),
                label: Text(_submitting ? 'Recording…' : 'Confirm $action'),
                onPressed: (_canSubmit && !_submitting) ? _submit : null,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _mapCard() {
    final position = _position;

    if (position == null) {
      return Card(
        child: SizedBox(
          height: 220,
          child: Center(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (_locationProblem == null) ...[
                  const CircularProgressIndicator(),
                  const SizedBox(height: 14),
                  const Text('Getting your location…'),
                ] else ...[
                  const Icon(Icons.location_disabled, size: 40, color: AppTheme.bad),
                  const SizedBox(height: 12),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 24),
                    child: Text(
                      _locationProblem!,
                      textAlign: TextAlign.center,
                      style: const TextStyle(fontSize: 13),
                    ),
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton(
                    onPressed: () {
                      setState(() => _locationProblem = null);
                      _startWatching();
                    },
                    child: const Text('Try again'),
                  ),
                ],
              ],
            ),
          ),
        ),
      );
    }

    final here = LatLng(position.latitude, position.longitude);

    return Card(
      clipBehavior: Clip.antiAlias,
      child: SizedBox(
        height: 260,
        child: FlutterMap(
          mapController: _mapController,
          options: MapOptions(
            initialCenter: here,
            initialZoom: 17,
            onMapReady: () {
              _mapReady = true;
              _centreMap();
            },
            // A read-only map: panning it would only confuse the meaning of the pin.
            interactionOptions: const InteractionOptions(flags: InteractiveFlag.pinchZoom),
          ),
          children: [
            // Prefers the downloaded Protomaps archive; falls back to online tiles
            // when it is not there yet. This is what makes the map appear with no signal.
            BaseMapLayer(
              offline: widget.offlineMap,
              fallbackUrl: widget.session.tileUrl,
            ),
            // Every assigned area is drawn, not only the primary one: an employee
            // standing in their second office should see themselves inside it.
            for (final area in widget.session.geofences) ..._fenceLayers(area),
            CircleLayer(
              circles: [
                // The accuracy halo, so a wide circle explains why a check-in is
                // being refused for accuracy.
                CircleMarker(
                  point: here,
                  radius: position.accuracy,
                  useRadiusInMeter: true,
                  color: AppTheme.brand.withValues(alpha: 0.15),
                  borderColor: AppTheme.brand.withValues(alpha: 0.5),
                  borderStrokeWidth: 1,
                ),
              ],
            ),
            MarkerLayer(
              markers: [
                Marker(
                  point: here,
                  width: 26,
                  height: 26,
                  child: Container(
                    decoration: BoxDecoration(
                      color: AppTheme.brand,
                      shape: BoxShape.circle,
                      border: Border.all(color: Colors.white, width: 3),
                    ),
                  ),
                ),
              ],
            ),
            RichAttributionWidget(
              attributions: [TextSourceAttribution(widget.session.tileAttribution)],
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _fenceLayers(Map<String, dynamic> fence) {
    const colour = AppTheme.ok;

    if (fence['type'] == 'polygon') {
      final ring = ((fence['polygon'] as List?) ?? [])
          .map((p) => LatLng((p[0] as num).toDouble(), (p[1] as num).toDouble()))
          .toList();
      if (ring.length < 3) return const [];

      return [
        PolygonLayer(
          polygons: [
            Polygon(
              points: ring,
              color: colour.withValues(alpha: 0.12),
              borderColor: colour,
              borderStrokeWidth: 2,
            ),
          ],
        ),
      ];
    }

    final centreLat = (fence['center_lat'] as num?)?.toDouble();
    final centreLng = (fence['center_lng'] as num?)?.toDouble();
    final radius = (fence['radius_m'] as num?)?.toDouble();
    if (centreLat == null || centreLng == null || radius == null) return const [];

    return [
      CircleLayer(
        circles: [
          CircleMarker(
            point: LatLng(centreLat, centreLng),
            radius: radius,
            useRadiusInMeter: true,
            color: colour.withValues(alpha: 0.12),
            borderColor: colour,
            borderStrokeWidth: 2,
          ),
        ],
      ),
    ];
  }

  Widget _statusCard() {
    final position = _position;
    final verdict = _verdict;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _check(
              ok: position != null && _accuracyOk,
              title: 'GPS accuracy',
              detail: position == null
                  ? 'Waiting for a fix'
                  : '±${position.accuracy.round()} m '
                      '(${widget.session.maxAccuracy} m or better required)',
              waiting: position != null && !_accuracyOk,
              waitingHint: 'Hold still or move outdoors — this usually improves '
                  'within a few seconds.',
            ),
            const Divider(height: 20),
            _check(
              ok: !_mockBlocked,
              title: 'Genuine location',
              detail: _mockBlocked
                  ? 'A mock location app is active. Turn it off to continue.'
                  : 'No fake GPS detected',
            ),
            const Divider(height: 20),
            _check(
              ok: verdict == null || verdict.inside || !_enforcedHere,
              title: widget.session.geofences.length > 1 ? 'Work areas' : 'Work area',
              detail: switch (verdict) {
                null => widget.session.geofences.isEmpty
                    ? 'No work area assigned'
                    : 'Waiting for a fix',
                // Names the area actually matched, which is the point of having
                // several — "Inside your work area" would not say which office.
                final v when v.inside =>
                  'Inside ${v.area ?? widget.session.geofenceName}',
                final v => '${NumberFormat.decimalPattern().format(v.distance)} m outside '
                    '${widget.session.geofences.length > 1 ? 'the nearest of your '
                        '${widget.session.geofences.length} areas '
                        '(${v.area ?? ''})' : (v.area ?? widget.session.geofenceName)}'
                    '${_enforcedHere ? '' : ' — recorded, but allowed'}',
              },
              // Being outside is only a failure when it actually blocks the action;
              // otherwise it is a fact to record.
              warnOnly: verdict != null && !verdict.inside && !_enforcedHere,
            ),
            if (widget.session.requirePhoto) ...[
              const Divider(height: 20),
              _check(
                ok: _photo != null,
                title: 'Photo',
                detail: _photo != null ? 'Taken' : 'Compulsory — take a photo below',
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _check({
    required bool ok,
    required String title,
    required String detail,
    bool waiting = false,
    String? waitingHint,
    bool warnOnly = false,
  }) {
    final colour = ok
        ? AppTheme.ok
        : (waiting || warnOnly ? AppTheme.warn : AppTheme.bad);
    final icon = ok
        ? Icons.check_circle
        : (waiting ? Icons.hourglass_top : (warnOnly ? Icons.info_outline : Icons.cancel));

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 19, color: colour),
        const SizedBox(width: 11),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5)),
              const SizedBox(height: 2),
              Text(detail, style: TextStyle(fontSize: 12.5, color: colour)),
              if (waiting && waitingHint != null) ...[
                const SizedBox(height: 3),
                Text(
                  waitingHint,
                  style: const TextStyle(fontSize: 11.5, color: AppTheme.inkSoft),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }

  Widget _photoCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Photo',
              style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
            ),
            const SizedBox(height: 4),
            const Text(
              'Taken with the camera now. You cannot choose an existing picture.',
              style: TextStyle(fontSize: 12.5, color: AppTheme.inkSoft),
            ),
            const SizedBox(height: 13),
            if (_photo != null)
              ClipRRect(
                borderRadius: BorderRadius.circular(11),
                child: Image.file(
                  _photo!,
                  height: 220,
                  width: double.infinity,
                  fit: BoxFit.cover,
                ),
              ),
            if (_photo != null) const SizedBox(height: 11),
            OutlinedButton.icon(
              icon: Icon(_photo == null ? Icons.photo_camera : Icons.refresh),
              label: Text(_photo == null ? 'Take photo' : 'Retake photo'),
              onPressed: _submitting ? null : _takePhoto,
            ),
          ],
        ),
      ),
    );
  }

  Widget _errorCard() {
    return Card(
      color: const Color(0xFFFDEAEC),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: const BorderSide(color: Color(0xFFF3C2C6)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(15),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(Icons.error_outline, color: AppTheme.bad, size: 20),
            const SizedBox(width: 10),
            Expanded(
              child: Text(_error!, style: const TextStyle(fontSize: 13, color: AppTheme.ink)),
            ),
          ],
        ),
      ),
    );
  }
}
