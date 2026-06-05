<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Content\IndexStoreFactory;
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
        return 'List content entries for a collection with optional filters, status, and pagination';
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
                'page' => [
                    'type' => 'integer',
                    'description' => 'Page number when using per_page',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'description' => 'Entries per page when using page',
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
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by status: published, draft, scheduled, or all',
                    'enum' => ['published', 'draft', 'scheduled', 'all'],
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

        $query = new Query(IndexStoreFactory::make($this->basePath));
        $query = $query->collection($collection);

        if (!empty($arguments['locale'])) {
            $query = $query->locale($arguments['locale']);
        }

        if (!empty($arguments['tag'])) {
            $query = $query->where('tags', 'has', $arguments['tag']);
        }

        if (!empty($arguments['status'])) {
            $status = (string) $arguments['status'];
            $query = $status === 'all' ? $query->includeAllStatuses() : $query->withStatus($status);
        }

        if (!empty($arguments['sort'])) {
            $direction = $arguments['direction'] ?? 'asc';
            $query = $query->sort($arguments['sort'], $direction);
        }

        $total = $query->count();
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $perPage = isset($arguments['per_page'])
            ? max(1, min(100, (int) $arguments['per_page']))
            : max(1, min(100, (int) ($arguments['limit'] ?? 50)));
        $query = $query->limit($perPage)->offset(($page - 1) * $perPage);

        $entries = $query->get();

        return [
            'collection' => $collection,
            'count' => count($entries),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int) ceil($total / $perPage),
            ],
            'entries' => array_map(fn ($e) => $e->toArray(), $entries),
        ];
    }
}
