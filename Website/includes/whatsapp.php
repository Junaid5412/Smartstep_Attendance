<?php
/**
 * WhatsApp notifications, sent through the operator's own Baileys connector.
 *
 * The connector (the Node "WA Server") owns the WhatsApp session: it holds the
 * credentials on its disk, survives restarts, and exposes POST /send. This file is only
 * a client of it. If the connector is down, notifications are skipped and the event is
 * still recorded — WhatsApp is a courtesy copy of the log, never the log itself.
 */

/** Connector settings, from att_settings. */
function attWaConfig() {
    $settings = attSettings();
    return [
        'enabled' => (int)($settings['whatsapp_enabled'] ?? 0) === 1,
        'url' => rtrim((string)($settings['whatsapp_server_url'] ?? ''), '/'),
        'session' => (string)($settings['whatsapp_session_id'] ?? 'sst-attendance'),
    ];
}

/** Numbers that should receive notifications right now. */
function attWaActiveNumbers() {
    try {
        return attDB()->query("
            SELECT phone, label FROM att_whatsapp_numbers
            WHERE is_active = 1 ORDER BY id
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Table absent (migration not run): behave as "nobody subscribed".
        return [];
    }
}

/**
 * Send one text to one number. Returns true on acceptance by the connector.
 *
 * A short timeout on purpose: this runs inside request handling (the app's upload,
 * a check-in), and a hung connector must cost the employee at most a few seconds,
 * not a spinner.
 */
function attWaSend($phone, $text) {
    $config = attWaConfig();
    if (!$config['enabled'] || $config['url'] === '') {
        return false;
    }

    $ch = curl_init($config['url'] . '/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'session_id' => $config['session'],
            'phone' => preg_replace('/[^0-9]/', '', (string)$phone),
            'message' => $text,
            'type' => 'text',
        ]),
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        // Logged for the operator, never surfaced to the employee whose upload
        // happened to be the one that triggered the notification.
        error_log('[attWaSend] connector refused: HTTP ' . $status . ' ' . substr((string)$body, 0, 200));
        return false;
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) && !empty($decoded['success']);
}

/**
 * Send a formatted notification to every active number.
 *
 * @param string $title one line, e.g. "Left work area"
 * @param array  $lines label => value pairs, printed in order
 * @return int how many numbers accepted it
 */
function attWaNotify($title, array $lines) {
    $config = attWaConfig();
    if (!$config['enabled']) {
        return 0;
    }

    $numbers = attWaActiveNumbers();
    if (!$numbers) {
        return 0;
    }

    // WhatsApp's own markup: *bold* headline, then aligned plain lines. Kept sober —
    // these arrive on a manager's personal phone, and a wall of emoji reads as spam.
    $text = "*SST Attendance — " . $title . "*\n";
    foreach ($lines as $label => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $text .= is_int($label) ? "\n" . $value : "\n" . $label . ": " . $value;
    }
    $text .= "\n\n_" . date('d M Y H:i') . "_";

    $sent = 0;
    foreach ($numbers as $number) {
        if (attWaSend($number['phone'], $text)) {
            $sent++;
        }
    }
    return $sent;
}
