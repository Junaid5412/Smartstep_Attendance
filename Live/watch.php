<?php
/**
 * Live board follow driver.
 *
 * While the personal board is open it heartbeats here with the staff on screen;
 * each heartbeat extends their follow windows so phones report every few
 * seconds. Nothing is stored beyond the window rows themselves, and the moment
 * the board closes the heartbeats stop and every window expires by itself —
 * phones drop back to their normal interval with no message needed.
 *
 * Same login as the Website admin panel (via its bootstrap).
 */

require_once __DIR__ . '/../Website/admin/bootstrap.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? ($_POST['action'] ?? 'watch');

    if ($action === 'stop') {
        // Best-effort immediate stand-down when the board closes. The expiry
        // below is the backstop, so a missed beacon costs nothing.
        $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? []))));
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            attDB()->prepare("DELETE FROM att_live_follow WHERE att_employee_id IN ($placeholders)")
                   ->execute($ids);
        }
        echo json_encode(['ok' => true]);
        return;
    }

    // 1s melts phone batteries (GPS + upload, continuously); 10s is calm.
    // The phones join within about a minute — they learn about the window on
    // their regular poll, not instantly.
    $seconds = max(1, min(10, (int)($input['seconds'] ?? 3)));
    $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? []))));
    if (!$ids) {
        echo json_encode(['ok' => true, 'watching' => 0]);
        return;
    }

    $stmt = attDB()->prepare("
        INSERT INTO att_live_follow (att_employee_id, interval_seconds, `until`, requested_by)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 SECOND), ?)
        ON DUPLICATE KEY UPDATE
            interval_seconds = VALUES(interval_seconds),
            `until` = VALUES(`until`),
            requested_by = VALUES(requested_by)
    ");
    $watching = 0;
    foreach ($ids as $id) {
        $stmt->execute([$id, $seconds, (int)$attAdmin['id']]);
        $watching++;
    }

    echo json_encode(['ok' => true, 'watching' => $watching, 'seconds' => $seconds]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'watch_failed']);
}
