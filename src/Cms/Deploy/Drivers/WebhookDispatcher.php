<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\Drivers;

/**
 * Sends a POST webhook request with optional HMAC signature.
 *
 * Retry behavior:
 *   - 5xx or cURL timeout → retry (up to 3 total attempts)
 *   - 4xx → no retry (client error, retrying won't help)
 *   - 2xx → success
 *
 * HMAC format: X-Hub-Signature-256: sha256={hmac_sha256(body, secret)}
 * Compatible with GitHub-style webhook signatures.
 *
 * SSL verification is on by default. Set $verifySsl=false only when
 * deploy.yaml explicitly declares verify_ssl: false (a WARNING is shown).
 */
final class WebhookDispatcher
{
    /** Backoff delays in seconds between retries */
    private const BACKOFF_SEC = [2, 4, 8];

    public function __construct(
        private readonly string $url,
        private readonly ?string $secret = null,
        private readonly int $timeoutSec = 15,
        private readonly bool $verifySsl = true,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(array $payload, callable $logger): bool
    {
        if (!$this->verifySsl) {
            $logger('<comment>WARNING: SSL verification is disabled for webhook. Set verify_ssl: false explicitly in deploy.yaml to suppress.</comment>');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $logger('<error>Webhook: could not JSON-encode payload.</error>');
            return false;
        }

        $headers = [
            'Content-Type: application/json',
            'User-Agent: RakunCMS-Deploy/1.0',
        ];

        if ($this->secret !== null && $this->secret !== '') {
            $hmac = hash_hmac('sha256', $body, $this->secret);
            $headers[] = "X-Hub-Signature-256: sha256={$hmac}";
        }

        $maxAttempts = count(self::BACKOFF_SEC) + 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            [$statusCode, $responseBody, $curlError] = $this->doPost($body, $headers);

            if ($curlError !== '') {
                $logger("<comment>Webhook attempt {$attempt}/{$maxAttempts}: cURL error — {$curlError}</comment>");
            } else {
                $logger("<comment>Webhook attempt {$attempt}/{$maxAttempts}: HTTP {$statusCode}</comment>");
            }

            // Success
            if ($statusCode >= 200 && $statusCode < 300) {
                $logger("<info>Webhook dispatched successfully (HTTP {$statusCode}).</info>");
                return true;
            }

            // 4xx: client error — do NOT retry
            if ($statusCode >= 400 && $statusCode < 500) {
                $logger("<error>Webhook failed with client error HTTP {$statusCode} (no retry): {$responseBody}</error>");
                return false;
            }

            // 5xx or timeout: retry with backoff (unless last attempt)
            if ($attempt < $maxAttempts) {
                $backoff = self::BACKOFF_SEC[$attempt - 1];
                $logger("<comment>Webhook: retrying in {$backoff}s...</comment>");
                sleep($backoff);
            }
        }

        $logger("<error>Webhook failed after {$maxAttempts} attempts.</error>");
        return false;
    }

    /**
     * @param array<string> $headers
     * @return array{0: int, 1: string, 2: string} [statusCode, body, curlError]
     */
    private function doPost(string $body, array $headers): array
    {
        $ch = curl_init($this->url);
        if ($ch === false) {
            return [0, '', 'curl_init() failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $responseBody = (string) curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [$statusCode, $responseBody, $curlError];
    }
}
