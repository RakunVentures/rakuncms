<?php

declare(strict_types=1);

use Rkn\Cms\Content\ContentScanner;
use Rkn\Cms\Content\DraftResolver;
use Rkn\Cms\Content\IndexStoreFactory;
use Rkn\Cms\Content\Parser;
use Rkn\Cms\Content\ScheduleChecker;
use Rkn\Cms\Content\Storage\FileContentStorage;
use Rkn\Cms\Http\Controllers\GlobalsApiController;
use Rkn\Cms\Template\Extensions\ContentExtension;
use Rkn\Framework\Application;

/**
 * 500/YAML resilience: a single malformed YAML document (frontmatter, manifest,
 * globals or config) must NOT take down the request/sync. Every hot-path parse
 * site degrades gracefully and logs a `[rakun]` line instead of throwing.
 */

// Unterminated double-quoted scalar → symfony/spatie/commonmark all throw ParseException.
const BAD_FM = "---\ntitle: \"unterminated\nbroken: : :\n---\nReal body text here.\n";

/**
 * Run $fn with error_log redirected to a temp file; return [result, logContents].
 * @return array{0: mixed, 1: string}
 */
function captureErrorLog(callable $fn): array
{
    $logFile = sys_get_temp_dir() . '/rakun-errlog-' . uniqid() . '.log';
    $prev = ini_get('error_log');
    ini_set('error_log', $logFile);
    try {
        $result = $fn();
    } finally {
        ini_set('error_log', $prev === false ? '' : $prev);
    }
    $log = file_exists($logFile) ? (string) file_get_contents($logFile) : '';
    @unlink($logFile);

    return [$result, $log];
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/rkn-yaml-res-' . uniqid();
    mkdir($this->dir . '/content/blog', 0755, true);
});

afterEach(function () {
    // Some tests boot an Application singleton; reset it so it never leaks.
    $prop = new ReflectionProperty(Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);

    $cleanup = function (string $dir) use (&$cleanup): void {
        if (!is_dir($dir)) return;
        foreach (new DirectoryIterator($dir) as $i) {
            if ($i->isDot()) continue;
            $i->isDir() ? $cleanup($i->getPathname()) : @unlink($i->getPathname());
        }
        @rmdir($dir);
    };
    $cleanup($this->dir);
});

test('ContentScanner::indexFile skips a malformed .md instead of aborting the sync', function () {
    $file = $this->dir . '/content/blog/bad.md';
    file_put_contents($file, BAD_FM);

    [$result, $log] = captureErrorLog(fn () =>
        (new ContentScanner($this->dir . '/content', 'es'))->indexFile($file, 'blog', $this->dir . '/content/blog')
    );

    expect($result)->toBeNull();
    expect($log)->toContain('[rakun]');
});

test('Parser::parse renders the body when frontmatter is malformed', function () {
    $file = $this->dir . '/content/blog/bad.md';
    file_put_contents($file, BAD_FM);

    [$result, $log] = captureErrorLog(fn () => (new Parser())->parse($file));

    expect($result['frontmatter'])->toBe([]);
    expect($result['html'])->toContain('Real body text here.');
    expect($log)->toContain('[rakun]');
});

test('FileContentStorage::read returns empty frontmatter for a malformed file', function () {
    file_put_contents($this->dir . '/content/blog/bad.en.md', BAD_FM);

    [$body, $log] = captureErrorLog(fn () =>
        (new FileContentStorage($this->dir, 'es'))->read('blog', 'en', 'bad')
    );

    expect($body)->not->toBeNull();
    expect($body->frontmatter)->toBe([]);
    expect($body->body)->toContain('Real body text here.');
    expect($log)->toContain('[rakun]');
});

test('IndexStoreFactory::make survives a malformed rakun.yaml (no throw)', function () {
    mkdir($this->dir . '/config', 0755, true);
    file_put_contents($this->dir . '/config/rakun.yaml', "site:\n  default_locale: \"unterminated\n");

    [$store, $log] = captureErrorLog(fn () => IndexStoreFactory::make($this->dir));

    expect($store)->not->toBeNull();
    expect($log)->toContain('[rakun]');
});

test('ScheduleChecker::findPublishableEntries skips a malformed .md and still returns the good ones', function () {
    file_put_contents($this->dir . '/content/blog/bad.md', BAD_FM);
    file_put_contents(
        $this->dir . '/content/blog/good.md',
        "---\ntitle: \"Ready\"\npublish_date: \"2020-01-01\"\n---\nBody.\n"
    );

    [$entries, $log] = captureErrorLog(fn () =>
        (new ScheduleChecker($this->dir))->findPublishableEntries()
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0]['title'])->toBe('Ready');
    expect($log)->toContain('[rakun]');
});

test('DraftResolver::findDraft skips a malformed .md and still finds the good draft', function () {
    file_put_contents($this->dir . '/content/blog/bad.md', BAD_FM);
    file_put_contents(
        $this->dir . '/content/blog/ready.en.md',
        "---\ntitle: \"Ready Draft\"\ndraft: true\n---\nBody.\n"
    );

    [$entry, $log] = captureErrorLog(fn () =>
        (new DraftResolver($this->dir))->findDraft('blog', 'en', 'ready')
    );

    expect($entry)->not->toBeNull();
    expect($entry->title())->toBe('Ready Draft');
    expect($log)->toContain('[rakun]');
});

test('GlobalsApiController::show returns empty data for a malformed global instead of 500', function () {
    mkdir($this->dir . '/content/_globals', 0755, true);
    file_put_contents($this->dir . '/content/_globals/bad.yaml', "foo: \"unterminated\n");

    [$response, $log] = captureErrorLog(fn () =>
        (new GlobalsApiController($this->dir))->show('bad')
    );

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getBody())->toContain('"data": []');
    expect($log)->toContain('[rakun]');
});

test('ContentExtension::global returns empty for a malformed global instead of throwing', function () {
    mkdir($this->dir . '/content/_globals', 0755, true);
    file_put_contents($this->dir . '/content/_globals/bad.yaml', "foo: \"unterminated\n");
    file_put_contents($this->dir . '/.env', '');
    new Application($this->dir);

    [$data, $log] = captureErrorLog(fn () =>
        (new ContentExtension())->global('bad')
    );

    expect($data)->toBe([]);
    expect($log)->toContain('[rakun]');
});
