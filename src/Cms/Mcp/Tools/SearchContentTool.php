<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Mcp\ToolInterface;

final class SearchContentTool implements ToolInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function name(): string
    {
        return 'search-content';
    }

    public function description(): string
    {
        return 'Full-text search across content entries by title and meta.description';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query (required)',
                ],
                'locale' => [
                    'type' => 'string',
                    'description' => 'Filter by locale',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results (default: 10)',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments): array
    {
        $searchQuery = $arguments['query'] ?? '';
        if ($searchQuery === '') {
            return ['error' => 'query is required'];
        }

        $locale = $arguments['locale'] ?? null;
        $limit = (int) ($arguments['limit'] ?? 10);

        $indexer = new Indexer($this->basePath);
        $index = $indexer->load();

        $search = mb_strtolower(trim($searchQuery));
        $matched = [];

        foreach ($index['entries'] as $entry) {
            if ($locale !== null && ($entry['locale'] ?? '') !== $locale) {
                continue;
            }

            $title = mb_strtolower($entry['title'] ?? '');
            $description = mb_strtolower($entry['meta']['description'] ?? '');

            if (str_contains($title, $search) || str_contains($description, $search)) {
                $matched[] = [
                    'title' => $entry['title'],
                    'slug' => $entry['slug'],
                    'collection' => $entry['collection'],
                    'locale' => $entry['locale'],
                    'file' => $entry['file'],
                ];

                if (count($matched) >= $limit) {
                    break;
                }
            }
        }

        return [
            'query' => $searchQuery,
            'count' => count($matched),
            'results' => $matched,
        ];
    }
}
