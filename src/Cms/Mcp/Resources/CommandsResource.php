<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Resources;

use Rkn\Cms\Mcp\ResourceInterface;

final class CommandsResource implements ResourceInterface
{
    public function uri(): string
    {
        return 'rakun://commands';
    }

    public function name(): string
    {
        return 'Commands';
    }

    public function description(): string
    {
        return 'MCP admin maintenance command allowlist';
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function read(): array
    {
        return [
            'text' => json_encode([
                'commands' => [
                    'cache:clear',
                    'cache:warmup',
                    'templates:warmup',
                    'index:rebuild',
                    'sitemap:generate',
                    'queue:process',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }
}

