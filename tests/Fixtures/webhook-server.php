<?php

declare(strict_types=1);

/**
 * Minimal PHP built-in server fixture for WebhookDispatcher tests.
 *
 * Reads response code from RAKUN_WEBHOOK_STATUS env variable (default 200).
 * Sets RAKUN_WEBHOOK_HMAC_SECRET to verify HMAC signatures.
 *
 * On /capture: captures the request and writes JSON to a temp file.
 * On /__ready:  returns 200 OK (liveness probe).
 */

$statusCode = (int) ($_SERVER['RAKUN_WEBHOOK_STATUS'] ?? getenv('RAKUN_WEBHOOK_STATUS') ?: 200);
$secret = $_SERVER['RAKUN_WEBHOOK_SECRET'] ?? getenv('RAKUN_WEBHOOK_SECRET') ?: '';

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

// Liveness probe — always 200
if ($path === '/__ready') {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'OK';
    exit(0);
}

// Capture route
$body = (string) file_get_contents('php://input');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Verify HMAC if secret is configured
$hmacOk = true;
$hmacError = '';
if ($secret !== '') {
    $signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if ($signatureHeader === '') {
        $hmacOk = false;
        $hmacError = 'Missing X-Hub-Signature-256 header';
    } else {
        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, $signatureHeader)) {
            $hmacOk = false;
            $hmacError = "Signature mismatch: expected {$expected}, got {$signatureHeader}";
        }
    }
}

// Write capture to temp file if RAKUN_CAPTURE_FILE is set
$captureFile = $_SERVER['RAKUN_CAPTURE_FILE'] ?? getenv('RAKUN_CAPTURE_FILE') ?: '';
if ($captureFile !== '') {
    $capture = [
        'method' => $method,
        'path' => $path,
        'body' => $body,
        'headers' => getallheaders() ?: [],
        'hmac_ok' => $hmacOk,
        'hmac_error' => $hmacError,
        'timestamp' => microtime(true),
    ];
    file_put_contents($captureFile, json_encode($capture, JSON_UNESCAPED_SLASHES));
}

http_response_code($statusCode);
header('Content-Type: application/json');

if (!$hmacOk) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $hmacError]);
    exit(0);
}

echo json_encode(['ok' => $statusCode >= 200 && $statusCode < 300, 'status' => $statusCode]);
