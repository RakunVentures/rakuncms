<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Mcp\ToolInterface;
use Symfony\Component\Yaml\Yaml;

final class ListCollectionsTool implements ToolInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function name(): string
    {
        return 'list-collections';
    }

    public function description(): string
    {
        return 'List all content collections with entry counts and configuration';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function execute(array $arguments): array
    {
        $indexer = new Indexer($this->basePath);
        $index = $indexer->load();

        $collections = [];
        $collectionNames = $index['meta']['collections'] ?? [];

        foreach ($collectionNames as $name) {
            $entryKeys = $index['indices']['by_collection'][$name] ?? [];
            $collection = [
                'name' => $name,
                'entry_count' => count($entryKeys),
            ];

            // Check for _collection.yaml
            $configFile = $this->basePath . '/content/' . $name . '/_collection.yaml';
            if (file_exists($configFile)) {
                $config = Yaml::parseFile($configFile);
                if (is_array($config)) {
                    $collection['config'] = $config;
                }
            }

            $collections[] = $collection;
        }

        return ['collections' => $collections];
    }
}
