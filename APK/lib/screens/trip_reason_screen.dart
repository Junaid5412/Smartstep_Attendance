import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/theme.dart';

/// Where the employee explains a trip outside their work area.
///
/// The app only records the claim; whether it counted as company work is the
/// admin's decision in the review queue, which is why nothing here is phrased as
/// an approval.
class TripReasonScreen extends StatefulWidget {
  const TripReasonScreen({
    super.key,
    required this.api,
    required this.eventId,
    this.tripSummary,
  });

  final Api api;
  final int eventId;

  /// Which trip this is about, e.g. "Today · 14:05 → 14:50 · 45m away". Shown at the
  /// top, because by the time somebody is asked there may be several to explain and
  /// the screen otherwise gives no way to tell which one is being asked about.
  final String? tripSummary;

  @override
  State<TripReasonScreen> createState() => _TripReasonScreenState();
}

class _TripReasonScreenState extends State<TripReasonScreen> {
  static const _categories = <String, ({String label, String hint, IconData icon})>{
    'company_work': (
      label: 'Company work',
      hint: 'Site visit, delivery, collection, another company location',
      icon: Icons.business_center_outlined,
    ),
    'client_visit': (
      label: 'Client visit',
      hint: 'Meeting or working at a client\'s premises',
      icon: Icons.handshake_outlined,
    ),
    'transit': (
      label: 'Travelling',
      hint: 'On the way between two work locations',
      icon: Icons.directions_car_outlined,
    ),
    'break': (
      label: 'Break',
      hint: 'Lunch or rest break away from the site',
      icon: Icons.coffee_outlined,
    ),
    'personal': (
      label: 'Personal',
      hint: 'Something not related to work',
      icon: Icons.person_outline,
    ),
    'other': (
      label: 'Other',
      hint: 'Anything the options above do not cover',
      icon: Icons.more_horiz,
    ),
  };

  final _reasonController = TextEditingController();
  String? _category;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _reasonController.dispose();
    super.dispose();
  }

  /// A break needs no written explanation; everything else does, otherwise the
  /// review queue fills with entries an admin cannot act on.
  bool get _reasonRequired => _category != null && _category != 'break';

  bool get _canSubmit {
    if (_category == null || _busy) return false;
    if (_reasonRequired && _reasonController.text.trim().isEmpty) return false;
    return true;
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final result = await widget.api.submitTripReason(
      eventId: widget.eventId,
      category: _category!,
      reason: _reasonController.text.trim(),
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
      _busy = false;
      _error = result.isNetworkFailure
          ? 'No connection. Try again once you have signal.'
          : result.message;
    });

    // The admin has already ruled on it, so the form is pointless now.
    if (result.code == 'ALREADY_REVIEWED') {
      await Future<void>.delayed(const Duration(seconds: 2));
      if (mounted) Navigator.of(context).pop(true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Why were you outside?')),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  const Text(
                    'Your route shows you left your assigned work area. Tell your '
                    'administrator why, so it can be recorded correctly.',
                    style: TextStyle(fontSize: 13.5, color: AppTheme.inkSoft),
                  ),
                  const SizedBox(height: 18),

                  ..._categories.entries.map((entry) {
                    final selected = _category == entry.key;
                    return Card(
                      color: selected ? const Color(0xFFE8EEFF) : Colors.white,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14),
                        side: BorderSide(
                          color: selected ? AppTheme.brand : const Color(0xFFE2E7F0),
                          width: selected ? 2 : 1,
                        ),
                      ),
                      child: ListTile(
                        leading: Icon(
                          entry.value.icon,
                          color: selected ? AppTheme.brand : AppTheme.inkSoft,
                        ),
                        title: Text(
                          entry.value.label,
                          style: TextStyle(
                            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                            fontSize: 14.5,
                          ),
                        ),
                        subtitle: Text(
                          entry.value.hint,
                          style: const TextStyle(fontSize: 12, color: AppTheme.inkSoft),
                        ),
                        trailing: selected
                            ? const Icon(Icons.check_circle, color: AppTheme.brand)
                            : null,
                        onTap: _busy ? null : () => setState(() => _category = entry.key),
                      ),
                    );
                  }),

                  const SizedBox(height: 6),
                  TextField(
                    controller: _reasonController,
                    enabled: !_busy,
                    maxLines: 3,
                    maxLength: 500,
                    textCapitalization: TextCapitalization.sentences,
                    onChanged: (_) => setState(() {}),
                    decoration: InputDecoration(
                      labelText: _reasonRequired
                          ? 'What were you doing?'
                          : 'Anything to add? (optional)',
                      hintText: 'e.g. Delivered documents to the Al Sadd client office',
                      alignLabelWithHint: true,
                    ),
                  ),

                  if (_error != null) ...[
                    const SizedBox(height: 10),
                    Text(
                      _error!,
                      style: const TextStyle(color: AppTheme.bad, fontSize: 13),
                    ),
                  ],
                ],
              ),
            ),
            Container(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
              decoration: const BoxDecoration(
                color: Colors.white,
                border: Border(top: BorderSide(color: Color(0xFFE2E7F0))),
              ),
              child: FilledButton(
                onPressed: _canSubmit ? _submit : null,
                child: _busy
                    ? const SizedBox(
                        height: 21,
                        width: 21,
                        child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                      )
                    : const Text('Submit for review'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
