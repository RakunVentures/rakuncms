<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp;

enum McpMode: string
{
    case Readonly = 'readonly';
    case Editor = 'editor';
    case Admin = 'admin';

    public static function fromEnvironment(): self
    {
        $raw = getenv('RAKUN_MCP_MODE');
        if (!is_string($raw) || $raw === '') {
            return self::Readonly;
        }

        return match (strtolower(trim($raw))) {
            'admin' => self::Admin,
            'editor' => self::Editor,
            default => self::Readonly,
        };
    }

    public function allows(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Readonly => 0,
            self::Editor => 10,
            self::Admin => 20,
        };
    }
}

