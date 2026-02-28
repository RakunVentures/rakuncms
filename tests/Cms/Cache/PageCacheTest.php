<?php

use Rkn\Cms\Cache\PageCache;

beforeEach(function () {
    $this->cacheDir = sys_get_temp_dir() . '/rakun-test-page-cache-' . uniqid();
    $this->cache = new PageCache($this->cacheDir);
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

it('stores and retrieves page HTML', function () {
    $html = '<html><body>Hello</body></html>';
    $this->cache->set('/es/nosotros', $html);
    expect($this->cache->get('/es/nosotros'))->toBe($html);
});

it('returns null for missing pages', function () {
    expect($this->cache->get('/es/missing'))->toBeNull();
});

it('checks page existence', function () {
    expect($this->cache->has('/es/test'))->toBeFalse();
    $this->cache->set('/es/test', '<html></html>');
    expect($this->cache->has('/es/test'))->toBeTrue();
});

it('deletes a cached page', function () {
    $this->cache->set('/es/test', '<html></html>');
    $this->cache->delete('/es/test');
    expect($this->cache->has('/es/test'))->toBeFalse();
});

it('clears all cached pages', function () {
    $this->cache->set('/es/page1', '<html>1</html>');
    $this->cache->set('/en/page2', '<html>2</html>');
    $this->cache->clear();
    expect($this->cache->has('/es/page1'))->toBeFalse();
    expect($this->cache->has('/en/page2'))->toBeFalse();
});

it('handles root URI', function () {
    $this->cache->set('/', '<html>root</html>');
    expect($this->cache->get('/'))->toBe('<html>root</html>');
});
