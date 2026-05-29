<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\GitHost;

/**
 * cURL-based production HTTP transport for the GitHub client.
 */
final class CurlTransport implements HttpTransport
{
    public function __construct(
        private readonly bool $verifySsl = true,
        private readonly int $timeout = 30,
    ) {}

    public function send(string $method, string $url, array $headers, string $body = ''): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new GitHubApiException('Failed to initialize cURL for GitHub request');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min($this->timeout, 10),
            CURLOPT_HEADER         => true,
        ];

        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false || $errno !== CURLE_OK) {
            throw new GitHubApiException("GitHub cURL request failed (errno {$errno}): {$error}");
        }

        $rawString = (string) $raw;
        $rawHeaders = substr($rawString, 0, $headerSize);
        $responseBody = substr($rawString, $headerSize);

        $parsedHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $parsedHeaders[trim(strtolower($name))] = trim($value);
        }

        return new HttpResponse($status, $responseBody, $parsedHeaders);
    }
}
