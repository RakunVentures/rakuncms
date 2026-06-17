<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Cache\PageCache;
use Rkn\Cms\Middleware\PageCacheReader;
use Rkn\Cms\Middleware\PageCacheWriter;

/**
 * Contract tests for the page-cache middleware pair.
 *
 * Locking the HTTP rules here prevents subtle regressions in the cache path:
 * a stray POST hit, a 500 written to disk, or a stale read served past invalidation.
 * These middlewares sit at the edge of the pipeline and have outsized blast radius.
 */

beforeEach(function () {
    $this->cacheDir = sys_get_temp_dir() . '/rkn-pcache-mw-' . uniqid();
    $this->cache = new PageCache($this->cacheDir);
});

afterEach(function () {
    if (!is_dir($this->cacheDir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($this->cacheDir);
});

function pageCacheHandler(int $status = 200, string $body = '<html>fresh</html>', string $contentType = 'text/html; charset=UTF-8'): RequestHandlerInterface
{
    return new class($status, $body, $contentType) implements RequestHandlerInterface {
        public function __construct(private int $status, private string $body, private string $contentType) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response($this->status, ['Content-Type' => $this->contentType], $this->body);
        }
    };
}

// --- PageCacheReader ---------------------------------------------------------

it('reader: short-circuits with cached HTML on GET', function () {
    $this->cache->set('/blog/foo', '<html>cached</html>');
    $reader = new PageCacheReader($this->cache);

    $response = $reader->process(new ServerRequest('GET', '/blog/foo'), pageCacheHandler(body: '<html>fresh</html>'));

    expect((string) $response->getBody())->toBe('<html>cached</html>')
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toContain('text/html');
});

it('reader: passes through when nothing is cached', function () {
    $reader = new PageCacheReader($this->cache);
    $response = $reader->process(new ServerRequest('GET', '/blog/missing'), pageCacheHandler(body: '<html>fresh</html>'));

    expect((string) $response->getBody())->toBe('<html>fresh</html>');
});

it('reader: skips POST requests even with cached entry', function () {
    $this->cache->set('/api/save', '<html>cached</html>');
    $reader = new PageCacheReader($this->cache);

    $response = $reader->process(new ServerRequest('POST', '/api/save'), pageCacheHandler(body: '<html>handler</html>'));

    expect((string) $response->getBody())->toBe('<html>handler</html>');
});

it('reader: bypasses /api/* paths', function () {
    $this->cache->set('/api/v1/posts', '<html>cached</html>');
    $reader = new PageCacheReader($this->cache);

    $response = $reader->process(new ServerRequest('GET', '/api/v1/posts'), pageCacheHandler(body: 'json'));

    expect((string) $response->getBody())->toBe('json');
});

it('reader: bypasses /yoyo* paths', function () {
    $this->cache->set('/yoyo', '<html>cached</html>');
    $reader = new PageCacheReader($this->cache);

    $response = $reader->process(new ServerRequest('GET', '/yoyo'), pageCacheHandler(body: 'yoyo-render'));

    expect((string) $response->getBody())->toBe('yoyo-render');
});

it('reader: disabled flag bypasses the cache entirely', function () {
    $this->cache->set('/blog/foo', '<html>cached</html>');
    $reader = new PageCacheReader($this->cache, enabled: false);

    $response = $reader->process(new ServerRequest('GET', '/blog/foo'), pageCacheHandler(body: '<html>fresh</html>'));

    expect((string) $response->getBody())->toBe('<html>fresh</html>');
});

// --- PageCacheWriter ---------------------------------------------------------

it('writer: stores GET + 200 + text/html responses with non-empty body', function () {
    $writer = new PageCacheWriter($this->cache);
    $writer->process(new ServerRequest('GET', '/blog/foo'), pageCacheHandler());

    expect($this->cache->get('/blog/foo'))->toBe('<html>fresh</html>');
});

it('writer: does not store non-200 responses', function () {
    $writer = new PageCacheWriter($this->cache);
    $writer->process(new ServerRequest('GET', '/blog/foo'), pageCacheHandler(status: 404, body: 'not found'));

    expect($this->cache->has('/blog/foo'))->toBeFalse();
});

it('writer: does not store 5xx responses', function () {
    $writer = new PageCacheWriter($this->cache);
    $writer->process(new ServerRequest('GET', '/blog/foo'), pageCacheHandler(status: 500, body: 'oops'));

    expect($this->cache->has('/blog/foo'))->toBeFalse();
});

it('writer: does not store POST responses', function () {
    $writer = new PageCacheWriter($this->cache);
    $writer->process(new ServerRequest('POST', '/blog/foo'), pageCacheHandler());

    expect($this->cache->has('/blog/foo'))->toBeFalse();
});

it('writer: does not store non-html content types', function () {
    $writer = new PageCacheWriter($this->cache);
    $writer->process(new ServerRequest('GET', '/blog/foo'), pageCacheHandler(contentType: 'application/json'));

    expect($this->cache->has('/blog/foo'))->toBeFalse();
});

it('writer: does not store empty body even on 200 html', function () {
    $writer = new PageCacheWriter($this->cache);
    $writer->process(new ServerRequest('GET', '/blog/foo'), pageCacheHandler(body: ''));

    expect($this->cache->has('/blog/foo'))->toBeFalse();
});

it('writer: bypasses /api/* paths', function () {
    $writer = new PageCacheWriter($this->cache);
    $writer->process(new ServerRequest('GET', '/api/v1/posts'), pageCacheHandler());

    expect($this->cache->has('/api/v1/posts'))->toBeFalse();
});

it('writer: bypasses /yoyo* paths', function () {
    $writer = new PageCacheWriter($this->cache);
    $writer->process(new ServerRequest('GET', '/yoyo'), pageCacheHandler());

    expect($this->cache->has('/yoyo'))->toBeFalse();
});

it('writer: disabled flag prevents any write', function () {
    $writer = new PageCacheWriter($this->cache, enabled: false);
    $writer->process(new ServerRequest('GET', '/blog/foo'), pageCacheHandler());

    expect($this->cache->has('/blog/foo'))->toBeFalse();
});

it('writer: returns the inner handler response untouched', function () {
    $writer = new PageCacheWriter($this->cache);
    $response = $writer->process(new ServerRequest('GET', '/blog/foo'), pageCacheHandler(body: '<html>fresh</html>'));

    expect((string) $response->getBody())->toBe('<html>fresh</html>')
        ->and($response->getStatusCode())->toBe(200);
});

// --- Reader + Writer round-trip ---------------------------------------------

it('round-trip: writer caches and reader serves from cache', function () {
    $writer = new PageCacheWriter($this->cache);
    $reader = new PageCacheReader($this->cache);

    $writer->process(new ServerRequest('GET', '/blog/foo'), pageCacheHandler(body: '<html>first</html>'));

    $response = $reader->process(new ServerRequest('GET', '/blog/foo'), pageCacheHandler(body: '<html>second-handler-never-called</html>'));

    expect((string) $response->getBody())->toBe('<html>first</html>');
});
