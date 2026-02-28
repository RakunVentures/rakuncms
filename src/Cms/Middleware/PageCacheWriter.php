<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Cache\PageCache;

/**
 * Captures successful GET responses and writes them to the page cache.
 * Only caches 200 OK responses with text/html content type.
 */
class PageCacheWriter implements MiddlewareInterface
{
    private PageCache $cache;
    private bool $enabled;

    public function __construct(PageCache $cache, bool $enabled = true)
    {
        $this->cache = $cache;
        $this->enabled = $enabled;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->enabled || $request->getMethod() !== 'GET') {
            return $response;
        }

        $uri = $request->getUri()->getPath();

        // Skip API and Yoyo requests
        if (str_starts_with($uri, '/api/') || str_starts_with($uri, '/yoyo')) {
            return $response;
        }

        // Only cache successful HTML responses
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();
        if ($body !== '') {
            $this->cache->set($uri, $body);
        }

        return $response;
    }
}
