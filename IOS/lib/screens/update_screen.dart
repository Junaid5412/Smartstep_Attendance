import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';

import '../core/native.dart';
import '../core/theme.dart';

/// Download and install a new build, from inside the app.
///
/// Shown as a blocking screen when the server has refused this version outright, and as
/// a dismissible one when the update is merely available. The forced case is the one
/// that matters: an app below the minimum is refused on *every* request including login,
/// so without this screen the employee is stuck with an error and nowhere to go.
///
/// It cannot install silently. Android requires the employee to confirm unless the app is
/// a device owner, which means provisioning every handset through MDM at factory-reset
/// time. What this does is remove every other step: no browser, no file manager, no
/// hunting for a download.
class UpdateScreen extends StatefulWidget {
  const UpdateScreen({
    super.key,
    required this.downloadUrl,
    required this.version,
    this.notes,
    this.forced = true,
  });

  final String downloadUrl;
  final String? version;
  final String? notes;

  /// When true there is no way past this screen except updating.
  final bool forced;

  @override
  State<UpdateScreen> createState() => _UpdateScreenState();
}

class _UpdateScreenState extends State<UpdateScreen> {
  double _progress = 0;
  bool _downloading = false;
  String? _error;
  String? _readyPath;

  Future<void> _download() async {
    setState(() {
      _downloading = true;
      _error = null;
      _progress = 0;
    });

    // Written into the folder the FileProvider is scoped to, under the version name so
    // a retry replaces the same file rather than filling storage with attempts.
    final dir = Directory('${(await getExternalStorageDirectory())!.path}/updates');
    if (!dir.existsSync()) dir.createSync(recursive: true);
    final target = File('${dir.path}/update-${widget.version ?? 'latest'}.apk');
    final temp = File('${target.path}.part');

    try {
      final request = http.Request('GET', Uri.parse(widget.downloadUrl));
      final response = await request.send().timeout(const Duration(minutes: 15));

      if (response.statusCode != 200) {
        throw HttpException('HTTP ${response.statusCode}');
      }

      // A server that refuses .apk answers with an HTML error page and a 200 in some
      // configurations, which would then be saved as an "APK" and fail at install with
      // nothing to explain it. Checking the type catches that at the right moment.
      final type = response.headers['content-type'] ?? '';
      if (type.contains('text/html')) {
        throw const FormatException('server returned a web page, not an app file');
      }

      final total = response.contentLength ?? 0;
      final sink = temp.openWrite();
      var received = 0;

      await response.stream.forEach((chunk) {
        sink.add(chunk);
        received += chunk.length;
        if (total > 0) {
          final next = received / total;
          // Stepped, not per chunk: a 56 MB file arrives in thousands of chunks and
          // rebuilding the bar for each one is wasted work on a slow phone.
          if (next - _progress >= 0.01 && mounted) {
            setState(() => _progress = next);
          }
        }
      });

      await sink.flush();
      await sink.close();

      // Renamed only once complete, so a failed download can never be handed to the
      // installer — a truncated APK fails with a message that explains nothing.
      if (target.existsSync()) await target.delete();
      await temp.rename(target.path);

      if (!mounted) return;
      setState(() {
        _downloading = false;
        _progress = 1;
        _readyPath = target.path;
      });

      await _install();
    } catch (e) {
      if (temp.existsSync()) await temp.delete();
      if (!mounted) return;
      setState(() {
        _downloading = false;
        _error = e is FormatException
            ? 'The server did not return an app file. Ask your administrator to '
                'check the published version.'
            : 'The download failed — $e. Try again, or ask your administrator.';
      });
    }
  }

  Future<void> _install() async {
    final path = _readyPath;
    if (path == null) return;

    // Asked for only when it is actually needed, rather than at first launch: a
    // permission prompt about installing apps, shown before there is anything to
    // install, reads as suspicious.
    if (!await Native.canInstallPackages()) {
      if (!mounted) return;
      setState(() => _error = 'Allow this app to install updates, then press Install again.');
      await Native.requestInstallPermission();
      return;
    }

    final launched = await Native.installApk(path);
    if (!launched && mounted) {
      setState(() => _error = 'The installer could not be opened. Try again.');
    }
  }

  @override
  Widget build(BuildContext context) {
    // The forced screen cannot be dismissed by the back gesture either; letting someone
    // swipe past it would defeat the point of forcing.
    return PopScope(
      canPop: !widget.forced,
      child: Scaffold(
        backgroundColor: AppTheme.brand,
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(28),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(
                    widget.forced ? Icons.system_update : Icons.system_update_outlined,
                    size: 62,
                    color: Colors.white,
                  ),
                  const SizedBox(height: 20),
                  Text(
                    widget.forced ? 'Update required' : 'Update available',
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 21,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    widget.forced
                        ? 'This version is no longer supported. Update to carry on '
                            'using the app.'
                        : 'A newer version is ready to install.',
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white70, fontSize: 13.5, height: 1.5),
                  ),

                  if (widget.version != null) ...[
                    const SizedBox(height: 14),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                      decoration: BoxDecoration(
                        color: Colors.white24,
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Text(
                        'Version ${widget.version}',
                        style: const TextStyle(color: Colors.white, fontSize: 12.5),
                      ),
                    ),
                  ],

                  if ((widget.notes ?? '').isNotEmpty) ...[
                    const SizedBox(height: 18),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(13),
                      decoration: BoxDecoration(
                        color: Colors.white12,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        widget.notes!,
                        style: const TextStyle(color: Colors.white, fontSize: 13, height: 1.4),
                      ),
                    ),
                  ],

                  const SizedBox(height: 26),

                  if (_downloading) ...[
                    ClipRRect(
                      borderRadius: BorderRadius.circular(999),
                      child: LinearProgressIndicator(
                        value: _progress > 0 ? _progress : null,
                        minHeight: 7,
                        backgroundColor: Colors.white24,
                        valueColor: const AlwaysStoppedAnimation(Colors.white),
                      ),
                    ),
                    const SizedBox(height: 9),
                    Text(
                      _progress > 0
                          ? 'Downloading… ${(_progress * 100).round()}%'
                          : 'Starting download…',
                      style: const TextStyle(color: Colors.white70, fontSize: 12.5),
                    ),
                  ] else if (_readyPath != null) ...[
                    FilledButton.icon(
                      style: FilledButton.styleFrom(
                        backgroundColor: Colors.white,
                        foregroundColor: AppTheme.brand,
                        minimumSize: const Size.fromHeight(48),
                      ),
                      icon: const Icon(Icons.install_mobile),
                      label: const Text('Install'),
                      onPressed: _install,
                    ),
                  ] else ...[
                    FilledButton.icon(
                      style: FilledButton.styleFrom(
                        backgroundColor: Colors.white,
                        foregroundColor: AppTheme.brand,
                        minimumSize: const Size.fromHeight(48),
                      ),
                      icon: const Icon(Icons.download),
                      label: const Text('Download update'),
                      onPressed: _download,
                    ),
                  ],

                  if (_error != null) ...[
                    const SizedBox(height: 14),
                    Text(
                      _error!,
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: Color(0xFFFFD6D9), fontSize: 12.5),
                    ),
                  ],

                  if (!widget.forced && !_downloading) ...[
                    const SizedBox(height: 8),
                    TextButton(
                      onPressed: () => Navigator.of(context).maybePop(),
                      child: const Text('Later', style: TextStyle(color: Colors.white70)),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
