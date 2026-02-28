<?php

declare(strict_types=1);

use Rkn\Framework\Router;

test('matches a simple GET route', function () {
    $router = new Router();
    $router->get('/hello', 'hello_handler');

    $result = $router->match('GET', '/hello');

    expect($result)->not->toBeNull();
    expect($result['handler'])->toBe('hello_handler');
    expect($result['vars'])->toBe([]);
});

test('matches route with parameters', function () {
    $router = new Router();
    $router->get('/users/{id}', 'user_handler');

    $result = $router->match('GET', '/users/42');

    expect($result)->not->toBeNull();
    expect($result['handler'])->toBe('user_handler');
    expect($result['vars'])->toBe(['id' => '42']);
});

test('matches catch-all route', function () {
    $router = new Router();
    $router->get('/{path:.*}', 'content_handler');

    $result = $router->match('GET', '/es/blog/my-post');

    expect($result)->not->toBeNull();
    expect($result['handler'])->toBe('content_handler');
    expect($result['vars'])->toBe(['path' => 'es/blog/my-post']);
});

test('returns null for unmatched route', function () {
    $router = new Router();
    $router->get('/hello', 'hello_handler');

    $result = $router->match('GET', '/goodbye');

    expect($result)->toBeNull();
});

test('matches POST routes', function () {
    $router = new Router();
    $router->post('/api/form/{name}', 'form_handler');

    $result = $router->match('POST', '/api/form/contact');

    expect($result)->not->toBeNull();
    expect($result['handler'])->toBe('form_handler');
    expect($result['vars'])->toBe(['name' => 'contact']);
});

test('does not match wrong HTTP method', function () {
    $router = new Router();
    $router->get('/only-get', 'handler');

    $result = $router->match('POST', '/only-get');

    expect($result)->toBeNull();
});
