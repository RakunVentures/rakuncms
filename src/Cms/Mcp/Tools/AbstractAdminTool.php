<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\McpException;
use Rkn\Cms\Mcp\McpMode;
use Rkn\Cms\Mcp\ScopedToolInterface;
use Rkn\Cms\Mcp\ToolInterface;

abstract class AbstractAdminTool implements ToolInterface, ScopedToolInterface
{
    public function __construct(protected string $basePath)
    {
    }

    public function requiredMode(): McpMode
    {
        return McpMode::Admin;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function requireString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw McpException::invalidParams("{$key} is required");
        }

        return trim($value);
    }
}
