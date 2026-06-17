<?php

declare(strict_types=1);

use Rkn\Cms\Cli\CacheClearCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Contract tests for cache:clear.
 *
 * Locks the five paths this command is responsible for. Regressing any of these
 * silently means template/asset edits stop propagating after deploy — exactly
 * the F6 footgun this hardening responds to.
 */

beforeEach(function () {
    $this->base = sys_get_temp_dir() . '/rkn-cache-clear-' . uniqid();
    mkdir($this->base . '/cache/templates/sub', 0755, true);
    mkdir($this->base . '/cache/pages/blog', 0755, true);
    mkdir($this->base . '/cache/data', 0755, true);
    file_put_contents($this->base . '/cache/templates/sub/abc.php', '<?php');
    file_put_contents($this->base . '/cache/pages/blog/foo.html', '<html></html>');
    file_put_contents($this->base . '/cache/pages/index.html', '<html></html>');
    file_put_contents($this->base . '/cache/content-index.php', '<?php return [];');
    file_put_contents($this->base . '/cache/data/foo.cache', 'x');
    file_put_contents($this->base . '/cache/dependencies.php', '<?php return [];');

    $this->tester = new CommandTester(new CacheClearCommand());
});

afterEach(function () {
    if (!is_dir($this->base)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($this->base);
});

it('clears Twig compiled templates, keeping the directory', function () {
    $this->tester->execute(['--base' => $this->base]);

    expect(is_dir($this->base . '/cache/templates'))->toBeTrue()
        ->and(glob($this->base . '/cache/templates/*'))->toBe([]);
});

it('clears the content index file', function () {
    $this->tester->execute(['--base' => $this->base]);

    expect(is_file($this->base . '/cache/content-index.php'))->toBeFalse();
});

it('clears the page HTML cache, keeping the directory', function () {
    $this->tester->execute(['--base' => $this->base]);

    expect(is_dir($this->base . '/cache/pages'))->toBeTrue()
        ->and(glob($this->base . '/cache/pages/*'))->toBe([]);
});

it('clears the PSR-16 data cache, keeping the directory', function () {
    $this->tester->execute(['--base' => $this->base]);

    expect(is_dir($this->base . '/cache/data'))->toBeTrue()
        ->and(glob($this->base . '/cache/data/*'))->toBe([]);
});

it('clears the dependency tracking file', function () {
    $this->tester->execute(['--base' => $this->base]);

    expect(is_file($this->base . '/cache/dependencies.php'))->toBeFalse();
});

it('does not delete the cache root directory itself', function () {
    $this->tester->execute(['--base' => $this->base]);

    expect(is_dir($this->base . '/cache'))->toBeTrue();
});

it('is idempotent — running twice does not fail', function () {
    $first = $this->tester->execute(['--base' => $this->base]);
    $second = $this->tester->execute(['--base' => $this->base]);

    expect($first)->toBe(0)->and($second)->toBe(0);
});

it('reports a clean state when no cache subsystems exist', function () {
    // Fresh base with no cache/ at all → no subsystem matches → "already clean" branch.
    $emptyBase = sys_get_temp_dir() . '/rkn-cache-clear-empty-' . uniqid();
    mkdir($emptyBase, 0755, true);

    $this->tester->execute(['--base' => $emptyBase]);

    expect($this->tester->getDisplay())->toContain('Cache is already clean');

    rmdir($emptyBase);
});

it('reports each cleared subsystem on first run', function () {
    $this->tester->execute(['--base' => $this->base]);

    $output = $this->tester->getDisplay();
    expect($output)->toContain('Twig templates')
        ->and($output)->toContain('Content index')
        ->and($output)->toContain('Page HTML cache')
        ->and($output)->toContain('Data cache')
        ->and($output)->toContain('Dependency tracking');
});

it('fails with a clear error when --base does not exist', function () {
    $exitCode = $this->tester->execute(['--base' => '/path/that/does/not/exist-' . uniqid()]);

    expect($exitCode)->not->toBe(0)
        ->and($this->tester->getDisplay())->toContain('Base path does not exist');
});

it('skips gracefully when individual cache subdirs are missing', function () {
    // Remove just one subsystem; command must still succeed for the rest.
    unlink($this->base . '/cache/content-index.php');

    $exitCode = $this->tester->execute(['--base' => $this->base]);

    expect($exitCode)->toBe(0)
        ->and(glob($this->base . '/cache/templates/*'))->toBe([]);
});
