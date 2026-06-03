<?php

declare(strict_types=1);

use Rkn\Cms\Content\ContentScanner;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Content\Stores\SqliteIndexStore;

/** Write N blog posts (one collection, es) under YYYY/MM sections. */
function genBlog(string $dir, int $n): void
{
    @mkdir($dir . '/config', 0755, true);
    file_put_contents($dir . '/config/rakun.yaml', "site:\n  default_locale: es\n");
    for ($i = 1; $i <= $n; $i++) {
        $mm = str_pad((string) (($i % 12) + 1), 2, '0', STR_PAD_LEFT);
        $secDir = $dir . "/content/blog/2024/{$mm}";
        if (!is_dir($secDir)) {
            mkdir($secDir, 0755, true);
        }
        $date = "2024-{$mm}-" . str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT) . " 10:00:00";
        file_put_contents(
            "{$secDir}/post-{$i}.md",
            "---\ntitle: \"Post {$i}\"\ndate: \"{$date}\"\norder: {$i}\ntags: [\"t" . ($i % 7) . "\"]\n---\nbody {$i}\n"
        );
    }
}

function makeSqliteStore(string $dir): SqliteIndexStore
{
    return new SqliteIndexStore($dir . '/cache/index.sqlite', new ContentScanner($dir . '/content', 'es'));
}

test('a paginated query over a large index stays bounded in memory', function () {
    $dir = $this->makeTempDir('rkn-budget-');
    genBlog($dir, 3000);
    $store = makeSqliteStore($dir);
    $store->sync();

    gc_collect_cycles();
    $before = memory_get_usage(true);
    $rows = (new Query($store))->collection('blog')->locale('es')->sort('date', 'desc')->limit(10)->get();
    $after = memory_get_usage(true);

    expect(count($rows))->toBe(10);
    // A limit(10) over 3000 entries must NOT retain memory proportional to N
    // (the legacy array index needed ~140MB for ~10k). Generous 16MB ceiling.
    expect($after - $before)->toBeLessThan(16 * 1024 * 1024);
})->group('budget');

test('count over a large index is O(1)-ish in memory', function () {
    $dir = $this->makeTempDir('rkn-budget-');
    genBlog($dir, 3000);
    $store = makeSqliteStore($dir);
    $store->sync();

    gc_collect_cycles();
    $before = memory_get_usage(true);
    $count = (new Query($store))->collection('blog')->locale('es')->count();
    $after = memory_get_usage(true);

    expect($count)->toBe(3000);
    expect($after - $before)->toBeLessThan(4 * 1024 * 1024);
})->group('budget');

test('incremental sync touches only changed files', function () {
    $dir = $this->makeTempDir('rkn-incr-');
    genBlog($dir, 50);
    $store = makeSqliteStore($dir);

    $first = $store->sync();
    expect($first['inserted'])->toBe(50);

    // Second sync: nothing changed.
    $noop = $store->sync();
    expect($noop)->toMatchArray(['inserted' => 0, 'updated' => 0, 'deleted' => 0]);

    // Add one new post.
    $secDir = $dir . '/content/blog/2024/01';
    file_put_contents("{$secDir}/post-new.md", "---\ntitle: New\ndate: \"2024-01-09 10:00:00\"\n---\nx\n");
    $added = $store->sync();
    expect($added)->toMatchArray(['inserted' => 1, 'updated' => 0, 'deleted' => 0]);

    // Modify it (bump mtime into the future so the change is detectable).
    file_put_contents("{$secDir}/post-new.md", "---\ntitle: New v2\ndate: \"2024-01-09 10:00:00\"\n---\nx2\n");
    touch("{$secDir}/post-new.md", time() + 20);
    $modified = $store->sync();
    expect($modified)->toMatchArray(['inserted' => 0, 'updated' => 1, 'deleted' => 0]);

    // Remove it.
    unlink("{$secDir}/post-new.md");
    $removed = $store->sync();
    expect($removed)->toMatchArray(['inserted' => 0, 'updated' => 0, 'deleted' => 1]);

    // Final state: back to 50, and the new post is gone from queries.
    expect((new Query($store))->collection('blog')->locale('es')->count())->toBe(50);
})->group('budget');
