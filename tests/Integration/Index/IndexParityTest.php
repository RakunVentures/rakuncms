<?php

declare(strict_types=1);

use Rkn\Cms\Content\ContentScanner;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Content\Stores\PhpArrayIndexStore;
use Rkn\Cms\Content\Stores\SqliteIndexStore;

/**
 * Differential gate: the SQLite store MUST be byte-for-byte equivalent to the
 * in-memory PHP-array store across routing, listings, conditions, sort,
 * pagination, taxonomy, sections, findBySlug and full enumeration — over a
 * deterministic fixture. If any sequence diverges, the redesign is not safe.
 */

/** Deterministic fixture: ~140 entries across collections/sections/locales. */
function buildParityFixture(string $dir): void
{
    @mkdir($dir . '/config', 0755, true);
    file_put_contents($dir . '/config/rakun.yaml', "site:\n  default_locale: es\n");

    // pages (root + named + a future-scheduled draft-ish)
    @mkdir($dir . '/content/pages', 0755, true);
    file_put_contents($dir . '/content/pages/home.md', "---\ntitle: Inicio\nslugs:\n  es: \"\"\n  en: \"\"\n---\nHola\n");
    file_put_contents($dir . '/content/pages/home.en.md', "---\ntitle: Home\nslugs:\n  es: \"\"\n  en: \"\"\n---\nHi\n");
    file_put_contents($dir . '/content/pages/about.md', "---\ntitle: Acerca\n---\nx\n");
    file_put_contents($dir . '/content/pages/draft.md', "---\ntitle: Borrador\ndraft: true\n---\nx\n");
    file_put_contents($dir . '/content/pages/future.md', "---\ntitle: Futuro\npublish_date: \"2999-01-01\"\n---\nx\n");

    // blog: 12 months x es/en, with tags, order prefixes and sections (YYYY/MM)
    $tags = ['moda', 'bodas', 'belleza', 'viajes'];
    for ($m = 1; $m <= 12; $m++) {
        $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
        $secDir = $dir . "/content/blog/2024/{$mm}";
        @mkdir($secDir, 0755, true);
        for ($i = 1; $i <= 5; $i++) {
            $ord = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $tag = $tags[($m + $i) % count($tags)];
            $featured = (($m + $i) % 3 === 0) ? 'true' : 'false';
            $date = "2024-{$mm}-" . str_pad((string) ($i * 5), 2, '0', STR_PAD_LEFT) . " 10:00:00";
            $es = "---\ntitle: \"Post {$mm}-{$i} ES\"\ndate: \"{$date}\"\norder: {$i}\ntags: [\"{$tag}\",\"comun\"]\nfeatured: {$featured}\n---\ncuerpo es {$m} {$i}\n";
            $en = "---\ntitle: \"Post {$mm}-{$i} EN\"\ndate: \"{$date}\"\norder: {$i}\ntags: [\"{$tag}\"]\nfeatured: {$featured}\n---\nbody en {$m} {$i}\n";
            file_put_contents("{$secDir}/{$ord}.post-{$mm}-{$i}.md", $es);
            file_put_contents("{$secDir}/{$ord}.post-{$mm}-{$i}.en.md", $en);
        }
    }
}

/**
 * @return array{0: PhpArrayIndexStore, 1: SqliteIndexStore}
 */
function buildBothStores(string $dir): array
{
    $contentPath = $dir . '/content';
    $scanned = (new ContentScanner($contentPath, 'es'))->scan();
    $php = new PhpArrayIndexStore(['entries' => $scanned['entries'], 'indices' => $scanned['indices']]);

    $sqlite = new SqliteIndexStore($dir . '/cache/index.sqlite', new ContentScanner($contentPath, 'es'));
    $sqlite->sync();

    return [$php, $sqlite];
}

/** Stable identity for an Entry sequence (url is unique per entry). */
function urlSeq(array $entries): array
{
    return array_map(fn ($e) => $e->url(), $entries);
}

beforeEach(function () {
    $this->dir = $this->makeTempDir('rkn-parity-');
    buildParityFixture($this->dir);
    [$this->php, $this->sqlite] = buildBothStores($this->dir);
});

test('full enumeration count matches (drafts + future excluded equally)', function () {
    $phpCount = iterator_count((function () { yield from $this->php->each(); })());
    $sqliteCount = iterator_count((function () { yield from $this->sqlite->each(); })());
    expect($sqliteCount)->toBe($phpCount);
    // 12*5*2 blog + home(es/en)+about = 120 + 3 = 123 (draft + future excluded)
    expect($phpCount)->toBe(123);
});

$cases = [
    'blog es all'              => fn (Query $q) => $q->collection('blog')->locale('es'),
    'blog es by date desc'     => fn (Query $q) => $q->collection('blog')->locale('es')->sort('date', 'desc'),
    'blog es order asc paged'  => fn (Query $q) => $q->collection('blog')->locale('es')->sort('order', 'asc')->limit(7)->offset(10),
    'blog en featured'         => fn (Query $q) => $q->collection('blog')->locale('en')->where('featured', '=', true),
    'blog es tag has'          => fn (Query $q) => $q->collection('blog')->locale('es')->where('tags', 'has', 'moda'),
    'blog es section'          => fn (Query $q) => $q->collection('blog')->locale('es')->section('2024/03')->sort('order', 'asc'),
    'blog es title contains'   => fn (Query $q) => $q->collection('blog')->locale('es')->where('title', 'contains', 'post 03'),
    'pages es'                 => fn (Query $q) => $q->collection('pages')->locale('es'),
];

foreach ($cases as $name => $chain) {
    test("query parity: {$name}", function () use ($chain) {
        $phpRes = urlSeq($chain(new Query($this->php))->get());
        $sqliteRes = urlSeq($chain(new Query($this->sqlite))->get());
        expect($sqliteRes)->toBe($phpRes);
        expect($sqliteRes)->not->toBeEmpty();
    });
}

test('count parity (collection/locale/section filters)', function () {
    foreach ([['blog', 'es', null], ['blog', 'en', null], ['pages', 'es', null]] as [$c, $l, $s]) {
        $php = (new Query($this->php))->collection($c)->locale($l);
        $sqlite = (new Query($this->sqlite))->collection($c)->locale($l);
        expect($sqlite->count())->toBe($php->count());
    }
});

test('findBySlug parity across every entry (routing)', function () {
    foreach ($this->php->each() as $row) {
        $c = $row['collection'];
        $l = $row['locale'];
        $full = (new ContentScanner($this->dir . '/content', 'es'))->fullSlug($row);
        $p = (new Query($this->php))->findBySlug($c, $l, $full);
        $s = (new Query($this->sqlite))->findBySlug($c, $l, $full);
        expect($s?->url())->toBe($p?->url());
        expect($s)->not->toBeNull();
    }
});

test('sections parity', function () {
    $php = (new Query($this->php))->collection('blog')->sections('es');
    $sqlite = (new Query($this->sqlite))->collection('blog')->sections('es');
    $pick = fn (array $list) => array_map(fn ($x) => [$x['section'], $x['title'], $x['order']], $list);
    expect($pick($sqlite))->toBe($pick($php));
    expect($sqlite)->not->toBeEmpty();
});

test('allTags / allDatePeriods parity', function () {
    $pt = $this->php->allTags();
    $st = $this->sqlite->allTags();
    sort($pt);
    sort($st);
    expect($st)->toBe($pt);

    $pd = $this->php->allDatePeriods();
    $sd = $this->sqlite->allDatePeriods();
    sort($pd);
    sort($sd);
    expect($sd)->toBe($pd);
});

test('findEntryByPath parity (exact + prefix+locale)', function () {
    foreach (['blog/2024/03/03.post-03-3', 'pages/about', 'blog/2024/06'] as $path) {
        $p = $this->php->findEntryByPath($path, 'es');
        $s = $this->sqlite->findEntryByPath($path, 'es');
        expect($s['url'] ?? null)->toBe($p['url'] ?? null);
    }
});

test('idempotent sync (second sync changes nothing)', function () {
    $report = $this->sqlite->sync();
    expect($report['inserted'])->toBe(0);
    expect($report['updated'])->toBe(0);
    expect($report['deleted'])->toBe(0);
});
