<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\ToolInterface;

final class ReadTemplateTool implements ToolInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function name(): string
    {
        return 'read-template';
    }

    public function description(): string
    {
        return 'Read the contents of a specific Twig template file';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Template path relative to templates/ (e.g. "_layouts/base.twig")',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $arguments): array
    {
        $path = $arguments['path'] ?? '';
        if ($path === '') {
            return ['error' => 'path is required'];
        }

        // Security: prevent directory traversal
        if (str_contains($path, '..')) {
            return ['error' => 'Invalid path: directory traversal not allowed'];
        }

        $fullPath = $this->basePath . '/templates/' . $path;
        if (!file_exists($fullPath)) {
            return ['error' => 'Template not found: ' . $path];
        }

        return [
            'path' => $path,
            'content' => file_get_contents($fullPath),
            'size' => filesize($fullPath),
        ];
    }
}
