<?php

declare(strict_types=1);

use Rkn\Cms\Middleware\CsrfProtection;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

function createCsrf(string $secret = 'test-secret', int $lifetime = 3600): CsrfProtection
{
    return new CsrfProtection($secret, $lifetime);
}

function createMockHandler(int $status = 200): RequestHandlerInterface
{
    return new class($status) implements RequestHandlerInterface {
        public function __construct(private int $status) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $factory = new Psr17Factory();
            return $factory->createResponse($this->status);
        }
    };
}

function createRequest(string $method = 'GET', string $path = '/', ?array $body = null): ServerRequestInterface
{
    $request = new ServerRequest($method, 'http://localhost' . $path);

    if ($body !== null) {
        $request = $request->withParsedBody($body);
    }

    return $request;
}

test('GET requests pass through without token', function () {
    $csrf = createCsrf();
    $handler = createMockHandler();

    $response = $csrf->process(createRequest('GET'), $handler);

    expect($response->getStatusCode())->toBe(200);
});

test('POST requests require a valid CSRF token', function () {
    $csrf = createCsrf();
    $handler = createMockHandler();

    $response = $csrf->process(
        createRequest('POST', '/', ['name' => 'test']),
        $handler
    );

    expect($response->getStatusCode())->toBe(403);
});

test('POST requests with valid token pass through', function () {
    $csrf = createCsrf();
    $handler = createMockHandler();

    // Generate a token slightly in the past to bypass temporal check
    $token = (time() - 5) . '.' . hash_hmac('sha256', (string) (time() - 5), 'test-secret');

    $response = $csrf->process(
        createRequest('POST', '/', ['_csrf_token' => $token]),
        $handler
    );

    expect($response->getStatusCode())->toBe(200);
});

test('expired tokens are rejected', function () {
    $csrf = createCsrf('test-secret', 60); // 60 second lifetime

    $handler = createMockHandler();

    // Token from 2 hours ago
    $timestamp = time() - 7200;
    $token = $timestamp . '.' . hash_hmac('sha256', (string) $timestamp, 'test-secret');

    $response = $csrf->process(
        createRequest('POST', '/', ['_csrf_token' => $token]),
        $handler
    );

    expect($response->getStatusCode())->toBe(403);
});

test('honeypot field triggers rejection', function () {
    $csrf = createCsrf();
    $handler = createMockHandler();

    $token = $csrf->generateToken();

    $response = $csrf->process(
        createRequest('POST', '/', [
            '_csrf_token' => $token,
            '_hp_email' => 'bot@spam.com',
        ]),
        $handler
    );

    expect($response->getStatusCode())->toBe(403);
});

test('Yoyo requests are skipped', function () {
    $csrf = createCsrf();
    $handler = createMockHandler();

    $response = $csrf->process(
        createRequest('POST', '/yoyo/update', ['component' => 'search']),
        $handler
    );

    expect($response->getStatusCode())->toBe(200);
});

test('generateToken produces valid format', function () {
    $csrf = createCsrf();
    $token = $csrf->generateToken();

    expect($token)->toContain('.');

    $parts = explode('.', $token);
    expect($parts)->toHaveCount(2);
    expect((int) $parts[0])->toBeGreaterThan(0);
    expect(strlen($parts[1]))->toBe(64); // SHA-256 hex
});

test('validateToken accepts generated tokens', function () {
    $csrf = createCsrf();
    $token = $csrf->generateToken();

    expect($csrf->validateToken($token))->toBeTrue();
});

test('validateToken rejects tampered tokens', function () {
    $csrf = createCsrf();
    $token = $csrf->generateToken();

    // Tamper with the signature
    $parts = explode('.', $token);
    $tampered = $parts[0] . '.invalid_signature';

    expect($csrf->validateToken($tampered))->toBeFalse();
});

test('validateToken rejects tokens from different secret', function () {
    $csrf1 = createCsrf('secret-1');
    $csrf2 = createCsrf('secret-2');

    $token = $csrf1->generateToken();

    expect($csrf2->validateToken($token))->toBeFalse();
});

test('DELETE requests also require CSRF token', function () {
    $csrf = createCsrf();
    $handler = createMockHandler();

    $response = $csrf->process(
        createRequest('DELETE', '/api/resource/1', []),
        $handler
    );

    expect($response->getStatusCode())->toBe(403);
});
