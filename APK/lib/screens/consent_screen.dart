import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/session.dart';
import '../core/theme.dart';

/// Background-location disclosure.
///
/// This screen is not decoration. Google Play requires a prominent, in-app
/// disclosure of background location collection before any is gathered, and it must
/// state what is collected, why, and that it continues when the app is closed.
/// Recording the acceptance server-side also gives the employer a defensible record
/// that the employee was told.
class ConsentScreen extends StatefulWidget {
  const ConsentScreen({
    super.key,
    required this.session,
    required this.api,
    required this.onAccepted,
  });

  final Session session;
  final Api api;
  final VoidCallback onAccepted;

  @override
  State<ConsentScreen> createState() => _ConsentScreenState();
}

class _ConsentScreenState extends State<ConsentScreen> {
  bool _busy = false;
  bool _ticked = false;
  String? _error;

  Future<void> _accept() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final result = await widget.api.acceptConsent();

    if (!mounted) return;

    if (result.success) {
      widget.onAccepted();
      return;
    }

    setState(() {
      _busy = false;
      _error = result.isNetworkFailure
          ? 'You need a connection to record your consent.'
          : result.message;
    });
  }

  @override
  Widget build(BuildContext context) {
    final interval = widget.session.intervalMinutes;

    return Scaffold(
      appBar: AppBar(title: const Text('Before you start')),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Hello ${widget.session.employeeName}',
                      style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 6),
                    const Text(
                      'This app records your location during your working hours. '
                      'Please read this before continuing.',
                      style: TextStyle(color: AppTheme.inkSoft, fontSize: 13.5),
                    ),
                    const SizedBox(height: 20),

                    _point(
                      Icons.my_location,
                      'Your location is recorded every $interval minutes',
                      'Continuously for attendance between your shift start and end times. '
                          'Outside those hours the phone sends one position, a few times an '
                          'hour, for the office live board only — never a route, and never '
                          'counted as attendance.',
                    ),
                    _point(
                      Icons.phone_android,
                      'Recording continues when the app is closed',
                      'While tracking is running, an "on duty" notification is shown so you '
                          'can always tell. If you switch notifications off for this app the '
                          'indicator disappears — recording carries on, and the app will warn '
                          'you about that on the home screen. Android also requires you to '
                          'choose "Allow all the time" for location.',
                    ),
                    _point(
                      Icons.camera_alt_outlined,
                      'A photo is taken at check-in and check-out',
                      'Taken with the camera at that moment. You cannot pick an old picture '
                          'from your gallery.',
                    ),
                    _point(
                      Icons.map_outlined,
                      'Your employer can see your route during working hours',
                      'Including whether you left your assigned work area, for how long, and '
                          'how far. You will be asked to give a reason when that happens.',
                    ),
                    _point(
                      Icons.phonelink_lock,
                      'Your account works on this device only',
                      'To move to a different phone, your HR administrator must reset it first.',
                    ),
                    _point(
                      Icons.battery_charging_full,
                      'Battery use',
                      'Continuous location uses more battery than normal. Please keep your '
                          'phone charged during your shift.',
                    ),

                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: const Color(0xFFEEF1F6),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Text(
                        'Your location is used for attendance and work verification only. '
                        'Outside working hours only the office live board sees your position, '
                        'a few times an hour. It is not shared with anyone outside your employer.',
                        style: TextStyle(fontSize: 12.5, color: AppTheme.inkSoft, height: 1.45),
                      ),
                    ),

                    if (_error != null) ...[
                      const SizedBox(height: 16),
                      Text(_error!, style: const TextStyle(color: AppTheme.bad, fontSize: 13.5)),
                    ],
                  ],
                ),
              ),
            ),

            // Kept out of the scroll view so the action is always reachable.
            Container(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
              decoration: const BoxDecoration(
                color: Colors.white,
                border: Border(top: BorderSide(color: Color(0xFFE2E7F0))),
              ),
              child: Column(
                children: [
                  CheckboxListTile(
                    value: _ticked,
                    onChanged: _busy ? null : (value) => setState(() => _ticked = value ?? false),
                    controlAffinity: ListTileControlAffinity.leading,
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    title: const Text(
                      'I have read and understood the above, and I agree to location '
                      'recording during my working hours.',
                      style: TextStyle(fontSize: 13),
                    ),
                  ),
                  const SizedBox(height: 6),
                  FilledButton(
                    onPressed: (_ticked && !_busy) ? _accept : null,
                    child: _busy
                        ? const SizedBox(
                            height: 22,
                            width: 22,
                            child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                          )
                        : const Text('Agree and continue'),
                  ),
                  TextButton(
                    onPressed: _busy ? null : () => widget.session.signOut(),
                    child: const Text('I do not agree — sign out'),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _point(IconData icon, String title, String body) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: const Color(0xFFE8EEFF),
              borderRadius: BorderRadius.circular(9),
            ),
            child: Icon(icon, size: 19, color: AppTheme.brand),
          ),
          const SizedBox(width: 13),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
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
    );
  }
}
