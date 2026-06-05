<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\McpException;
use Rkn\Cms\Mcp\McpMode;

final class DeleteEntryTool extends AbstractEntryMutationTool
{
    /** Deletion is irreversible (no recycle bin) — require Admin, not just Editor. */
    public function requiredMode(): McpMode
    {
        return McpMode::Admin;
    }

    public function name(): string
    {
        return 'delete-entry';
    }

    public function description(): string
    {
        return 'Delete a content entry from the active RakunCMS content store';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'collection' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'locale' => ['type' => 'string'],
            ],
            'required' => ['collection', 'slug'],
        ];
    }

    public function execute(array $arguments): array
    {
        $collection = $this->requireString($arguments, 'collection');
        $slug = $this->slugify($this->requireString($arguments, 'slug'));
        $locale = $this->optionalString($arguments, 'locale', $this->defaultLocale()) ?? $this->defaultLocale();

        if (!$this->deleteEntry($collection, $locale, $slug)) {
            throw McpException::invalidParams("Entry not found: {$collection}/{$slug} ({$locale})");
        }

        return [
            'ok' => true,
            'action' => 'deleted',
            'entry' => compact('collection', 'locale', 'slug'),
        ];
    }
}

