<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

use Rkn\Cms\Deploy\PleskTransportException;

/**
 * In-memory HTTP transport for unit testing.
 *
 * Usage in tests:
 *   $transport = new FakeTransport();
 *   $transport->queueResponse(200, '{"data":[]}');
 *   $client = new Client('https://plesk.test:8443', 'key123', transport: $transport);
 *
 * This is dependency injection, not a stub — no production behavior is bypassed.
 */
final class FakeTransport implements HttpTransport
{
    /** @var HttpResponse[] */
    private array $queue = [];

    /** @var array<int, array{method: string, url: string, headers: array<string,string>, body: string}> */
    private array $recorded = [];

    /** @param array<string, string> $headers */
    public function queueResponse(int $statusCode, string $body, array $headers = []): void
    {
        $this->queue[] = new HttpResponse($statusCode, $body, $headers);
    }

    public function send(string $method, string $url, array $headers, string $body = ''): HttpResponse
    {
        $this->recorded[] = compact('method', 'url', 'headers', 'body');

        if (empty($this->queue)) {
            throw new PleskTransportException('FakeTransport: no queued response for ' . $method . ' ' . $url);
        }

        return array_shift($this->queue);
    }

    /**
     * Return all recorded requests for assertion.
     *
     * @return array<int, array{method: string, url: string, headers: array<string,string>, body: string}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * @return array{method: string, url: string, headers: array<string,string>, body: string}|null
     */
    public function lastRequest(): ?array
    {
        return $this->recorded[count($this->recorded) - 1] ?? null;
    }

    public function requestCount(): int
    {
        return count($this->recorded);
    }
}
