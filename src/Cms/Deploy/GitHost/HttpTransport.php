<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\GitHost;

/**
 * Abstraction over HTTP transport for the GitHub client.
 *
 * Exists so tests can inject deterministic responses via {@see FakeTransport}.
 * Production uses {@see CurlTransport}.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers Key => Value pairs
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        string $body = '',
    ): HttpResponse;
}
