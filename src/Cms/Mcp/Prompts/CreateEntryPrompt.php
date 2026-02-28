<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Prompts;

use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Mcp\PromptInterface;

final class CreateEntryPrompt implements PromptInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function name(): string
    {
        return 'create-entry';
    }

    public function description(): string
    {
        return 'Generate structured instructions for creating a new content entry in a collection';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'collection', 'description' => 'Target collection name', 'required' => true],
            ['name' => 'locale', 'description' => 'Locale for the entry (default: es)', 'required' => false],
            ['name' => 'title', 'description' => 'Title for the new entry', 'required' => false],
        ];
    }

    public function get(array $arguments): array
    {
        $collection = $arguments['collection'] ?? 'pages';
        $locale = $arguments['locale'] ?? 'es';
        $title = $arguments['title'] ?? 'New Entry';
        $slug = $this->slugify($title);

        // Try to get an example entry from this collection
        $example = $this->getExampleEntry($collection, $locale);

        $instructions = "# Create a new entry in the \"{$collection}\" collection\n\n";
        $instructions .= "## File location\n\n";
        $instructions .= "Create: `content/{$collection}/{$slug}.md`\n";

        if ($locale !== 'es') {
            $instructions .= "For locale '{$locale}': `content/{$collection}/{$slug}.{$locale}.md`\n";
        }

        $instructions .= "\n## Required frontmatter\n\n";
        $instructions .= "```yaml\n---\n";
        $instructions .= "title: \"{$title}\"\n";

        if ($collection !== 'pages') {
            $instructions .= "date: \"" . date('Y-m-d') . "\"\n";
        }

        $instructions .= "template: {$collection}/show\n";
        $instructions .= "meta:\n  description: \"Brief description for SEO\"\n";
        $instructions .= "---\n```\n\n";

        $instructions .= "## Content body\n\n";
        $instructions .= "Write Markdown content below the frontmatter. Supports:\n";
        $instructions .= "- GitHub Flavored Markdown (tables, strikethrough, autolinks)\n";
        $instructions .= "- HTML tags (inline allowed)\n";
        $instructions .= "- Standard headings (h2-h6 recommended, h1 is usually the title)\n\n";

        $instructions .= "## Naming conventions\n\n";
        $instructions .= "- Filename = slug (lowercase, hyphens, no spaces)\n";
        $instructions .= "- Order prefix: `01.slug.md` (optional, for manual sorting)\n";
        $instructions .= "- Locale suffix: `slug.en.md` (for non-default locale)\n\n";

        if ($example !== null) {
            $instructions .= "## Example from existing entry\n\n";
            $instructions .= "Based on `{$example['file']}`:\n";
            $instructions .= "- Title: {$example['title']}\n";
            $instructions .= "- Template: " . ($example['template'] ?? 'auto-resolved') . "\n";
            if (!empty($example['meta'])) {
                $instructions .= "- Meta keys: " . implode(', ', array_keys($example['meta'])) . "\n";
            }
        }

        $instructions .= "\n## After creating\n\n";
        $instructions .= "Run `php rakun index:rebuild` to update the content index.\n";

        return [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => $instructions,
                    ],
                ],
            ],
        ];
    }

    private function slugify(string $text): string
    {
        $slug = mb_strtolower($text);
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? $slug;
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        return trim($slug, '-');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getExampleEntry(string $collection, string $locale): ?array
    {
        try {
            $indexer = new Indexer($this->basePath);
            $index = $indexer->load();
            $query = new Query($index);
            $entry = $query->collection($collection)->locale($locale)->first();
            return $entry?->toArray();
        } catch (\Throwable) {
            return null;
        }
    }
}
