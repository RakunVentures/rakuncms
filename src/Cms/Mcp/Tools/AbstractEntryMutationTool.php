<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Content\ContentDraft;
use Rkn\Cms\Content\ContentRef;
use Rkn\Cms\Content\ContentStorageFactory;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\IndexStoreFactory;
use Rkn\Cms\Content\Stores\SqliteIndexStore;
use Rkn\Cms\Mcp\McpException;
use Rkn\Cms\Mcp\McpMode;
use Rkn\Cms\Mcp\ScopedToolInterface;
use Rkn\Cms\Mcp\ToolInterface;
use Symfony\Component\Yaml\Yaml;

abstract class AbstractEntryMutationTool implements ToolInterface, ScopedToolInterface
{
    public function __construct(protected string $basePath)
    {
    }

    public function requiredMode(): McpMode
    {
        return McpMode::Editor;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function requireString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw McpException::invalidParams("{$key} is required");
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function optionalString(array $arguments, string $key, ?string $default = null): ?string
    {
        $value = $arguments[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }

        return is_string($value) ? trim($value) : $default;
    }

    protected function defaultLocale(): string
    {
        try {
            $locale = \config('site.default_locale') ?? \config('rakun.site.default_locale');
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        } catch (\Throwable) {
        }

        $configFile = $this->basePath . '/config/rakun.yaml';
        if (is_file($configFile)) {
            $config = Yaml::parseFile($configFile);
            if (is_array($config) && is_string($config['site']['default_locale'] ?? null)) {
                return (string) $config['site']['default_locale'];
            }
        }

        return 'en';
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    protected function writeEntry(string $collection, string $locale, string $slug, array $frontmatter, string $body): ContentRef
    {
        $collection = $this->safeCollection($collection);
        $locale     = $this->safeLocale($locale);

        $ref = ContentStorageFactory::make($this->basePath)
            ->write(new ContentDraft($collection, $locale, $slug, $frontmatter, $body));

        $this->refreshIndex();

        return $ref;
    }

    /**
     * @return array{frontmatter: array<string, mixed>, body: string, file: string}|null
     */
    protected function readEntry(string $collection, string $locale, string $slug): ?array
    {
        $collection = $this->safeCollection($collection);
        $locale     = $this->safeLocale($locale);

        $entry = ContentStorageFactory::make($this->basePath)->read($collection, $locale, $slug);
        if ($entry === null) {
            return null;
        }

        return [
            'frontmatter' => $entry->frontmatter,
            'body' => $entry->body,
            'file' => $entry->file,
        ];
    }

    protected function deleteEntry(string $collection, string $locale, string $slug): bool
    {
        $collection = $this->safeCollection($collection);
        $locale     = $this->safeLocale($locale);

        $deleted = ContentStorageFactory::make($this->basePath)->delete($collection, $locale, $slug);
        if ($deleted) {
            $this->refreshIndex();
        }

        return $deleted;
    }

    protected function refreshIndex(): void
    {
        try {
            $store = IndexStoreFactory::make($this->basePath);
            if ($store instanceof SqliteIndexStore) {
                $store->sync();
            } else {
                (new Indexer($this->basePath))->rebuild();
            }
        } catch (\Throwable) {
            // Explicit index rebuild remains available via MCP/admin command.
        }
    }

    protected function slugify(string $value): string
    {
        $slug = mb_strtolower(trim($value));
        $slug = strtr($slug, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ñ' => 'n', 'ü' => 'u',
        ]);
        $slug = preg_replace('/[^a-z0-9\/_-]+/', '-', $slug) ?? '';
        $slug = preg_replace('#/+#', '/', $slug) ?? '';
        $slug = trim($slug, '-/');

        if ($slug === '' || str_contains($slug, '..')) {
            throw McpException::invalidParams('slug is invalid');
        }

        return $slug;
    }

    /** Collection name: a single safe segment (no path separators / traversal). */
    protected function safeCollection(string $value): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $value)) {
            throw McpException::invalidParams('collection is invalid');
        }

        return $value;
    }

    /** Locale code (BCP-47-ish); never a filesystem path component with traversal. */
    protected function safeLocale(string $value): string
    {
        if (!preg_match('/^[a-z]{2}(-[a-z]{2,4})?$/i', $value)) {
            throw McpException::invalidParams('locale is invalid');
        }

        return $value;
    }

    protected function status(string $value): string
    {
        $status = strtolower(trim($value));
        if (!in_array($status, ['published', 'draft', 'scheduled'], true)) {
            throw McpException::invalidParams('status must be one of: published, draft, scheduled');
        }

        return $status;
    }
}
