<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\McpException;

class UpdateEntryTool extends AbstractEntryMutationTool
{
    public function name(): string
    {
        return 'update-entry';
    }

    public function description(): string
    {
        return 'Update an existing content entry while preserving omitted fields';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'collection' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'locale' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'meta' => ['type' => 'object'],
                'status' => ['type' => 'string', 'enum' => ['published', 'draft', 'scheduled']],
            ],
            'required' => ['collection', 'slug'],
        ];
    }

    public function execute(array $arguments): array
    {
        $collection = $this->requireString($arguments, 'collection');
        $slug = $this->slugify($this->requireString($arguments, 'slug'));
        $locale = $this->optionalString($arguments, 'locale', $this->defaultLocale()) ?? $this->defaultLocale();
        $existing = $this->readEntry($collection, $locale, $slug);

        if ($existing === null) {
            throw McpException::invalidParams("Entry not found: {$collection}/{$slug} ({$locale})");
        }

        $frontmatter = $existing['frontmatter'];

        // Merge free-form meta FIRST, with protected keys stripped — so meta cannot
        // smuggle an unvalidated `status`/`title` past the dedicated validated fields.
        if (is_array($arguments['meta'] ?? null)) {
            $meta = $arguments['meta'];
            unset($meta['status'], $meta['title']);
            $frontmatter = array_replace_recursive($frontmatter, $meta);
        }
        if (array_key_exists('title', $arguments)) {
            $frontmatter['title'] = $this->requireString($arguments, 'title');
        }
        if (array_key_exists('status', $arguments)) {
            $frontmatter['status'] = $this->status($this->requireString($arguments, 'status'));
        }

        $body = array_key_exists('content', $arguments) ? (string) $arguments['content'] : $existing['body'];
        $ref = $this->writeEntry($collection, $locale, $slug, $frontmatter, $body);

        return [
            'ok' => true,
            'action' => 'updated',
            'entry' => [
                'collection' => $collection,
                'locale' => $locale,
                'slug' => $slug,
                'title' => $frontmatter['title'] ?? $slug,
                'status' => $frontmatter['status'] ?? 'published',
                'file' => $ref->file,
            ],
        ];
    }
}
