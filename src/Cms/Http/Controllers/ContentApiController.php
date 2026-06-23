<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rkn\Cms\Cache\PageCache;
use Rkn\Cms\Content\ContentDraft;
use Rkn\Cms\Content\ContentStorageFactory;
use Rkn\Cms\Content\DraftResolver;
use Rkn\Cms\Content\Entry;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\IndexStoreFactory;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Content\QuerySpec;

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
                'positions'        => is_array($def['positions'] ?? null) ? $def['positions'] : [],
                'image_variants'   => is_array($def['image_variants'] ?? null) ? $def['image_variants'] : [],
                // Plantillas de nomenclatura (opcionales): el admin calcula slug/título
                // del registro a partir de campos capturados (p. ej. año + mes).
                'slug_template'    => isset($def['slug_template']) ? (string) $def['slug_template'] : null,
                'title_template'   => isset($def['title_template']) ? (string) $def['title_template'] : null,
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

    /**
     * Devuelve una URL firmada de vista previa para una entrada (publicada o no),
     * para que el panel la abra/comparta. El secreto vive SOLO en el sitio: el
     * panel no firma ni resuelve URLs de slugs compuestos (lo hace el engine).
     * GET /api/v1/preview-url?collection=&slug=&locale=
     */
    public function previewUrl(ServerRequestInterface $request): ResponseInterface
    {
        $qp         = $request->getQueryParams();
        $collection = (string) ($qp['collection'] ?? '');
        $slug       = (string) ($qp['slug'] ?? '');
        $locale     = (string) ($qp['locale'] ?? $this->defaultLocale());

        if ($collection === '' || $slug === '') {
            return $this->json(422, ['error' => 'collection y slug son requeridos']);
        }

        $resolver = new DraftResolver($this->basePath);
        if (!$resolver->isConfigured()) {
            return $this->json(409, ['error' => 'Vista previa no configurada: falta preview.secret en el sitio.']);
        }

        $entry = $resolver->resolveEntry($collection, $locale, $slug);
        if ($entry === null) {
            return $this->json(404, ['error' => "Entrada '{$slug}' no encontrada en '{$collection}'"]);
        }

        $token = $resolver->signToken($collection, $locale, $slug);
        $base  = rtrim((string) (\config('site.url') ?? \config('rakun.site.url') ?? ''), '/');
        $path  = $entry->url();
        $url   = $base . $path . (str_contains($path, '?') ? '&' : '?') . 'preview=' . rawurlencode($token);

        return $this->json(200, [
            'data' => [
                'url'        => $url,
                'expires_at' => date('c', $resolver->expiresAt()),
            ],
        ]);
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

        // El panel siempre envía el slug "limpio" (basename). Para entradas
        // creadas desde el panel coincide con la clave de storage y read() acierta
        // directo. Para entradas importadas (p.ej. WordPress en content/X/YYYY/MM/foo.md)
        // la clave de storage es compuesta y read() falla con el slug limpio: ahí
        // resolvemos vía el índice (full_slug es la clave canónica de storage).
        $storageSlug = $slug;
        $existing    = $storage->read($collection, $locale, $storageSlug);
        if ($existing === null) {
            $resolved = $this->resolveStorageSlug($collection, $locale, $slug);
            if ($resolved !== null && $resolved !== $slug) {
                $storageSlug = $resolved;
                $existing    = $storage->read($collection, $locale, $storageSlug);
            }
        }

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

        $storage->write(new ContentDraft($collection, $locale, $storageSlug, $meta, $content));
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

    public function delete(ServerRequestInterface $request, string $collection, string $slug): ResponseInterface
    {
        $qp     = $request->getQueryParams();
        $locale = isset($qp['locale']) && $qp['locale'] !== ''
            ? (string) $qp['locale']
            : $this->defaultLocale();

        $storage = ContentStorageFactory::make($this->basePath);

        // Limpieza de media: si la colección declara `cleanup_media: true`, los
        // archivos referenciados por los campos de media (image/pdf/file/media) de la
        // entrada se borran de assets/ al borrarla, para no dejar residuos (p.ej. el
        // PDF y la portada de una revista). Opt-in por colección: solo donde los
        // archivos son propios de la entrada (no compartidos).
        $def         = $this->collectionDef($collection);
        $cleanupMedia = (bool) ($def['cleanup_media'] ?? false);
        $mediaFiles  = [];

        // (a) Gate por filesystem (flat-file legacy): un `.md` directamente bajo
        //     content/{collection}/ con variantes por locale. Conserva la
        //     semántica histórica de borrar TODAS las variantes locales en una
        //     sola llamada cuando aplique.
        $dir         = $this->basePath . '/content/' . $collection;
        $matched     = glob("{$dir}/{$slug}.*.md") ?: [];
        $flatFallback = "{$dir}/{$slug}.md";
        if (is_file($flatFallback)) {
            $matched[] = $flatFallback;
        }

        if ($matched !== []) {
            foreach ($matched as $file) {
                $segments = explode('.', basename($file, '.md'));
                $fileLocale = (count($segments) >= 2 && strlen((string) end($segments)) === 2)
                    ? (string) end($segments)
                    : $this->defaultLocale();
                if ($cleanupMedia) {
                    $mediaFiles = array_merge($mediaFiles, $this->mediaFilesFromFrontmatter($def, $storage->read($collection, $fileLocale, $slug)));
                }
                $storage->delete($collection, $fileLocale, $slug);
            }
            $this->refreshIndex();
            $this->deleteMediaFiles($mediaFiles);

            return $this->json(200, ['message' => 'Deleted']);
        }

        // (b) Gate por índice: aplica a MySQL y a layouts flat-file con
        //     subdirectorios (p.ej. content/blog/YYYY/MM/foo.md). El panel envía
        //     siempre slug limpio; el índice nos da la clave canónica de
        //     storage (full_slug) que puede ser compuesta.
        $storageSlug = $this->resolveStorageSlug($collection, $locale, $slug);
        if ($storageSlug === null) {
            return $this->json(404, ['error' => "Entry '{$slug}' not found in '{$collection}'"]);
        }

        if ($cleanupMedia) {
            $mediaFiles = $this->mediaFilesFromFrontmatter($def, $storage->read($collection, $locale, $storageSlug));
        }
        $storage->delete($collection, $locale, $storageSlug);
        $this->refreshIndex();
        $this->deleteMediaFiles($mediaFiles);

        return $this->json(200, ['message' => 'Deleted']);
    }

    /**
     * Definición de la colección desde la config (collections.{name}).
     *
     * @return array<string, mixed>
     */
    private function collectionDef(string $collection): array
    {
        $config      = $this->fullConfig();
        $collections = $config['collections'] ?? ($config['rakun']['collections'] ?? []);
        $def         = is_array($collections) ? ($collections[$collection] ?? null) : null;

        return is_array($def) ? $def : [];
    }

    /**
     * Rutas absolutas (contenidas en public/) de los archivos de media que referencia
     * la entrada en sus campos de tipo image/pdf/file/media. Ignora URLs externas.
     *
     * @param  array<string, mixed>  $def
     * @return list<string>
     */
    private function mediaFilesFromFrontmatter(array $def, ?\Rkn\Cms\Content\ContentBody $body): array
    {
        if ($body === null) {
            return [];
        }
        $mediaKeys = [];
        foreach (($def['fields'] ?? []) as $f) {
            if (is_array($f) && in_array((string) ($f['type'] ?? ''), ['image', 'pdf', 'file', 'media'], true)) {
                $key = (string) ($f['key'] ?? $f['name'] ?? '');
                if ($key !== '') {
                    $mediaKeys[] = $key;
                }
            }
        }
        if ($mediaKeys === []) {
            return [];
        }

        $publicReal = realpath($this->basePath . '/public');
        if ($publicReal === false) {
            return [];
        }

        $files = [];
        foreach ($mediaKeys as $key) {
            $val = $body->frontmatter[$key] ?? null;
            if (!is_string($val) || $val === '' || str_starts_with($val, 'http')) {
                continue; // vacío o URL externa → no es un archivo local que poseamos
            }
            $abs = realpath($this->basePath . '/public/' . ltrim($val, '/'));
            if ($abs !== false && str_starts_with($abs, $publicReal) && is_file($abs)) {
                $files[] = $abs;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  list<string>  $files
     */
    private function deleteMediaFiles(array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * Resolver del slug "limpio" (basename) que el panel siempre envía hacia la
     * clave canónica de storage (full_slug). Cubre dos topologías:
     *
     *  - Flat-file en subdirectorio: content/{collection}/YYYY/MM/{slug}.md →
     *    el indexer guarda slug=basename y full_slug='YYYY/MM/basename', y
     *    storage->read/write/delete usan full_slug como clave.
     *  - MySQL con datos importados de WordPress: el WxrImportCommand persiste
     *    archivos en YYYY/MM/{slug}.md y el importer FS→MySQL copia el slug
     *    compuesto a la columna `slug`, dejando MySQL con
     *    slug='YYYY/MM/basename' (y por tanto storage->read('basename') falla).
     *
     * Estrategia: el índice (ya sea PhpArray o Sqlite) implementa findBySlug con
     * fallback `(full_slug = slug OR locale_slug = slug)`, así que matchea
     * tanto el slug limpio como el compuesto. Retornamos su `full_slug` como la
     * clave real de storage. null si no hay match publicado.
     */
    private function resolveStorageSlug(string $collection, string $locale, string $slug): ?string
    {
        try {
            $row = \index_store()->findBySlug($collection, $locale, $slug);
        } catch (\Throwable $e) {
            error_log('[rakun] storage-slug resolution failed: ' . $e->getMessage());
            return null;
        }
        if ($row === null) {
            return null;
        }

        // SqliteIndexStore expone `full_slug` (la clave canónica de storage)
        // directamente; PhpArrayIndexStore no — devuelve el array crudo de
        // ContentScanner con `slug` (basename) y `section` separados, así que
        // recomponemos la clave aquí.
        $full = $row['full_slug'] ?? null;
        if (is_string($full) && $full !== '') {
            return $full;
        }

        $rowSlug = isset($row['slug']) ? (string) $row['slug'] : '';
        $section = isset($row['section']) ? (string) $row['section'] : '';
        if ($rowSlug === '') {
            return null;
        }

        return $section !== '' ? "{$section}/{$rowSlug}" : $rowSlug;
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
        } catch (\Throwable $e) {
            error_log('[rakun] index refresh after write failed; explicit rebuild still available: ' . $e->getMessage());
        }

        $this->invalidatePageCache();
    }

    /**
     * Drop the full-page HTML cache after a content mutation. Without this the
     * home/listing/archive pages keep serving frozen HTML and "new posts don't
     * publish". clear() is the correct minimal invalidation: with no per-file
     * dependency tracking we cannot know which cached pages embed the touched
     * entry, so we wipe them all and let them regenerate on the next request.
     * Guarded by is_dir() so a write never materialises an empty cache dir when
     * page caching is off or the site has never been visited; best-effort so a
     * cache-permission glitch never breaks the write itself.
     */
    private function invalidatePageCache(): void
    {
        $dir = $this->basePath . '/cache/pages';
        if (!is_dir($dir)) {
            return;
        }
        try {
            (new PageCache($dir))->clear();
        } catch (\Throwable $e) {
            error_log('[rakun] page cache invalidation after write failed; refreshed on next manual clear/visit: ' . $e->getMessage());
        }
    }

    public function collections(): ResponseInterface
    {
        $store = IndexStoreFactory::make($this->basePath);

        $data = [];
        foreach ($this->discoverCollections() as $name) {
            $data[] = [
                'id'          => $name,
                'name'        => $name,
                // Count from the index, not a raw directory scan: entries live in
                // nested folders (e.g. blog/YYYY/MM/) that a non-recursive
                // DirectoryIterator misses, reporting 0 for chronological blogs.
                // Mirrors the MCP CollectionsResource and stays memory-safe on
                // SQLite (a COUNT query, never materialising the rows).
                'entry_count' => $store->count(new QuerySpec(collection: $name)),
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
            // Preferir el status computado por el índice (date-aware: una entrada
            // `future` con fecha pasada es 'published'). normalizeStatus($meta)
            // solo mira el status crudo y no la fecha → mostraba "Programado" en
            // el admin para entradas ya vencidas. Mismo criterio que rowSummary().
            'status'     => $entry->status() ?? $this->normalizeStatus($meta),
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
