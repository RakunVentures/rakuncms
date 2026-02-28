<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Resources;

use Rkn\Cms\Mcp\ResourceInterface;

final class GuidelinesResource implements ResourceInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function uri(): string
    {
        return 'rakun://guidelines';
    }

    public function name(): string
    {
        return 'Development Guidelines';
    }

    public function description(): string
    {
        return 'RakunCMS development conventions and directives (directives-zero)';
    }

    public function mimeType(): string
    {
        return 'text/markdown';
    }

    public function read(): array
    {
        // Try directives-zero first, then CLAUDE.md
        $paths = [
            $this->basePath . '/.claude/skills/directives-zero.md',
            $this->basePath . '/CLAUDE.md',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return ['text' => file_get_contents($path)];
            }
        }

        return ['text' => 'No guidelines file found.'];
    }
}
