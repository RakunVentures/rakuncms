<?php

declare(strict_types=1);

use Rkn\Cms\Content\ContentScanner;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Content\Stores\PhpArrayIndexStore;
use Rkn\Cms\Content\Stores\SqliteIndexStore;

/**
 * Causa B regression (end-to-end). Reproduces the WXR-import shape that hid 12
 * fiancee posts: WordPress status copied verbatim (`future`/`pending`) plus a
 * legacy top-level `date` in `Y-m-d H:i:s` format and NO publish_date.
 *
 * A WXR post whose date is already in the past must be PUBLISHED (visible to the
 * public), while a genuinely future one must stay SCHEDULED (hidden). Both stores
 * must agree. This also pins that parseDate tolerates the space-separated WP date.
 */

function buildWxrFixture(string $dir): void
{
    @mkdir($dir . '/config', 0755, true);
    file_put_contents($dir . '/config/rakun.yaml', "site:\n  default_locale: es\n");
    @mkdir($dir . '/content/posts', 0755, true);

    // WXR shape: top-level `status` + space-separated `date`, no publish_date.
    // (1) Past scheduled WP post → due → must publish.
    file_put_contents(
        "{$dir}/content/posts/past-wp.md",
        "---\ntitle: \"Past WP Scheduled\"\ndate: \"2018-03-15 10:30:00\"\nstatus: \"future\"\n---\nbody\n"
    );

    // (2) Genuinely future scheduled WP post → must stay hidden.
    file_put_contents(
        "{$dir}/content/posts/future-wp.md",
        "---\ntitle: \"Future WP Scheduled\"\ndate: \"2999-01-01 10:00:00\"\nstatus: \"pending\"\n---\nbody\n"
    );

    // (3) A normal published post for a sanity anchor.
    file_put_contents(
        "{$dir}/content/posts/plain.md",
        "---\ntitle: \"Plain Published\"\ndate: \"2024-01-01\"\n---\nbody\n"
    );
}

/** @return array{0: PhpArrayIndexStore, 1: SqliteIndexStore} */
function buildWxrStores(string $dir): array
{
    $contentPath = $dir . '/content';
    $scanned = (new ContentScanner($contentPath, 'es'))->scan();
    $php = new PhpArrayIndexStore(['entries' => $scanned['entries'], 'indices' => $scanned['indices']]);

    $sqlite = new SqliteIndexStore($dir . '/cache/wxr-index.sqlite', new ContentScanner($contentPath, 'es'));
    $sqlite->sync();

    return [$php, $sqlite];
}

function urlsOf(array $entries): array
{
    $urls = array_map(fn ($e) => $e->url(), $entries);
    sort($urls);
    return $urls;
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/rkn-wxr-' . uniqid();
    buildWxrFixture($this->dir);
    [$this->php, $this->sqlite] = buildWxrStores($this->dir);
});

afterEach(function () {
    $cleanup = function (string $dir) use (&$cleanup): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new DirectoryIterator($dir) as $item) {
            if ($item->isDot()) continue;
            $item->isDir() ? $cleanup($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    };
    $cleanup($this->dir);
});

test('past WXR scheduled post is published and publicly visible — php store', function () {
    $urls = urlsOf((new Query($this->php))->collection('posts')->get());
    expect($urls)->toContain('/posts/past-wp');
    expect($urls)->toContain('/posts/plain');
    expect($urls)->not->toContain('/posts/future-wp');
});

test('past WXR scheduled post is published and publicly visible — sqlite store', function () {
    $urls = urlsOf((new Query($this->sqlite))->collection('posts')->get());
    expect($urls)->toContain('/posts/past-wp');
    expect($urls)->toContain('/posts/plain');
    expect($urls)->not->toContain('/posts/future-wp');
});

test('genuinely future WXR post stays scheduled (hidden) in both stores', function () {
    $phpScheduled = urlsOf((new Query($this->php))->collection('posts')->withStatus('scheduled')->get());
    $sqliteScheduled = urlsOf((new Query($this->sqlite))->collection('posts')->withStatus('scheduled')->get());

    expect($phpScheduled)->toBe(['/posts/future-wp']);
    expect($sqliteScheduled)->toBe(['/posts/future-wp']);
});
