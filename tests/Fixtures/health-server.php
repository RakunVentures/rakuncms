<?php

declare(strict_types=1);

/**
 * Minimal PHP built-in server fixture for HealthChecker tests.
 *
 * Reads response code from RAKUN_HEALTH_STATUS env variable (default 200).
 * Supports a sequence of status codes via RAKUN_HEALTH_SEQUENCE (CSV).
 *
 * On /__ready:  returns 200 OK (liveness probe).
 * On any other path: returns status from env or sequence.
 *
 * The sequence counter is tracked via a temp file (RAKUN_SEQ_FILE).
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

if ($path === '/__ready') {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'OK';
    exit(0);
}

// Check for sequence file
$seqFile = $_SERVER['RAKUN_SEQ_FILE'] ?? getenv('RAKUN_SEQ_FILE') ?: '';
$sequence = array_filter(array_map('intval', explode(',', $_SERVER['RAKUN_HEALTH_SEQUENCE'] ?? getenv('RAKUN_HEALTH_SEQUENCE') ?: '')));

if ($seqFile !== '' && !empty($sequence)) {
    $sequence = array_values($sequence);
    $count = (int) (file_exists($seqFile) ? file_get_contents($seqFile) : 0);
    $statusCode = $sequence[$count] ?? end($sequence);
    file_put_contents($seqFile, (string)($count + 1));
} else {
    $statusCode = (int) ($_SERVER['RAKUN_HEALTH_STATUS'] ?? getenv('RAKUN_HEALTH_STATUS') ?: 200);
}

http_response_code($statusCode);
header('Content-Type: application/json');
echo json_encode(['status' => $statusCode]);
