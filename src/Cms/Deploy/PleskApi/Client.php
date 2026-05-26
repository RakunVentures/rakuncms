<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

use Rkn\Cms\Deploy\PleskAuthException;
use Rkn\Cms\Deploy\PleskEndpointNotFoundException;
use Rkn\Cms\Deploy\PleskResponseException;
use Rkn\Cms\Deploy\PleskTransportException;

/**
 * Dual-protocol Plesk API client: REST v2 + XML-RPC.
 *
 * Design decision (D1 in deploy-plesk-api.md):
 *   There is NO automatic fallback from REST to XML-RPC. Each consumer
 *   (Inspector, Provisioner) explicitly calls the correct method based on
 *   what the Plesk REST v2 specification actually exposes. This avoids
 *   "try REST, catch 404, retry as XML-RPC" ambiguity.
 *
 * REST v2 base:   https://{host}:8443/api/v2/
 * XML-RPC base:   https://{host}:8443/enterprise/control/agent.php
 * Auth header:    X-API-Key (both protocols accept the same key)
 */
final class Client
{
    private readonly HttpTransport $transport;

    public function __construct(
        private readonly string $host,
        private readonly string $apiKey,
        private readonly bool $verifySsl = true,
        private readonly int $timeout = 30,
        ?HttpTransport $transport = null,
    ) {
        $this->transport = $transport ?? new CurlTransport($this->verifySsl, $this->timeout);
    }

    // -------------------------------------------------------------------------
    // REST v2
    // -------------------------------------------------------------------------

    /**
     * Execute a REST v2 GET request.
     *
     * @param array<string, string|int> $params Query parameters
     * @return array<mixed>
     *
     * @throws PleskAuthException            on 401/403
     * @throws PleskEndpointNotFoundException on 404
     * @throws PleskResponseException        on 5xx or malformed JSON
     * @throws PleskTransportException       on network failure
     */
    public function restGet(string $endpoint, array $params = []): array
    {
        $url = $this->buildRestUrl($endpoint, $params);
        $response = $this->transport->send('GET', $url, $this->restHeaders());
        return $this->decodeRestResponse($response);
    }

    /**
     * Execute a REST v2 POST request.
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
        $response = $this->transport->send('POST', $url, $this->restHeaders(), $encoded);
        return $this->decodeRestResponse($response);
    }

    // -------------------------------------------------------------------------
    // XML-RPC
    // -------------------------------------------------------------------------

    /**
     * Execute an XML-RPC packet against the Plesk agent endpoint.
     *
     * @param string $packetXml Complete <packet version="1.6.9.0">...</packet> document
     * @return array<mixed> Decoded representation of the XML response
     *
     * @throws PleskAuthException
     * @throws PleskResponseException
     * @throws PleskTransportException
     */
    public function xmlRpcCall(string $packetXml): array
    {
        $url = $this->normalizedHost() . '/enterprise/control/agent.php';

        $headers = [
            'Content-Type' => 'text/xml',
            'HTTP_AUTH_LOGIN' => '',
            'HTTP_AUTH_PASSWD' => '',
            'KEY' => $this->apiKey,
        ];

        $response = $this->transport->send('POST', $url, $headers, $packetXml);
        return $this->decodeXmlRpcResponse($response);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function getHost(): string
    {
        return $this->host;
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
    private function restHeaders(): array
    {
        return [
            'X-API-Key' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    private function normalizedHost(): string
    {
        return rtrim($this->host, '/');
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
            $msg = $this->extractErrorMessage($response->body) ?? 'Unauthorized';
            throw new PleskAuthException("Plesk auth error ({$response->statusCode}): {$msg}", $response->statusCode);
        }

        if ($response->statusCode === 404) {
            throw new PleskEndpointNotFoundException(
                "Plesk REST v2 endpoint not found (404). Consider using xmlRpcCall() for this operation.",
                404,
            );
        }

        if ($response->statusCode >= 500) {
            $msg = $this->extractErrorMessage($response->body) ?? 'Internal server error';
            throw new PleskResponseException("Plesk server error ({$response->statusCode}): {$msg}", $response->statusCode);
        }

        if ($response->statusCode >= 400) {
            $msg = $this->extractErrorMessage($response->body) ?? 'Bad request';
            throw new PleskResponseException("Plesk API error ({$response->statusCode}): {$msg}", $response->statusCode);
        }

        if ($response->body === '') {
            return [];
        }

        $decoded = json_decode($response->body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PleskResponseException('Plesk REST response is not valid JSON: ' . json_last_error_msg());
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<mixed>
     * @throws PleskAuthException
     * @throws PleskResponseException
     */
    private function decodeXmlRpcResponse(HttpResponse $response): array
    {
        $this->assertNotTransportError($response);

        if ($response->statusCode === 401 || $response->statusCode === 403) {
            throw new PleskAuthException(
                "Plesk XML-RPC auth error ({$response->statusCode})",
                $response->statusCode,
            );
        }

        if ($response->statusCode >= 500) {
            throw new PleskResponseException(
                "Plesk XML-RPC server error ({$response->statusCode})",
                $response->statusCode,
            );
        }

        if ($response->body === '') {
            throw new PleskResponseException('Plesk XML-RPC response is empty');
        }

        try {
            return XmlRpcDecoder::parse($response->body);
        } catch (\InvalidArgumentException $e) {
            throw new PleskResponseException('Plesk XML-RPC response parse error: ' . $e->getMessage(), 0, $e);
        }
    }

    private function assertNotTransportError(HttpResponse $response): void
    {
        // Status 0 means cURL received nothing (transport-level failure already thrown by CurlTransport).
        // This guard handles edge cases in FakeTransport-based tests.
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
