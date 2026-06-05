<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Resources;

use Rkn\Cms\Mcp\ResourceInterface;
use Symfony\Component\Yaml\Yaml;

final class SiteInfoResource implements ResourceInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function uri(): string
    {
        return 'rakun://site/info';
    }

    public function name(): string
    {
        return 'Site Info';
    }

    public function description(): string
    {
        return 'Basic RakunCMS site metadata and MCP mode';
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function read(): array
    {
        $config = [];
        $configFile = $this->basePath . '/config/rakun.yaml';
        if (is_file($configFile)) {
            $parsed = Yaml::parseFile($configFile);
            $config = is_array($parsed) ? $parsed : [];
        }

        return [
            'text' => json_encode([
                // base_path (absolute server path) intentionally omitted — info disclosure.
                'site' => $config['site'] ?? [],
                'mcp_mode' => getenv('RAKUN_MCP_MODE') ?: 'readonly',
                'content_driver' => $config['content']['driver'] ?? $config['rakun']['content']['driver'] ?? 'file',
                'index_driver' => $config['index']['driver'] ?? $config['rakun']['index']['driver'] ?? 'php',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }
}

