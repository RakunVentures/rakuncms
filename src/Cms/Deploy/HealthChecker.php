<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

/**
 * Checks that a URL returns a 2xx HTTP response after deployment.
 *
 * Retry behavior:
 *   - Any non-2xx response or timeout → retry with backoff
 *   - Returns false after all retries are exhausted
 *
 * The total number of HTTP requests made is at most $retries + 1.
 * With default settings (retries=2, backoff=[2,4,8]):
 *   - 1st attempt + up to 2 retries = 3 total attempts maximum.
 */
final class HealthChecker
{
    /**
     * @param int $retries    Number of retries (total attempts = retries + 1).
     * @param array<int> $backoffSec Seconds to wait before each retry attempt.
     */
    public function __construct(
        private readonly int $retries = 3,
        private readonly array $backoffSec = [2, 4, 8],
        private readonly bool $verifySsl = true,
        private readonly int $timeoutSec = 30,
    ) {}

    public function check(string $url, callable $logger): bool
    {
        $maxAttempts = $this->retries + 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $startTime = microtime(true);
            [$statusCode, $curlError] = $this->doGet($url);
            $duration = round(microtime(true) - $startTime, 3);

            if ($curlError !== '') {
                $logger("<comment>Health check attempt {$attempt}/{$maxAttempts}: cURL error after {$duration}s — {$curlError}</comment>");
            } else {
                $logger("<comment>Health check attempt {$attempt}/{$maxAttempts}: HTTP {$statusCode} in {$duration}s</comment>");
            }

            if ($statusCode >= 200 && $statusCode < 300) {
                $logger("<info>Health check passed (HTTP {$statusCode}).</info>");
                return true;
            }

            if ($attempt < $maxAttempts) {
                $backoffArr = $this->backoffSec;
                $backoff = $backoffArr[$attempt - 1] ?? end($backoffArr);
                $logger("<comment>Health check: retrying in {$backoff}s...</comment>");
                sleep((int) $backoff);
            }
        }

        $logger("<error>Health check failed after {$maxAttempts} attempts for URL: {$url}</error>");
        return false;
    }

    /**
     * @return array{0: int, 1: string} [statusCode, curlError]
     */
    private function doGet(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [0, 'curl_init() failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ]);

        curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [$statusCode, $curlError];
    }
}
