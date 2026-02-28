<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Tools\SearchContentTool;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_mcp_search_' . uniqid();
    mkdir($this->tmpDir . '/content/pages', 0755, true);
    mkdir($this->tmpDir . '/content/blog', 0755, true);
    mkdir($this->tmpDir . '/cache', 0755, true);

    file_put_contents($this->tmpDir . '/content/pages/home.md', "---\ntitle: Inicio\nmeta:\n  description: Página principal del sitio\n---\nBienvenido");
    file_put_contents($this->tmpDir . '/content/pages/about.md', "---\ntitle: Nosotros\nmeta:\n  description: Conoce al equipo\n---\nSobre nosotros");
    file_put_contents($this->tmpDir . '/content/blog/post-1.md', "---\ntitle: Tutorial PHP\nmeta:\n  description: Aprende PHP desde cero\n---\nContenido");
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

test('searches by title', function () {
    $tool = new SearchContentTool($this->tmpDir);
    $result = $tool->execute(['query' => 'PHP']);

    expect($result['count'])->toBe(1);
    expect($result['results'][0]['title'])->toBe('Tutorial PHP');
});

test('searches by description', function () {
    $tool = new SearchContentTool($this->tmpDir);
    $result = $tool->execute(['query' => 'equipo']);

    expect($result['count'])->toBe(1);
    expect($result['results'][0]['slug'])->toBe('about');
});

test('search is case insensitive', function () {
    $tool = new SearchContentTool($this->tmpDir);
    $result = $tool->execute(['query' => 'php']);

    expect($result['count'])->toBe(1);
});

test('limits results', function () {
    $tool = new SearchContentTool($this->tmpDir);
    $result = $tool->execute(['query' => 'o', 'limit' => 1]);

    expect($result['count'])->toBeLessThanOrEqual(1);
});

test('returns error when query missing', function () {
    $tool = new SearchContentTool($this->tmpDir);
    $result = $tool->execute([]);

    expect($result)->toHaveKey('error');
});
