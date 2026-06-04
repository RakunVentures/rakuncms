<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rkn\Cms\Content\ContentDraft;
use Rkn\Cms\Content\ContentStorageFactory;
use Rkn\Cms\Content\Entry;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\IndexStoreFactory;
use Rkn\Cms\Content\Query;

final class ContentApiController
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function showConfig(): ResponseInterface
    {
        return $this->json(200, $this->redactSecrets($this->fullConfig()));
    }

    /**
     * Collection/field schema for building dynamic admin forms. Exposes only
     * structural info (no secrets), from either config layout (top-level
     * `collections` or monolithic `rakun.collections`).
     */
    public function schema(): ResponseInterface
    {
        $config = $this->fullConfig();
        $collections = $config['collections'] ?? ($config['rakun']['collections'] ?? []);
        if (!is_array($collections)) {
            $collections = [];
        }

        $out = [];
        foreach ($collections as $slug => $def) {
            if (!is_array($def)) {
                continue;
            }
            $out[] = [
                'slug'             => is_string($slug) ? $slug : (string) ($def['id'] ?? ''),
                'name'             => $def['name'] ?? (is_string($slug) ? $slug : ''),
                'chronological'    => (bool) ($def['chronological'] ?? false),
                'default_template' => $def['default_template'] ?? null,
                'active'           => (bool) ($def['active'] ?? true),
                'fields'           => is_array($def['fields'] ?? null) ? $def['fields'] : [],
            ];
        }

        return $this->json(200, ['data' => $out]);
    }

    /**
     * The full merged config array (or [] outside a booted app).
     *
     * @return array<array-key, mixed>
     */
    private function fullConfig(): array
    {
        try {
            $config = \app('config');
        } catch (\Throwable) {
            return [];
        }

        return is_array($config) ? $config : [];
    }

    /**
     * Strip secrets before exposing config over the API: drops any `api.keys`
     * list (live API keys) at any depth and redacts password/secret/token
     * scalar fields. Recursive.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<array-key, mixed>
     */
    private function redactSecrets(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                if ($key === 'api' && array_key_exists('keys', $value)) {
                    unset($value['keys']);
                }
                $config[$key] = $this->redactSecrets($value);
            } elseif (is_string($key) && preg_match('/(password|secret|token)/i', $key) === 1) {
                $config[$key] = '***';
            }
        }

        return $config;
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $params     = $request->getQueryParams();
        $collection = isset($params['collection']) && $params['collection'] !== '' ? (string) $params['collection'] : null;
        $locale     = isset($params['locale']) && $params['locale'] !== '' ? (string) $params['locale'] : null;
        $search     = isset($params['q']) ? trim((string) $params['q']) : '';
        $sortField  = isset($params['sort']) ? (string) $params['sort'] : '';
        $page       = max(1, (int) ($params['page'] ?? 1));
        $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 20)));
        $offset     = ($page - 1) * $perPage;
        $status     = $this->parseStatusParam($params['status'] ?? null);

        $store = IndexStoreFactory::make($this->basePath);

        // No collection filter: full enumeration via the index (admin overview).
        // Constant-memory stream, sliced in PHP for the requested page.
        // Pass the resolved status to each() so the admin can see drafts too.
        if ($collection === null) {
            $eachStatus = $status === 'published' ? null : $status;
            $rows = [];
            foreach ($store->each($eachStatus) as $row) {
                $rows[] = $row;
            }
            $total = count($rows);
            $data  = array_map(
                fn (array $row): array => $this->rowSummary($row),
                array_slice($rows, $offset, $perPage),
            );

            return $this->json(200, ['data' => $data, 'meta' => $this->pageMeta($total, $page, $perPage)]);
        }

        $base = (new Query($store))->collection($collection);
        if ($locale !== null) {
            $base = $base->locale($locale);
        }
        if ($sortField !== '') {
            $direction = strtolower((string) ($params['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $base = $base->sort($sortField, $direction);
        }

        // Apply the status filter (default published-only; admin opts in via ?status=).
        $base = $status === 'all'
            ? $base->includeAllStatuses()
            : $base->withStatus($status);

        if ($search !== '') {
            // Title search. Filtered total requires materialising the matched
            // set (fine for the admin's low traffic); meta.total reflects matches.
            $matched = $base->where('title', 'contains', $search)->get();
            $total   = count($matched);
            $data    = array_map(
                fn (Entry $e): array => $this->serializeEntry($e),
                array_slice($matched, $offset, $perPage),
            );

            return $this->json(200, ['data' => $data, 'meta' => $this->pageMeta($total, $page, $perPage)]);
        }

        $total   = $base->count();
        $entries = $base->limit($perPage)->offset($offset)->get();
        $data    = array_map(fn (Entry $e): array => $this->serializeEntry($e), $entries);

        return $this->json(200, ['data' => $data, 'meta' => $this->pageMeta($total, $page, $perPage)]);
    }

    /**
     * Parse the ?status query param to a canonical value.
     * Accepted: 'published', 'draft', 'scheduled', 'all'.
     * Default (null or invalid) → 'published' (safe fail-closed).
     */
    private function parseStatusParam(mixed $raw): string
    {
        if (!is_string($raw) || $raw === '') {
            return 'published';
        }
        return match (strtolower($raw)) {
            'all'       => 'all',
            'draft'     => 'draft',
            'scheduled' => 'scheduled',
            default     => 'published',
        };
    }

    /**
     * Summary row for the list endpoint, from a raw index row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowSummary(array $row): array
    {
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];

        // Prefer the pre-computed index 'status' column (set during ContentScanner::indexFile,
        // which includes date-based scheduling logic). Fall back to normalizeStatus() for
        // older or manually-constructed index rows that lack the column.
        $storedStatus = isset($row['status']) && is_string($row['status']) && $row['status'] !== ''
            ? $row['status']
            : null;
        $status = $storedStatus ?? $this->normalizeStatus($meta, (bool) ($row['draft'] ?? false));

        return [
            'title'      => $row['title'] ?? ($row['slug'] ?? ''),
            'slug'       => $row['slug'] ?? '',
            'collection' => $row['collection'] ?? '',
            'locale'     => $row['locale'] ?? null,
            'status'     => $status,
            'date'       => $row['date'] ?? null,
            'meta'       => $meta,
        ];
    }

    /**
     * Normalize an entry's publication status to a stable machine value:
     * published | draft | scheduled. Honors frontmatter `status` (incl. the
     * WordPress `publish`/`future` vocabulary) and the `draft` flag.
     *
     * @param  array<string, mixed>  $meta
     */
    private function normalizeStatus(array $meta, bool $draft = false): string
    {
        $raw = $meta['status'] ?? null;
        if (is_string($raw) && $raw !== '') {
            return match (strtolower($raw)) {
                'publish', 'published'        => 'published',
                'draft'                       => 'draft',
                'future', 'scheduled', 'pending' => 'scheduled',
                default                       => strtolower($raw),
            };
        }

        if ($draft || !empty($meta['draft'])) {
            return 'draft';
        }

        return 'published';
    }

    /**
     * @return array{total: int, page: int, per_page: int, pages: int}
     */
    private function pageMeta(int $total, int $page, int $perPage): array
    {
        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    /**
     * @param string|null $status Narrow to a specific status. null (default) → all statuses
     *                            (admin preview endpoint; existing callers are unaffected).
     *                            Accepted values: 'published', 'draft', 'scheduled'.
     */
    public function show(string $collection, string $slug, bool $raw = false, ?string $status = null): ResponseInterface
    {
        $query = (new Query(IndexStoreFactory::make($this->basePath)))->collection($collection);

        // Default: include all statuses (admin preview endpoint).
        // Callers can pass a specific status to narrow (e.g. published-only public routes).
        if ($status !== null) {
            $query = $query->withStatus($status);
        } else {
            $query = $query->includeAllStatuses();
        }

        $entries = $query->where('slug', '=', $slug)->get();
        $entry   = $entries[0] ?? null;

        if ($entry === null) {
            return $this->json(404, ['error' => "Entry '{$slug}' not found"]);
        }

        $data = $this->serializeEntry($entry);
        // `?raw=1` returns the raw Markdown body (for editors); otherwise rendered HTML.
        $data['content'] = $raw ? $this->rawBody($entry->file()) : $entry->content();

        return $this->json(200, ['data' => $data]);
    }

    /**
     * Raw Markdown body (frontmatter stripped) read from the entry's `.md` file.
     */
    private function rawBody(string $relativeFile): string
    {
        if ($relativeFile === '') {
            return '';
        }
        $path = $this->basePath . '/' . ltrim($relativeFile, '/');
        if (!is_file($path)) {
            return '';
        }

        $raw   = (string) file_get_contents($path);
        $parts = explode('---', $raw, 3);

        return count($parts) >= 3 ? ltrim($parts[2], "\n") : $raw;
    }

    /**
     * Update an existing entry: merge incoming title/meta/content into the
     * file's frontmatter+body, preserving its locale and any fields not sent.
     * The `.md` file remains the write target; the active index is refreshed.
     * (PUT routes to here from both the native and WP-compat dispatchers.)
     */
    public function update(ServerRequestInterface $request, string $collection, string $slug): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->json(400, ['error' => 'Invalid JSON body']);
        }

        $locale  = (string) ($body['locale'] ?? $this->defaultLocale());
        $storage = ContentStorageFactory::make($this->basePath);

        $existing = $storage->read($collection, $locale, $slug);
        if ($existing === null) {
            return $this->json(404, ['error' => "Entry '{$slug}' not found in '{$collection}'"]);
        }

        $meta = $existing->frontmatter;
        if (array_key_exists('title', $body)) {
            $meta['title'] = (string) $body['title'];
        }
        if (isset($body['meta']) && is_array($body['meta'])) {
            $meta = array_merge($meta, $body['meta']);
        }
        $content = array_key_exists('content', $body) ? (string) $body['content'] : $existing->body;

        $storage->write(new ContentDraft($collection, $locale, $slug, $meta, $content));
        $this->refreshIndex();

        return $this->json(200, [
            'data' => [
                'title'      => $meta['title'] ?? $slug,
                'slug'       => $slug,
                'collection' => $collection,
                'locale'     => $locale,
                'meta'       => $meta,
            ],
            'message' => 'Updated',
        ]);
    }

    public function create(ServerRequestInterface $request, string $collection): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->json(400, ['error' => 'Invalid JSON body']);
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return $this->json(422, ['error' => 'Title is required']);
        }

        $locale  = (string) ($body['locale'] ?? $this->defaultLocale());
        $slug    = (string) ($body['slug'] ?? $this->slugify($title));
        $meta    = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $content = (string) ($body['content'] ?? '');

        $storage = ContentStorageFactory::make($this->basePath);
        if ($storage->read($collection, $locale, $slug) !== null) {
            return $this->json(409, ['error' => "Entry '{$slug}' already exists in '{$collection}'"]);
        }

        $frontmatter = array_merge(
            ['title' => $title, 'date' => date('Y-m-d H:i:s')],
            $meta,
        );

        $storage->write(new ContentDraft($collection, $locale, $slug, $frontmatter, $content));
        $this->refreshIndex();

        return $this->json(201, [
            'data' => [
                'title'      => $title,
                'slug'       => $slug,
                'collection' => $collection,
                'locale'     => $locale,
                'meta'       => $meta,
            ],
            'message' => 'Created',
        ]);
    }

    public function delete(string $collection, string $slug): ResponseInterface
    {
        $dir = $this->basePath . '/content/' . $collection;

        $matched = glob("{$dir}/{$slug}.*.md") ?: [];
        $fallback = "{$dir}/{$slug}.md";
        if (is_file($fallback)) {
            $matched[] = $fallback;
        }

        if (empty($matched)) {
            return $this->json(404, ['error' => "Entry '{$slug}' not found in '{$collection}'"]);
        }

        $storage = ContentStorageFactory::make($this->basePath);
        foreach ($matched as $file) {
            $segments = explode('.', basename($file, '.md'));
            $locale = (count($segments) >= 2 && strlen((string) end($segments)) === 2)
                ? (string) end($segments)
                : $this->defaultLocale();
            $storage->delete($collection, $locale, $slug);
        }
        $this->refreshIndex();

        return $this->json(200, ['message' => 'Deleted']);
    }

    /**
     * Site default locale (or 'en' when the app isn't booted, e.g. unit tests).
     */
    private function defaultLocale(): string
    {
        try {
            $locale = \config('site.default_locale') ?? \config('rakun.site.default_locale');
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        } catch (\Throwable) {
            // Application not initialised — fall through to the safe default.
        }

        return 'en';
    }

    /**
     * Refresh the active content index after a write so changes are visible
     * immediately. SQLite sync is incremental (only the touched file); the PHP
     * driver rebuilds. Best-effort — the index/rebuild endpoint also refreshes.
     */
    private function refreshIndex(): void
    {
        try {
            $store = IndexStoreFactory::make($this->basePath);
            if ($store instanceof \Rkn\Cms\Content\Stores\SqliteIndexStore) {
                $store->sync();
            } else {
                (new Indexer($this->basePath))->rebuild();
            }
        } catch (\Throwable) {
            // ignore; explicit rebuild still available
        }
    }

    public function collections(): ResponseInterface
    {
        $data = [];
        foreach ($this->discoverCollections() as $name) {
            $dir   = $this->basePath . '/content/' . $name;
            $count = 0;
            if (is_dir($dir)) {
                foreach (new \DirectoryIterator($dir) as $f) {
                    if ($f->getExtension() === 'md') {
                        $count++;
                    }
                }
            }
            $data[] = [
                'id'          => $name,
                'name'        => $name,
                'entry_count' => $count,
            ];
        }
        return $this->json(200, ['data' => $data]);
    }

    /**
     * @return list<string>
     */
    private function discoverCollections(): array
    {
        $dir = $this->basePath . '/content';
        if (!is_dir($dir)) {
            return [];
        }

        $names = [];
        foreach (new \DirectoryIterator($dir) as $f) {
            if (!$f->isDir() || $f->isDot() || str_starts_with($f->getFilename(), '_')) {
                continue;
            }
            $names[] = $f->getFilename();
        }
        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEntry(Entry $entry): array
    {
        $meta = $entry->meta();

        return [
            'title'      => $entry->title(),
            'slug'       => $entry->slug(),
            'collection' => $entry->collection(),
            'status'     => $this->normalizeStatus($meta),
            'date'       => $entry->date(),
            'meta'       => $meta,
        ];
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text) ?? '';
        $text = preg_replace('/[\s-]+/', '-', $text) ?? '';
        return trim($text, '-');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(int $status, array $data): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
        );
    }
}
