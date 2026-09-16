import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../core/api.dart';
import '../core/native.dart';
import '../core/session.dart';
import '../core/theme.dart';

/// Sign-in, and the device-binding refusal that goes with it.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.session, required this.api});

  final Session session;
  final Api api;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _codeController = TextEditingController();
  final _passwordController = TextEditingController();
  final _formKey = GlobalKey<FormState>();

  bool _busy = false;
  bool _obscure = true;
  String? _error;
  String? _boundDeviceModel;
  String _appVersion = '1.0.0';

  @override
  void initState() {
    super.initState();
    PackageInfo.fromPlatform().then((info) {
      if (mounted) setState(() => _appVersion = info.version);
    });
  }

  @override
  void dispose() {
    _codeController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() {
      _busy = true;
      _error = null;
      _boundDeviceModel = null;
    });

    final deviceUid = await Native.deviceUid();
    if (deviceUid == null || deviceUid.length < 8) {
      setState(() {
        _busy = false;
        _error = 'This device could not be identified. Reinstall the app and try again.';
      });
      return;
    }

    final result = await widget.api.login(
      loginCode: _codeController.text.trim(),
      password: _passwordController.text,
      deviceUid: deviceUid,
      device: await Native.deviceInfo(),
      appVersion: _appVersion,
    );

    if (!mounted) return;

    if (result.success) {
      final data = result.map;
      await widget.session.signIn(
        token: data['token'] as String,
        employee: (data['employee'] as Map).cast<String, dynamic>(),
        config: {
          'settings': data['settings'],
          'geofence': data['geofence'],
          'geofences': data['geofences'],
          'map': data['map'],
          'branding': data['branding'],
        },
      );
      // The Gate listens to the session and swaps the screen out from under us.
      return;
    }

    setState(() {
      _busy = false;
      _error = result.message;
      // The server names the handset the account is tied to, which is what makes
      // this refusal actionable rather than mysterious.
      if (result.code == 'DEVICE_ALREADY_BOUND') {
        _boundDeviceModel = result.map['bound_device_model'] as String?;
      }
    });
  }

  Future<void> _editServer() async {
    final controller = TextEditingController(text: widget.session.baseUrl);

    final url = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Server address'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: controller,
              autofocus: true,
              keyboardType: TextInputType.url,
              decoration: const InputDecoration(
                labelText: 'API base URL',
                helperText: 'e.g. https://sstqa.com/Attendance/Website/api/v1',
                helperMaxLines: 2,
              ),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: const Text('Save'),
          ),
        ],
      ),
    );

    if (url != null && url.isNotEmpty) {
      await widget.session.setBaseUrl(url);
      if (mounted) setState(() {});
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(26),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const SizedBox(height: 22),
                    // The logo survives sign-out in the cached config, so a
                    // returning employee sees their own company here rather than a
                    // generic mark.
                    Center(child: _brandMark()),
                    const SizedBox(height: 18),
                    Text(
                      widget.session.companyName,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -0.3,
                      ),
                    ),
                    const SizedBox(height: 5),
                    const Text(
                      'Sign in with the code your HR office gave you',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: AppTheme.inkSoft, fontSize: 13.5),
                    ),
                    const SizedBox(height: 30),

                    if (_error != null) _errorBox(),

                    TextFormField(
                      controller: _codeController,
                      enabled: !_busy,
                      textInputAction: TextInputAction.next,
                      autocorrect: false,
                      decoration: const InputDecoration(
                        labelText: 'Employee code',
                        prefixIcon: Icon(Icons.badge_outlined),
                      ),
                      validator: (value) =>
                          (value == null || value.trim().isEmpty) ? 'Enter your employee code' : null,
                    ),
                    const SizedBox(height: 14),
                    TextFormField(
                      controller: _passwordController,
                      enabled: !_busy,
                      obscureText: _obscure,
                      textInputAction: TextInputAction.done,
                      onFieldSubmitted: (_) => _busy ? null : _submit(),
                      decoration: InputDecoration(
                        labelText: 'Password',
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          icon: Icon(_obscure ? Icons.visibility_off : Icons.visibility),
                          onPressed: () => setState(() => _obscure = !_obscure),
                        ),
                      ),
                      validator: (value) =>
                          (value == null || value.isEmpty) ? 'Enter your password' : null,
                    ),
                    const SizedBox(height: 22),
                    FilledButton(
                      onPressed: _busy ? null : _submit,
                      child: _busy
                          ? const SizedBox(
                              height: 22,
                              width: 22,
                              child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                            )
                          : const Text('Sign in'),
                    ),
                    const SizedBox(height: 26),
                    // The server address is fixed at build time and no longer shown.
                    // Leaving it editable let anyone point the app at a server they
                    // control, which is the easiest way to feed false attendance into a
                    // system - far easier than defeating any of the other checks.
                    // _editServer() is kept for a deliberate support build.
                    Center(
                      // Long-press, not a button. Support can still reach the server
                      // address when they need to; nobody discovers it by accident.
                      child: GestureDetector(
                        onLongPress: _busy ? null : _editServer,
                        child: Text(
                          'Version $_appVersion',
                          style: const TextStyle(color: AppTheme.inkSoft, fontSize: 12),
                        ),
                      ),
                    ),
                    const SizedBox(height: 10),
                    Center(
                      child: TextButton(
                        onPressed: () {
                          final base = widget.session.baseUrl.replaceAll('/api/v1', '');
                          Native.openUrl('$base/privacy-policy.php');
                        },
                        style: TextButton.styleFrom(
                          foregroundColor: AppTheme.inkSoft,
                          visualDensity: VisualDensity.compact,
                        ),
                        child: const Text(
                          'Privacy Policy',
                          style: TextStyle(
                            fontSize: 12.5,
                            decoration: TextDecoration.underline,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _brandMark() {
    final url = widget.session.logoUrl;
    final file = widget.session.logoFile;

    return Container(
      width: 78,
      height: 78,
      decoration: BoxDecoration(
        color: url == null ? AppTheme.brand : Colors.white,
        borderRadius: BorderRadius.circular(19),
        boxShadow: [
          BoxShadow(
            color: AppTheme.brand.withValues(alpha: 0.18),
            blurRadius: 18,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: url == null
          ? const Center(
              child: Text(
                'SST',
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: 21,
                  letterSpacing: 1,
                ),
              ),
            )
          : Padding(
              padding: const EdgeInsets.all(9),
              child: file != null
                  ? Image.file(file, fit: BoxFit.contain,
                      errorBuilder: (_, __, ___) => Image.network(url, fit: BoxFit.contain))
                  : Image.network(
                      url,
                      fit: BoxFit.contain,
                      errorBuilder: (_, __, ___) => const Center(
                        child: Icon(Icons.location_on, size: 38, color: AppTheme.brand),
                      ),
                    ),
            ),
    );
  }

  Widget _errorBox() {
    final isDeviceLock = _boundDeviceModel != null;

    return Container(
      margin: const EdgeInsets.only(bottom: 20),
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
        color: const Color(0xFFFDEAEC),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFF3C2C6)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                isDeviceLock ? Icons.phonelink_lock : Icons.error_outline,
                color: AppTheme.bad,
                size: 20,
              ),
              const SizedBox(width: 9),
              Expanded(
                child: Text(
                  isDeviceLock ? 'Account locked to another device' : 'Could not sign in',
                  style: const TextStyle(fontWeight: FontWeight.w700, color: AppTheme.bad),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(_error!, style: const TextStyle(fontSize: 13.5, color: AppTheme.ink)),
          if (isDeviceLock) ...[
            const SizedBox(height: 10),
            const Text(
              'Ask your HR administrator to reset your device in the attendance '
              'dashboard, then sign in again here.',
              style: TextStyle(fontSize: 12.5, color: AppTheme.inkSoft),
            ),
          ],
        ],
      ),
    );
  }
}
