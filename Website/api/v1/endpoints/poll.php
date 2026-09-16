<?php
/**
 * GET /api/v1/poll
 *
 * The device's cheap heartbeat: "anything for me?". Called every
 * command_poll_seconds while a shift is running, so it is deliberately the
 * smallest useful response in the API — no profile, no config, no geofence.
 *
 * Body (optional): done=<command id> to acknowledge one just acted on.
 */

if (!defined('ATT_NAME')) { http_response_code(404); exit; }

$auth = attAuthenticate();

// Acknowledgement rides along on the next poll rather than costing its own request.
$done = attParam('done');
if ($done !== null && is_numeric($done)) {
    attCompleteCommand($auth['att_employee_id'], (int)$done);
}

$commands = attCollectCommands($auth['att_employee_id'], $auth['device_id']);

$follow = attFollowState($auth['att_employee_id']);

attOk([
    'commands' => $commands,
    // Seconds between reports while an admin is watching this person on the live
    // map; 0 means go back to the normal interval. Short-lived by design - see
    // attStartFollow().
    'live_seconds' => $follow['seconds'],
    'live_until' => $follow['until'],
    'poll_seconds' => attCommandPollSeconds(),
    // Lets the device notice a settings change without pulling the whole config on
    // every poll.
    'interval_min' => (int)$auth['settings']['tracking_interval_min'],
    'tracking_active_now' => attTrackingActive($auth['att_employee_id'], $auth['settings']),
]);
