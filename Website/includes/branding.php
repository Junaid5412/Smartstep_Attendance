<?php
/**
 * Branding served to the mobile app.
 *
 * Resolution order is deliberate: an attendance-specific override first, then the
 * ERP's own company_settings, then a built-in default. That keeps the ERP as the one
 * place a company's identity is maintained — nobody uploads the same logo twice —
 * while still allowing the app to differ where a client wants it to.
 */

function attBranding() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $settings = attSettings();

    // company_settings belongs to the ERP, so a missing row or table must not be
    // able to break the app's login response.
    $company = ['company_name' => null, 'company_logo' => null];
    try {
        $row = attDB()->query("
            SELECT company_name, company_logo FROM company_settings ORDER BY id DESC LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $company = $row;
        }
    } catch (Exception $e) {
        // Fall through to defaults.
    }

    $name = $settings['app_display_name']
        ?: ($company['company_name'] ?: 'SST Attendance');

    // The two logos live in different upload roots, so the URL depends on which
    // one answered.
    $logoUrl = null;
    if (!empty($settings['app_logo'])) {
        $logoUrl = ATT_UPLOAD_URL . '/' . ltrim($settings['app_logo'], '/');
    } elseif (!empty($company['company_logo'])) {
        $logoUrl = ATT_ERP_URL . '/assets/uploads/' . ltrim($company['company_logo'], '/');
    }

    // Clamped rather than trusted: a huge value would strand the employee on the
    // splash screen with no way past it.
    $splash = (float)($settings['splash_seconds'] ?? 3);
    $splash = max(0.0, min(10.0, $splash));

    return $cached = [
        'company_name' => $name,
        'logo_url' => $logoUrl,
        // Used for the app's headers and the loading screen tint.
        'primary_color' => '#1F4FD8',
        'splash_seconds' => $splash,
    ];
}
