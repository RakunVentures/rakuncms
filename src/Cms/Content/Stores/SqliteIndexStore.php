<?php

declare(strict_types=1);

namespace Rkn\Cms\Content\Stores;

use Rkn\Cms\Content\ContentScanner;
use Rkn\Cms\Content\EntryStatus;
use Rkn\Cms\Content\IndexStore;
use Rkn\Cms\Content\QuerySpec;
use Rkn\Cms\Content\ScheduleChecker;

/**
 * SQLite-backed content index: a derived, queryable index over the .md tree
 * (the .md files remain the source of truth). Queries run with LIMIT against
 * B-tree indexes, so memory stays bounded regardless of total entry count.
 *
 * Parity contract with PhpArrayIndexStore: rows are persisted/rehydrated into
 * the exact ContentScanner::indexFile() array shape, and filter/sort/condition
 * semantics mirror the historical in-memory Query behaviour (sort only over
 * top-level entry fields; conditions evaluated in PHP with the same operators).
 */
final class SqliteIndexStore implements IndexStore
{
    private const SCHEMA_VERSION = '3';

    /** Top-level entry fields that sort() may order by (meta keys are not sortable, matching legacy Query). */
    private const SORT_COLUMNS = [
        'title', 'slug', 'url', 'section', 'collection', 'locale',
        'file', 'template', 'date', 'order', 'draft', 'mtime',
    ];

    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $dbPath,
        private readonly ContentScanner $scanner,
    ) {
    }

    // ---------------------------------------------------------------- reads

    public function query(QuerySpec $spec): array
    {
        [$where, $params] = $this->filterSql($spec);

        if ($spec->conditions !== []) {
            // Conditions are evaluated in PHP (exact operator parity). Bounded by
            // the collection/locale/section subset, never the whole index.
            $stmt = $this->db()->prepare("SELECT * FROM entries{$where}");
            $stmt->execute($params);
            $rows = array_map(fn ($r) => $this->hydrate($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
            $rows = array_values(array_filter($rows, fn ($row) => $this->matchesAll($row, $spec->conditions)));
            $rows = $this->sortRows($rows, $spec);
            return $this->slice($rows, $spec);
        }

        // No conditions: fetch only (key, sort-value), sort + paginate in PHP for
        // exact parity, then fetch the page's full rows by key.
        $sortExpr = $this->sortExpr($spec->sortField);
        $stmt = $this->db()->prepare("SELECT key, {$sortExpr} AS sv FROM entries{$where}");
        $stmt->execute($params);
        $pairs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($spec->sortField !== null) {
            $this->sortPairs($pairs, $spec->sortDirection);
        }

        $pageKeys = array_map(fn ($p) => $p['key'], $this->slice($pairs, $spec));
        if ($pageKeys === []) {
            return [];
        }

        $rowsByKey = $this->fetchByKeys($pageKeys);
        $out = [];
        foreach ($pageKeys as $key) {
            if (isset($rowsByKey[$key])) {
                $out[] = $rowsByKey[$key];
            }
        }
        return $out;
    }

    public function count(QuerySpec $spec): int
    {
        [$where, $params] = $this->filterSql($spec);
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM entries{$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findBySlug(string $collection, string $locale, string $slug): ?array
    {
        $stmt = $this->db()->prepare(
            "SELECT * FROM entries WHERE collection = ? AND locale = ? AND full_slug = ? AND status = 'published' LIMIT 1"
        );
        $stmt->execute([$collection, $locale, $slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $this->hydrate($row);
        }

        // Fallback: bare locale-slug (covers root-only slug matches).
        $stmt = $this->db()->prepare(
            "SELECT * FROM entries WHERE collection = ? AND locale = ? AND (full_slug = ? OR locale_slug = ?) AND status = 'published' LIMIT 1"
        );
        $stmt->execute([$collection, $locale, $slug, $slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function sectionsFor(string $collection): array
    {
        $stmt = $this->db()->prepare('SELECT * FROM sections WHERE collection = ? ORDER BY "order" ASC');
        $stmt->execute([$collection]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['section']] = [
                'section' => (string) $r['section'],
                'title' => (string) $r['title'],
                'titles' => $this->jsonArr($r['titles_json']),
                'order' => (int) $r['order'],
                'icon' => $r['icon'] !== null ? (string) $r['icon'] : null,
                'meta' => $this->jsonArr($r['meta_json']),
            ];
        }
        return $out;
    }

    public function findEntryByPath(string $path, ?string $locale = null): ?array
    {
        $stmt = $this->db()->prepare("SELECT * FROM entries WHERE key = ? AND status = 'published' LIMIT 1");
        $stmt->execute([$path]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $this->hydrate($row);
        }

        $len = strlen($path);
        $sql = "SELECT * FROM entries WHERE substr(key, 1, ?) = ? AND status = 'published'";
        $params = [$len, $path];
        if ($locale !== null) {
            $sql .= ' AND locale = ?';
            $params[] = $locale;
        }
        $sql .= ' ORDER BY key ASC LIMIT 1';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function allTags(): array
    {
        $rows = $this->db()
            ->query("SELECT DISTINCT et.tag FROM entry_tags et JOIN entries e ON e.key = et.key WHERE e.status = 'published' ORDER BY et.tag ASC")
            ->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    public function allDatePeriods(): array
    {
        $rows = $this->db()
            ->query("SELECT DISTINCT substr(date, 1, 7) AS p FROM entries WHERE date IS NOT NULL AND date <> '' AND status = 'published' ORDER BY p ASC")
            ->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    public function each(?string $status = null): iterable
    {
        if ($status === 'all') {
            $stmt = $this->db()->query('SELECT * FROM entries ORDER BY key ASC');
        } elseif ($status !== null) {
            $stmt = $this->db()->prepare('SELECT * FROM entries WHERE status = ? ORDER BY key ASC');
            $stmt->execute([$status]);
        } else {
            // null → published only (safe default)
            $stmt = $this->db()->query("SELECT * FROM entries WHERE status = 'published' ORDER BY key ASC");
        }
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            yield $this->hydrate($row);
        }
    }

    // ------------------------------------------------------------- build/sync

    /**
     * Synchronise the SQLite index with the .md tree. Incremental by mtime:
     * only changed/new files are re-parsed+upserted, removed/unpublished ones
     * are deleted. An empty DB naturally becomes a full build.
     *
     * @return array{inserted:int, updated:int, deleted:int, scanned:int}
     */
    public function sync(): array
    {
        $db = $this->db();
        $contentPath = $this->scanner->contentPath();

        $existing = [];
        foreach ($db->query('SELECT key, mtime FROM entries')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $existing[(string) $r['key']] = (int) $r['mtime'];
        }

        $report = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'scanned' => 0, 'skipped' => 0];
        $seen = [];

        $db->beginTransaction();
        try {
            if (is_dir($contentPath)) {
                $scheduleChecker = new ScheduleChecker(dirname($contentPath));

                foreach ($this->scanner->discoverCollections() as $collection) {
                    $collectionPath = $contentPath . '/' . $collection;
                    if (!is_dir($collectionPath)) {
                        continue;
                    }

                    $this->replaceSections($collection, $this->scanner->discoverSections($collectionPath));

                    foreach ($this->scanner->collectMarkdownFiles($collectionPath) as $file) {
                        $report['scanned']++;
                        $entry = $this->scanner->indexFile($file, $collection, $collectionPath);
                        if ($entry === null) {
                            continue;
                        }

                        $key = $this->scanner->buildEntryKey($collection, $entry['section'], basename($file, '.md'));
                        $seen[$key] = true;

                        $mtime = (int) $entry['mtime'];
                        try {
                            if (!array_key_exists($key, $existing)) {
                                $this->upsertRow($key, $entry, $scheduleChecker);
                                $report['inserted']++;
                            } elseif ($existing[$key] !== $mtime) {
                                $this->upsertRow($key, $entry, $scheduleChecker);
                                $report['updated']++;
                            }
                        } catch (\PDOException $e) {
                            // Otro archivo PUBLICADO ya ocupa este (collection, locale, full_slug)
                            // — p.ej. un {slug}.{locale}.md huérfano junto a {slug}.md. Se omite para
                            // no tumbar el índice completo (el primero gana). SQLite no aborta la
                            // transacción tras una violación de constraint (ABORT por defecto).
                            $report['skipped']++;
                        }
                    }
                }
            }

            // Delete rows whose file is gone or no longer publishable.
            foreach (array_keys($existing) as $key) {
                if (!isset($seen[$key])) {
                    $this->deleteRow($key);
                    $report['deleted']++;
                }
            }

            $this->setMeta('built_at', (string) ($_SERVER['REQUEST_TIME'] ?? 0));
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $tags
     */
    public function upsert(array $row, array $tags): void
    {
        // tags arrive separately for callers that already have them; the row's
        // own 'tags' wins for hydration consistency.
        $row['tags'] = $tags !== [] ? $tags : ($row['tags'] ?? []);
        $key = $row['__key'] ?? $this->scanner->buildEntryKey(
            (string) $row['collection'],
            (string) ($row['section'] ?? ''),
            basename((string) $row['file'], '.md')
        );
        $this->db()->beginTransaction();
        try {
            $this->upsertRow($key, $row);
            $this->db()->commit();
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }
    }

    public function delete(string $key): void
    {
        $this->db()->beginTransaction();
        try {
            $this->deleteRow($key);
            $this->db()->commit();
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }
    }

    // ----------------------------------------------------------- internals

    /** @param array<string, mixed> $entry */
    private function upsertRow(string $key, array $entry, ?ScheduleChecker $sc = null): void
    {
        $fullSlug = $this->scanner->fullSlug($entry);
        $localeSlug = $entry['slugs'][$entry['locale']] ?? $entry['slug'];

        // Compute status: use pre-computed value if already set (e.g. from scan()),
        // otherwise compute it now (e.g. from the public upsert() API).
        if (isset($entry['status'])) {
            $status = (string) $entry['status'];
        } else {
            $checker = $sc ?? new ScheduleChecker(dirname($this->scanner->contentPath()));
            $status = EntryStatus::of($entry, $checker);
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO entries
              (key, collection, locale, section, slug, full_slug, locale_slug, title, url, template, date, "order", draft, mtime, file, slugs_json, tags_json, meta_json, status)
             VALUES (:key,:collection,:locale,:section,:slug,:full_slug,:locale_slug,:title,:url,:template,:date,:ord,:draft,:mtime,:file,:slugs,:tags,:meta,:status)
             ON CONFLICT(key) DO UPDATE SET
              collection=excluded.collection, locale=excluded.locale, section=excluded.section,
              slug=excluded.slug, full_slug=excluded.full_slug, locale_slug=excluded.locale_slug,
              title=excluded.title, url=excluded.url, template=excluded.template, date=excluded.date,
              "order"=excluded."order", draft=excluded.draft, mtime=excluded.mtime, file=excluded.file,
              slugs_json=excluded.slugs_json, tags_json=excluded.tags_json, meta_json=excluded.meta_json,
              status=excluded.status'
        );
        $stmt->execute([
            ':key' => $key,
            ':collection' => (string) $entry['collection'],
            ':locale' => (string) $entry['locale'],
            ':section' => (string) ($entry['section'] ?? ''),
            ':slug' => (string) $entry['slug'],
            ':full_slug' => $fullSlug,
            ':locale_slug' => (string) $localeSlug,
            ':title' => $entry['title'] !== null ? (string) $entry['title'] : null,
            ':url' => (string) $entry['url'],
            ':template' => $entry['template'] !== null ? (string) $entry['template'] : null,
            ':date' => $entry['date'] !== null ? (string) $entry['date'] : null,
            ':ord' => (int) ($entry['order'] ?? 0),
            ':draft' => !empty($entry['draft']) ? 1 : 0,
            ':mtime' => (int) ($entry['mtime'] ?? 0),
            ':file' => (string) $entry['file'],
            ':slugs' => $this->jsonStr($entry['slugs'] ?? []),
            ':tags' => $this->jsonStr($entry['tags'] ?? []),
            ':meta' => $this->jsonStr($entry['meta'] ?? []),
            ':status' => $status,
        ]);

        $del = $this->db()->prepare('DELETE FROM entry_tags WHERE key = ?');
        $del->execute([$key]);
        if (!empty($entry['tags']) && is_array($entry['tags'])) {
            $ins = $this->db()->prepare('INSERT OR IGNORE INTO entry_tags (key, tag) VALUES (?, ?)');
            foreach (array_unique(array_map('strval', $entry['tags'])) as $tag) {
                $ins->execute([$key, $tag]);
            }
        }
    }

    private function deleteRow(string $key): void
    {
        // entry_tags cascades via FK.
        $this->db()->prepare('DELETE FROM entries WHERE key = ?')->execute([$key]);
    }

    /**
     * @param array<string, array<string, mixed>> $sections
     */
    private function replaceSections(string $collection, array $sections): void
    {
        $this->db()->prepare('DELETE FROM sections WHERE collection = ?')->execute([$collection]);
        $ins = $this->db()->prepare(
            'INSERT INTO sections (collection, section, title, titles_json, "order", icon, meta_json)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($sections as $s) {
            $ins->execute([
                $collection,
                (string) ($s['section'] ?? ''),
                (string) ($s['title'] ?? ''),
                $this->jsonStr($s['titles'] ?? []),
                (int) ($s['order'] ?? 0),
                isset($s['icon']) && $s['icon'] !== null ? (string) $s['icon'] : null,
                $this->jsonStr($s['meta'] ?? []),
            ]);
        }
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function filterSql(QuerySpec $spec): array
    {
        $clauses = [];
        $params = [];
        if ($spec->collection !== null) {
            $clauses[] = 'collection = ?';
            $params[] = $spec->collection;
        }
        if ($spec->locale !== null) {
            $clauses[] = 'locale = ?';
            $params[] = $spec->locale;
        }
        if ($spec->section !== null) {
            $clauses[] = 'section = ?';
            $params[] = $spec->section;
        }
        // Status filter: null or 'all' means no filter; anything else means that status.
        if ($spec->status !== null && $spec->status !== 'all') {
            $clauses[] = 'status = ?';
            $params[] = $spec->status;
        }
        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
        return [$where, $params];
    }

    private function sortExpr(?string $field): string
    {
        if ($field !== null && in_array($field, self::SORT_COLUMNS, true)) {
            return '"' . $field . '"';
        }
        // Non-top-level (meta) fields are not sortable in the legacy Query → constant.
        return "''";
    }

    /**
     * @param list<array{key:string, sv:mixed}> $pairs
     */
    private function sortPairs(array &$pairs, string $direction): void
    {
        usort($pairs, function (array $a, array $b) use ($direction) {
            $va = $a['sv'] ?? '';
            $vb = $b['sv'] ?? '';
            $cmp = is_numeric($va) && is_numeric($vb) ? $va <=> $vb : strcmp((string) $va, (string) $vb);
            return $direction === 'desc' ? -$cmp : $cmp;
        });
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function sortRows(array $rows, QuerySpec $spec): array
    {
        if ($spec->sortField === null) {
            return $rows;
        }
        $field = $spec->sortField;
        $dir = $spec->sortDirection;
        usort($rows, function (array $a, array $b) use ($field, $dir) {
            $va = $a[$field] ?? '';
            $vb = $b[$field] ?? '';
            $cmp = is_numeric($va) && is_numeric($vb) ? $va <=> $vb : strcmp((string) $va, (string) $vb);
            return $dir === 'desc' ? -$cmp : $cmp;
        });
        return $rows;
    }

    /**
     * @param list<mixed> $items
     * @return list<mixed>
     */
    private function slice(array $items, QuerySpec $spec): array
    {
        if ($spec->offset > 0 || $spec->limit !== null) {
            return array_slice($items, $spec->offset, $spec->limit);
        }
        return $items;
    }

    /**
     * @param list<string> $keys
     * @return array<string, array<string, mixed>>
     */
    private function fetchByKeys(array $keys): array
    {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->db()->prepare("SELECT * FROM entries WHERE key IN ({$placeholders})");
        $stmt->execute($keys);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['key']] = $this->hydrate($r);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<array{field:string, operator:string, value:mixed}> $conditions
     */
    private function matchesAll(array $entry, array $conditions): bool
    {
        foreach ($conditions as $c) {
            $field = $c['field'];
            $operator = $c['operator'];
            $value = $c['value'];
            $entryValue = $entry[$field] ?? ($entry['meta'][$field] ?? null);

            $ok = match ($operator) {
                '=', '==' => $entryValue == $value,
                '===' => $entryValue === $value,
                '!=', '<>' => $entryValue != $value,
                '>' => $entryValue > $value,
                '<' => $entryValue < $value,
                '>=' => $entryValue >= $value,
                '<=' => $entryValue <= $value,
                'contains' => is_string($entryValue) && str_contains(strtolower($entryValue), strtolower((string) $value)),
                'in' => is_array($value) && in_array($entryValue, $value),
                'has' => is_array($entryValue) && in_array($value, $entryValue),
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $r raw DB row
     * @return array<string, mixed> indexFile-shaped entry
     */
    private function hydrate(array $r): array
    {
        return [
            'title' => $r['title'],
            'slug' => $r['slug'],
            'url' => $r['url'],
            'section' => (string) $r['section'],
            'collection' => $r['collection'],
            'locale' => $r['locale'],
            'file' => $r['file'],
            'template' => $r['template'],
            'date' => $r['date'],
            'order' => (int) $r['order'],
            'draft' => (bool) $r['draft'],
            'meta' => $this->jsonArr($r['meta_json']),
            'slugs' => $this->jsonArr($r['slugs_json']),
            'tags' => $this->jsonArr($r['tags_json']),
            'mtime' => (int) $r['mtime'],
            'status' => (string) ($r['status'] ?? 'published'),
        ];
    }

    private function jsonStr(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array<mixed>
     */
    private function jsonArr(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function setMeta(string $k, string $v): void
    {
        $this->db()->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')
            ->execute([$k, $v]);
    }

    private function db(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA foreign_keys=ON');

        $this->pdo = $pdo;
        $this->ensureSchema();
        return $pdo;
    }

    private function ensureSchema(): void
    {
        $pdo = $this->pdo;
        $version = null;
        try {
            $version = $pdo->query("SELECT v FROM meta WHERE k = 'schema_version'")->fetchColumn();
        } catch (\Throwable) {
            $version = false;
        }

        if ($version === self::SCHEMA_VERSION) {
            return;
        }

        // Fresh or stale schema → (re)create.
        $pdo->exec('DROP TABLE IF EXISTS entry_tags');
        $pdo->exec('DROP TABLE IF EXISTS entries');
        $pdo->exec('DROP TABLE IF EXISTS sections');
        $pdo->exec('DROP TABLE IF EXISTS meta');

        $pdo->exec('CREATE TABLE entries (
            key TEXT PRIMARY KEY, collection TEXT NOT NULL, locale TEXT NOT NULL,
            section TEXT NOT NULL DEFAULT "", slug TEXT NOT NULL, full_slug TEXT NOT NULL,
            locale_slug TEXT NOT NULL DEFAULT "", title TEXT, url TEXT, template TEXT, date TEXT,
            "order" INTEGER NOT NULL DEFAULT 0, draft INTEGER NOT NULL DEFAULT 0,
            mtime INTEGER NOT NULL DEFAULT 0, file TEXT NOT NULL,
            slugs_json TEXT NOT NULL DEFAULT "{}", tags_json TEXT NOT NULL DEFAULT "[]",
            meta_json TEXT NOT NULL DEFAULT "{}",
            status TEXT NOT NULL DEFAULT "published"
        )');
        // PARCIAL: la unicidad (collection, locale, full_slug) solo aplica entre
        // entradas PUBLICADAS (invariante de routing público). Drafts/scheduled
        // pueden compartir slug con su versión publicada (revisión en borrador).
        $pdo->exec("CREATE UNIQUE INDEX ux_entries_pub_slug ON entries(collection, locale, full_slug) WHERE status = 'published'");
        $pdo->exec('CREATE INDEX ix_entries_locale_slug ON entries(collection, locale, full_slug)');
        $pdo->exec('CREATE INDEX ix_entries_coll_locale ON entries(collection, locale, "order")');
        $pdo->exec('CREATE INDEX ix_entries_coll_sec_loc ON entries(collection, section, locale, "order")');
        $pdo->exec('CREATE INDEX ix_entries_date ON entries(collection, locale, date)');
        $pdo->exec('CREATE INDEX ix_entries_status ON entries(collection, locale, status)');

        $pdo->exec('CREATE TABLE entry_tags (
            key TEXT NOT NULL REFERENCES entries(key) ON DELETE CASCADE,
            tag TEXT NOT NULL, PRIMARY KEY (key, tag)
        )');
        $pdo->exec('CREATE INDEX ix_entry_tags_tag ON entry_tags(tag)');

        $pdo->exec('CREATE TABLE sections (
            collection TEXT NOT NULL, section TEXT NOT NULL, title TEXT,
            titles_json TEXT NOT NULL DEFAULT "{}", "order" INTEGER NOT NULL DEFAULT 0,
            icon TEXT, meta_json TEXT NOT NULL DEFAULT "{}", PRIMARY KEY (collection, section)
        )');

        $pdo->exec('CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT)');
        $pdo->prepare("INSERT INTO meta (k, v) VALUES ('schema_version', ?)")->execute([self::SCHEMA_VERSION]);
    }
}
