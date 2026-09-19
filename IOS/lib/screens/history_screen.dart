import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../core/api.dart';
import '../core/native.dart';
import '../core/session.dart';
import '../core/theme.dart';

/// The employee's own attendance record, so they can check what was logged for
/// them rather than only the payroll office seeing it.
class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key, required this.session, required this.api});

  final Session session;
  final Api api;

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  late DateTime _month;
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _summary = {};
  List<Map<String, dynamic>> _days = [];

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _month = DateTime(now.year, now.month);
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final from = DateFormat('yyyy-MM-dd').format(_month);
    // Day 0 of the next month is the last day of this one, which avoids having to
    // know how long February is.
    final lastDay = DateTime(_month.year, _month.month + 1, 0);
    final to = DateFormat('yyyy-MM-dd').format(lastDay);

    final result = await widget.api.history(from: from, to: to);

    if (!mounted) return;

    if (!result.success) {
      setState(() {
        _loading = false;
        _error = result.isNetworkFailure
            ? 'No connection. Your attendance history needs internet.'
            : result.message;
      });
      return;
    }

    setState(() {
      _loading = false;
      _summary = (result.map['summary'] as Map?)?.cast<String, dynamic>() ?? {};
      _days = ((result.map['days'] as List?) ?? [])
          .map((e) => (e as Map).cast<String, dynamic>())
          .toList();
    });
  }

  void _shiftMonth(int delta) {
    final next = DateTime(_month.year, _month.month + delta);
    // No point offering months that cannot contain data yet.
    if (next.isAfter(DateTime.now())) return;
    setState(() => _month = next);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final atCurrentMonth = _month.year == DateTime.now().year &&
        _month.month == DateTime.now().month;

    return Scaffold(
      appBar: AppBar(
        title: const Text('My attendance'),
        actions: [
          IconButton(
            tooltip: 'Privacy Policy',
            icon: const Icon(Icons.privacy_tip_outlined),
            onPressed: () {
              final base = widget.session.baseUrl.replaceAll('/api/v1', '');
              Native.openUrl('$base/privacy-policy.php');
            },
          ),
        ],
      ),
      body: Column(
        children: [
          Container(
            color: Colors.white,
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
            child: Row(
              children: [
                IconButton(
                  icon: const Icon(Icons.chevron_left),
                  onPressed: _loading ? null : () => _shiftMonth(-1),
                ),
                Expanded(
                  child: Text(
                    DateFormat('MMMM yyyy').format(_month),
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.chevron_right),
                  onPressed: (_loading || atCurrentMonth) ? null : () => _shiftMonth(1),
                ),
              ],
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _error != null
                    ? _errorView()
                    : RefreshIndicator(
                        onRefresh: _load,
                        child: ListView(
                          padding: const EdgeInsets.all(16),
                          children: [
                            _summaryCard(),
                            if (_days.isEmpty)
                              const Padding(
                                padding: EdgeInsets.symmetric(vertical: 46),
                                child: Text(
                                  'No attendance recorded this month.',
                                  textAlign: TextAlign.center,
                                  style: TextStyle(color: AppTheme.inkSoft),
                                ),
                              )
                            else
                              ..._days.map(_dayCard),
                            const SizedBox(height: 20),
                          ],
                        ),
                      ),
          ),
        ],
      ),
    );
  }

  Widget _errorView() => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.cloud_off, size: 46, color: AppTheme.inkSoft),
              const SizedBox(height: 14),
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 18),
              FilledButton(onPressed: _load, child: const Text('Try again')),
            ],
          ),
        ),
      );

  Widget _summaryCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(17),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'This month',
              style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                _tile('Present', '${_int('present')}', AppTheme.ok),
                _tile('Late', '${_int('late')}', AppTheme.warn),
                // Scheduled days off are counted separately and never as absence.
                _tile(
                  'Absent',
                  '${_int('absent')}',
                  _int('absent') > 0 ? AppTheme.bad : AppTheme.ink,
                ),
              ],
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                _tile('Worked', _minutes(_int('worked_minutes')), AppTheme.ink),
                _tile(
                  'Outside area',
                  _minutes(_int('outside_minutes')),
                  _int('outside_minutes') > 0 ? AppTheme.bad : AppTheme.ink,
                ),
                _tile('Days off', '${_int('off_days')}', AppTheme.inkSoft),
              ],
            ),
            if (_int('overtime_minutes') > 0)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(
                  '${_minutes(_int('overtime_minutes'))} overtime in this period',
                  style: const TextStyle(
                    fontSize: 12,
                    color: AppTheme.brand,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            if (_int('half_day') > 0 ||
                _int('incomplete') > 0 ||
                _int('missing_checkout') > 0)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(
                  [
                    if (_int('half_day') > 0) '${_int('half_day')} half day',
                    if (_int('incomplete') > 0) '${_int('incomplete')} still open',
                    if (_int('missing_checkout') > 0)
                      '${_int('missing_checkout')} with no check-out',
                  ].join('  ·  '),
                  style: const TextStyle(fontSize: 12, color: AppTheme.inkSoft),
                ),
              ),
          ],
        ),
      ),
    );
  }

  int _int(String key) => (_summary[key] as num?)?.toInt() ?? 0;

  Widget _tile(String label, String value, Color colour) => Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: const TextStyle(fontSize: 11.5, color: AppTheme.inkSoft)),
            const SizedBox(height: 3),
            Text(
              value,
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700, color: colour),
            ),
          ],
        ),
      );

  Widget _dayCard(Map<String, dynamic> day) {
    final date = DateTime.tryParse(day['work_date'] as String? ?? '');
    final status = (day['status'] as String?) ?? 'absent';
    final outside = (day['outside_minutes'] as num?)?.toInt() ?? 0;
    final trips = (day['trips_outside'] as num?)?.toInt() ?? 0;
    final late = (day['late_minutes'] as num?)?.toInt() ?? 0;
    final worked = (day['worked_minutes'] as num?)?.toInt();
    final overtime = (day['overtime_minutes'] as num?)?.toInt() ?? 0;

    final inPhoto = day['check_in_photo_url'] as String?;
    final outPhoto = day['check_out_photo_url'] as String?;

    // Days with nothing recorded get a one-line card. Showing empty In/Out/Worked
    // blocks for a rest day or an absence is noise, and it makes a real absence
    // harder to spot in the list.
    final noRecord = day['check_in_at'] == null;

    if (noRecord && (status == 'off' || status == 'absent' || status == 'pending')) {
      return Card(
        color: status == 'absent' ? const Color(0xFFFDEAEC) : Colors.white,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(15, 13, 15, 13),
          child: Row(
            children: [
              Icon(
                switch (status) {
                  'off' => Icons.weekend_outlined,
                  'pending' => Icons.schedule,
                  _ => Icons.event_busy,
                },
                size: 18,
                color: status == 'absent' ? AppTheme.bad : AppTheme.inkSoft,
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Text(
                  date == null ? '—' : DateFormat('EEE, d MMM').format(date),
                  style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                ),
              ),
              StatusChip(label: _statusLabel(status), tone: _tone(status)),
            ],
          ),
        ),
      );
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(15),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    date == null ? '—' : DateFormat('EEE, d MMM').format(date),
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5),
                  ),
                ),
                StatusChip(label: _statusLabel(status), tone: _tone(status)),
              ],
            ),
            const SizedBox(height: 11),
            Row(
              children: [
                Expanded(
                  child: _line(
                    Icons.login,
                    'In',
                    _time(day['check_in_at'] as String?),
                    warn: day['check_in_inside_fence'] == false ? 'outside area' : null,
                  ),
                ),
                Expanded(
                  child: _line(
                    Icons.logout,
                    'Out',
                    // An overnight or overtime shift clocks off on the next calendar
                    // day; a bare "07:00" beside an "In" of 20:05 reads backwards.
                    _shiftTime(day['check_out_at'] as String?, day['work_date'] as String?),
                    warn: day['check_out_inside_fence'] == false ? 'outside area' : null,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 9),
            Row(
              children: [
                Expanded(
                  child: _line(
                    Icons.timer_outlined,
                    'Worked',
                    worked == null ? '—' : _minutes(worked),
                    note: overtime > 0 ? '+${_minutes(overtime)} overtime' : null,
                  ),
                ),
                Expanded(
                  child: _line(
                    Icons.location_off_outlined,
                    'Outside',
                    outside > 0 ? '${_minutes(outside)} · $trips trip${trips == 1 ? '' : 's'}' : '—',
                  ),
                ),
              ],
            ),
            if (late > 0) ...[
              const SizedBox(height: 8),
              StatusChip(label: '$late min late', tone: ChipTone.warn),
            ],
            if (inPhoto != null || outPhoto != null) ...[
              const SizedBox(height: 12),
              Row(
                children: [
                  if (inPhoto != null) _thumb(inPhoto, 'Check in'),
                  if (outPhoto != null) _thumb(outPhoto, 'Check out'),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _line(IconData icon, String label, String value, {String? warn, String? note}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(icon, size: 13, color: AppTheme.inkSoft),
            const SizedBox(width: 5),
            Text(label, style: const TextStyle(fontSize: 11.5, color: AppTheme.inkSoft)),
          ],
        ),
        const SizedBox(height: 2),
        Text(value, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
        if (warn != null)
          Text(warn, style: const TextStyle(fontSize: 11, color: AppTheme.bad)),
        if (note != null)
          Text(
            note,
            style: const TextStyle(
              fontSize: 11,
              color: AppTheme.brand,
              fontWeight: FontWeight.w600,
            ),
          ),
      ],
    );
  }

  /// A time, marked +1d when it lands on a later date than the shift it belongs to.
  String _shiftTime(String? value, String? workDate) {
    final parsed = value == null ? null : DateTime.tryParse(value);
    final day = workDate == null ? null : DateTime.tryParse(workDate);
    if (parsed == null) return '—';

    final formatted = DateFormat('HH:mm').format(parsed);
    if (day == null) return formatted;

    final shift = DateTime(day.year, day.month, day.day);
    final on = DateTime(parsed.year, parsed.month, parsed.day);
    final days = on.difference(shift).inDays;
    return days > 0 ? '$formatted +${days}d' : formatted;
  }

  Widget _thumb(String url, String label) {
    return Padding(
      padding: const EdgeInsets.only(right: 10),
      child: GestureDetector(
        onTap: () => showDialog<void>(
          context: context,
          builder: (context) => Dialog(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                ClipRRect(
                  borderRadius: const BorderRadius.vertical(top: Radius.circular(12)),
                  child: Image.network(
                    url,
                    errorBuilder: (_, __, ___) => const Padding(
                      padding: EdgeInsets.all(30),
                      child: Text('Photo could not be loaded.'),
                    ),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
                ),
              ],
            ),
          ),
        ),
        child: Column(
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: Image.network(
                url,
                height: 54,
                width: 54,
                fit: BoxFit.cover,
                errorBuilder: (_, __, ___) => Container(
                  height: 54,
                  width: 54,
                  color: const Color(0xFFEEF1F6),
                  child: const Icon(Icons.broken_image_outlined,
                      size: 20, color: AppTheme.inkSoft),
                ),
              ),
            ),
            const SizedBox(height: 3),
            Text(label, style: const TextStyle(fontSize: 10.5, color: AppTheme.inkSoft)),
          ],
        ),
      ),
    );
  }

  ChipTone _tone(String status) => switch (status) {
        'present' => ChipTone.ok,
        'late' || 'half-day' => ChipTone.warn,
        'absent' || 'missing-checkout' => ChipTone.bad,
        'incomplete' => ChipTone.info,
        // A scheduled day off is not a shortfall, so it stays visually neutral.
        'off' || 'holiday' || 'leave' || 'pending' => ChipTone.muted,
        _ => ChipTone.muted,
      };

  String _statusLabel(String status) => switch (status) {
        'present' => 'Present',
        'late' => 'Late',
        'half-day' => 'Half day',
        'absent' => 'Absent',
        'incomplete' => 'Open',
        'missing-checkout' => 'No check-out',
        'off' => 'Day off',
        'pending' => 'Not yet',
        'holiday' => 'Holiday',
        'leave' => 'Leave',
        _ => status,
      };

  String _time(String? value) {
    if (value == null || value.isEmpty) return '—';
    final parsed = DateTime.tryParse(value);
    return parsed == null ? '—' : DateFormat('HH:mm').format(parsed);
  }

  String _minutes(int total) {
    final hours = total ~/ 60;
    final rest = total % 60;
    return hours > 0 ? '${hours}h ${rest}m' : '${rest}m';
  }
}
