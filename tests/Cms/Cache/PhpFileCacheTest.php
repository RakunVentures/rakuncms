<?php

use Rkn\Cms\Cache\PhpFileCache;

beforeEach(function () {
    $this->cacheDir = sys_get_temp_dir() . '/rakun-test-cache-' . uniqid();
    $this->cache = new PhpFileCache($this->cacheDir);
});

afterEach(function () {
    // Cleanup
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

it('creates the cache directory', function () {
    expect(is_dir($this->cacheDir))->toBeTrue();
});

it('stores and retrieves values', function () {
    $this->cache->set('test-key', ['foo' => 'bar']);
    $value = $this->cache->get('test-key');
    expect($value)->toBe(['foo' => 'bar']);
});

it('returns default for missing keys', function () {
    expect($this->cache->get('nonexistent', 'default'))->toBe('default');
});

it('checks key existence', function () {
    expect($this->cache->has('key'))->toBeFalse();
    $this->cache->set('key', 'value');
    expect($this->cache->has('key'))->toBeTrue();
});

it('deletes a key', function () {
    $this->cache->set('key', 'value');
    expect($this->cache->has('key'))->toBeTrue();
    $this->cache->delete('key');
    expect($this->cache->has('key'))->toBeFalse();
});

it('clears all cached values', function () {
    $this->cache->set('a', 1);
    $this->cache->set('b', 2);
    $this->cache->clear();
    expect($this->cache->has('a'))->toBeFalse();
    expect($this->cache->has('b'))->toBeFalse();
});

it('handles TTL expiration', function () {
    $this->cache->set('expiring', 'value', 1);
    expect($this->cache->get('expiring'))->toBe('value');
    sleep(2);
    expect($this->cache->get('expiring'))->toBeNull();
});

it('stores multiple values', function () {
    $this->cache->setMultiple(['x' => 10, 'y' => 20]);
    $values = $this->cache->getMultiple(['x', 'y']);
    expect($values)->toBe(['x' => 10, 'y' => 20]);
});
