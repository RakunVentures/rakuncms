<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

/**
 * Backend for content queries. Implementations return RAW entry arrays (same
 * shape as ContentScanner::indexFile) which Query hydrates into Entry objects.
 *
 * Two implementations:
 *  - PhpArrayIndexStore: in-memory over {entries, indices} (back-compat).
 *  - SqliteIndexStore:   indexed, constant-memory queries with LIMIT.
 */
interface IndexStore
{
    /**
     * Filtered + sorted + paginated entry rows.
     *
     * @return list<array<string, mixed>>
     */
    public function query(QuerySpec $spec): array;

    /**
     * Count of entries matching the spec's collection/locale/section filters
     * (conditions and pagination are intentionally NOT applied — mirrors the
     * historical Query::count() behaviour).
     */
    public function count(QuerySpec $spec): int;

    /**
     * Resolve an entry by collection + locale + slug (full-slug first, then a
     * bare-slug fallback). Returns the raw row or null.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $collection, string $locale, string $slug): ?array;

    /**
     * Raw section descriptors for a collection (map under indices['sections'][collection]).
     *
     * @return array<string, array<string, mixed>>
     */
    public function sectionsFor(string $collection): array;

    /**
     * Resolve a single entry by exact index key, or — failing that — by key
     * prefix restricted to the given locale (mirrors the entry() helper).
     *
     * @return array<string, mixed>|null
     */
    public function findEntryByPath(string $path, ?string $locale = null): ?array;

    /**
     * Distinct tags across the index (for taxonomy listings).
     *
     * @return list<string>
     */
    public function allTags(): array;

    /**
     * Distinct YYYY-MM date periods across the index (for date archives).
     *
     * @return list<string>
     */
    public function allDatePeriods(): array;

    /**
     * Stream every entry row (build/search/sitemap full enumeration). Returns a
     * generator so the SQLite store never materialises all rows at once.
     *
     * @return iterable<array<string, mixed>>
     */
    public function each(): iterable;
}
