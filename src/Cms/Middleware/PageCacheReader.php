<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Cache\PageCache;

/**
 * Short-circuits the pipeline if a cached HTML page exists.
 * Only caches GET requests. Skips POST, Yoyo, and API requests.
 */
class PageCacheReader implements MiddlewareInterface
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
        if (!$this->enabled || $request->getMethod() !== 'GET') {
            return $handler->handle($request);
        }

        $uri = $request->getUri()->getPath();

        // Skip API, Yoyo y vista previa (?preview). El preview NO debe leerse de
        // cache (devolvería la versión pública, no el draft) — la clave de cache
        // es solo el path, así que sin esta guarda el preview leería/escribiría la
        // clave pública y filtraría el borrador.
        if (str_starts_with($uri, '/api/') || str_starts_with($uri, '/yoyo')
            || ((string) ($request->getQueryParams()['preview'] ?? '')) !== '') {
            return $handler->handle($request);
        }

        $html = $this->cache->get($uri);
        if ($html !== null) {
            return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
        }

        return $handler->handle($request);
    }
}
