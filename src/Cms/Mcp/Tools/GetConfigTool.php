<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\ToolInterface;
use Symfony\Component\Yaml\Yaml;

final class GetConfigTool implements ToolInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function name(): string
    {
        return 'get-config';
    }

    public function description(): string
    {
        return 'Read rakun.yaml configuration. Optionally pass a dot-notation key to get a specific value.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'Dot-notation config key (e.g. "site.default_locale"). Omit to get full config.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $configFile = $this->basePath . '/config/rakun.yaml';

        if (!file_exists($configFile)) {
            return ['error' => 'Config file not found: config/rakun.yaml'];
        }

        $config = Yaml::parseFile($configFile);
        if (!is_array($config)) {
            return ['error' => 'Failed to parse config file'];
        }

        $key = $arguments['key'] ?? null;
        if ($key === null || $key === '') {
            return ['config' => $config];
        }

        $value = $this->dotGet($config, $key);

        return ['key' => $key, 'value' => $value];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dotGet(array $data, string $key): mixed
    {
        $segments = explode('.', $key);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
