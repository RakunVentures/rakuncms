<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Mcp\ToolInterface;

final class ListEntriesTool implements ToolInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function name(): string
    {
        return 'list-entries';
    }

    public function description(): string
    {
        return 'List content entries for a collection with optional filters (locale, tag, sort, limit)';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'collection' => [
                    'type' => 'string',
                    'description' => 'Collection name (required)',
                ],
                'locale' => [
                    'type' => 'string',
                    'description' => 'Filter by locale (e.g. "es", "en")',
                ],
                'tag' => [
                    'type' => 'string',
                    'description' => 'Filter by tag',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max entries to return (default: 50)',
                ],
                'sort' => [
                    'type' => 'string',
                    'description' => 'Sort field (e.g. "date", "order", "title")',
                ],
                'direction' => [
                    'type' => 'string',
                    'description' => 'Sort direction: "asc" or "desc"',
                    'enum' => ['asc', 'desc'],
                ],
            ],
            'required' => ['collection'],
        ];
    }

    public function execute(array $arguments): array
    {
        $collection = $arguments['collection'] ?? '';
        if ($collection === '') {
            return ['error' => 'collection is required'];
        }

        $indexer = new Indexer($this->basePath);
        $index = $indexer->load();
        $query = new Query($index);
        $query = $query->collection($collection);

        if (!empty($arguments['locale'])) {
            $query = $query->locale($arguments['locale']);
        }

        if (!empty($arguments['tag'])) {
            $query = $query->where('tags', 'has', $arguments['tag']);
        }

        if (!empty($arguments['sort'])) {
            $direction = $arguments['direction'] ?? 'asc';
            $query = $query->sort($arguments['sort'], $direction);
        }

        $limit = (int) ($arguments['limit'] ?? 50);
        $query = $query->limit($limit);

        $entries = $query->get();

        return [
            'collection' => $collection,
            'count' => count($entries),
            'entries' => array_map(fn ($e) => $e->toArray(), $entries),
        ];
    }
}
