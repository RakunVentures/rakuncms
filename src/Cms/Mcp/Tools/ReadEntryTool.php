<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\Parser;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Mcp\ToolInterface;

final class ReadEntryTool implements ToolInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function name(): string
    {
        return 'read-entry';
    }

    public function description(): string
    {
        return 'Read a specific content entry: frontmatter, raw markdown, and rendered HTML';
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
                'slug' => [
                    'type' => 'string',
                    'description' => 'Entry slug (required)',
                ],
                'locale' => [
                    'type' => 'string',
                    'description' => 'Locale (defaults to site default_locale)',
                ],
            ],
            'required' => ['collection', 'slug'],
        ];
    }

    public function execute(array $arguments): array
    {
        $collection = $arguments['collection'] ?? '';
        $slug = $arguments['slug'] ?? '';

        if ($collection === '' || $slug === '') {
            return ['error' => 'collection and slug are required'];
        }

        $locale = $arguments['locale'] ?? 'es';

        $indexer = new Indexer($this->basePath);
        $index = $indexer->load();
        $query = new Query($index);
        $entry = $query->findBySlug($collection, $locale, $slug);

        if ($entry === null) {
            return ['error' => "Entry not found: {$collection}/{$slug} ({$locale})"];
        }

        $filePath = $this->basePath . '/' . $entry->file();
        $result = $entry->toArray();

        if (file_exists($filePath)) {
            $result['raw_markdown'] = file_get_contents($filePath);

            $parser = new Parser();
            $parsed = $parser->parse($filePath);
            $result['html'] = $parsed['html'];
        }

        return $result;
    }
}
