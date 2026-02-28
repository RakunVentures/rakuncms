<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp;

final class JsonRpcHandler
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /**
     * Parse a JSON-RPC 2.0 request string.
     *
     * @return array{id: int|string|null, method: string, params: array<string, mixed>}
     * @throws \RuntimeException on parse or validation error
     */
    public function parseRequest(string $raw): array
    {
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Parse error', self::PARSE_ERROR);
        }

        if (($data['jsonrpc'] ?? null) !== '2.0') {
            throw new \RuntimeException('Invalid Request: missing jsonrpc 2.0', self::INVALID_REQUEST);
        }

        $method = $data['method'] ?? null;
        if (!is_string($method) || $method === '') {
            throw new \RuntimeException('Invalid Request: missing method', self::INVALID_REQUEST);
        }

        return [
            'id' => $data['id'] ?? null,
            'method' => $method,
            'params' => is_array($data['params'] ?? null) ? $data['params'] : [],
        ];
    }

    /**
     * Format a successful JSON-RPC 2.0 response.
     *
     * @param array<string, mixed> $result
     */
    public function formatResult(int|string $id, array $result): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Format a JSON-RPC 2.0 error response.
     */
    public function formatError(int|string|null $id, int $code, string $message): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Check if a parsed request is a notification (no id).
     *
     * @param array{id: int|string|null, method: string, params: array<string, mixed>} $request
     */
    public function isNotification(array $request): bool
    {
        return $request['id'] === null;
    }
}
