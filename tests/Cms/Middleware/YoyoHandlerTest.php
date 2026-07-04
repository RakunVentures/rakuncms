<?php

declare(strict_types=1);

use Rkn\Cms\Middleware\YoyoHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

function yoyoPassthroughHandler(int $status = 404): RequestHandlerInterface
{
    return new class($status) implements RequestHandlerInterface {
        public function __construct(private int $status) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return (new Psr17Factory())->createResponse($this->status);
        }
    };
}

test('serves the vendored yoyo.js runtime at GET /yoyo.js', function () {
    // yoyo_scripts() emits <script src="/yoyo.js">, but the asset only ships inside
    // vendor/clickfwd/yoyo. Without this route every site 404s the script, htmx never
    // loads the yoyo extension, and no reactive component can fire a request.
    $response = (new YoyoHandler())->process(
        new ServerRequest('GET', 'http://localhost/yoyo.js'),
        yoyoPassthroughHandler(404)
    );

    expect($response->getStatusCode())->toBe(200);
    expect($response->getHeaderLine('Content-Type'))->toContain('javascript');

    $body = (string) $response->getBody();
    expect($body)->not->toBe('');
    // The bundled runtime registers the htmx extension named 'yoyo'.
    expect($body)->toContain("defineExtension('yoyo'");
});

test('non-yoyo paths pass through untouched', function () {
    $response = (new YoyoHandler())->process(
        new ServerRequest('GET', 'http://localhost/es/'),
        yoyoPassthroughHandler(204)
    );

    // Falls straight through to the next handler.
    expect($response->getStatusCode())->toBe(204);
});
