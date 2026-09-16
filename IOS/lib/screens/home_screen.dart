import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../core/api.dart';
import '../core/native.dart';
import '../core/offline_map.dart';
import '../core/session.dart';
import '../core/theme.dart';
import 'checkin_screen.dart';
import 'history_screen.dart';
import 'notifications_screen.dart';
import 'trip_reason_screen.dart';

/// The screen the employee actually uses: check in, check out, and see status.
class HomeScreen extends StatefulWidget {
  const HomeScreen({
    super.key,
    required this.session,
    required this.api,
    required this.offlineMap,
  });

  final Session session;
  final Api api;
  final OfflineMap offlineMap;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  Map<String, dynamic> _today = {};
  Map<String, dynamic>? _currentlyOutside;
  List<Map<String, dynamic>> _reasonsPending = [];
  List<Map<String, dynamic>> _recentDecisions = [];
  TrackingStatus _tracking = const TrackingStatus();

  /// The consent screen promises a visible indicator while tracking runs, so this
  /// being false is a broken promise, not a cosmetic detail.
  bool _notificationsBlocked = false;

  bool _loading = true;
  bool _offline = false;
  Timer? _poll;
  StreamSubscription<FenceEvent>? _fenceSub;

  /// Set by a push from the service so the warning is on screen before the next
  /// poll confirms it from the server.
  FenceEvent? _liveFence;

  /// When the crossing was pushed, so the banner can stop claiming the prompt is
  /// about to appear once it plainly is not.
  DateTime? _liveFenceSince;

  /// Seconds since the crossing was pushed.
  int get _liveFenceWaited {
    final since = _liveFenceSince;
    return since == null ? 0 : DateTime.now().difference(since).inSeconds;
  }

  /// Trips already prompted for, so the sheet opens once per departure rather than
  /// reappearing on every 15-second poll while the employee is still outside.
  final Set<int> _promptedTrips = {};

  /// Decision ids the employee has already opened notifications on, so the bell
  /// badge counts what is new rather than what exists.
  final Set<int> _seenDecisions = {};

  /// Guards against opening two prompts if a push and a poll land together.
  bool _promptOpen = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _refresh();

    // 15s rather than 45s: an admin's decision on a trip, or a reason becoming due,
    // should appear while the employee is still looking at the screen. The request
    // is small, and it only runs while this screen is in the foreground.
    _poll = Timer.periodic(const Duration(seconds: 15), (_) => _refresh(quiet: true));

    // The service detects a crossing in seconds; this is what makes the warning and
    // the reason prompt appear then, rather than on the next poll.
    _fenceSub = Native.fenceEvents().listen(_onFenceEvent);
  }

  @override
  void dispose() {
    _poll?.cancel();
    _fenceSub?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Notifications, with a count of decisions the employee has not opened yet.
  ///
  /// The badge counts only *new* verdicts. A badge that simply counted decided trips
  /// would never clear, and a permanent number teaches people to ignore it — at which
  /// point it is worse than no badge at all.
  Widget _bell() {
    final unseen = _recentDecisions
        .where((d) => !_seenDecisions.contains((d['id'] as num?)?.toInt()))
        .length;

    return Stack(
      alignment: Alignment.center,
      children: [
        IconButton(
          tooltip: 'Notifications',
          icon: const Icon(Icons.notifications_none),
          onPressed: _openNotifications,
        ),
        if (unseen > 0)
          Positioned(
            top: 8,
            right: 8,
            child: IgnorePointer(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                constraints: const BoxConstraints(minWidth: 16),
                decoration: BoxDecoration(
                  color: AppTheme.bad,
                  borderRadius: BorderRadius.circular(9),
                  border: Border.all(color: AppTheme.brand, width: 1.5),
                ),
                child: Text(
                  unseen > 9 ? '9+' : '$unseen',
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }

  Future<void> _openNotifications() async {
    // Marked seen on opening, so the badge reflects "since you last looked".
    setState(() {
      _seenDecisions.addAll(
        _recentDecisions.map((d) => (d['id'] as num?)?.toInt()).whereType<int>(),
      );
    });

    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => NotificationsScreen(api: widget.api)),
    );
    if (mounted) await _refresh(quiet: true);
  }

  /// A crossing arrived from the service.
  void _onFenceEvent(FenceEvent event) {
    if (!mounted) return;

    setState(() {
      _liveFence = event.outside ? event : null;
      _liveFenceSince = event.outside ? DateTime.now() : null;
    });

    if (event.outside) {
      // Shown immediately; the server round trip that produces the trip id follows
      // a moment later, and _refresh picks it up so the reason button can work.
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: AppTheme.bad,
          duration: const Duration(seconds: 8),
          content: Text(
            'You have left ${event.areaName} — ${event.distanceM} m outside. '
            'Please record a reason.',
          ),
        ),
      );
    }

    if (event.outside) {
      unawaited(_chaseTrip());
    } else {
      _refresh(quiet: true);
    }
  }

  /// Poll hard for a few seconds until the departure exists server-side.
  ///
  /// A single refresh here was always too early: the device knows it has crossed the
  /// moment its watch says so, but the server only opens the trip once it has enough
  /// confirmation, which takes a few seconds. The old code refreshed once, found
  /// nothing, and then waited out the ordinary fifteen-second poll — leaving the
  /// employee watching a spinner that said the prompt was coming "shortly".
  ///
  /// Stops as soon as the trip appears, so the prompt opens the instant it can.
  Future<void> _chaseTrip() async {
    const attempts = [0, 2, 4, 7, 11, 16, 22, 30];

    for (final delay in attempts) {
      if (!mounted) return;
      if (delay > 0) await Future<void>.delayed(Duration(seconds: delay));
      if (!mounted) return;

      await _refresh(quiet: true);

      // _refresh opens the prompt itself once there is a trip to attach it to.
      if (_currentlyOutside != null) return;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _refresh(quiet: true);
  }

  Future<void> _refresh({bool quiet = false}) async {
    if (!quiet) setState(() => _loading = true);

    final status = await Native.trackingStatus();
    final notificationsOk = await Native.notificationsEnabled();
    final result = await widget.api.me();

    if (!mounted) return;

    _notificationsBlocked = !notificationsOk;

    if (result.sessionDead) {
      final message = result.message;
      await widget.session.signOut();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(message), backgroundColor: AppTheme.bad),
      );
      return;
    }

    if (result.isNetworkFailure) {
      // Fall back to the last state the server confirmed. Rendering an empty day
      // here told a checked-in employee they had "Not started" and offered a Check
      // In button - inviting a duplicate check-in, or the belief that theirs was
      // lost. It also made the banner's "showing your last saved information" a lie.
      final cached = widget.session.lastMe;
      setState(() {
        _loading = false;
        _offline = true;
        _tracking = status;
        if (_today.isEmpty && cached.isNotEmpty) {
          _today = (cached['today'] as Map?)?.cast<String, dynamic>() ?? {};
          _currentlyOutside =
              (cached['currently_outside'] as Map?)?.cast<String, dynamic>();
          _reasonsPending = ((cached['reasons_pending'] as List?) ?? [])
              .map((e) => (e as Map).cast<String, dynamic>())
              .toList();
        }
      });
      return;
    }

    if (!result.success) {
      setState(() {
        _loading = false;
        _tracking = status;
      });
      return;
    }

    final data = result.map;

    // Kept for the next cold start with no signal.
    unawaited(widget.session.rememberMe(data));

    // Pull the rules again in the background: an admin may have changed the
    // interval, the shift or the work area since the app last looked. Deliberately
    // not awaited — the screen should render from /me straight away rather than
    // waiting on a second round trip.
    unawaited(_refreshConfig());

    setState(() {
      _loading = false;
      _offline = false;
      _tracking = status;
      _today = (data['today'] as Map?)?.cast<String, dynamic>() ?? {};
      _currentlyOutside = (data['currently_outside'] as Map?)?.cast<String, dynamic>();
      _reasonsPending = ((data['reasons_pending'] as List?) ?? [])
          .map((e) => (e as Map).cast<String, dynamic>())
          .toList();
      _recentDecisions = ((data['recent_decisions'] as List?) ?? [])
          .map((e) => (e as Map).cast<String, dynamic>())
          .toList();
    });

    // The native service records on shift hours alone, which say nothing about an
    // employee still clocked in after their shift was due to end. Telling it the
    // register's answer is what keeps overtime on the route instead of blank.
    // This is the native service's authoritative session gate. Await it so a
    // checkout cannot race a stale poll/start callback and briefly re-enable GPS.
    await Native.setShiftOpen(_checkedIn && !_checkedOut);

    // Keep the service's alarm state in step with the server's view of whether this
    // departure has been explained — including a reason an admin entered, or one
    // submitted before the app was reinstalled.
    final outside = _currentlyOutside;
    unawaited(Native.setOutsideReasonGiven(
      outside != null && (outside['employee_reason'] as String?)?.isNotEmpty == true,
    ));

    // Deliberately not awaited: this pushes a route and waits for the employee, and
    // _refresh must not stay suspended for however long they take to type a reason.
    unawaited(_maybePromptForReason());
  }

  /// Open the reason prompt by itself when a departure is unexplained.
  ///
  /// The banner and its button remain, because an employee who dismisses the sheet
  /// still needs a way back to it — but the prompt appearing on its own is what
  /// makes the reason get recorded near the time it happened, rather than being
  /// reconstructed days later when an admin chases it.
  Future<void> _maybePromptForReason() async {
    if (_promptOpen) return;

    final outside = _currentlyOutside;
    if (outside == null) return;

    final tripId = (outside['id'] as num?)?.toInt();
    if (tripId == null) return;

    // Already explained, or already asked this time.
    final hasReason = (outside['employee_reason'] as String?)?.isNotEmpty == true;
    if (hasReason || _promptedTrips.contains(tripId)) return;

    _promptedTrips.add(tripId);
    _promptOpen = true;

    final submitted = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => TripReasonScreen(
          api: widget.api,
          eventId: tripId,
          tripSummary: _tripSummary(outside),
        ),
      ),
    );

    _promptOpen = false;

    if (submitted == true) {
      // Before the refresh, not after: the alarm must stop the instant the question
      // is answered, and _refresh makes a network round trip first.
      await Native.setOutsideReasonGiven(true);
      if (mounted) await _refresh(quiet: true);
    }
  }

  Future<void> _refreshConfig() async {
    final result = await widget.api.appConfig();
    if (!result.success || !mounted) return;

    await widget.session.updateConfig({
      'settings': result.map['settings'],
      'geofence': result.map['geofence'],
      'geofences': result.map['geofences'],
      'map': result.map['map'],
      'branding': result.map['branding'],
      'today': result.map['today'],
    });

    // Only start it if it is not already running.
    //
    // This method runs on a 45-second poll, and calling startTracking() every time
    // used to reset the service's own timers before they could elapse — the service
    // is idempotent about it now, but there is no reason to keep knocking on a door
    // that is already open.
    if (_checkedIn && !_checkedOut &&
        widget.session.trackingActiveNow && !_tracking.running) {
      await Native.startTracking();
    }
  }

  bool get _checkedIn => _today['checked_in'] == true;
  bool get _checkedOut => _today['checked_out'] == true;

  Future<void> _openCheckIn({required bool checkingOut}) async {
    final changed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => CheckInScreen(
          session: widget.session,
          api: widget.api,
          offlineMap: widget.offlineMap,
          checkingOut: checkingOut,
        ),
      ),
    );

    if (changed == true) {
      await _refresh();
      // Tracking begins only after a confirmed check-in. Checkout must stop it
      // immediately, including any service instance left from an earlier session.
      if (_checkedIn && !_checkedOut) {
        await Native.startTracking();
      } else {
        await Native.stopTracking();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = widget.session;

    return Scaffold(
      backgroundColor: AppTheme.surface,
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : CustomScrollView(
                slivers: [
                  _header(session),
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
                    sliver: SliverList(
                      delegate: SliverChildListDelegate([
                        if (_notificationsBlocked) _notificationsBanner(),
                        if (_offline) _offlineBanner(),
                        // The server's record wins when it has one; the live push
                        // covers the seconds before it arrives.
                        if (_currentlyOutside != null)
                          _outsideBanner()
                        else if (_liveFence != null)
                          _liveFenceBanner(_liveFence!),
                        // Pending work only. Decided trips live in Notifications:
                        // a dashboard that also lists settled history buries the one
                        // item the employee still has to act on.
                        if (_reasonsPending.isNotEmpty) _pendingReasonsCard(),
                        // No pending-points row and no manual Upload. Retrying is the
                        // app's job: it uploads the moment a connection exists. Showing a
                        // queue only made the employee doubt it was working and hunt for
                        // a button, which is a worse experience than silence.
                        _todayCard(),
                        _actionButton(),
                      ]),
                    ),
                  ),
                ],
              ),
      ),
    );
  }

  /* ---------------- header ---------------- */

  /// Branded header: company logo and the employee's identity, nothing else.
  ///
  /// Tracking state is deliberately not shown here. The ongoing Android
  /// notification already says whether recording is running, and repeating it on
  /// the dashboard invited the employee to watch a status that is not theirs to
  /// act on. The one tracking signal that does warrant attention — something stuck
  /// unsent — appears as its own row, and only when it is true.
  Widget _header(Session session) {
    return SliverAppBar(
      pinned: true,
      elevation: 0,
      expandedHeight: 148,
      backgroundColor: AppTheme.brand,
      foregroundColor: Colors.white,
      surfaceTintColor: Colors.transparent,
      systemOverlayStyle: SystemUiOverlayStyle.light,
      actions: [
        _bell(),
        IconButton(
          tooltip: 'My attendance',
          icon: const Icon(Icons.calendar_month_outlined),
          onPressed: () => Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => HistoryScreen(session: session, api: widget.api),
            ),
          ),
        ),
        // No sign-out button, deliberately.
        //
        // Signing out stops tracking and leaves the day half-recorded, and an employee
        // has no use for it: the device is bound to their account, so there is nobody
        // else who could sign in on this phone. Releasing a device is an administrator's
        // action, from the panel. _confirmSignOut is kept for that path and for a
        // session the server has already killed.
        const SizedBox(width: 4),
      ],
      // Collapses to just the company name, so the pinned bar stays usable.
      title: _collapsedTitle(session),
      flexibleSpace: FlexibleSpaceBar(
        collapseMode: CollapseMode.parallax,
        background: Container(
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [AppTheme.brand, Color(0xFF163BA6)],
            ),
          ),
          child: SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.end,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      _logo(session),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              session.employeeName,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 19,
                                fontWeight: FontWeight.w700,
                                letterSpacing: -0.2,
                              ),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              [
                                session.employeeCode,
                                // Designation (job title), not the ERP access role.
                                session.employee['designation'] as String? ?? '',
                              ].where((s) => s.isNotEmpty).join('  ·  '),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                color: Colors.white.withValues(alpha: 0.75),
                                fontSize: 12.5,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _collapsedTitle(Session session) {
    return Text(
      session.companyName,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: const TextStyle(fontSize: 15.5, fontWeight: FontWeight.w600),
    );
  }

  /// The configurable logo, falling back to the built-in mark so the header never
  /// renders with a hole in it while the image loads or if none is set.
  Widget _logo(Session session) {
    final url = session.logoUrl;
    final file = session.logoFile;

    return Container(
      width: 46,
      height: 46,
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(11),
      ),
      clipBehavior: Clip.antiAlias,
      // Disk first so the header logo is present in the first frame.
      child: file != null
          ? Image.file(file, fit: BoxFit.contain,
              errorBuilder: (_, __, ___) => _logoFallback())
          : (url == null
              ? _logoFallback()
              : Image.network(
                  url,
                  fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) => _logoFallback(),
                  loadingBuilder: (context, child, progress) =>
                      progress == null ? child : _logoFallback(),
                )),
    );
  }

  Widget _logoFallback() => const Center(
        child: Text(
          'SST',
          style: TextStyle(
            color: AppTheme.brand,
            fontWeight: FontWeight.w800,
            fontSize: 14,
            letterSpacing: 0.5,
          ),
        ),
      );

  /* ---------------- banners ---------------- */

  /// Deliberately not dismissible. While notifications are off the employee has no
  /// way to tell whether tracking is running, which is exactly what they were told
  /// they would always be able to see.
  Widget _notificationsBanner() {
    return Card(
      color: const Color(0xFFFDF2DC),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: const BorderSide(color: Color(0xFFF0D9A8)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(15),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.notifications_off, color: AppTheme.warn, size: 20),
                SizedBox(width: 9),
                Expanded(
                  child: Text(
                    'Notifications are switched off',
                    style: TextStyle(fontWeight: FontWeight.w700, color: AppTheme.warn),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 7),
            const Text(
              'Tracking still works, but the "on duty" notification is hidden — so you '
              'cannot see when your location is being recorded. Turning notifications '
              'back on is how you stay in control of that.',
              style: TextStyle(fontSize: 12.5, color: AppTheme.ink, height: 1.4),
            ),
            const SizedBox(height: 11),
            FilledButton.icon(
              style: FilledButton.styleFrom(
                backgroundColor: AppTheme.warn,
                minimumSize: const Size.fromHeight(44),
              ),
              icon: const Icon(Icons.settings, size: 18),
              label: const Text('Turn notifications on'),
              onPressed: () async {
                await Native.openNotificationSettings();
                // Re-checked on resume by didChangeAppLifecycleState.
              },
            ),
          ],
        ),
      ),
    );
  }

  Widget _offlineBanner() => _banner(
        colour: const Color(0xFFEEF1F6),
        textColour: AppTheme.inkSoft,
        icon: Icons.cloud_off,
        title: 'No connection',
        body: 'Showing your last saved information. Your location is still being '
            'recorded and will upload when you are back online.',
      );

  Widget _outsideBanner() {
    final since = _currentlyOutside!['exit_at'] as String?;
    final distance = (_currentlyOutside!['max_distance_m'] as num?)?.toInt() ?? 0;
    final eventId = (_currentlyOutside!['id'] as num?)?.toInt();
    final hasReason = (_currentlyOutside!['employee_reason'] as String?)?.isNotEmpty == true;

    return Card(
      color: const Color(0xFFFDEAEC),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: const BorderSide(color: Color(0xFFF3C2C6)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(15),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.location_off, color: AppTheme.bad, size: 20),
                SizedBox(width: 9),
                Expanded(
                  child: Text(
                    'You are outside your work area',
                    style: TextStyle(fontWeight: FontWeight.w700, color: AppTheme.bad),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 7),
            Text(
              '${NumberFormat.decimalPattern().format(distance)} m away'
              '${since != null ? ' · since ${_time(since)}' : ''}',
              style: const TextStyle(fontSize: 13),
            ),
            const SizedBox(height: 10),
            if (!hasReason && eventId != null)
              FilledButton.icon(
                style: FilledButton.styleFrom(
                  backgroundColor: AppTheme.bad,
                  minimumSize: const Size.fromHeight(44),
                ),
                icon: const Icon(Icons.edit_note, size: 19),
                label: const Text('Give a reason'),
                onPressed: () => _openReason(eventId),
              )
            else
              // Explained already, so there is nothing to do here. The verdict and the
              // full detail live in Notifications — this banner exists to report that
              // they are outside right now, not to duplicate the log.
              Row(
                children: [
                  const Icon(Icons.check_circle_outline, size: 17, color: AppTheme.inkSoft),
                  const SizedBox(width: 8),
                  const Expanded(
                    child: Text(
                      'Reason submitted. See Notifications for the decision.',
                      style: TextStyle(fontSize: 12.5, color: AppTheme.inkSoft),
                    ),
                  ),
                  TextButton(
                    onPressed: _openNotifications,
                    child: const Text('Open'),
                  ),
                ],
              ),
          ],
        ),
      ),
    );
  }

  /// The gap-filler between the service noticing a crossing and the server having a
  /// trip record for it. Same look as the confirmed banner, but it says the reason
  /// prompt is still coming rather than offering a button that cannot work yet.
  Widget _liveFenceBanner(FenceEvent event) {
    return Card(
      color: const Color(0xFFFDEAEC),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: const BorderSide(color: Color(0xFFF3C2C6)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(15),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.location_off, color: AppTheme.bad, size: 20),
                SizedBox(width: 9),
                Expanded(
                  child: Text(
                    'You have left your work area',
                    style: TextStyle(fontWeight: FontWeight.w700, color: AppTheme.bad),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 7),
            Text(
              '${NumberFormat.decimalPattern().format(event.distanceM)} m outside '
              '${event.areaName}',
              style: const TextStyle(fontSize: 13),
            ),
            const SizedBox(height: 8),
            // The wait is real — the server needs a couple of confirming fixes before
            // the departure exists to attach a reason to. Say so, and stop implying it
            // is imminent once it clearly is not, rather than spinning indefinitely.
            if (_liveFenceWaited < 25)
              const Row(
                children: [
                  SizedBox(
                    width: 13,
                    height: 13,
                    child: CircularProgressIndicator(strokeWidth: 2, color: AppTheme.bad),
                  ),
                  SizedBox(width: 9),
                  Expanded(
                    child: Text(
                      'Confirming your position — the reason prompt opens in a few seconds.',
                      style: TextStyle(fontSize: 12.5, color: AppTheme.inkSoft),
                    ),
                  ),
                ],
              )
            else
              Row(
                children: [
                  const Expanded(
                    child: Text(
                      'Still confirming. This needs a data connection — check yours, '
                      'then try again.',
                      style: TextStyle(fontSize: 12.5, color: AppTheme.inkSoft),
                    ),
                  ),
                  const SizedBox(width: 8),
                  TextButton(
                    onPressed: () => unawaited(_chaseTrip()),
                    child: const Text('Try again'),
                  ),
                ],
              ),
          ],
        ),
      ),
    );
  }

  /// "14:05 → 14:50" for a closed trip, "since 14:05" while still out.
  ///
  /// Times come from the server as "YYYY-MM-DD HH:MM:SS"; only the clock part is
  /// shown because the date is already on the same line.
  /// One line naming a trip: day, clock window, length and distance.
  ///
  /// Handles both shapes the server sends — a finished trip (exit_at/entry_at) and the
  /// one currently open (since) — so the instant prompt and the pending list describe
  /// the same departure the same way.
  String _tripSummary(Map<String, dynamic> trip) {
    final parts = <String>[];

    final date = trip['work_date'] as String?;
    if (date != null && date.isNotEmpty) parts.add(_dayLabel(date));

    final window = _tripWindow(trip);
    if (window.isNotEmpty) {
      parts.add(window);
    } else {
      final since = trip['since'] as String?;
      if (since != null && since.length >= 16) parts.add('since ${since.substring(11, 16)}');
    }

    final minutes = (trip['duration_min'] as num?)?.toInt();
    if (minutes != null && minutes > 0) parts.add('${_minutes(minutes)} away');

    final distance = (trip['distance_m'] ?? trip['max_distance_m']) as num?;
    if (distance != null && distance > 0) {
      parts.add('${NumberFormat.decimalPattern().format(distance.toInt())} m out');
    }

    return parts.join(' · ');
  }

  String _tripWindow(Map<String, dynamic> trip) {
    String clock(dynamic value) {
      final text = value as String?;
      if (text == null || text.length < 16) return '';
      return text.substring(11, 16);
    }

    final from = clock(trip['exit_at']);
    final to = clock(trip['entry_at']);

    if (from.isEmpty) return '';
    return to.isEmpty ? 'since $from' : '$from → $to';
  }

  Widget _pendingReasonsCard() {
    return Card(
      color: const Color(0xFFFDF2DC),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: const BorderSide(color: Color(0xFFF0D9A8)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(15),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.pending_actions, color: AppTheme.warn, size: 20),
                const SizedBox(width: 9),
                Expanded(
                  child: Text(
                    '${_reasonsPending.length} trip${_reasonsPending.length == 1 ? '' : 's'} '
                    'need a reason',
                    style: const TextStyle(fontWeight: FontWeight.w700, color: AppTheme.warn),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            const Text(
              'You left your work area and have not said why yet.',
              style: TextStyle(fontSize: 12.5, color: AppTheme.inkSoft),
            ),
            const SizedBox(height: 10),
            ..._reasonsPending.take(3).map((trip) {
              final id = (trip['id'] as num?)?.toInt();
              final minutes = (trip['duration_min'] as num?)?.toInt() ?? 0;
              final distance = (trip['max_distance_m'] as num?)?.toInt() ?? 0;
              final date = trip['work_date'] as String? ?? '';

              // The clock times of the trip, not just its length. "45m away" is a
              // fact about a trip the employee has to recognise before they can
              // explain it, and by the time they are asked it may be one of several
              // — the only thing that identifies which is when it happened.
              final window = _tripWindow(trip);

              return ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text(
                  window.isEmpty
                      ? '${_dayLabel(date)} · ${_minutes(minutes)} away'
                      : '${_dayLabel(date)} · $window',
                  style: const TextStyle(fontSize: 13.5),
                ),
                subtitle: Text(
                  '${_minutes(minutes)} away · '
                  '${NumberFormat.decimalPattern().format(distance)} m from your area',
                  style: const TextStyle(fontSize: 12),
                ),
                trailing: TextButton(
                  onPressed: id == null ? null : () => _openReason(id, _tripSummary(trip)),
                  child: const Text('Explain'),
                ),
              );
            }),
          ],
        ),
      ),
    );
  }

  Widget _banner({
    required Color colour,
    required Color textColour,
    required IconData icon,
    required String title,
    required String body,
  }) {
    return Card(
      color: colour,
      child: Padding(
        padding: const EdgeInsets.all(15),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: textColour, size: 20),
            const SizedBox(width: 11),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: TextStyle(fontWeight: FontWeight.w700, color: textColour)),
                  const SizedBox(height: 4),
                  Text(body, style: TextStyle(fontSize: 12.5, color: textColour, height: 1.4)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /* ---------------- cards ---------------- */

  /// The day at a glance.
  ///
  /// This replaces what used to be three separate cards (today, location recording,
  /// work area). The recording card is gone entirely, and the work area has become a
  /// footer row here, because it is context for the day rather than a subject of its
  /// own.
  Widget _todayCard() {
    final status = (_today['status'] as String?) ?? 'absent';
    final worked = (_today['worked_minutes'] as num?)?.toInt();
    final outside = (_today['outside_minutes'] as num?)?.toInt() ?? 0;
    final session = widget.session;

    // The shift being worked, which is not always today: an employee still on site
    // after their end time — or on an overnight shift after midnight — is working a
    // shift dated earlier. Showing today's date there made a still-open shift look
    // like a fresh day, which is exactly what made the wrong button appear.
    final shiftDay =
        DateTime.tryParse(_today['work_date'] as String? ?? '') ?? DateTime.now();
    final isPreviousDay = _today['is_previous_day'] == true;

    // Overtime is live while the shift is open and fixed once it closes.
    final overtime = _checkedOut
        ? (_today['overtime_minutes'] as num?)?.toInt() ?? 0
        : (_today['running_overtime_minutes'] as num?)?.toInt() ?? 0;

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE2E7F0)),
        boxShadow: const [
          BoxShadow(
            color: Color(0x0A16233A),
            blurRadius: 14,
            offset: Offset(0, 4),
          ),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 16, 18, 0),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        DateFormat('EEEE').format(shiftDay),
                        style: const TextStyle(
                          fontWeight: FontWeight.w700,
                          fontSize: 17,
                          letterSpacing: -0.2,
                        ),
                      ),
                      Text(
                        DateFormat('d MMMM yyyy').format(shiftDay),
                        style: const TextStyle(fontSize: 12.5, color: AppTheme.inkSoft),
                      ),
                      if (isPreviousDay)
                        const Padding(
                          padding: EdgeInsets.only(top: 4),
                          child: Text(
                            'Shift still open from yesterday',
                            style: TextStyle(
                              fontSize: 11.5,
                              color: AppTheme.warn,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      // Two-session day: "Shift 1 of 2" etc, so a part-time
                      // monitor knows which check-in they are on.
                      if ((_today['sessions_per_day'] as num?)?.toInt() == 2)
                        Padding(
                          padding: const EdgeInsets.only(top: 4),
                          child: Text(
                            _sessionProgressLabel(),
                            style: const TextStyle(
                              fontSize: 11.5,
                              color: AppTheme.brand,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                StatusChip(label: _statusLabel(status), tone: _statusTone(status)),
              ],
            ),
          ),

          const SizedBox(height: 18),

          // The two times are the headline of the screen, so they get the space.
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18),
            child: Row(
              children: [
                _timeBlock(
                  'Check in',
                  _time(_today['check_in_at'] as String?),
                  Icons.login_rounded,
                  AppTheme.ok,
                  note: (_today['late_minutes'] as num?) != null &&
                          (_today['late_minutes'] as num) > 0
                      ? '${(_today['late_minutes'] as num).toInt()} min late'
                      : null,
                ),
                Container(width: 1, height: 52, color: const Color(0xFFEEF1F6)),
                _timeBlock(
                  'Check out',
                  _time(_today['check_out_at'] as String?),
                  Icons.logout_rounded,
                  AppTheme.bad,
                ),
              ],
            ),
          ),

          const SizedBox(height: 18),
          _shiftProgress(overtime: overtime),

          // Worked / outside, on a tinted strip so they read as derived figures
          // rather than competing with the times above.
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 13),
            color: const Color(0xFFF8FAFD),
            child: Row(
              children: [
                _miniStat('Worked', worked == null ? '—' : _minutes(worked)),
                _miniStat(
                  'Outside area',
                  outside > 0 ? _minutes(outside) : '—',
                  tone: outside > 0 ? AppTheme.bad : null,
                ),
                // Replaces the ping interval once there is overtime to report: the
                // interval is a static setting, and time worked beyond the shift is
                // the thing someone staying late actually wants confirmed.
                if (overtime > 0)
                  _miniStat('Overtime', _minutes(overtime), tone: AppTheme.brand)
                else
                  _miniStat('Every', '${session.intervalMinutes} min'),
              ],
            ),
          ),

          Container(height: 1, color: const Color(0xFFEEF1F6)),

          // Work area as a footer row: context for the day, not its own card.
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 13, 18, 14),
            child: Row(
              children: [
                Icon(
                  session.geofence == null ? Icons.help_outline : Icons.place_outlined,
                  size: 17,
                  color: session.geofence == null ? AppTheme.warn : AppTheme.inkSoft,
                ),
                const SizedBox(width: 9),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        session.geofences.length > 1
                            ? '${session.geofenceName} +${session.geofences.length - 1} more'
                            : session.geofenceName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600),
                      ),
                      Text(
                        session.geofence == null
                            ? 'No work area assigned — ask your HR office'
                            : session.fullShiftLabel,
                        style: TextStyle(
                          fontSize: 11.5,
                          color: session.geofence == null ? AppTheme.warn : AppTheme.inkSoft,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// A thin bar showing how far through the shift the employee is. Purely
  /// orientation — the authoritative times are the two blocks above it.
  Widget _shiftProgress({int overtime = 0}) {
    final settings = widget.session.settings;
    final start = _parseShiftTime(settings['shift_start'] as String?);
    final end = _parseShiftTime(settings['shift_end'] as String?);
    if (start == null || end == null) return const SizedBox(height: 4);

    final now = DateTime.now();
    final minutesNow = now.hour * 60 + now.minute;
    // An end before the start means the shift runs past midnight.
    final total = end > start ? end - start : (24 * 60 - start) + end;
    final elapsed = end > start
        ? minutesNow - start
        : (minutesNow >= start ? minutesNow - start : (24 * 60 - start) + minutesNow);

    final progress = total <= 0 ? 0.0 : (elapsed / total).clamp(0.0, 1.0);

    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 0, 18, 16),
      child: Column(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 5,
              backgroundColor: const Color(0xFFEEF1F6),
              valueColor: AlwaysStoppedAnimation(
                // A full bar reads as "nothing left to do", which is wrong for
                // someone who is still working; overtime keeps it live.
                overtime > 0
                    ? AppTheme.brand
                    : (progress >= 1.0 ? AppTheme.inkSoft : AppTheme.brand),
              ),
            ),
          ),
          const SizedBox(height: 6),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                settings['shift_start'] as String? ?? '',
                style: const TextStyle(fontSize: 10.5, color: AppTheme.inkSoft),
              ),
              Text(
                overtime > 0
                    ? '${_minutes(overtime)} overtime'
                    : (progress >= 1.0
                        ? 'Shift ended'
                        : (elapsed < 0
                            ? 'Shift not started'
                            : '${(progress * 100).round()}% through')),
                style: TextStyle(
                  fontSize: 10.5,
                  color: overtime > 0 ? AppTheme.brand : AppTheme.inkSoft,
                  fontWeight: FontWeight.w600,
                ),
              ),
              Text(
                settings['shift_end'] as String? ?? '',
                style: const TextStyle(fontSize: 10.5, color: AppTheme.inkSoft),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// "08:30" -> minutes since midnight.
  int? _parseShiftTime(String? value) {
    if (value == null || !value.contains(':')) return null;
    final parts = value.split(':');
    final h = int.tryParse(parts[0]);
    final m = int.tryParse(parts[1]);
    if (h == null || m == null) return null;
    return h * 60 + m;
  }

  Widget _timeBlock(
    String label,
    String value,
    IconData icon,
    Color tone, {
    String? note,
  }) {
    final empty = value == '—';

    return Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 14, color: empty ? AppTheme.inkSoft : tone),
              const SizedBox(width: 5),
              Text(
                label,
                style: const TextStyle(
                  fontSize: 11,
                  color: AppTheme.inkSoft,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 0.2,
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            value,
            style: TextStyle(
              fontSize: 27,
              fontWeight: FontWeight.w700,
              height: 1.05,
              letterSpacing: -0.5,
              color: empty ? const Color(0xFFC2CBDA) : AppTheme.ink,
            ),
          ),
          if (note != null)
            Padding(
              padding: const EdgeInsets.only(top: 3),
              child: Text(
                note,
                style: const TextStyle(fontSize: 11, color: AppTheme.warn),
              ),
            ),
        ],
      ),
    );
  }

  Widget _miniStat(String label, String value, {Color? tone}) {
    return Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label.toUpperCase(),
            style: const TextStyle(
              fontSize: 9.5,
              color: AppTheme.inkSoft,
              fontWeight: FontWeight.w700,
              letterSpacing: 0.6,
            ),
          ),
          const SizedBox(height: 3),
          Text(
            value,
            style: TextStyle(
              fontSize: 14.5,
              fontWeight: FontWeight.w700,
              color: tone ?? AppTheme.ink,
            ),
          ),
        ],
      ),
    );
  }

  /// "half-day" reads badly in a chip; give the statuses human labels.
  String _statusLabel(String status) => switch (status) {
        'present' => 'Present',
        'late' => 'Late',
        'half-day' => 'Half day',
        'absent' => 'Not started',
        'incomplete' => 'In progress',
        'missing-checkout' => 'No check-out',
        'holiday' => 'Holiday',
        'leave' => 'Leave',
        _ => status,
      };

  /// "Shift 2 of 2 · 1 done" — the one-line progress for a split-shift day.
  String _sessionProgressLabel() {
    final total = (_today['sessions_per_day'] as num?)?.toInt() ?? 2;
    final done = (_today['sessions_done'] as num?)?.toInt() ?? 0;
    final current = (_today['open_session_no'] as num?)?.toInt() ??
        (_today['next_session_no'] as num?)?.toInt() ??
        (_today['session_no'] as num?)?.toInt() ??
        1;
    if (_checkedIn && _checkedOut) return 'Both shifts done · $done of $total';
    if (_checkedIn) return 'Shift $current of $total in progress';
    if (done > 0) return 'Shift 1 done · Shift $current of $total next';
    return 'Shift $current of $total';
  }


  Widget _actionButton() {
    // A rest day or authorised leave is not a day to check in on. Offering the button
    // anyway invites a check-in that will be refused, and — worse — implies the
    // employee is expected at work on a day they were given off.
    if (!_checkedIn && (_today['is_leave'] == true || _today['is_work_day'] == false)) {
      final onLeave = _today['is_leave'] == true;
      return Container(
        padding: const EdgeInsets.all(17),
        decoration: BoxDecoration(
          color: const Color(0xFFF1F4F9),
          borderRadius: BorderRadius.circular(13),
        ),
        child: Row(
          children: [
            Icon(onLeave ? Icons.beach_access_outlined : Icons.weekend_outlined,
                color: AppTheme.inkSoft),
            const SizedBox(width: 11),
            Expanded(
              child: Text(
                onLeave
                    ? 'You are on approved leave today. Nothing to do — your location '
                        'is not being recorded.'
                    : 'Today is not one of your working days. Nothing to do — your '
                        'location is not being recorded.',
                style: const TextStyle(fontSize: 13, color: AppTheme.inkSoft),
              ),
            ),
          ],
        ),
      );
    }

    if (!_checkedIn) {
      // Two-session day between shifts: the first checkout is done, the second
      // check-in is next. Say so explicitly — a bare "Check in" after already
      // checking out once reads like a duplicate or a bug.
      final sessions = (_today['sessions_per_day'] as num?)?.toInt() ?? 1;
      final nextNo = (_today['next_session_no'] as num?)?.toInt() ??
          (_today['session_no'] as num?)?.toInt() ??
          1;
      final label = sessions > 1 ? 'Check in — Shift $nextNo of $sessions' : 'Check in';
      return FilledButton.icon(
        icon: const Icon(Icons.login),
        label: Text(label),
        onPressed: () => _openCheckIn(checkingOut: false),
      );
    }

    if (!_checkedOut) {
      final sessions = (_today['sessions_per_day'] as num?)?.toInt() ?? 1;
      final openNo = (_today['open_session_no'] as num?)?.toInt() ??
          (_today['session_no'] as num?)?.toInt() ??
          1;
      final label = sessions > 1 ? 'Check out — Shift $openNo of $sessions' : 'Check out';
      return FilledButton.icon(
        style: FilledButton.styleFrom(backgroundColor: AppTheme.bad),
        icon: const Icon(Icons.logout),
        label: Text(label),
        onPressed: () => _openCheckIn(checkingOut: true),
      );
    }

    return Container(
      padding: const EdgeInsets.all(17),
      decoration: BoxDecoration(
        color: const Color(0xFFE4F6EE),
        borderRadius: BorderRadius.circular(13),
      ),
      child: Row(
        children: [
          const Icon(Icons.check_circle, color: AppTheme.ok),
          const SizedBox(width: 11),
          Expanded(
            child: Text(
              'Your day is complete. Checked out at '
              '${_time(_today['check_out_at'] as String?)}.',
              style: const TextStyle(color: AppTheme.ok, fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }


  Future<void> _openReason(int eventId, [String? summary]) async {
    final submitted = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => TripReasonScreen(
          api: widget.api, eventId: eventId, tripSummary: summary,
        ),
      ),
    );
    if (submitted == true) {
      // Same reasoning as the automatic prompt: silence the alarm before the round trip.
      await Native.setOutsideReasonGiven(true);
      await _refresh();
    }
  }

  /* ---------------- formatting ---------------- */

  ChipTone _statusTone(String status) => switch (status) {
        'present' => ChipTone.ok,
        'late' || 'half-day' => ChipTone.warn,
        'absent' || 'missing-checkout' => ChipTone.bad,
        'incomplete' => ChipTone.info,
        _ => ChipTone.muted,
      };

  String _time(String? value) {
    if (value == null || value.isEmpty) return '—';
    final parsed = DateTime.tryParse(value);
    return parsed == null ? '—' : DateFormat('HH:mm').format(parsed);
  }

  String _dayLabel(String date) {
    final parsed = DateTime.tryParse(date);
    return parsed == null ? date : DateFormat('d MMM').format(parsed);
  }

  String _minutes(int total) {
    final hours = total ~/ 60;
    final rest = total % 60;
    return hours > 0 ? '${hours}h ${rest}m' : '${rest}m';
  }
}
