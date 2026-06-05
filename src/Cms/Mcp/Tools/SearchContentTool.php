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
        return 'Search content entries by title and meta.description with filters and matching modes';
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
                'collection' => [
                    'type' => 'string',
                    'description' => 'Filter by collection',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by status',
                    'enum' => ['published', 'draft', 'scheduled', 'all'],
                ],
                'tag' => [
                    'type' => 'string',
                    'description' => 'Filter by tag',
                ],
                'mode' => [
                    'type' => 'string',
                    'description' => 'Match mode: contains, exact, or fuzzy',
                    'enum' => ['contains', 'exact', 'fuzzy'],
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
        $collection = $arguments['collection'] ?? null;
        $status = $arguments['status'] ?? null;
        $tag = $arguments['tag'] ?? null;
        $mode = is_string($arguments['mode'] ?? null) ? $arguments['mode'] : 'contains';
        $limit = (int) ($arguments['limit'] ?? 10);

        $indexer = new Indexer($this->basePath);
        $index = $indexer->load();

        $search = mb_strtolower(trim($searchQuery));
        $matched = [];

        foreach ($index['entries'] as $entry) {
            if ($locale !== null && ($entry['locale'] ?? '') !== $locale) {
                continue;
            }
            if ($collection !== null && ($entry['collection'] ?? '') !== $collection) {
                continue;
            }
            if ($status !== null && $status !== 'all' && ($entry['status'] ?? 'published') !== $status) {
                continue;
            }
            if ($tag !== null && (!is_array($entry['tags'] ?? null) || !in_array($tag, $entry['tags'], true))) {
                continue;
            }

            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $title = mb_strtolower((string) ($entry['title'] ?? ''));
            $description = mb_strtolower((string) ($meta['description'] ?? ''));
            $haystack = trim($title . ' ' . $description);

            $score = $this->score($haystack, $title, $description, $search, $mode);
            if ($score > 0) {
                $matched[] = [
                    'title' => $entry['title'],
                    'slug' => $entry['slug'],
                    'collection' => $entry['collection'],
                    'locale' => $entry['locale'],
                    'status' => $entry['status'] ?? 'published',
                    'file' => $entry['file'],
                    'score' => $score,
                    'snippet' => $meta['description'] ?? $entry['title'] ?? '',
                ];
            }
        }

        usort($matched, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $matched = array_slice($matched, 0, max(1, $limit));

        return [
            'query' => $searchQuery,
            'mode' => $mode,
            'count' => count($matched),
            'results' => $matched,
        ];
    }

    private function score(string $haystack, string $title, string $description, string $search, string $mode): int
    {
        if ($mode === 'exact') {
            return ($title === $search || $description === $search) ? 100 : 0;
        }

        if (str_contains($haystack, $search)) {
            return $mode === 'fuzzy' ? 90 : 100;
        }

        if ($mode !== 'fuzzy') {
            return 0;
        }

        foreach (preg_split('/\s+/', $haystack) ?: [] as $word) {
            if ($word !== '' && levenshtein($search, $word) <= max(1, (int) floor(strlen($search) / 4))) {
                return 60;
            }
        }

        return 0;
    }
}
