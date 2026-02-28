<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Resources;

use Rkn\Cms\Mcp\ResourceInterface;

final class ConfigResource implements ResourceInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function uri(): string
    {
        return 'rakun://config';
    }

    public function name(): string
    {
        return 'Site Configuration';
    }

    public function description(): string
    {
        return 'Raw rakun.yaml configuration file';
    }

    public function mimeType(): string
    {
        return 'text/yaml';
    }

    public function read(): array
    {
        $path = $this->basePath . '/config/rakun.yaml';

        if (file_exists($path)) {
            return ['text' => file_get_contents($path)];
        }

        return ['text' => 'Config file not found at config/rakun.yaml'];
    }
}
