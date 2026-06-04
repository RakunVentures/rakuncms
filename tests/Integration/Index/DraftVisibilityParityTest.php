<?php

declare(strict_types=1);

use Rkn\Cms\Content\ContentScanner;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Content\Stores\PhpArrayIndexStore;
use Rkn\Cms\Content\Stores\SqliteIndexStore;

/**
 * Gate: drafts and scheduled entries are invisible to the public (default query)
 * and visible only when explicitly requested via withStatus() / includeAllStatuses().
 * Both stores (PHP and SQLite) must behave identically.
 *
 * Seeded fixture: deterministic mix of published / draft / scheduled entries.
 */

/** Build a deterministic fixture with published + draft + scheduled entries. */
function buildVisibilityFixture(string $dir): void
{
    @mkdir($dir . '/config', 0755, true);
    file_put_contents($dir . '/config/rakun.yaml', "site:\n  default_locale: es\n");

    @mkdir($dir . '/content/posts', 0755, true);

    // 5 published entries
    for ($i = 1; $i <= 5; $i++) {
        file_put_contents(
            "{$dir}/content/posts/published-{$i}.md",
            "---\ntitle: \"Published {$i}\"\ntags: [\"pub-tag\"]\ndate: \"2024-01-0{$i}\"\n---\ncontent\n"
        );
    }

    // 3 draft entries — draft:true flag
    for ($i = 1; $i <= 3; $i++) {
        file_put_contents(
            "{$dir}/content/posts/draft-{$i}.md",
            "---\ntitle: \"Draft {$i}\"\ndraft: true\ntags: [\"draft-only-tag\"]\ndate: \"2024-02-0{$i}\"\n---\ncontent\n"
        );
    }

    // 1 draft entry — via meta.status
    file_put_contents(
        "{$dir}/content/posts/draft-meta.md",
        "---\ntitle: \"Draft Meta\"\nmeta:\n  status: draft\ntags: [\"draft-only-tag\"]\ndate: \"2024-02-10\"\n---\ncontent\n"
    );

    // 2 scheduled entries — publish_date far in the future
    for ($i = 1; $i <= 2; $i++) {
        file_put_contents(
            "{$dir}/content/posts/scheduled-{$i}.md",
            "---\ntitle: \"Scheduled {$i}\"\npublish_date: \"2999-12-3{$i}\"\ntags: [\"scheduled-tag\"]\ndate: \"2024-03-0{$i}\"\n---\ncontent\n"
        );
    }
}

/**
 * @return array{0: PhpArrayIndexStore, 1: SqliteIndexStore}
 */
function buildVisibilityStores(string $dir): array
{
    $contentPath = $dir . '/content';
    $scanned = (new ContentScanner($contentPath, 'es'))->scan();
    $php = new PhpArrayIndexStore(['entries' => $scanned['entries'], 'indices' => $scanned['indices']]);

    $sqlite = new SqliteIndexStore($dir . '/cache/vis-index.sqlite', new ContentScanner($contentPath, 'es'));
    $sqlite->sync();

    return [$php, $sqlite];
}

/** Extract URLs from Entry array (for stable comparison). */
function visUrls(array $entries): array
{
    $urls = array_map(fn ($e) => $e->url(), $entries);
    sort($urls);
    return $urls;
}

/** Extract slugs from raw rows (for stable comparison). */
function rawSlugs(array $rows): array
{
    $slugs = array_map(fn ($r) => $r['slug'], $rows);
    sort($slugs);
    return $slugs;
}

beforeEach(function () {
    $this->dir = $this->makeTempDir('rkn-vis-');
    buildVisibilityFixture($this->dir);
    [$this->php, $this->sqlite] = buildVisibilityStores($this->dir);
});

// ─────────────────────────────────────────────────────────────────────────────
// (a) Default query: drafts and scheduled ABSENT
// ─────────────────────────────────────────────────────────────────────────────

test('(a) default query excludes drafts — php store', function () {
    $results = (new Query($this->php))->collection('posts')->get();
    $urls = visUrls($results);
    expect(count($urls))->toBe(5);
    foreach ($urls as $url) {
        expect($url)->not->toContain('draft');
        expect($url)->not->toContain('scheduled');
    }
});

test('(a) default query excludes drafts — sqlite store', function () {
    $results = (new Query($this->sqlite))->collection('posts')->get();
    $urls = visUrls($results);
    expect(count($urls))->toBe(5);
    foreach ($urls as $url) {
        expect($url)->not->toContain('draft');
        expect($url)->not->toContain('scheduled');
    }
});

test('(a) count() by default excludes drafts and scheduled — both stores', function () {
    $phpCount = (new Query($this->php))->collection('posts')->count();
    $sqliteCount = (new Query($this->sqlite))->collection('posts')->count();
    expect($phpCount)->toBe(5);
    expect($sqliteCount)->toBe(5);
});

test('(a) findBySlug returns null for draft — php store', function () {
    $result = (new Query($this->php))->findBySlug('posts', 'es', 'draft-1');
    expect($result)->toBeNull();
});

test('(a) findBySlug returns null for draft — sqlite store', function () {
    $result = (new Query($this->sqlite))->findBySlug('posts', 'es', 'draft-1');
    expect($result)->toBeNull();
});

test('(a) findBySlug returns null for scheduled — both stores', function () {
    $php = (new Query($this->php))->findBySlug('posts', 'es', 'scheduled-1');
    $sqlite = (new Query($this->sqlite))->findBySlug('posts', 'es', 'scheduled-1');
    expect($php)->toBeNull();
    expect($sqlite)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// (b) withStatus('draft') shows only drafts; withStatus('scheduled') only scheduled;
//     includeAllStatuses() shows all
// ─────────────────────────────────────────────────────────────────────────────

test('(b) withStatus(draft) shows only drafts — php store', function () {
    $results = (new Query($this->php))->collection('posts')->withStatus('draft')->get();
    $urls = visUrls($results);
    expect(count($urls))->toBe(4); // 3 draft-flag + 1 draft-meta
    foreach ($urls as $url) {
        expect($url)->toContain('draft');
    }
});

test('(b) withStatus(draft) shows only drafts — sqlite store', function () {
    $results = (new Query($this->sqlite))->collection('posts')->withStatus('draft')->get();
    $urls = visUrls($results);
    expect(count($urls))->toBe(4);
    foreach ($urls as $url) {
        expect($url)->toContain('draft');
    }
});

test('(b) withStatus(scheduled) shows only scheduled — php store', function () {
    $results = (new Query($this->php))->collection('posts')->withStatus('scheduled')->get();
    $urls = visUrls($results);
    expect(count($urls))->toBe(2);
    foreach ($urls as $url) {
        expect($url)->toContain('scheduled');
    }
});

test('(b) withStatus(scheduled) shows only scheduled — sqlite store', function () {
    $results = (new Query($this->sqlite))->collection('posts')->withStatus('scheduled')->get();
    $urls = visUrls($results);
    expect(count($urls))->toBe(2);
    foreach ($urls as $url) {
        expect($url)->toContain('scheduled');
    }
});

test('(b) includeAllStatuses shows all 11 entries — php store', function () {
    $count = (new Query($this->php))->collection('posts')->includeAllStatuses()->count();
    expect($count)->toBe(11); // 5 published + 4 draft + 2 scheduled
});

test('(b) includeAllStatuses shows all 11 entries — sqlite store', function () {
    $count = (new Query($this->sqlite))->collection('posts')->includeAllStatuses()->count();
    expect($count)->toBe(11);
});

// ─────────────────────────────────────────────────────────────────────────────
// (c) php and sqlite return the SAME set of keys for each case
// ─────────────────────────────────────────────────────────────────────────────

test('(c) parity: default published — php == sqlite', function () {
    $phpUrls = visUrls((new Query($this->php))->collection('posts')->get());
    $sqliteUrls = visUrls((new Query($this->sqlite))->collection('posts')->get());
    expect($sqliteUrls)->toBe($phpUrls);
});

test('(c) parity: draft status — php == sqlite', function () {
    $phpUrls = visUrls((new Query($this->php))->collection('posts')->withStatus('draft')->get());
    $sqliteUrls = visUrls((new Query($this->sqlite))->collection('posts')->withStatus('draft')->get());
    expect($sqliteUrls)->toBe($phpUrls);
});

test('(c) parity: scheduled status — php == sqlite', function () {
    $phpUrls = visUrls((new Query($this->php))->collection('posts')->withStatus('scheduled')->get());
    $sqliteUrls = visUrls((new Query($this->sqlite))->collection('posts')->withStatus('scheduled')->get());
    expect($sqliteUrls)->toBe($phpUrls);
});

test('(c) parity: all statuses — php == sqlite', function () {
    $phpUrls = visUrls((new Query($this->php))->collection('posts')->includeAllStatuses()->get());
    $sqliteUrls = visUrls((new Query($this->sqlite))->collection('posts')->includeAllStatuses()->get());
    expect($sqliteUrls)->toBe($phpUrls);
});

// ─────────────────────────────────────────────────────────────────────────────
// (d) count() respects status filter equally in both stores
// ─────────────────────────────────────────────────────────────────────────────

test('(d) count parity across all status filters', function () {
    $filters = ['published', 'draft', 'scheduled', 'all'];
    foreach ($filters as $status) {
        $q = $status === 'all'
            ? fn ($q) => $q->collection('posts')->includeAllStatuses()
            : fn ($q) => $q->collection('posts')->withStatus($status);

        $phpCount = $q(new Query($this->php))->count();
        $sqliteCount = $q(new Query($this->sqlite))->count();
        expect($sqliteCount)->toBe($phpCount, "count mismatch for status={$status}");
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// (e) allTags / allDatePeriods do NOT expose draft-only tags or dates
// ─────────────────────────────────────────────────────────────────────────────

test('(e) allTags excludes draft-only tags — php store', function () {
    $tags = $this->php->allTags();
    expect($tags)->toContain('pub-tag');
    expect($tags)->not->toContain('draft-only-tag');
});

test('(e) allTags excludes draft-only tags — sqlite store', function () {
    $tags = $this->sqlite->allTags();
    expect($tags)->toContain('pub-tag');
    expect($tags)->not->toContain('draft-only-tag');
});

test('(e) allTags excludes scheduled-only tags — both stores', function () {
    $phpTags = $this->php->allTags();
    $sqliteTags = $this->sqlite->allTags();
    expect($phpTags)->not->toContain('scheduled-tag');
    expect($sqliteTags)->not->toContain('scheduled-tag');
});

test('(e) allDatePeriods excludes draft-only dates — both stores', function () {
    $phpPeriods = $this->php->allDatePeriods();
    $sqlitePeriods = $this->sqlite->allDatePeriods();
    // Draft entries only have dates in 2024-02; published entries in 2024-01.
    expect($phpPeriods)->not->toContain('2024-02');
    expect($sqlitePeriods)->not->toContain('2024-02');
    expect($phpPeriods)->toContain('2024-01');
    expect($sqlitePeriods)->toContain('2024-01');
});

// ─────────────────────────────────────────────────────────────────────────────
// (f) Resiliencia: dos archivos PUBLICADOS con el mismo full_slug (p.ej. un
//     {slug}.{locale}.md huérfano junto a {slug}.md) no deben tumbar el índice.
// ─────────────────────────────────────────────────────────────────────────────

test('(f) sqlite sync skips a duplicate published full_slug instead of crashing', function () {
    $dir = $this->makeTempDir('rkn-dup-');
    @mkdir($dir . '/content/posts', 0755, true);
    file_put_contents($dir . '/content/posts/uno.md', "---\ntitle: Uno\ndate: \"2024-01-01\"\n---\nok\n");
    // Dos archivos publicados que resuelven al MISMO (posts, es, full_slug=dup):
    file_put_contents($dir . '/content/posts/dup.md', "---\ntitle: Dup A\nslug: dup\n---\nuno\n");
    file_put_contents($dir . '/content/posts/dup.es.md', "---\ntitle: Dup B\nslug: dup\n---\ndos\n");

    $store = new SqliteIndexStore($dir . '/cache/dup.sqlite', new ContentScanner($dir . '/content', 'es'));

    $report = $store->sync(); // NO debe lanzar PDOException

    expect($report['skipped'])->toBe(1);                       // uno de los dos dup se omite
    $q = new Query($store);
    expect($q->collection('posts')->count())->toBe(2);          // uno.md + un solo dup
    expect($q->findBySlug('posts', 'es', 'dup'))->not->toBeNull(); // el publicado sigue resolviendo
});
