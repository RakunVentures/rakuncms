<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\HealthChecker;

/**
 * Start a health-check server fixture on an ephemeral port.
 *
 * @param array<string, string> $env
 * @return array{0: int, 1: mixed}
 */
function startHealthServer(string $scriptPath, array $env = []): array
{
    $envPairs = [];
    foreach ($env as $k => $v) {
        $envPairs[] = "{$k}=" . escapeshellarg($v);
    }

    $port = findHealthFreePort();
    $envStr = implode(' ', $envPairs);
    $cmd = "{$envStr} herd php -S 127.0.0.1:{$port} " . escapeshellarg($scriptPath) . " > /dev/null 2>&1 &";
    $proc = popen($cmd, 'r');

    // Poll until server responds to /__ready
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(100_000);
        $ch = curl_init("http://127.0.0.1:{$port}/__ready");
        if ($ch === false) {
            continue;
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            $ready = true;
            break;
        }
    }

    if (!$ready) {
        if ($proc !== false) {
            pclose($proc);
        }
        throw new \RuntimeException("Health server on port {$port} did not start in time");
    }

    return [$port, $proc];
}

function findHealthFreePort(): int
{
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_bind($sock, '127.0.0.1', 0);
    socket_getsockname($sock, $addr, $port);
    socket_close($sock);
    return (int) $port;
}

function stopHealthServer(mixed $proc): void
{
    if ($proc !== null && $proc !== false && is_resource($proc)) {
        pclose($proc);
    }
}

$fixtureScript = dirname(__DIR__, 2) . '/Fixtures/health-server.php';

describe('HealthChecker', function () use ($fixtureScript): void {
    it('returns true immediately when server returns 200', function () use ($fixtureScript): void {
        [$port, $proc] = startHealthServer($fixtureScript, ['RAKUN_HEALTH_STATUS' => '200']);
        try {
            $logs = [];
            $logger = function (string $msg) use (&$logs): void {
                $logs[] = $msg;
            };

            $checker = new HealthChecker(retries: 2, backoffSec: [1, 1, 1], verifySsl: false, timeoutSec: 5);
            $result = $checker->check("http://127.0.0.1:{$port}/health", $logger);

            expect($result)->toBeTrue();
            // Only 1 attempt made
            expect(implode("\n", $logs))->toContain('attempt 1/3');
            expect(implode("\n", $logs))->not->toContain('attempt 2/3');
        } finally {
            stopHealthServer($proc);
        }
    });

    it('retries on 500 responses and returns false after all attempts exhausted', function () use ($fixtureScript): void {
        [$port, $proc] = startHealthServer($fixtureScript, ['RAKUN_HEALTH_STATUS' => '500']);
        try {
            $logs = [];
            $logger = function (string $msg) use (&$logs): void {
                $logs[] = $msg;
            };

            // retries=2 → 3 total attempts, use short backoff for speed
            $checker = new HealthChecker(retries: 2, backoffSec: [0, 0, 0], verifySsl: false, timeoutSec: 5);
            $result = $checker->check("http://127.0.0.1:{$port}/health", $logger);

            expect($result)->toBeFalse();

            $logOutput = implode("\n", $logs);
            expect($logOutput)->toContain('attempt 1/3');
            expect($logOutput)->toContain('attempt 2/3');
            expect($logOutput)->toContain('attempt 3/3');
            expect($logOutput)->toContain('failed after');
        } finally {
            stopHealthServer($proc);
        }
    });

    it('makes exactly retries+1 attempts in worst case', function () use ($fixtureScript): void {
        [$port, $proc] = startHealthServer($fixtureScript, ['RAKUN_HEALTH_STATUS' => '503']);
        try {
            $attemptCount = 0;
            $logger = function (string $msg) use (&$attemptCount): void {
                // Count lines that look like "Health check attempt N/M:"
                if (preg_match('/Health check attempt \d+\/\d+/', $msg)) {
                    $attemptCount++;
                }
            };

            // retries=1 → 2 total attempts
            $checker = new HealthChecker(retries: 1, backoffSec: [0, 0], verifySsl: false, timeoutSec: 5);
            $result = $checker->check("http://127.0.0.1:{$port}/health", $logger);

            expect($result)->toBeFalse();
            expect($attemptCount)->toBe(2); // exactly retries+1 attempts
        } finally {
            stopHealthServer($proc);
        }
    });

    it('succeeds with sequence 500→200 when retries allow it', function () use ($fixtureScript): void {
        // The health server fixture supports RAKUN_HEALTH_SEQUENCE for ordered responses.
        $seqFile = sys_get_temp_dir() . '/rakun-health-seq-' . uniqid('', true);

        [$port, $proc] = startHealthServer($fixtureScript, [
            'RAKUN_HEALTH_SEQUENCE' => '500,200',
            'RAKUN_SEQ_FILE' => $seqFile,
        ]);

        try {
            $logs = [];
            $logger = function (string $msg) use (&$logs): void {
                $logs[] = $msg;
            };

            // retries=1 → up to 2 attempts: 500 (retry), 200 (success)
            $checker = new HealthChecker(retries: 1, backoffSec: [0, 0], verifySsl: false, timeoutSec: 5);
            $result = $checker->check("http://127.0.0.1:{$port}/health", $logger);

            expect($result)->toBeTrue();
            $logOutput = implode("\n", $logs);
            expect($logOutput)->toContain('HTTP 500');
            expect($logOutput)->toContain('HTTP 200');
        } finally {
            stopHealthServer($proc);
            @unlink($seqFile);
        }
    });
});
