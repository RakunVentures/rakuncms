<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\McpServer;
use Rkn\Cms\Mcp\ToolInterface;
use Rkn\Cms\Mcp\ResourceInterface;
use Rkn\Cms\Mcp\PromptInterface;

function createTestServer(): McpServer
{
    $stdin = fopen('php://memory', 'r+');
    $stdout = fopen('php://memory', 'r+');
    return new McpServer($stdin, $stdout);
}

test('responds to initialize with protocol version and capabilities', function () {
    $server = createTestServer();
    $response = $server->handleLine('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test"}}}');

    $decoded = json_decode($response, true);
    expect($decoded['id'])->toBe(1);
    expect($decoded['result']['protocolVersion'])->toBe('2024-11-05');
    expect($decoded['result']['serverInfo']['name'])->toBe('rakuncms-boost');
    expect($decoded['result']['capabilities'])->toHaveKey('tools');
    expect($decoded['result']['capabilities'])->toHaveKey('resources');
    expect($decoded['result']['capabilities'])->toHaveKey('prompts');
});

test('handles notifications/initialized silently', function () {
    $server = createTestServer();
    $response = $server->handleLine('{"jsonrpc":"2.0","method":"notifications/initialized"}');

    expect($response)->toBeNull();
});

test('responds to ping', function () {
    $server = createTestServer();
    $response = $server->handleLine('{"jsonrpc":"2.0","id":2,"method":"ping"}');

    $decoded = json_decode($response, true);
    expect($decoded['id'])->toBe(2);
    expect($decoded['result'])->toBe([]);
});

test('returns method not found for unknown methods', function () {
    $server = createTestServer();
    $response = $server->handleLine('{"jsonrpc":"2.0","id":3,"method":"unknown/method","params":{}}');

    $decoded = json_decode($response, true);
    expect($decoded['error']['code'])->toBe(-32601);
});

test('lists registered tools', function () {
    $server = createTestServer();

    $tool = new class implements ToolInterface {
        public function name(): string { return 'test-tool'; }
        public function description(): string { return 'A test tool'; }
        public function inputSchema(): array { return ['type' => 'object', 'properties' => new \stdClass()]; }
        public function execute(array $arguments): array { return ['ok' => true]; }
    };

    $server->registerTool($tool);
    $response = $server->handleLine('{"jsonrpc":"2.0","id":4,"method":"tools/list","params":{}}');

    $decoded = json_decode($response, true);
    expect($decoded['result']['tools'])->toHaveCount(1);
    expect($decoded['result']['tools'][0]['name'])->toBe('test-tool');
});

test('calls a registered tool', function () {
    $server = createTestServer();

    $tool = new class implements ToolInterface {
        public function name(): string { return 'echo-tool'; }
        public function description(): string { return 'Echoes input'; }
        public function inputSchema(): array { return ['type' => 'object', 'properties' => new \stdClass()]; }
        public function execute(array $arguments): array { return ['echoed' => $arguments['msg'] ?? 'none']; }
    };

    $server->registerTool($tool);
    $response = $server->handleLine('{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"echo-tool","arguments":{"msg":"hello"}}}');

    $decoded = json_decode($response, true);
    expect($decoded['result']['content'])->toHaveCount(1);
    $text = json_decode($decoded['result']['content'][0]['text'], true);
    expect($text['echoed'])->toBe('hello');
});

test('returns error for unknown tool call', function () {
    $server = createTestServer();
    $response = $server->handleLine('{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"nonexistent"}}');

    $decoded = json_decode($response, true);
    expect($decoded['error']['code'])->toBe(-32602);
});

test('lists registered resources', function () {
    $server = createTestServer();

    $resource = new class implements ResourceInterface {
        public function uri(): string { return 'test://resource'; }
        public function name(): string { return 'Test Resource'; }
        public function description(): string { return 'A test resource'; }
        public function mimeType(): string { return 'text/plain'; }
        public function read(): array { return ['text' => 'hello']; }
    };

    $server->registerResource($resource);
    $response = $server->handleLine('{"jsonrpc":"2.0","id":7,"method":"resources/list","params":{}}');

    $decoded = json_decode($response, true);
    expect($decoded['result']['resources'])->toHaveCount(1);
    expect($decoded['result']['resources'][0]['uri'])->toBe('test://resource');
});

test('reads a registered resource', function () {
    $server = createTestServer();

    $resource = new class implements ResourceInterface {
        public function uri(): string { return 'test://resource'; }
        public function name(): string { return 'Test Resource'; }
        public function description(): string { return 'A test resource'; }
        public function mimeType(): string { return 'text/plain'; }
        public function read(): array { return ['text' => 'resource content']; }
    };

    $server->registerResource($resource);
    $response = $server->handleLine('{"jsonrpc":"2.0","id":8,"method":"resources/read","params":{"uri":"test://resource"}}');

    $decoded = json_decode($response, true);
    expect($decoded['result']['contents'][0]['text'])->toBe('resource content');
});

test('lists registered prompts', function () {
    $server = createTestServer();

    $prompt = new class implements PromptInterface {
        public function name(): string { return 'test-prompt'; }
        public function description(): string { return 'A test prompt'; }
        public function arguments(): array { return [['name' => 'arg1', 'description' => 'First arg', 'required' => true]]; }
        public function get(array $arguments): array { return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'test']]]]; }
    };

    $server->registerPrompt($prompt);
    $response = $server->handleLine('{"jsonrpc":"2.0","id":9,"method":"prompts/list","params":{}}');

    $decoded = json_decode($response, true);
    expect($decoded['result']['prompts'])->toHaveCount(1);
    expect($decoded['result']['prompts'][0]['name'])->toBe('test-prompt');
});

test('gets a registered prompt', function () {
    $server = createTestServer();

    $prompt = new class implements PromptInterface {
        public function name(): string { return 'test-prompt'; }
        public function description(): string { return 'A test prompt'; }
        public function arguments(): array { return []; }
        public function get(array $arguments): array {
            return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Generated prompt']]]];
        }
    };

    $server->registerPrompt($prompt);
    $response = $server->handleLine('{"jsonrpc":"2.0","id":10,"method":"prompts/get","params":{"name":"test-prompt","arguments":{}}}');

    $decoded = json_decode($response, true);
    expect($decoded['result']['messages'])->toHaveCount(1);
});

test('handles invalid JSON gracefully', function () {
    $server = createTestServer();
    $response = $server->handleLine('not valid json');

    $decoded = json_decode($response, true);
    expect($decoded['error']['code'])->toBe(-32700);
});
