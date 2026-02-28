<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp;

final class McpServer
{
    private JsonRpcHandler $handler;

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /** @var array<string, ResourceInterface> */
    private array $resources = [];

    /** @var array<string, PromptInterface> */
    private array $prompts = [];

    /** @phpstan-ignore-next-line Property tracked for protocol state, read in future extensions */
    private bool $initialized = false;

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    /**
     * @param resource|null $stdin
     * @param resource|null $stdout
     */
    public function __construct($stdin = null, $stdout = null)
    {
        $this->handler = new JsonRpcHandler();
        $this->stdin = $stdin ?? STDIN;
        $this->stdout = $stdout ?? STDOUT;
    }

    public function registerTool(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function registerResource(ResourceInterface $resource): void
    {
        $this->resources[$resource->uri()] = $resource;
    }

    public function registerPrompt(PromptInterface $prompt): void
    {
        $this->prompts[$prompt->name()] = $prompt;
    }

    /**
     * Main stdio loop. Reads JSON-RPC lines from stdin, dispatches, writes responses.
     */
    public function run(): void
    {
        while (($line = fgets($this->stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $response = $this->handleLine($line);
            if ($response !== null) {
                fwrite($this->stdout, $response . "\n");
                fflush($this->stdout);
            }
        }
    }

    /**
     * Handle a single JSON-RPC line and return the response (or null for notifications).
     */
    public function handleLine(string $line): ?string
    {
        try {
            $request = $this->handler->parseRequest($line);
        } catch (\RuntimeException $e) {
            return $this->handler->formatError(null, $e->getCode(), $e->getMessage());
        }

        if ($this->handler->isNotification($request)) {
            $this->handleNotification($request['method'], $request['params']);
            return null;
        }

        return $this->dispatch($request['id'], $request['method'], $request['params']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleNotification(string $method, array $params): void
    {
        if ($method === 'notifications/initialized') {
            $this->initialized = true;
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dispatch(int|string $id, string $method, array $params): string
    {
        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params),
                'ping' => new \stdClass(),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($params),
                'resources/list' => $this->handleResourcesList(),
                'resources/read' => $this->handleResourcesRead($params),
                'prompts/list' => $this->handlePromptsList(),
                'prompts/get' => $this->handlePromptsGet($params),
                default => throw new \RuntimeException('Method not found: ' . $method, JsonRpcHandler::METHOD_NOT_FOUND),
            };

            $resultArray = ($result instanceof \stdClass) ? (array) $result : $result;

            return $this->handler->formatResult($id, $resultArray);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: JsonRpcHandler::INTERNAL_ERROR;
            return $this->handler->formatError($id, $code, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => new \stdClass(),
                'resources' => new \stdClass(),
                'prompts' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => 'rakuncms-boost',
                'version' => '0.1.0',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleToolsList(): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }
        return ['tools' => $tools];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$name])) {
            throw new \RuntimeException('Tool not found: ' . $name, JsonRpcHandler::INVALID_PARAMS);
        }

        $result = $this->tools[$name]->execute(is_array($arguments) ? $arguments : []);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResourcesList(): array
    {
        $resources = [];
        foreach ($this->resources as $resource) {
            $resources[] = [
                'uri' => $resource->uri(),
                'name' => $resource->name(),
                'description' => $resource->description(),
                'mimeType' => $resource->mimeType(),
            ];
        }
        return ['resources' => $resources];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleResourcesRead(array $params): array
    {
        $uri = $params['uri'] ?? '';

        if (!isset($this->resources[$uri])) {
            throw new \RuntimeException('Resource not found: ' . $uri, JsonRpcHandler::INVALID_PARAMS);
        }

        $resource = $this->resources[$uri];
        $data = $resource->read();

        return [
            'contents' => [
                [
                    'uri' => $resource->uri(),
                    'mimeType' => $resource->mimeType(),
                    'text' => $data['text'] ?? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handlePromptsList(): array
    {
        $prompts = [];
        foreach ($this->prompts as $prompt) {
            $prompts[] = [
                'name' => $prompt->name(),
                'description' => $prompt->description(),
                'arguments' => $prompt->arguments(),
            ];
        }
        return ['prompts' => $prompts];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handlePromptsGet(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->prompts[$name])) {
            throw new \RuntimeException('Prompt not found: ' . $name, JsonRpcHandler::INVALID_PARAMS);
        }

        return $this->prompts[$name]->get(is_array($arguments) ? $arguments : []);
    }
}
