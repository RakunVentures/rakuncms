<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\GitHost;

/**
 * In-memory HTTP transport for unit testing.
 *
 * Usage:
 *   $transport = new FakeTransport();
 *   $transport->queueResponse(200, '{"id":42}');
 *   $client = new GitHubClient(token: 't', transport: $transport);
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
            throw new GitHubApiException("FakeTransport: no queued response for {$method} {$url}");
        }

        return array_shift($this->queue);
    }

    /**
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
