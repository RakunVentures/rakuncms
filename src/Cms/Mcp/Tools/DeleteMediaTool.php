<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\McpException;

final class DeleteMediaTool extends AbstractMediaTool
{
    public function name(): string
    {
        return 'delete-media';
    }

    public function description(): string
    {
        return 'Delete a file under public/assets by relative path';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $arguments): array
    {
        $path = ltrim($this->requireString($arguments, 'path'), '/');
        if (str_contains($path, '..')) {
            throw McpException::invalidParams('Invalid media path');
        }
        $path = str_starts_with($path, 'assets/') ? $path : 'assets/' . $path;

        // Confine deletion to public/assets/ only — never the rest of public/
        // (front controller, .htaccess, etc.). Trailing separator blocks sibling-
        // prefix bypasses (public/assets-evil).
        $realBase   = realpath($this->assetsDir()) ?: '';
        $realTarget = realpath($this->basePath . '/public/' . $path);
        if ($realTarget === false || $realBase === '' || !str_starts_with($realTarget, $realBase . DIRECTORY_SEPARATOR)) {
            throw McpException::invalidParams('Invalid media path');
        }

        if (!is_file($realTarget)) {
            throw McpException::invalidParams('Media file not found');
        }

        unlink($realTarget);

        return ['ok' => true, 'action' => 'deleted', 'path' => $path];
    }
}

