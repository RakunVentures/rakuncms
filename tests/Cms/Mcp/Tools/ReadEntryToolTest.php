<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Tools\ReadEntryTool;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_mcp_read_' . uniqid();
    mkdir($this->tmpDir . '/content/pages', 0755, true);
    mkdir($this->tmpDir . '/cache', 0755, true);

    file_put_contents($this->tmpDir . '/content/pages/about.md', <<<'MD'
---
title: Nosotros
template: pages/about
meta:
  description: Página sobre nosotros
---

# Sobre nosotros

Somos un equipo dedicado.
MD);
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

test('reads entry with frontmatter and html', function () {
    $tool = new ReadEntryTool($this->tmpDir);
    $result = $tool->execute(['collection' => 'pages', 'slug' => 'about', 'locale' => 'es']);

    expect($result['title'])->toBe('Nosotros');
    expect($result['collection'])->toBe('pages');
    expect($result)->toHaveKey('raw_markdown');
    expect($result)->toHaveKey('html');
    expect($result['html'])->toContain('<h1>Sobre nosotros</h1>');
});

test('returns error for missing entry', function () {
    $tool = new ReadEntryTool($this->tmpDir);
    $result = $tool->execute(['collection' => 'pages', 'slug' => 'nonexistent', 'locale' => 'es']);

    expect($result)->toHaveKey('error');
});

test('returns error when required params missing', function () {
    $tool = new ReadEntryTool($this->tmpDir);
    $result = $tool->execute([]);

    expect($result)->toHaveKey('error');
});
