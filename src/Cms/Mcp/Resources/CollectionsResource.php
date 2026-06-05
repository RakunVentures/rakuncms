<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Resources;

use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Mcp\ResourceInterface;
use Symfony\Component\Yaml\Yaml;

final class CollectionsResource implements ResourceInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function uri(): string
    {
        return 'rakun://collections';
    }

    public function name(): string
    {
        return 'Collections';
    }

    public function description(): string
    {
        return 'Content collections, counts, and collection config';
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function read(): array
    {
        $index = (new Indexer($this->basePath))->load();
        $collections = [];

        foreach (($index['meta']['collections'] ?? []) as $name) {
            $item = [
                'name' => $name,
                'entry_count' => count($index['indices']['by_collection'][$name] ?? []),
            ];
            $configFile = $this->basePath . '/content/' . $name . '/_collection.yaml';
            if (is_file($configFile)) {
                $config = Yaml::parseFile($configFile);
                if (is_array($config)) {
                    $item['config'] = $config;
                }
            }
            $collections[] = $item;
        }

        return [
            'text' => json_encode(['collections' => $collections], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }
}

