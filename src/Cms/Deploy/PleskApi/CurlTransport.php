<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

use Rkn\Cms\Deploy\PleskTransportException;

/**
 * Production HTTP transport using PHP's built-in cURL extension.
 */
final class CurlTransport implements HttpTransport
{
    public function __construct(
        private readonly bool $verifySsl,
        private readonly int $timeout,
    ) {}

    public function send(
        string $method,
        string $url,
        array $headers,
        string $body = '',
    ): HttpResponse {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new PleskTransportException('Failed to initialize cURL handle');
        }

        $headerLines = array_map(
            static fn (string $key, string $value): string => "{$key}: {$value}",
            array_keys($headers),
            array_values($headers),
        );

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($this->timeout, 10));

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== CURLE_OK) {
            throw new PleskTransportException("cURL request failed (errno {$errno}): {$error}");
        }

        $rawString = (string) $raw;
        $rawHeaders = substr($rawString, 0, $headerSize);
        $responseBody = substr($rawString, $headerSize);
        $parsedHeaders = self::parseRawHeaders($rawHeaders);

        return new HttpResponse($statusCode, $responseBody, $parsedHeaders);
    }

    /** @return array<string, string> */
    private static function parseRawHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }
        return $headers;
    }
}
