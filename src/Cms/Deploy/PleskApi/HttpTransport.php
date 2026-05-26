<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

/**
 * Abstraction over HTTP transport for the Plesk API client.
 *
 * This interface exists solely to allow injecting deterministic responses
 * in unit tests (via FakeTransport) without using mocks/stubs. The production
 * implementation is CurlTransport.
 *
 * @see CurlTransport
 * @see FakeTransport
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers Key => Value pairs
     * @return HttpResponse
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        string $body = '',
    ): HttpResponse;
}
