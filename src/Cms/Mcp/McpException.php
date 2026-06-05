<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp;

final class McpException extends \RuntimeException
{
    public static function invalidParams(string $message): self
    {
        return new self($message, JsonRpcHandler::INVALID_PARAMS);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, JsonRpcHandler::FORBIDDEN);
    }
}

