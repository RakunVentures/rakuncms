<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

use Rkn\Cms\Deploy\PleskAuthException;
use Rkn\Cms\Deploy\PleskEndpointNotFoundException;
use Rkn\Cms\Deploy\PleskResponseException;
use Rkn\Cms\Deploy\PleskTransportException;

/**
 * REST-only Plesk API client.
 *
 * Three public operations:
 *   - restGet/restPost:  native REST v2 resources (domains, ftpusers, extensions, server, auth/keys)
 *   - cliCall:           CLI gateway (POST /api/v2/cli/{id}/call) — covers everything REST v2 does not expose natively
 *
 * Authentication:
 *   - Default: X-API-Key header with the REST API key.
 *   - Alternative: Basic Auth (admin:password) for the initial key-creation flow only.
 *     Use {@see Client::withBasicAuth()} to obtain a transient client bound to admin credentials.
 *
 * Rate-limit handling (D7):
 *   Plesk's bruteforce protection silently returns 401 with empty body for ~300s after 5
 *   failed auth attempts. New API keys also exhibit a ~5-8s propagation delay during which
 *   they return 401 empty. Both cases are transient. The client retries up to 3 times with
 *   exponential backoff (2/4/8s) on 401-empty before throwing PleskAuthException.
 *
 * Host normalization:
 *   The {host} parameter may be a bare hostname or a full URL with optional port.
 *   No port is forced — let the server's reverse proxy decide.
 */
final class Client
{
    private readonly HttpTransport $transport;
    /** @var \Closure(int): void */
    private readonly \Closure $sleeper;

    /**
     * @param \Closure(int): void|null $sleeper Custom sleeper for tests; defaults to PHP's sleep().
     */
    public function __construct(
        private readonly string $host,
        private readonly string $apiKey,
        private readonly bool $verifySsl = true,
        private readonly int $timeout = 30,
        ?HttpTransport $transport = null,
        ?\Closure $sleeper = null,
        private readonly ?string $basicAuthHeader = null,
    ) {
        $this->transport = $transport ?? new CurlTransport($this->verifySsl, $this->timeout);
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    /**
     * Construct a transient client that authenticates with Basic Auth (admin user + password).
     *
     * Intended ONLY for the initial flow that calls POST /api/v2/auth/keys to mint a REST
     * API key. Once the key is obtained, the caller MUST switch back to the X-API-Key-based
     * client and discard admin credentials.
     */
    public static function withBasicAuth(
        string $host,
        string $user,
        string $password,
        bool $verifySsl = true,
        int $timeout = 30,
        ?HttpTransport $transport = null,
        ?\Closure $sleeper = null,
    ): self {
        $header = 'Basic ' . base64_encode("{$user}:{$password}");

        return new self(
            host: $host,
            apiKey: '',
            verifySsl: $verifySsl,
            timeout: $timeout,
            transport: $transport,
            sleeper: $sleeper,
            basicAuthHeader: $header,
        );
    }

    // -------------------------------------------------------------------------
    // Public operations
    // -------------------------------------------------------------------------

    /**
     * Execute a REST v2 GET request.
     *
     * @param array<string, string|int> $params Query parameters
     * @return array<mixed>
     *
     * @throws PleskAuthException            on 401/403 (after retry exhaustion)
     * @throws PleskEndpointNotFoundException on 404
     * @throws PleskResponseException        on 5xx or malformed JSON
     * @throws PleskTransportException       on network failure
     */
    public function restGet(string $endpoint, array $params = []): array
    {
        $url = $this->buildRestUrl($endpoint, $params);
        $response = $this->sendWithAuthRetry('GET', $url, $this->jsonHeaders());

        return $this->decodeRestResponse($response);
    }

    /**
     * Execute a REST v2 POST request with a JSON body.
     *
     * @param array<mixed> $body JSON-serializable payload
     * @return array<mixed>
     *
     * @throws PleskAuthException
     * @throws PleskEndpointNotFoundException
     * @throws PleskResponseException
     * @throws PleskTransportException
     */
    public function restPost(string $endpoint, array $body = []): array
    {
        $url = $this->buildRestUrl($endpoint);
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        $response = $this->sendWithAuthRetry('POST', $url, $this->jsonHeaders(), $encoded);

        return $this->decodeRestResponse($response);
    }

    /**
     * Execute a Plesk CLI utility through the REST gateway.
     *
     * @param string $commandId  CLI command id (e.g. "domain", "extension", "subscription"). See
     *                           GET /api/v2/cli/commands for the full list.
     * @param array<int, string> $params Positional CLI arguments (e.g. ['--info', 'xyz.rkn.mx']).
     *
     * @throws PleskAuthException
     * @throws PleskEndpointNotFoundException If the commandId is unknown to this Plesk version
     * @throws PleskResponseException
     * @throws PleskTransportException
     */
    public function cliCall(string $commandId, array $params): CliResult
    {
        $url = $this->buildRestUrl('cli/' . rawurlencode($commandId) . '/call');
        $encoded = json_encode(['params' => array_values($params)], JSON_THROW_ON_ERROR);
        $response = $this->sendWithAuthRetry('POST', $url, $this->jsonHeaders(), $encoded);

        $decoded = $this->decodeRestResponse($response);

        $code = isset($decoded['code']) && is_int($decoded['code']) ? $decoded['code'] : -1;
        $stdout = isset($decoded['stdout']) && is_string($decoded['stdout']) ? $decoded['stdout'] : '';
        $stderr = isset($decoded['stderr']) && is_string($decoded['stderr']) ? $decoded['stderr'] : '';

        return new CliResult($code, $stdout, $stderr);
    }

    public function getHost(): string
    {
        return $this->host;
    }

    // -------------------------------------------------------------------------
    // Internal: retry + transport
    // -------------------------------------------------------------------------

    /**
     * Send a request and retry on 401-empty-body (Plesk rate-limit / key propagation window).
     *
     * @param array<string, string> $headers
     */
    private function sendWithAuthRetry(
        string $method,
        string $url,
        array $headers,
        string $body = '',
    ): HttpResponse {
        $attempts = 3;
        $response = null;

        for ($i = 0; $i < $attempts; $i++) {
            $response = $this->transport->send($method, $url, $headers, $body);

            if ($response->statusCode !== 401 || $response->body !== '') {
                return $response;
            }

            if ($i < $attempts - 1) {
                ($this->sleeper)(2 ** ($i + 1));
            }
        }

        return $response;
    }

    /** @param array<string, string|int> $params */
    private function buildRestUrl(string $endpoint, array $params = []): string
    {
        $base = $this->normalizedHost() . '/api/v2/' . ltrim($endpoint, '/');

        if (!empty($params)) {
            $base .= '?' . http_build_query($params);
        }

        return $base;
    }

    /** @return array<string, string> */
    private function jsonHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($this->basicAuthHeader !== null) {
            $headers['Authorization'] = $this->basicAuthHeader;
        } else {
            $headers['X-API-Key'] = $this->apiKey;
        }

        return $headers;
    }

    private function normalizedHost(): string
    {
        $host = rtrim($this->host, '/');

        if (parse_url($host, PHP_URL_SCHEME) === null) {
            $host = 'https://' . $host;
        }

        return $host;
    }

    /**
     * @return array<mixed>
     * @throws PleskAuthException
     * @throws PleskEndpointNotFoundException
     * @throws PleskResponseException
     * @throws PleskTransportException
     */
    private function decodeRestResponse(HttpResponse $response): array
    {
        $this->assertNotTransportError($response);

        if ($response->statusCode === 401 || $response->statusCode === 403) {
            $msg = $this->extractErrorMessage($response->body);
            if ($msg === null) {
                $msg = $response->body === ''
                    ? 'Unauthorized (empty body — possible rate-limit or key propagation window)'
                    : 'Unauthorized';
            }
            throw new PleskAuthException(
                "Plesk auth error ({$response->statusCode}): {$msg}",
                $response->statusCode,
            );
        }

        if ($response->statusCode === 404) {
            $msg = $this->extractErrorMessage($response->body) ?? 'Endpoint not found';
            throw new PleskEndpointNotFoundException(
                "Plesk REST v2 endpoint not found (404): {$msg}",
                404,
            );
        }

        if ($response->statusCode >= 500) {
            $msg = $this->extractErrorMessage($response->body) ?? 'Internal server error';
            throw new PleskResponseException(
                "Plesk server error ({$response->statusCode}): {$msg}",
                $response->statusCode,
            );
        }

        if ($response->statusCode >= 400) {
            $msg = $this->extractErrorMessage($response->body) ?? 'Bad request';
            throw new PleskResponseException(
                "Plesk API error ({$response->statusCode}): {$msg}",
                $response->statusCode,
            );
        }

        if ($response->body === '') {
            return [];
        }

        $decoded = json_decode($response->body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PleskResponseException(
                'Plesk REST response is not valid JSON: ' . json_last_error_msg(),
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function assertNotTransportError(HttpResponse $response): void
    {
        if ($response->statusCode === 0) {
            throw new PleskTransportException('Plesk API returned no HTTP status (transport error)');
        }
    }

    private function extractErrorMessage(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        foreach (['message', 'error', 'description'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                return $decoded[$key];
            }
        }

        return null;
    }
}
