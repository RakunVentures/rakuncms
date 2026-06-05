<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Tools\ListEntriesTool;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_mcp_entries_' . uniqid();
    mkdir($this->tmpDir . '/content/pages', 0755, true);
    mkdir($this->tmpDir . '/content/blog', 0755, true);
    mkdir($this->tmpDir . '/cache', 0755, true);

    file_put_contents($this->tmpDir . '/content/pages/home.md', "---\ntitle: Inicio\norder: 1\n---\nBienvenido");
    file_put_contents($this->tmpDir . '/content/pages/about.md', "---\ntitle: Nosotros\norder: 2\n---\nSobre nosotros");
    file_put_contents($this->tmpDir . '/content/blog/post-1.md', "---\ntitle: Primer Post\ndate: 2025-03-01\ntags:\n  - php\n---\nContenido");
    file_put_contents($this->tmpDir . '/content/blog/post-2.md', "---\ntitle: Segundo Post\ndate: 2025-03-15\ntags:\n  - cms\n---\nContenido 2");
    file_put_contents($this->tmpDir . '/content/blog/draft.md', "---\ntitle: Draft Post\nstatus: draft\ndate: 2025-03-20\n---\nContenido draft");
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tmpDir);
});

test('lists entries for a collection', function () {
    $tool = new ListEntriesTool($this->tmpDir);
    $result = $tool->execute(['collection' => 'pages']);

    expect($result['collection'])->toBe('pages');
    expect($result['count'])->toBe(2);
    expect($result['entries'])->toHaveCount(2);
    expect($result['meta']['total'])->toBe(2);
});

test('filters entries by status and paginates', function () {
    $tool = new ListEntriesTool($this->tmpDir);
    $result = $tool->execute(['collection' => 'blog', 'status' => 'all', 'page' => 2, 'per_page' => 2]);

    expect($result['meta']['total'])->toBe(3);
    expect($result['meta']['page'])->toBe(2);
    expect($result['count'])->toBe(1);
});

test('returns error when collection missing', function () {
    $tool = new ListEntriesTool($this->tmpDir);
    $result = $tool->execute([]);

    expect($result)->toHaveKey('error');
});

test('limits results', function () {
    $tool = new ListEntriesTool($this->tmpDir);
    $result = $tool->execute(['collection' => 'blog', 'limit' => 1]);

    expect($result['count'])->toBe(1);
});

test('sorts entries', function () {
    $tool = new ListEntriesTool($this->tmpDir);
    $result = $tool->execute(['collection' => 'blog', 'sort' => 'date', 'direction' => 'desc']);

    expect($result['entries'][0]['title'])->toBe('Segundo Post');
});
