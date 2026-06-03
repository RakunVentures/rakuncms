<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Middleware\ContentApiDispatcher;
use Rkn\Framework\Application;

/**
 * Fase 0: las mutaciones del API deben gatearse por permiso (write/media; 'admin'
 * comodín), leyendo el atributo api_permissions que pone ApiAuthMiddleware. Antes
 * cualquier key válida hacía todo.
 */

afterEach(function () {
    $prop = new ReflectionProperty(Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function bootDispatcherFixture(string $dir): void
{
    mkdir($dir . '/config', 0755, true);
    mkdir($dir . '/content/blog', 0755, true);
    file_put_contents($dir . '/.env', '');
    file_put_contents($dir . '/config/rakun.yaml', "site:\n  default_locale: en\napi:\n  enabled: true\n");
    new Application($dir);
}

function passthroughHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new \Nyholm\Psr7\Response(200, [], 'passthrough');
        }
    };
}

test('create is denied 403 without write permission', function () {
    $dir = $this->makeTempDir();
    bootDispatcherFixture($dir);

    $body = (string) json_encode(['title' => 'X', 'slug' => 'x', 'locale' => 'en']);
    $request = (new ServerRequest('POST', new Uri('/api/v1/entries/blog')))
        ->withBody(Stream::create($body))
        ->withAttribute('api_permissions', ['media']); // no 'write'

    $response = (new ContentApiDispatcher())->process($request, passthroughHandler());

    expect($response->getStatusCode())->toBe(403);
});

test('create proceeds past the gate with write permission', function () {
    $dir = $this->makeTempDir();
    bootDispatcherFixture($dir);

    $body = (string) json_encode(['title' => 'X', 'slug' => 'gated-x', 'locale' => 'en']);
    $request = (new ServerRequest('POST', new Uri('/api/v1/entries/blog')))
        ->withBody(Stream::create($body))
        ->withAttribute('api_permissions', ['write']);

    $response = (new ContentApiDispatcher())->process($request, passthroughHandler());

    expect($response->getStatusCode())->not->toBe(403);
});

test('admin permission is a wildcard for write', function () {
    $dir = $this->makeTempDir();
    bootDispatcherFixture($dir);

    $request = (new ServerRequest('DELETE', new Uri('/api/v1/entries/blog/nope')))
        ->withAttribute('api_permissions', ['admin']);

    $response = (new ContentApiDispatcher())->process($request, passthroughHandler());

    // Passes the gate; delete then 404s (entry absent) — the point is it is NOT 403.
    expect($response->getStatusCode())->not->toBe(403);
});

test('media upload is denied 403 without media permission', function () {
    $dir = $this->makeTempDir();
    bootDispatcherFixture($dir);

    $request = (new ServerRequest('POST', new Uri('/api/v1/media')))
        ->withAttribute('api_permissions', ['write']); // no 'media'

    $response = (new ContentApiDispatcher())->process($request, passthroughHandler());

    expect($response->getStatusCode())->toBe(403);
});
