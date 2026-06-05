<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\McpException;

final class CreateEntryTool extends AbstractEntryMutationTool
{
    public function name(): string
    {
        return 'create-entry';
    }

    public function description(): string
    {
        return 'Create a content entry in the active RakunCMS content store';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'collection' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'locale' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'meta' => ['type' => 'object'],
                'status' => ['type' => 'string', 'enum' => ['published', 'draft', 'scheduled']],
            ],
            'required' => ['collection', 'title'],
        ];
    }

    public function execute(array $arguments): array
    {
        $collection = $this->requireString($arguments, 'collection');
        $title = $this->requireString($arguments, 'title');
        $locale = $this->optionalString($arguments, 'locale', $this->defaultLocale()) ?? $this->defaultLocale();
        $slug = $this->optionalString($arguments, 'slug');
        $slug = $slug !== null ? $this->slugify($slug) : $this->slugify($title);

        if ($this->readEntry($collection, $locale, $slug) !== null) {
            throw McpException::invalidParams("Entry already exists: {$collection}/{$slug} ({$locale})");
        }

        $meta = is_array($arguments['meta'] ?? null) ? $arguments['meta'] : [];
        $status = $this->status($this->optionalString($arguments, 'status', 'draft') ?? 'draft');
        $frontmatter = array_merge(
            ['title' => $title, 'date' => date('Y-m-d H:i:s'), 'status' => $status],
            $meta,
        );
        $frontmatter['title'] = $title;
        $frontmatter['status'] = $status;

        $ref = $this->writeEntry($collection, $locale, $slug, $frontmatter, (string) ($arguments['content'] ?? ''));

        return [
            'ok' => true,
            'action' => 'created',
            'entry' => [
                'collection' => $collection,
                'locale' => $locale,
                'slug' => $slug,
                'title' => $title,
                'status' => $status,
                'file' => $ref->file,
            ],
        ];
    }
}
