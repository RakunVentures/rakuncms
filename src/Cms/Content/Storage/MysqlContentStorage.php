<?php

declare(strict_types=1);

namespace Rkn\Cms\Content\Storage;

use PDO;
use Rkn\Cms\Content\ContentBody;
use Rkn\Cms\Content\ContentDraft;
use Rkn\Cms\Content\ContentRef;
use Rkn\Cms\Content\ContentStorage;

/**
 * MySQL-backed content storage: the SOURCE OF TRUTH for managed sites. Each write
 * persists to `contents` (+ a `content_revisions` row for history, + `content_tags`)
 * and regenerates the `.md` cache via an injected FileContentStorage — so the
 * render path (ContentScanner/Entry/Query) keeps reading flat files unchanged, but
 * the durable truth lives in the database. Losing the `.md` cache is harmless:
 * rebuild it from MySQL.
 */
final class MysqlContentStorage implements ContentStorage
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FileContentStorage $cache,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ensureSchema();
    }

    public function read(string $collection, string $locale, string $slug): ?ContentBody
    {
        $stmt = $this->pdo->prepare(
            'SELECT collection, locale, slug, section, body_markdown, meta_json
             FROM contents WHERE collection = ? AND locale = ? AND slug = ? LIMIT 1',
        );
        $stmt->execute([$collection, $locale, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $frontmatter = $this->decodeJson($row['meta_json']);
        $file = $this->cacheRelativePath($collection, $locale, $slug);

        return new ContentBody($collection, $locale, $slug, $frontmatter, (string) $row['body_markdown'], $file);
    }

    public function write(ContentDraft $draft): ContentRef
    {
        $fm       = $draft->frontmatter;
        $section  = isset($fm['section']) ? (string) $fm['section'] : '';
        $fullSlug = $section !== '' ? $section . '/' . $draft->slug : $draft->slug;
        $title    = isset($fm['title']) ? (string) $fm['title'] : $draft->slug;
        $template = isset($fm['template']) ? (string) $fm['template'] : null;
        $status   = $this->resolveStatus($fm);
        $publishedAt = $this->resolvePublishedAt($fm);
        $order    = isset($fm['order']) ? (int) $fm['order'] : 0;
        $tags     = $this->resolveTags($fm);
        $now      = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO contents
                   (collection, locale, slug, section, full_slug, title, template, status, published_at, `order`,
                    body_markdown, meta_json, tags_json, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    slug = VALUES(slug), section = VALUES(section), title = VALUES(title),
                    template = VALUES(template), status = VALUES(status), published_at = VALUES(published_at),
                    `order` = VALUES(`order`), body_markdown = VALUES(body_markdown),
                    meta_json = VALUES(meta_json), tags_json = VALUES(tags_json), updated_at = VALUES(updated_at)',
            )->execute([
                $draft->collection, $draft->locale, $draft->slug, $section, $fullSlug, $title, $template,
                $status, $publishedAt, $order, $draft->body,
                $this->encodeJson($fm), $this->encodeJson($tags), $now, $now,
            ]);

            $id = $this->contentId($draft->collection, $draft->locale, $fullSlug);

            // History: every write is a revision.
            $this->pdo->prepare(
                'INSERT INTO content_revisions (content_id, title, body_markdown, meta_json, author, created_at)
                 VALUES (?,?,?,?,?,?)',
            )->execute([$id, $title, $draft->body, $this->encodeJson($fm), $this->author($fm), $now]);

            // Tags.
            $this->pdo->prepare('DELETE FROM content_tags WHERE content_id = ?')->execute([$id]);
            if ($tags !== []) {
                $insertTag = $this->pdo->prepare('INSERT INTO content_tags (content_id, tag) VALUES (?, ?)');
                foreach ($tags as $tag) {
                    $insertTag->execute([$id, $tag]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // Regenerate the .md cache so the render path/index see the change.
        $this->cache->write($draft);

        return new ContentRef($draft->collection, $draft->locale, $draft->slug, $this->cacheRelativePath($draft->collection, $draft->locale, $draft->slug));
    }

    public function delete(string $collection, string $locale, string $slug): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM contents WHERE collection = ? AND locale = ? AND slug = ?');
        $stmt->execute([$collection, $locale, $slug]);
        $deleted = $stmt->rowCount() > 0;

        // Drop the cache file regardless (best-effort).
        $this->cache->delete($collection, $locale, $slug);

        return $deleted;
    }

    public function listKeys(): iterable
    {
        $stmt = $this->pdo->query('SELECT collection, locale, slug, full_slug FROM contents');
        if ($stmt === false) {
            return;
        }
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield new ContentRef(
                (string) $row['collection'],
                (string) $row['locale'],
                (string) $row['slug'],
                $this->cacheRelativePath((string) $row['collection'], (string) $row['locale'], (string) $row['slug']),
            );
        }
    }

    /**
     * Revision history for an entry, newest first.
     *
     * @return list<array{title: string, created_at: string, author: ?string}>
     */
    public function revisions(string $collection, string $locale, string $slug): array
    {
        $fullSlug = $slug; // sections resolved by caller if needed
        $id = $this->contentId($collection, $locale, $fullSlug);
        if ($id === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT title, author, created_at FROM content_revisions WHERE content_id = ? ORDER BY created_at DESC, id DESC',
        );
        $stmt->execute([$id]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'title'      => (string) $r['title'],
                'created_at' => (string) $r['created_at'],
                'author'     => $r['author'] !== null ? (string) $r['author'] : null,
            ];
        }

        return $out;
    }

    // --------------------------------------------------------------- internals

    private function contentId(string $collection, string $locale, string $fullSlug): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM contents WHERE collection = ? AND locale = ? AND full_slug = ? LIMIT 1',
        );
        $stmt->execute([$collection, $locale, $fullSlug]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param  array<string, mixed>  $fm
     */
    private function resolveStatus(array $fm): string
    {
        if (isset($fm['status']) && is_string($fm['status'])) {
            return $fm['status'];
        }

        return !empty($fm['draft']) ? 'draft' : 'published';
    }

    /**
     * @param  array<string, mixed>  $fm
     */
    private function resolvePublishedAt(array $fm): ?string
    {
        if (!isset($fm['date'])) {
            return null;
        }
        $ts = strtotime((string) $fm['date']);

        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    /**
     * @param  array<string, mixed>  $fm
     * @return list<string>
     */
    private function resolveTags(array $fm): array
    {
        if (!isset($fm['tags']) || !is_array($fm['tags'])) {
            return [];
        }
        $tags = [];
        foreach ($fm['tags'] as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @param  array<string, mixed>  $fm
     */
    private function author(array $fm): ?string
    {
        return isset($fm['author']) ? (string) $fm['author'] : null;
    }

    private function cacheRelativePath(string $collection, string $locale, string $slug): string
    {
        $suffix = $locale !== '' ? ".{$locale}" : '';

        return "content/{$collection}/{$slug}{$suffix}.md";
    }

    /**
     * @param  mixed  $json
     * @return array<string, mixed>
     */
    private function decodeJson($json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS contents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                collection VARCHAR(190) NOT NULL,
                locale VARCHAR(10) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                section VARCHAR(190) NOT NULL DEFAULT \'\',
                full_slug VARCHAR(190) NOT NULL,
                title VARCHAR(255) NULL,
                template VARCHAR(190) NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'published\',
                published_at DATETIME NULL,
                `order` INT NOT NULL DEFAULT 0,
                body_markdown MEDIUMTEXT NULL,
                body_html MEDIUMTEXT NULL,
                meta_json JSON NULL,
                tags_json JSON NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY ux_coll_loc_full (collection, locale, full_slug),
                KEY ix_coll_loc_status (collection, locale, status, `order`),
                KEY ix_coll_loc_pub (collection, locale, published_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS content_revisions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                content_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NULL,
                body_markdown MEDIUMTEXT NULL,
                meta_json JSON NULL,
                author VARCHAR(190) NULL,
                created_at DATETIME NOT NULL,
                KEY ix_rev_content (content_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS content_tags (
                content_id BIGINT UNSIGNED NOT NULL,
                tag VARCHAR(190) NOT NULL,
                PRIMARY KEY (content_id, tag),
                KEY ix_tag (tag)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
}
