<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Framework\Dispatcher;

test('dispatches through middleware pipeline', function () {
    $middleware = new class implements MiddlewareInterface {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            return new Response(200, [], 'Hello from middleware');
        }
    };

    $dispatcher = new Dispatcher([$middleware]);
    $request = new ServerRequest('GET', '/');

    $response = $dispatcher->handle($request);

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getBody())->toBe('Hello from middleware');
});

test('middleware can delegate to next', function () {
    $first = new class implements MiddlewareInterface {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $request = $request->withAttribute('first', true);
            return $handler->handle($request);
        }
    };

    $second = new class implements MiddlewareInterface {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $hasFirst = $request->getAttribute('first', false);
            return new Response(200, [], $hasFirst ? 'chained' : 'not chained');
        }
    };

    $dispatcher = new Dispatcher([$first, $second]);
    $response = $dispatcher->handle(new ServerRequest('GET', '/'));

    expect((string) $response->getBody())->toBe('chained');
});

test('throws when no middleware handles request', function () {
    $passthrough = new class implements MiddlewareInterface {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            return $handler->handle($request);
        }
    };

    $dispatcher = new Dispatcher([$passthrough]);
    $dispatcher->handle(new ServerRequest('GET', '/'));
})->throws(RuntimeException::class, 'Unhandled request');
