<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Tools\ListCollectionsTool;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_mcp_coll_' . uniqid();
    mkdir($this->tmpDir . '/content/pages', 0755, true);
    mkdir($this->tmpDir . '/content/blog', 0755, true);
    mkdir($this->tmpDir . '/content/_globals', 0755, true);
    mkdir($this->tmpDir . '/cache', 0755, true);

    file_put_contents($this->tmpDir . '/content/pages/home.md', "---\ntitle: Home\n---\nWelcome");
    file_put_contents($this->tmpDir . '/content/pages/about.md', "---\ntitle: About\n---\nAbout");
    file_put_contents($this->tmpDir . '/content/blog/post-1.md', "---\ntitle: First Post\ndate: 2025-01-01\n---\nContent");
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

test('lists collections with entry counts', function () {
    $tool = new ListCollectionsTool($this->tmpDir);
    $result = $tool->execute([]);

    expect($result['collections'])->toHaveCount(2);

    $names = array_column($result['collections'], 'name');
    expect($names)->toContain('pages');
    expect($names)->toContain('blog');

    $pages = array_filter($result['collections'], fn ($c) => $c['name'] === 'pages');
    $pages = array_values($pages);
    expect($pages[0]['entry_count'])->toBe(2);
});

test('excludes special directories like _globals', function () {
    $tool = new ListCollectionsTool($this->tmpDir);
    $result = $tool->execute([]);

    $names = array_column($result['collections'], 'name');
    expect($names)->not->toContain('_globals');
});

test('includes collection config when _collection.yaml exists', function () {
    file_put_contents($this->tmpDir . '/content/blog/_collection.yaml', "sort: date\nsort_direction: desc\ntemplate: blog/show");

    $tool = new ListCollectionsTool($this->tmpDir);
    $result = $tool->execute([]);

    $blog = array_filter($result['collections'], fn ($c) => $c['name'] === 'blog');
    $blog = array_values($blog);
    expect($blog[0]['config'])->toHaveKey('sort');
    expect($blog[0]['config']['sort'])->toBe('date');
});
