<?php

declare(strict_types=1);

use Rkn\Cms\Middleware\ErrorHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

function createErrorHandler(): ErrorHandler
{
    return new ErrorHandler();
}

function createSuccessHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $factory = new Psr17Factory();
            $body = $factory->createStream('<h1>Hello</h1>');
            return $factory->createResponse(200)->withBody($body)->withHeader('Content-Type', 'text/html');
        }
    };
}

function createThrowingHandler(\Throwable $exception): RequestHandlerInterface
{
    return new class($exception) implements RequestHandlerInterface {
        public function __construct(private \Throwable $exception) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            throw $this->exception;
        }
    };
}

function create404Handler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            throw new \RuntimeException('Unhandled request — no middleware produced a response.');
        }
    };
}

test('passes through successful responses', function () {
    $handler = createErrorHandler();
    $response = $handler->process(new ServerRequest('GET', '/'), createSuccessHandler());

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getBody())->toContain('Hello');
});

test('catches exceptions and returns 500', function () {
    $handler = createErrorHandler();
    $response = $handler->process(
        new ServerRequest('GET', '/'),
        createThrowingHandler(new \Exception('Something broke'))
    );

    expect($response->getStatusCode())->toBe(500);
    expect((string) $response->getBody())->toContain('500');
});

test('returns 404 for unhandled request exceptions', function () {
    $handler = createErrorHandler();
    $response = $handler->process(
        new ServerRequest('GET', '/not-found'),
        create404Handler()
    );

    expect($response->getStatusCode())->toBe(404);
    expect((string) $response->getBody())->toContain('404');
});

test('includes HTML content type in error responses', function () {
    $handler = createErrorHandler();
    $response = $handler->process(
        new ServerRequest('GET', '/'),
        createThrowingHandler(new \Exception('error'))
    );

    expect($response->getHeaderLine('Content-Type'))->toContain('text/html');
});

test('fallback HTML includes Go Home link', function () {
    $handler = createErrorHandler();
    $response = $handler->process(
        new ServerRequest('GET', '/'),
        createThrowingHandler(new \Exception('error'))
    );

    expect((string) $response->getBody())->toContain('Go Home');
});
