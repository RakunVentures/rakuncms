<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\JsonRpcHandler;

test('parses valid JSON-RPC request', function () {
    $handler = new JsonRpcHandler();
    $result = $handler->parseRequest('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');

    expect($result['id'])->toBe(1);
    expect($result['method'])->toBe('initialize');
    expect($result['params'])->toBe([]);
});

test('parses request with params', function () {
    $handler = new JsonRpcHandler();
    $result = $handler->parseRequest('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"project-info"}}');

    expect($result['method'])->toBe('tools/call');
    expect($result['params']['name'])->toBe('project-info');
});

test('parses notification (no id)', function () {
    $handler = new JsonRpcHandler();
    $result = $handler->parseRequest('{"jsonrpc":"2.0","method":"notifications/initialized"}');

    expect($result['id'])->toBeNull();
    expect($result['method'])->toBe('notifications/initialized');
});

test('throws on invalid JSON', function () {
    $handler = new JsonRpcHandler();
    $handler->parseRequest('not-json');
})->throws(\RuntimeException::class, 'Parse error');

test('throws on missing jsonrpc field', function () {
    $handler = new JsonRpcHandler();
    $handler->parseRequest('{"id":1,"method":"test"}');
})->throws(\RuntimeException::class, 'Invalid Request');

test('throws on missing method', function () {
    $handler = new JsonRpcHandler();
    $handler->parseRequest('{"jsonrpc":"2.0","id":1}');
})->throws(\RuntimeException::class, 'Invalid Request');

test('formats result response', function () {
    $handler = new JsonRpcHandler();
    $json = $handler->formatResult(1, ['protocolVersion' => '2024-11-05']);

    $decoded = json_decode($json, true);
    expect($decoded['jsonrpc'])->toBe('2.0');
    expect($decoded['id'])->toBe(1);
    expect($decoded['result']['protocolVersion'])->toBe('2024-11-05');
});

test('formats error response', function () {
    $handler = new JsonRpcHandler();
    $json = $handler->formatError(1, -32601, 'Method not found');

    $decoded = json_decode($json, true);
    expect($decoded['jsonrpc'])->toBe('2.0');
    expect($decoded['id'])->toBe(1);
    expect($decoded['error']['code'])->toBe(-32601);
    expect($decoded['error']['message'])->toBe('Method not found');
});

test('formats error response with null id', function () {
    $handler = new JsonRpcHandler();
    $json = $handler->formatError(null, -32700, 'Parse error');

    $decoded = json_decode($json, true);
    expect($decoded['id'])->toBeNull();
});

test('isNotification returns true for requests without id', function () {
    $handler = new JsonRpcHandler();
    expect($handler->isNotification(['id' => null, 'method' => 'test', 'params' => []]))->toBeTrue();
    expect($handler->isNotification(['id' => 1, 'method' => 'test', 'params' => []]))->toBeFalse();
});
