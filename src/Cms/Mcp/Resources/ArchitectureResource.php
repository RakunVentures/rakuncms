<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Resources;

use Rkn\Cms\Mcp\ResourceInterface;

final class ArchitectureResource implements ResourceInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function uri(): string
    {
        return 'rakun://architecture';
    }

    public function name(): string
    {
        return 'Architecture Documentation';
    }

    public function description(): string
    {
        return 'RakunCMS architecture overview, stack rationale, and design decisions';
    }

    public function mimeType(): string
    {
        return 'text/markdown';
    }

    public function read(): array
    {
        $path = $this->basePath . '/docs/rakuncms-arquitectura-v2.md';

        if (file_exists($path)) {
            return ['text' => file_get_contents($path)];
        }

        return ['text' => 'Architecture document not found at docs/rakuncms-arquitectura-v2.md'];
    }
}
