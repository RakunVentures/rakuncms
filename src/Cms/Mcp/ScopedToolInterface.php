<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp;

interface ScopedToolInterface
{
    public function requiredMode(): McpMode;
}

