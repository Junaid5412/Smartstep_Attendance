import 'dart:async';

import 'package:flutter/material.dart';

import 'core/api.dart';
import 'core/offline_map.dart';
import 'core/session.dart';
import 'core/theme.dart';
import 'screens/consent_screen.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'screens/permissions_screen.dart';
import 'screens/security_gate.dart';
import 'screens/splash_screen.dart';
import 'screens/update_screen.dart';

/// Compiled-in default; the operator can point the app elsewhere from the login
/// screen's server field without a rebuild.
///
/// Must name the real server. Every request carries the employee's bearer token, so a
/// placeholder here sends that token to whoever owns the placeholder domain as soon as
/// the stored setting is missing — which is exactly what happened once already.
const String kDefaultApiBase = 'https://sstqa.com/Attendance/Website/api/v1';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final session = Session(defaultBaseUrl: kDefaultApiBase);
  await session.load();

  // Loaded before the first frame so a map opened immediately on a cold start with no
  // signal already has the archive, rather than briefly falling back to online tiles
  // that cannot load. Only reads local files - no network here.
  final offlineMap = OfflineMap(baseUrl: session.baseUrl, token: session.token);
  await offlineMap.load();

  runApp(SstAttendanceApp(session: session, offlineMap: offlineMap));
}

class SstAttendanceApp extends StatelessWidget {
  const SstAttendanceApp({super.key, required this.session, required this.offlineMap});

  final Session session;
  final OfflineMap offlineMap;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'SST Attendance',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.build(),
      // Wraps everything: the integrity verdict is decided before any real screen is
      // rendered, and re-decided on every resume.
      home: SecurityGate(
        child: Gate(session: session, offlineMap: offlineMap),
      ),
    );
  }
}

/// Decides which screen the employee belongs on.
///
/// The order matters and is not cosmetic: consent must be recorded before any
/// location is collected, and the permission gate must pass before the home screen
/// can offer a check-in it would fail to complete.
class Gate extends StatefulWidget {
  const Gate({super.key, required this.session, required this.offlineMap});

  final Session session;
  final OfflineMap offlineMap;

  @override
  State<Gate> createState() => _GateState();
}

class _GateState extends State<Gate> {
  /// Held for the branded splash on a cold start.
  ///
  /// Signed-in users get their splash from SessionShell while it checks the
  /// account, so gating here as well would show it twice. This covers the
  /// signed-out path, where there is no network work to hide behind.
  bool _booting = true;

  @override
  void initState() {
    super.initState();
    widget.session.addListener(_onSessionChanged);

    if (widget.session.isSignedIn) {
      // SessionShell owns the splash for this path.
      _booting = false;
    } else {
      Future<void>.delayed(widget.session.splashDuration, () {
        if (mounted) setState(() => _booting = false);
      });
    }
  }

  @override
  void dispose() {
    widget.session.removeListener(_onSessionChanged);
    super.dispose();
  }

  void _onSessionChanged() {
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final session = widget.session;
    final api = Api(session);

    if (_booting) {
      return SplashScreen(
        logoUrl: session.logoUrl,
        logoFile: session.logoFile,
        companyName: session.companyName,
        message: 'Starting…',
      );
    }

    if (!session.isSignedIn) {
      return LoginScreen(session: session, api: api);
    }

    return SessionShell(session: session, api: api, offlineMap: widget.offlineMap);
  }
}

/// Once signed in, checks consent and permissions before handing over to home.
///
/// This runs on every launch rather than once, because a permission the employee
/// granted last week can be revoked from Android settings at any time — and a
/// revoked background-location grant silently stops tracking.
class SessionShell extends StatefulWidget {
  const SessionShell({
    super.key,
    required this.session,
    required this.api,
    required this.offlineMap,
  });

  final OfflineMap offlineMap;

  final Session session;
  final Api api;

  @override
  State<SessionShell> createState() => _SessionShellState();
}

class _SessionShellState extends State<SessionShell> {
  bool _loading = true;
  bool _consentNeeded = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _check();

    // Fetched once per launch, in the background and never awaited: a 13 MB download
    // must not stand between the employee and a check-in. Needs the token, which is
    // why it happens here rather than in main().
    widget.offlineMap.token = widget.session.token;
    widget.offlineMap.baseUrl = widget.session.baseUrl;
    unawaited(widget.offlineMap.sync());
  }

  /// Set once per launch, so a later re-check (after accepting consent, say) does
  /// not sit on the splash for another three seconds.
  bool _splashHeld = false;

  /// Set when the server has refused this build outright. Nothing else renders while
  /// it is set: every request would be refused the same way.
  ApiResult? _forcedUpdate;

  Future<void> _check() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final startedAt = DateTime.now();
    final result = await widget.api.me();

    // Caught before anything else is decided. An install below the permitted minimum
    // is refused on every request including login, so there is no state to recover to
    // and nothing else worth trying — the download is the only way forward, and the
    // refusal itself carried the address.
    if (result.upgradeRequired && result.upgradeUrl != null) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _forcedUpdate = result;
      });
      return;
    }

    // Hold the splash for its configured minimum. The account check usually
    // finishes well inside it, and cutting away after 300ms reads as a flicker
    // rather than a launch.
    if (!_splashHeld) {
      _splashHeld = true;
      final elapsed = DateTime.now().difference(startedAt);
      final remaining = widget.session.splashDuration - elapsed;
      if (remaining > Duration.zero) {
        await Future<void>.delayed(remaining);
      }
    }

    if (!mounted) return;

    if (result.sessionDead) {
      // The admin released the device or the session expired: back to sign-in with
      // the server's own wording, which explains which of the two happened.
      final message = result.message;
      await widget.session.signOut();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(message), backgroundColor: AppTheme.bad),
      );
      return;
    }

    if (result.isNetworkFailure) {
      // Offline launch: trust the cached session so an employee with no signal can
      // still check in — the queue will sync when the connection returns.
      setState(() {
        _loading = false;
        _consentNeeded = false;
        _error = 'Working offline — showing saved information.';
      });
      return;
    }

    if (!result.success) {
      setState(() {
        _loading = false;
        _error = result.message.isEmpty ? 'Could not load your profile.' : result.message;
      });
      return;
    }

    setState(() {
      _loading = false;
      _consentNeeded = result.map['consent_required'] == true;
    });
  }

  @override
  Widget build(BuildContext context) {
    // Ahead of the splash and every other branch: nothing this app can show is more
    // useful than the way out.
    final forced = _forcedUpdate;
    if (forced != null) {
      return UpdateScreen(
        downloadUrl: forced.upgradeUrl!,
        version: forced.upgradeVersion,
        notes: forced.upgradeNotes,
        forced: true,
      );
    }

    if (_loading) {
      // Continues the native splash rather than flashing a bare spinner, and
      // shows the company logo now that the cached config is available.
      return SplashScreen(
        logoUrl: widget.session.logoUrl,
        logoFile: widget.session.logoFile,
        companyName: widget.session.companyName,
        message: 'Checking your account…',
      );
    }

    if (_error != null && !_error!.startsWith('Working offline')) {
      return Scaffold(
        body: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.cloud_off, size: 52, color: AppTheme.inkSoft),
              const SizedBox(height: 16),
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 22),
              FilledButton(onPressed: _check, child: const Text('Try again')),
              const SizedBox(height: 10),
              TextButton(
                onPressed: () => widget.session.signOut(),
                child: const Text('Sign out'),
              ),
            ],
          ),
        ),
      );
    }

    if (_consentNeeded) {
      return ConsentScreen(
        session: widget.session,
        api: widget.api,
        onAccepted: _check,
      );
    }

    return PermissionsGate(
      session: widget.session,
      child: HomeScreen(
        session: widget.session,
        api: widget.api,
        offlineMap: widget.offlineMap,
      ),
    );
  }
}
