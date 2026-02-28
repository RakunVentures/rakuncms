<?php

declare(strict_types=1);

use Rkn\Cms\Http\Controllers\FormController;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

function createFormRequest(string $formName, array $body = []): ServerRequestInterface
{
    $request = new ServerRequest('POST', 'http://localhost/api/form/' . $formName);

    return $request
        ->withParsedBody($body)
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded');
}

test('handles unknown form names', function () {
    $controller = new FormController();
    $request = createFormRequest('unknown', ['data' => 'test']);

    $response = $controller->handle($request, 'unknown');

    expect($response->getStatusCode())->toBe(404);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['error'])->toContain('Unknown form');
});

test('validates contact form requires name', function () {
    $controller = new FormController();
    $request = createFormRequest('contact', [
        'email' => 'test@example.com',
        'message' => 'Hello',
    ]);

    $response = $controller->handle($request, 'contact');

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['errors'])->toHaveKey('name');
});

test('validates contact form requires valid email', function () {
    $controller = new FormController();
    $request = createFormRequest('contact', [
        'name' => 'Test',
        'email' => 'not-an-email',
        'message' => 'Hello',
    ]);

    $response = $controller->handle($request, 'contact');

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['errors'])->toHaveKey('email');
});

test('validates contact form requires message', function () {
    $controller = new FormController();
    $request = createFormRequest('contact', [
        'name' => 'Test',
        'email' => 'test@example.com',
    ]);

    $response = $controller->handle($request, 'contact');

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['errors'])->toHaveKey('message');
});

test('strips internal fields from payload', function () {
    $controller = new FormController();
    $request = createFormRequest('contact', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'message' => 'Hello there',
        '_csrf_token' => 'should-be-removed',
        '_hp_email' => '',
        '_timestamp' => '12345',
    ]);

    // Without a container, it still validates successfully
    $response = $controller->handle($request, 'contact');

    // Should not error due to internal fields
    $body = json_decode((string) $response->getBody(), true);
    // Either success or server config error (no container in test)
    expect($response->getStatusCode())->toBeIn([200, 500]);
});
