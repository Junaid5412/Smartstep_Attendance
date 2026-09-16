import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../core/api.dart';
import '../core/theme.dart';
import 'trip_reason_screen.dart';

/// Every departure from the work area, and what came of it.
///
/// This is the log. The dashboard deliberately shows only what the employee still has
/// to act on, because a screen that mixes "you must explain this now" with a month of
/// settled history buries the one item that actually needs them. Anything decided,
/// or already explained and waiting, lives here with the full detail: when they left
/// and returned, how far, what they said, and who decided what.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, required this.api});

  final Api api;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<Map<String, dynamic>> _trips = [];
  Map<String, dynamic> _summary = {};
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final result = await widget.api.trips();
    if (!mounted) return;

    if (!result.success) {
      setState(() {
        _loading = false;
        _error = result.isNetworkFailure
            ? 'No connection. Your notifications will load when you are back online.'
            : result.message;
      });
      return;
    }

    setState(() {
      _loading = false;
      _trips = ((result.map['trips'] as List?) ?? [])
          .map((e) => (e as Map).cast<String, dynamic>())
          .toList();
      _summary = (result.map['summary'] as Map?)?.cast<String, dynamic>() ?? {};
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.surface,
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          IconButton(
            tooltip: 'Refresh',
            icon: const Icon(Icons.refresh),
            onPressed: _load,
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _body(),
      ),
    );
  }

  Widget _body() {
    if (_loading && _trips.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _trips.isEmpty) {
      return ListView(
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 60),
          const Icon(Icons.cloud_off, size: 44, color: AppTheme.inkSoft),
          const SizedBox(height: 14),
          Text(_error!, textAlign: TextAlign.center),
        ],
      );
    }

    if (_trips.isEmpty) {
      return ListView(
        padding: const EdgeInsets.all(24),
        children: const [
          SizedBox(height: 70),
          Icon(Icons.notifications_none, size: 46, color: AppTheme.inkSoft),
          SizedBox(height: 14),
          Text(
            'Nothing here yet.\n\nWhen you leave your work area during a shift, the trip '
            'and your administrator’s decision on it will appear here.',
            textAlign: TextAlign.center,
            style: TextStyle(color: AppTheme.inkSoft, height: 1.5),
          ),
        ],
      );
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 28),
      children: [
        _summaryStrip(),
        const SizedBox(height: 14),
        ..._trips.map(_tripCard),
      ],
    );
  }

  Widget _summaryStrip() {
    int count(String key) => (_summary[key] as num?)?.toInt() ?? 0;

    final needsReason = count('needs_reason');
    final awaiting = count('awaiting_review');

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 15, vertical: 13),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(13),
        border: Border.all(color: const Color(0xFFE2E7F0)),
      ),
      child: Row(
        children: [
          _stat('Total trips', count('total'), AppTheme.ink),
          // Only shown when it is not zero: a permanent "0 to explain" is noise, and
          // when it is not zero it is the one thing on this screen that needs action.
          if (needsReason > 0) _stat('To explain', needsReason, AppTheme.bad),
          if (awaiting > 0) _stat('Awaiting review', awaiting, AppTheme.warn),
          _stat('Approved', count('approved'), AppTheme.ok),
          if (count('rejected') > 0) _stat('Declined', count('rejected'), AppTheme.bad),
        ],
      ),
    );
  }

  Widget _stat(String label, int value, Color colour) => Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label.toUpperCase(),
              style: const TextStyle(
                fontSize: 9.5,
                color: AppTheme.inkSoft,
                fontWeight: FontWeight.w700,
                letterSpacing: 0.5,
              ),
            ),
            const SizedBox(height: 3),
            Text(
              '$value',
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700, color: colour),
            ),
          ],
        ),
      );

  Widget _tripCard(Map<String, dynamic> trip) {
    final state = trip['state'] as String? ?? 'needs_reason';
    final tone = switch (state) {
      'approved' => AppTheme.ok,
      'rejected' => AppTheme.bad,
      'awaiting_review' => AppTheme.warn,
      _ => AppTheme.bad,
    };
    final label = switch (state) {
      'approved' => 'Approved',
      'rejected' => 'Not approved',
      'awaiting_review' => 'Awaiting review',
      _ => 'Needs a reason',
    };
    final icon = switch (state) {
      'approved' => Icons.check_circle,
      'rejected' => Icons.cancel,
      'awaiting_review' => Icons.hourglass_top,
      _ => Icons.edit_note,
    };

    final date = DateTime.tryParse(trip['work_date'] as String? ?? '');
    final duration = (trip['duration_min'] as num?)?.toInt();
    final distance = (trip['max_distance_m'] as num?)?.toInt() ?? 0;

    return Container(
      margin: const EdgeInsets.only(bottom: 11),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(13),
        border: Border.all(color: const Color(0xFFE2E7F0)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(icon, size: 18, color: tone),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    label,
                    style: TextStyle(fontWeight: FontWeight.w700, color: tone, fontSize: 14),
                  ),
                ),
                Text(
                  date == null ? '' : DateFormat('d MMM yyyy').format(date),
                  style: const TextStyle(fontSize: 12, color: AppTheme.inkSoft),
                ),
              ],
            ),

            const Divider(height: 18),

            // The facts of the trip. "Left 14:02, back 14:39" is what makes a decision
            // checkable months later; a bare duration is not.
            _row(Icons.logout, 'Left', _time(trip['exit_at'] as String?)),
            _row(
              Icons.login,
              'Returned',
              trip['still_out'] == true
                  ? 'still outside'
                  : _time(trip['entry_at'] as String?),
            ),
            _row(
              Icons.timer_outlined,
              'Away',
              duration == null ? '—' : _minutes(duration),
            ),
            _row(
              Icons.social_distance,
              'Furthest',
              '${NumberFormat.decimalPattern().format(distance)} m from '
                  '${trip['geofence_name'] ?? 'your work area'}',
            ),

            if ((trip['employee_reason'] as String?)?.isNotEmpty == true) ...[
              const SizedBox(height: 10),
              _quote(
                'Your reason'
                '${trip['category'] != null ? ' · ${_category(trip['category'] as String)}' : ''}',
                trip['employee_reason'] as String,
                const Color(0xFFF4F6FA),
              ),
            ],

            if (state == 'approved' || state == 'rejected') ...[
              const SizedBox(height: 9),
              _quote(
                '${state == 'approved' ? 'Approved' : 'Declined'}'
                '${trip['reviewed_by_name'] != null ? ' by ${trip['reviewed_by_name']}' : ''}'
                '${trip['reviewed_at'] != null ? ' · ${_time(trip['reviewed_at'] as String?)}' : ''}',
                (trip['review_note'] as String?)?.isNotEmpty == true
                    ? trip['review_note'] as String
                    : (state == 'approved'
                        ? 'This time is accepted as work.'
                        : 'This time is not counted as work.'),
                state == 'approved' ? const Color(0xFFE4F6EE) : const Color(0xFFFDEAEC),
              ),
            ],

            // The only action offered here, and only where it applies: an unexplained
            // trip is the one thing on this screen the employee can still change.
            if (state == 'needs_reason') ...[
              const SizedBox(height: 10),
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  icon: const Icon(Icons.edit_note, size: 18),
                  label: const Text('Give a reason'),
                  onPressed: () => _explain(
                    (trip['id'] as num).toInt(),
                    _summaryFor(trip),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// The same one-line identity the home card shows, so the two agree.
  String _summaryFor(Map<String, dynamic> trip) {
    String clock(dynamic value) {
      final text = value as String?;
      if (text == null || text.length < 16) return '';
      return text.substring(11, 16);
    }

    final parts = <String>[];
    final date = trip['work_date'] as String?;
    if (date != null && date.length >= 10) {
      parts.add(DateFormat('d MMM').format(DateTime.parse(date)));
    }

    final from = clock(trip['exit_at']);
    final to = clock(trip['entry_at']);
    if (from.isNotEmpty) parts.add(to.isEmpty ? 'since $from' : '$from → $to');

    final minutes = (trip['duration_min'] as num?)?.toInt();
    if (minutes != null && minutes > 0) parts.add('${_minutes(minutes)} away');

    return parts.join(' · ');
  }

  Future<void> _explain(int eventId, [String? summary]) async {
    final submitted = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => TripReasonScreen(
          api: widget.api, eventId: eventId, tripSummary: summary,
        ),
      ),
    );
    if (submitted == true) await _load();
  }

  Widget _row(IconData icon, String label, String value) => Padding(
        padding: const EdgeInsets.only(bottom: 5),
        child: Row(
          children: [
            Icon(icon, size: 14, color: AppTheme.inkSoft),
            const SizedBox(width: 8),
            SizedBox(
              width: 74,
              child: Text(
                label,
                style: const TextStyle(fontSize: 12.5, color: AppTheme.inkSoft),
              ),
            ),
            Expanded(
              child: Text(
                value,
                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      );

  Widget _quote(String heading, String body, Color background) => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(11),
        decoration: BoxDecoration(
          color: background,
          borderRadius: BorderRadius.circular(9),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              heading,
              style: const TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w700,
                color: AppTheme.inkSoft,
              ),
            ),
            const SizedBox(height: 3),
            Text(body, style: const TextStyle(fontSize: 13, height: 1.35)),
          ],
        ),
      );

  String _category(String value) => switch (value) {
        'company_work' => 'Company work',
        'travelling' => 'Travelling',
        'break' => 'Break',
        'personal' => 'Personal',
        _ => 'Other',
      };

  String _time(String? value) {
    if (value == null || value.isEmpty) return '—';
    final parsed = DateTime.tryParse(value);
    return parsed == null ? '—' : DateFormat('d MMM, HH:mm').format(parsed);
  }

  String _minutes(int total) {
    final h = total ~/ 60;
    final m = total % 60;
    return h > 0 ? '${h}h ${m}m' : '${m}m';
  }
}
