<?php

// just log post
// to test webhooks

$log = __DIR__ . '/webhook_test.log';

error_log(date('Y-m-d H:i:s') . " Webhook test triggered\n", 3, $log);

// Log RAW body (e.g. application/json)
$raw = file_get_contents('php://input');
error_log("RAW BODY:\n" . ($raw ?: '<empty>') . "\n", 3, $log);

// If JSON, log decoded array
if ($raw) {
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        error_log("JSON DECODED:\n" . print_r($json, true) . "\n", 3, $log);
    }
}

// Also log $_REQUEST (will be empty for JSON bodies)
error_log("_REQUEST:\n" . print_r($_REQUEST, true) . "\n\n", 3, $log);
