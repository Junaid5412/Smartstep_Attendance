import 'dart:async';

import 'package:flutter/material.dart';

import '../core/native.dart';
import '../core/theme.dart';

/// Wraps the whole app and refuses to show it when the integrity check fails.
///
/// Re-checked on every resume, not only at launch, because the obvious way round a
/// startup-only check is to pass it and then change things — which is exactly the
/// behaviour this was asked to stop.
///
/// The check runs on the native side (signing certificate, package name, debuggable
/// flag). It fails closed: if it cannot run at all, the app does not open.
///
/// What this honestly is: a barrier to casual tampering. On a rooted device with a
/// runtime instrumentation framework, this check can be patched out — that is a
/// property of running on hardware somebody else controls. It is worth having because
/// it stops the easy attacks, and because the defences that actually hold are on the
/// server, where the phone gets no vote.
class SecurityGate extends StatefulWidget {
  const SecurityGate({super.key, required this.child});

  final Widget child;

  @override
  State<SecurityGate> createState() => _SecurityGateState();
}

class _SecurityGateState extends State<SecurityGate> with WidgetsBindingObserver {
  bool? _ok;
  String? _reason;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(_verify());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Every return to the foreground is re-checked. Cheap — it reads the app's own
    // certificate, no network, no disk beyond the package manager.
    if (state == AppLifecycleState.resumed) unawaited(_verify());
  }

  Future<void> _verify() async {
    final verdict = await Native.integrityCheck();
    if (!mounted) return;
    setState(() {
      _ok = verdict.ok;
      _reason = verdict.reason;
    });
  }

  @override
  Widget build(BuildContext context) {
    // Nothing is shown until the verdict is in. A brief blank frame is the right
    // trade: rendering the app first and blocking afterwards would leave the real
    // screen visible and screenshottable in between.
    if (_ok == null) {
      return const ColoredBox(
        color: AppTheme.brand,
        child: Center(child: CircularProgressIndicator(color: Colors.white)),
      );
    }

    if (_ok == false) return _blocked();

    return widget.child;
  }

  Widget _blocked() {
    return Scaffold(
      backgroundColor: AppTheme.brand,
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(28),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.gpp_bad_outlined, size: 66, color: Colors.white),
                const SizedBox(height: 22),
                const Text(
                  'This app cannot run',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 21,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  // The specific reason is shown rather than a blanket refusal: an
                  // employee whose phone fails this is usually not the person who
                  // modified anything, and "contact your administrator" with nothing
                  // else is unactionable for them and for whoever they contact.
                  _reason ?? 'A security check did not pass.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white, fontSize: 14, height: 1.5),
                ),
                const SizedBox(height: 20),
                const Text(
                  'Install the official app from your administrator. If you believe this '
                  'is a mistake, show them this screen.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.white70, fontSize: 12.5, height: 1.5),
                ),
                const SizedBox(height: 26),
                OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Colors.white54),
                  ),
                  onPressed: _verify,
                  child: const Text('Check again'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
