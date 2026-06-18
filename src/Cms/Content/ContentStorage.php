<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

/**
 * Pluggable backend for the SOURCE OF TRUTH of content bodies. Mirrors the
 * IndexStore seam (which abstracts the query index) for the content itself.
 *
 *  - FileContentStorage: `.md` files on disk (current behaviour; OSS default).
 *  - MysqlContentStorage: rows in MySQL (SSoT for managed sites), regenerating
 *    the `.md` cache so the render path stays untouched.
 *
 * Selected by config `content.driver` (file|mysql). The `.md`/HTML files become
 * a regenerable cache when the driver is mysql — eliminating the flat-file
 * fragility (lost content) for sites like fiancee.
 */
interface ContentStorage
{
    /**
     * Read a single entry's frontmatter + raw body, or null if absent.
     */
    public function read(string $collection, string $locale, string $slug): ?ContentBody;

    /**
     * Create or replace an entry. Returns a reference to the canonical location.
     */
    public function write(ContentDraft $draft): ContentRef;

    /**
     * Remove an entry. Returns true if something was deleted.
     */
    public function delete(string $collection, string $locale, string $slug): bool;

    /**
     * Enumerate references to every stored entry (for cache/index rebuilds).
     *
     * @return iterable<ContentRef>
     */
    public function listKeys(): iterable;

    /**
     * Enumerate references to entries whose stored status is "scheduled-ish"
     * (future/scheduled/pending). The date/"due" check is the caller's job. This
     * lets `publish:check` find candidates cheaply (MySQL resolves it with a WHERE
     * on the indexed `status` column) instead of reading every entry.
     *
     * @return iterable<ContentRef>
     */
    public function listScheduled(): iterable;
}
