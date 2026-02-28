<?php

use Rkn\Cms\Cache\PageCache;
use Rkn\Cms\Cache\Invalidator;

beforeEach(function () {
    $this->cacheDir = sys_get_temp_dir() . '/rakun-test-invalidator-' . uniqid();
    mkdir($this->cacheDir, 0775, true);
    $this->pageCache = new PageCache($this->cacheDir . '/pages');
    $this->trackingFile = $this->cacheDir . '/dependencies.php';
    $this->invalidator = new Invalidator($this->pageCache, $this->trackingFile);
});

afterEach(function () {
    if (is_dir($this->cacheDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->cacheDir);
    }
});

it('tracks page dependencies', function () {
    $this->invalidator->track('/es/nosotros', ['/content/pages/nosotros.md']);
    $deps = $this->invalidator->getDependencies();
    expect($deps)->toHaveKey('/es/nosotros');
    expect($deps['/es/nosotros'])->toBe(['/content/pages/nosotros.md']);
});

it('invalidates pages when content file changes', function () {
    $this->pageCache->set('/es/home', '<html>home</html>');
    $this->pageCache->set('/es/nosotros', '<html>about</html>');
    $this->invalidator->track('/es/home', ['/content/pages/home.md', '/templates/home.twig']);
    $this->invalidator->track('/es/nosotros', ['/content/pages/nosotros.md']);

    $invalidated = $this->invalidator->invalidateByFile('/content/pages/home.md');

    expect($invalidated)->toBe(['/es/home']);
    expect($this->pageCache->has('/es/home'))->toBeFalse();
    expect($this->pageCache->has('/es/nosotros'))->toBeTrue();
});

it('invalidates specific URI', function () {
    $this->pageCache->set('/es/test', '<html>test</html>');
    $this->invalidator->track('/es/test', ['/content/pages/test.md']);
    $this->invalidator->invalidateUri('/es/test');
    expect($this->pageCache->has('/es/test'))->toBeFalse();
});

it('clears all tracking and cache', function () {
    $this->pageCache->set('/es/a', '<html>a</html>');
    $this->pageCache->set('/es/b', '<html>b</html>');
    $this->invalidator->track('/es/a', ['a.md']);
    $this->invalidator->track('/es/b', ['b.md']);
    $this->invalidator->clearAll();
    expect($this->invalidator->getDependencies())->toBe([]);
    expect($this->pageCache->has('/es/a'))->toBeFalse();
    expect($this->pageCache->has('/es/b'))->toBeFalse();
});

it('persists dependencies across instances', function () {
    $this->invalidator->track('/es/test', ['test.md']);
    // Create new instance that reads from same tracking file
    $newInvalidator = new Invalidator($this->pageCache, $this->trackingFile);
    $deps = $newInvalidator->getDependencies();
    expect($deps)->toHaveKey('/es/test');
});
