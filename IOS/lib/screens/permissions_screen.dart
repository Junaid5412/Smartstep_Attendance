import 'package:flutter/material.dart';
import 'package:permission_handler/permission_handler.dart';

import '../core/location.dart';
import '../core/native.dart';
import '../core/session.dart';
import '../core/theme.dart';

/// Blocks the app until the permissions tracking actually needs are granted.
///
/// It re-checks on every resume, because Android lets the employee revoke
/// background location at any time from settings — and a revoked grant stops
/// tracking silently, which would look like a route that simply ends.
class PermissionsGate extends StatefulWidget {
  const PermissionsGate({super.key, required this.session, required this.child});

  final Session session;
  final Widget child;

  @override
  State<PermissionsGate> createState() => _PermissionsGateState();
}

class _PermissionsGateState extends State<PermissionsGate> with WidgetsBindingObserver {
  PermissionState? _state;
  bool _batteryOptimised = false;

  /// Uninstall protection. Not an essential permission — the screen never blocks on it,
  /// because Android grants it on its own screen and an employee who declines should
  /// still be able to record their attendance.
  bool _adminActive = false;

  /// Physical activity. Not essential — attendance still records without it — but without it
  /// the app has to guess whether somebody is moving by comparing GPS positions, which is
  /// unreliable indoors and is what produced jittery routes.
  bool _motionGranted = false;

  /// Set when the employee has chosen to carry on without the battery exemption.
  ///
  /// Kept separate from _batteryOptimised, which _refresh() overwrites from the real
  /// system state on every resume — so dismissing alone would not stick, and the
  /// employee would be sent back to this page every time they reopened the app.
  /// Battery optimisation is genuinely not a blocker: tracking works under it, just
  /// with more gaps. Refusing to let anyone past would be a worse failure.
  bool _batteryDismissed = false;
  bool _checking = true;
  bool _requesting = false;

  /// The employee tapped "Grant all permissions": every return from a system
  /// screen continues the chain automatically, so they just keep tapping Allow
  /// with no knowledge needed. Cleared the moment anything is denied (no
  /// nagging loops) or the moment everything is granted.
  bool _grantAllActive = false;

  /// Guards the automatic prompt so it fires once per screen, not on every resume.
  /// Without this, coming back from the settings page with a permission still
  /// missing would immediately throw the user back into settings — an inescapable
  /// loop with no way to reach the Sign out button.
  bool _autoPrompted = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _refresh();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // The employee has just come back from the system settings screen, so what we
    // knew about permissions is now stale.
    if (state == AppLifecycleState.resumed) {
      _refresh();
      // Midway through "Grant all": carry on with the next step by itself, so a
      // trip to the settings page does not strand the employee back at the list
      // wondering what to press next.
      if (_grantAllActive && !_requesting) {
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) _grantAll();
        });
      }
    }
  }

  Future<void> _refresh() async {
    final permissions = await LocationHelper.state();
    final optimised = await Native.isBatteryOptimised();
    final admin = await Native.isDeviceAdminActive();
    final motion = await Permission.activityRecognition.isGranted;

    if (!mounted) return;
    setState(() {
      _state = permissions;
      _batteryOptimised = optimised;
      _adminActive = admin;
      _motionGranted = motion;
      _checking = false;
    });

    // Everything in place: make sure the service is running for this shift.
    if (permissions.allEssentialGranted) {
      await Native.startTracking();
      return;
    }

    // Something is missing: ask straight away rather than making the employee find
    // and press a button. This is the first thing they see after accepting the
    // disclosure, so the prompt arriving immediately is what they expect.
    if (!_autoPrompted) {
      _autoPrompted = true;
      // Deferred to the next frame so the screen behind the dialog is painted
      // first; a system dialog over a blank white screen looks like a crash.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _requestNext();
      });
    }
  }

  Future<void> _requestNext() async {
    final state = _state;
    if (state == null || _requesting) return;

    setState(() => _requesting = true);

    var granted = false;

    if (!state.serviceOn) {
      await Native.openLocationSettings();
    } else if (!state.foreground) {
      granted = await LocationHelper.requestForeground();
      if (!granted && mounted) {
        final now = await LocationHelper.state();
        // Permanently denied: the dialog will not appear again, so the only route
        // left is the app settings screen.
        if (now.foregroundPermanentlyDenied) await Native.openAppSettings();
      }
    } else if (!state.background) {
      // Android 11+ opens the settings page for this rather than a dialog; either
      // way it can only be asked for once foreground location is already granted.
      granted = await LocationHelper.requestBackground();
      if (!granted && mounted) {
        final now = await LocationHelper.state();
        if (now.backgroundPermanentlyDenied) await Native.openAppSettings();
      }
    } else if (!state.camera) {
      granted = await LocationHelper.requestCamera();
      if (!granted && mounted) {
        final now = await LocationHelper.state();
        if (now.cameraPermanentlyDenied) await Native.openAppSettings();
      }
    } else if (!state.notifications) {
      granted = await LocationHelper.requestNotifications();
    }

    if (mounted) setState(() => _requesting = false);

    // Chain onto the next permission, but only when this one was actually granted.
    // Tying the next automatic prompt to real progress is what keeps a denial from
    // becoming a loop: deny once and the prompts stop, leaving the buttons.
    //
    // The background-location step is excluded on purpose — it leaves the app for
    // the system settings page, and firing that automatically off the back of
    // another grant feels like the app has hijacked the phone.
    if (granted && mounted) {
      final now = await LocationHelper.state();
      final nextIsDialog = now.serviceOn && now.foreground && now.background &&
          (!now.camera || !now.notifications);
      if (nextIsDialog) {
        _autoPrompted = false;
      }
    }

    await _refresh();
  }

  /// One tap grants everything, step by step in the order Android demands:
  /// location service → foreground → "Allow all the time" → camera →
  /// notifications → movement detection.
  ///
  /// Android will not allow this in a single dialog — background location in
  /// particular must be picked on a Settings page — so this walks the employee
  /// through each screen automatically. They only ever tap Allow (or pick "Allow
  /// all the time" once). The chain stops at the first denial rather than
  /// nagging, and resumes by itself after every return from Settings.
  Future<void> _grantAll() async {
    if (_requesting) return;
    setState(() {
      _requesting = true;
      _grantAllActive = true;
    });

    try {
      var state = await LocationHelper.state();

      // 1. Location service: nothing can be granted before this is on.
      if (!state.serviceOn) {
        await Native.openLocationSettings();
        return; // continues on resume via _grantAllActive
      }

      // 2. Foreground location dialog.
      if (!state.foreground) {
        final granted = await LocationHelper.requestForeground();
        if (!granted) {
          _grantAllActive = false;
          state = await LocationHelper.state();
          if (state.foregroundPermanentlyDenied) await Native.openAppSettings();
          return;
        }
        state = await LocationHelper.state();
      }

      // 3. Background location. On Android 11+ this opens the Settings page —
      // the OS gives no dialog — so the employee picks "Allow all the time"
      // there and the chain continues when they come back.
      if (!state.background) {
        await LocationHelper.requestBackground();
        state = await LocationHelper.state();
        if (!state.background) {
          _grantAllActive = false;
          if (state.backgroundPermanentlyDenied) await Native.openAppSettings();
          return;
        }
      }

      // 4. Camera dialog (the check-in selfie is compulsory).
      if (!state.camera) {
        final granted = await LocationHelper.requestCamera();
        if (!granted) {
          _grantAllActive = false;
          state = await LocationHelper.state();
          if (state.cameraPermanentlyDenied) await Native.openAppSettings();
          return;
        }
      }

      // 5. Notifications dialog. Optional for tracking, but the consent screen
      // promises a visible indicator while tracking runs — asking here keeps
      // that promise without a second trip through setup.
      state = await LocationHelper.state();
      if (!state.notifications) {
        await LocationHelper.requestNotifications();
      }

      // 6. Movement detection dialog. Optional too, but one dialog now saves a
      // jittery-route mystery later — and it is the last tap of setup.
      if (!await Permission.activityRecognition.isGranted) {
        await Permission.activityRecognition.request();
      }

      _grantAllActive = false;
    } finally {
      if (mounted) setState(() => _requesting = false);
    }

    await _refresh();
  }

  /// "Step 2 of 6 · Allow all the time" — so the employee sees progress rather
  /// than an endless series of popups.
  String _grantAllLabel(PermissionState state) {
    final steps = <String>[];
    if (!state.serviceOn) steps.add('Turn on location');
    if (!state.foreground) steps.add('Allow location');
    if (!state.background) steps.add('Allow all the time');
    if (!state.camera) steps.add('Allow camera');
    if (!state.notifications) steps.add('Allow notifications');
    return steps.isEmpty ? 'Almost done…' : 'Step ${6 - steps.length} of 6 · ${steps.first}';
  }

  @override
  Widget build(BuildContext context) {
    if (_checking) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    final state = _state!;

    // Battery optimisation used to be stacked over the dashboard once the essential
    // permissions were granted, which put a permission prompt permanently on top of
    // the screen the employee actually works from. It is a step on this page now.
    if (state.allEssentialGranted && (!_batteryOptimised || _batteryDismissed)) {
      return widget.child;
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Permissions needed')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Almost ready',
                style: TextStyle(fontSize: 19, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 6),
              const Text(
                'Tap once below and keep tapping Allow — the app walks through '
                'each one for you, in the order Android demands. (Background '
                'location is the only one you pick yourself: choose "Allow all '
                'the time" on that screen.)',
                style: TextStyle(color: AppTheme.inkSoft, fontSize: 13.5),
              ),
              const SizedBox(height: 22),

              _row(
                granted: state.serviceOn,
                icon: Icons.gps_fixed,
                title: 'Location switched on',
                body: 'Your phone\'s location service must be turned on.',
              ),
              _row(
                granted: state.foreground,
                icon: Icons.near_me,
                title: 'Location while using the app',
                body: 'Needed to verify you are inside your work area at check-in.',
                blocked: state.foregroundPermanentlyDenied,
              ),
              _row(
                granted: state.background,
                icon: Icons.all_inclusive,
                title: 'Location "Allow all the time"',
                body: 'Needed to record your route through the shift. Choose '
                    '"Allow all the time" on the settings screen — "While using the app" '
                    'is not enough.',
                blocked: state.backgroundPermanentlyDenied,
              ),
              _row(
                granted: state.camera,
                icon: Icons.photo_camera,
                title: 'Camera',
                body: 'A photo is compulsory at check-in and check-out.',
                blocked: state.cameraPermanentlyDenied,
              ),
              _row(
                granted: state.notifications,
                icon: Icons.notifications_active_outlined,
                title: 'Notifications (recommended)',
                body: 'Shows you when tracking is running and warns you if you leave '
                    'your work area.',
                optional: true,
              ),

              // Battery optimisation, as a step on this page rather than a banner
              // stacked over the dashboard. Optional: 'Fix' opens the system screen,
              // the close button carries on without it.
              if (_batteryOptimised && !_batteryDismissed) ...[
                const SizedBox(height: 6),
                _batteryBanner(),
              ],

              const SizedBox(height: 6),
              _motionCard(),

              const SizedBox(height: 6),
              _protectionCard(),

              const SizedBox(height: 20),
              FilledButton.icon(
                icon: const Icon(Icons.touch_app),
                onPressed: _requesting ? null : _grantAll,
                label: Text(_requesting ? 'Opening…' : 'Grant all permissions'),
              ),
              if (_requesting || _grantAllActive) ...[
                const SizedBox(height: 8),
                Center(
                  child: Text(
                    _grantAllLabel(state),
                    style: const TextStyle(
                      fontSize: 12.5,
                      color: AppTheme.brand,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ],
              const SizedBox(height: 8),
              OutlinedButton(
                onPressed: () => Native.openAppSettings(),
                child: const Text('Open app settings'),
              ),
              if (state.anyPermanentlyDenied) ...[
                const SizedBox(height: 14),
                Container(
                  padding: const EdgeInsets.all(13),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFDF2DC),
                    borderRadius: BorderRadius.circular(11),
                  ),
                  child: const Text(
                    'One of the permissions was permanently denied, so Android will no '
                    'longer show its dialog. Use "Open app settings" and enable it there.',
                    style: TextStyle(fontSize: 12.5, color: AppTheme.warn),
                  ),
                ),
              ],
              const SizedBox(height: 18),
              Center(
                child: TextButton(
                  onPressed: () => widget.session.signOut(),
                  child: const Text('Sign out'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _row({
    required bool granted,
    required IconData icon,
    required String title,
    required String body,
    bool blocked = false,
    bool optional = false,
  }) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              granted ? Icons.check_circle : (blocked ? Icons.block : icon),
              color: granted ? AppTheme.ok : (blocked ? AppTheme.bad : AppTheme.inkSoft),
              size: 22,
            ),
            const SizedBox(width: 13),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          title,
                          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                        ),
                      ),
                      if (optional && !granted)
                        const StatusChip(label: 'optional', tone: ChipTone.muted),
                    ],
                  ),
                  const SizedBox(height: 3),
                  Text(
                    body,
                    style: const TextStyle(color: AppTheme.inkSoft, fontSize: 12.5, height: 1.4),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// A button that sizes to its label instead of filling the width.
  ///
  /// The app's theme sets `minimumSize: Size.fromHeight(52)` on FilledButton, and
  /// Size.fromHeight means Size(double.infinity, 52) — deliberately, because almost every
  /// button in this app is a full-width primary action at the bottom of a Column. Inside a
  /// Row that same style takes all the available width and starves its siblings to zero, so
  /// the text beside it wrapped one character per line. These in-card buttons therefore have
  /// to opt out of the app-wide width.
  static final ButtonStyle _inlineButton = FilledButton.styleFrom(
    minimumSize: const Size(76, 38),
    padding: const EdgeInsets.symmetric(horizontal: 14),
    textStyle: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600),
  );

  /// Physical activity, explained in terms of what it buys.
  ///
  /// Asking for "physical activity" sounds invasive and is easy to refuse, so the card says
  /// exactly what it is used for and what it is not. It is genuinely optional: everything
  /// still works without it, just with a messier route.
  Widget _motionCard() {
    final on = _motionGranted;
    return Container(
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: on ? const Color(0xFFE8F6EE) : const Color(0xFFF4F6FA),
        borderRadius: BorderRadius.circular(11),
        border: Border.all(color: on ? const Color(0xFFB7E2C7) : const Color(0xFFDDE3EC)),
      ),
      child: Row(
        children: [
          Icon(on ? Icons.directions_walk : Icons.directions_walk_outlined,
              color: on ? AppTheme.ok : AppTheme.inkSoft, size: 22),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  on ? 'Movement detection is on' : 'Improve route accuracy',
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 13,
                    color: on ? AppTheme.ok : AppTheme.ink,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  on
                      ? 'Your location is not recorded while you are standing still.'
                      : 'Lets the app tell walking from standing still, so it stops recording '
                          'your location when you are not moving. It reads step motion only — '
                          'not health data, and not what you are doing.',
                  style: const TextStyle(fontSize: 12, color: AppTheme.inkSoft, height: 1.35),
                ),
              ],
            ),
          ),
          if (!on) ...[
            const SizedBox(width: 8),
            FilledButton(
              style: _inlineButton,
              onPressed: () async {
                await Permission.activityRecognition.request();
                // Re-read rather than assume: a declined request must show as declined.
                await _refresh();
              },
              child: const Text('Allow'),
            ),
          ],
        ],
      ),
    );
  }

  /// Uninstall protection, offered plainly.
  ///
  /// Stated in terms of what it does and does not do. An employee asked to hand an app
  /// "device administrator" on their phone is right to be wary, and the honest answer —
  /// it blocks removal, it cannot read anything, it cannot wipe the phone — is more
  /// persuasive than a vague reassurance, as well as being true.
  Widget _protectionCard() {
    final on = _adminActive;
    return Container(
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: on ? const Color(0xFFE8F6EE) : const Color(0xFFF4F6FA),
        borderRadius: BorderRadius.circular(11),
        border: Border.all(color: on ? const Color(0xFFB7E2C7) : const Color(0xFFDDE3EC)),
      ),
      child: Row(
        children: [
          Icon(on ? Icons.verified_user : Icons.shield_outlined,
              color: on ? AppTheme.ok : AppTheme.inkSoft, size: 22),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  on ? 'Removal protection is on' : 'Stop this app being removed',
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 13,
                    color: on ? AppTheme.ok : AppTheme.ink,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  on
                      ? 'Your administrator can lift this from the office when the phone '
                          'is handed back.'
                      : 'Blocks the Uninstall button. It cannot read your photos, '
                          'messages or files, and cannot lock or erase the phone.',
                  style: const TextStyle(fontSize: 12, color: AppTheme.inkSoft, height: 1.35),
                ),
              ],
            ),
          ),
          if (!on) ...[
            const SizedBox(width: 8),
            FilledButton(
              style: _inlineButton,
              onPressed: () async {
                await Native.requestDeviceAdmin();
                // Android's grant screen is a separate activity; the state is re-read on
                // resume rather than assumed, so a declined prompt shows honestly.
              },
              child: const Text('Turn on'),
            ),
          ],
        ],
      ),
    );
  }

  /// The battery-saver warning, as a card in the page.
  ///
  /// This used to float over the dashboard, which is why it was a Positioned. When it
  /// became a step on this page nobody changed that, and a Positioned inside a Column
  /// throws "FlexParentData is not a subtype of StackParentData" — which takes the whole
  /// build down, so the employee saw a bare "Permissions needed" title over a blank
  /// screen and could go no further. It only appeared once battery optimisation was
  /// detected, which is why it looked like being stuck after granting one permission.
  Widget _batteryBanner() {
    return Container(
      margin: const EdgeInsets.only(top: 4),
      decoration: BoxDecoration(
        color: const Color(0xFFFDF2DC),
        borderRadius: BorderRadius.circular(11),
        border: Border.all(color: const Color(0xFFF0D9A8)),
      ),
      padding: const EdgeInsets.fromLTRB(13, 11, 6, 11),
      child: Row(
        children: [
          const Icon(Icons.battery_alert, color: AppTheme.warn, size: 20),
          const SizedBox(width: 11),
          const Expanded(
            child: Text(
              'Battery saver may stop tracking and leave gaps in your route.',
              style: TextStyle(fontSize: 12.5, color: AppTheme.warn, height: 1.35),
            ),
          ),
          TextButton(
            onPressed: () async {
              await Native.requestBatteryExemption();
              await _refresh();
            },
            child: const Text('Fix'),
          ),
          IconButton(
            tooltip: 'Dismiss',
            icon: const Icon(Icons.close, size: 18),
            onPressed: () => setState(() => _batteryDismissed = true),
          ),
        ],
      ),
    );
  }
}
