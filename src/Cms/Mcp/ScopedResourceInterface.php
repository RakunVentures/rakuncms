<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp;

/**
 * A resource that is only exposed/readable at or above a given MCP mode, mirroring
 * ScopedToolInterface. Resources without this interface default to Readonly (public).
 */
interface ScopedResourceInterface
{
    public function requiredMode(): McpMode;
}
