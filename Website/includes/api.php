<?php
/**
 * Request/response plumbing for the app API.
 *
 * Every endpoint answers with the same envelope so the Flutter client has one
 * decoding path: {"success":bool,"data":mixed,"message":string,"code":string}.
 */

/** Emit a JSON envelope and stop. */
function attJson($payload, $httpStatus = 200) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        http_response_code($httpStatus);
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function attOk($data = null, $message = '') {
    attJson(['success' => true, 'data' => $data, 'message' => $message, 'code' => 'OK']);
}

/**
 * Error response. `code` is a stable machine-readable string the app branches on
 * (e.g. DEVICE_ALREADY_BOUND drives the "contact admin" screen) — never change an
 * existing code's meaning without shipping a matching app update.
 */
function attFail($code, $message, $httpStatus = 400, $data = null) {
    attJson(['success' => false, 'data' => $data, 'message' => $message, 'code' => $code], $httpStatus);
}

/** Parsed request body: JSON, or form fields for multipart uploads. */
function attInput() {
    static $input = null;
    if ($input !== null) {
        return $input;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        $input = is_array($decoded) ? $decoded : [];
    } else {
        $input = $_POST;
    }
    return $input;
}

function attParam($key, $default = null) {
    $input = attInput();
    if (array_key_exists($key, $input)) {
        return $input[$key];
    }
    return $_GET[$key] ?? $default;
}

/** Require a set of parameters, failing with the list of what is missing. */
function attRequire(array $keys) {
    $values = [];
    $missing = [];
    foreach ($keys as $key) {
        $value = attParam($key);
        if ($value === null || $value === '') {
            $missing[] = $key;
        }
        $values[$key] = $value;
    }
    if ($missing) {
        attFail('MISSING_FIELDS', 'Missing required field(s): ' . implode(', ', $missing), 422);
    }
    return $values;
}

function attClientIp() {
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/** Reject anything but the expected HTTP verb. */
function attRequireMethod($method) {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        attFail('METHOD_NOT_ALLOWED', 'This endpoint expects ' . strtoupper($method) . '.', 405);
    }
}

/** Coordinate sanity check: rejects nulls, out-of-range values and the 0,0 fix. */
function attValidCoords($lat, $lng) {
    if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
        return false;
    }
    $lat = (float)$lat;
    $lng = (float)$lng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return false;
    }
    // A literal 0,0 is in the Gulf of Guinea and in practice always means the
    // device reported an empty fix rather than a real position.
    return !(abs($lat) < 0.00001 && abs($lng) < 0.00001);
}

function attAudit($action, $entity = null, $entityId = null, $details = null, $actorType = 'employee', $actorId = null) {
    try {
        $stmt = attDB()->prepare("
            INSERT INTO att_audit_logs (actor_type, actor_id, action, entity, entity_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $actorType,
            $actorId,
            $action,
            $entity,
            $entityId,
            is_string($details) || $details === null ? $details : json_encode($details),
            attClientIp(),
        ]);
    } catch (Exception $e) {
        // Auditing must never break the request it is describing.
    }
}
