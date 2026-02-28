<?php

declare(strict_types=1);

use Rkn\Cms\Content\Indexer;

beforeEach(function () {
    // Create a temporary project structure
    $this->tmpDir = sys_get_temp_dir() . '/rkn_test_' . uniqid();
    mkdir($this->tmpDir . '/content/pages', 0755, true);
    mkdir($this->tmpDir . '/content/blog', 0755, true);
    mkdir($this->tmpDir . '/cache', 0755, true);

    // Create test content files
    file_put_contents($this->tmpDir . '/content/pages/about.md', <<<'MD'
---
title: Nosotros
slugs:
  es: nosotros
  en: about
template: pages/about
order: 2
---

Sobre nosotros...
MD);

    file_put_contents($this->tmpDir . '/content/pages/about.en.md', <<<'MD'
---
title: About Us
slugs:
  es: nosotros
  en: about
template: pages/about
order: 2
---

About us...
MD);

    file_put_contents($this->tmpDir . '/content/blog/2025-03-15.first-post.md', <<<'MD'
---
title: Mi primer post
date: 2025-03-15
tags:
  - php
  - cms
---

Contenido del post.
MD);
});

afterEach(function () {
    // Clean up temp directory
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($this->tmpDir);
});

test('rebuilds index from content directory', function () {
    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    expect($index['meta']['entry_count'])->toBe(3);
    expect($index['meta']['collections'])->toContain('pages');
    expect($index['meta']['collections'])->toContain('blog');
});

test('indexes entries with correct data', function () {
    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    // Find the about page
    $aboutKey = null;
    foreach ($index['entries'] as $key => $entry) {
        if ($entry['slug'] === 'about' && $entry['locale'] === 'es') {
            $aboutKey = $key;
            break;
        }
    }

    expect($aboutKey)->not->toBeNull();
    $entry = $index['entries'][$aboutKey];
    expect($entry['title'])->toBe('Nosotros');
    expect($entry['collection'])->toBe('pages');
    expect($entry['template'])->toBe('pages/about');
    expect($entry['slugs'])->toBe(['es' => 'nosotros', 'en' => 'about']);
});

test('builds locale indices', function () {
    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    expect($index['indices']['by_locale'])->toHaveKey('es');
    expect($index['indices']['by_locale'])->toHaveKey('en');
});

test('builds collection indices', function () {
    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    expect($index['indices']['by_collection'])->toHaveKey('pages');
    expect($index['indices']['by_collection'])->toHaveKey('blog');
});

test('builds tag indices', function () {
    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    expect($index['indices']['by_tag'])->toHaveKey('php');
    expect($index['indices']['by_tag'])->toHaveKey('cms');
});

test('loads index from cache', function () {
    $indexer = new Indexer($this->tmpDir);
    $original = $indexer->rebuild();

    // Load should return cached version
    $loaded = $indexer->load();
    expect($loaded['meta']['entry_count'])->toBe($original['meta']['entry_count']);
});

test('detects locale from filename suffix', function () {
    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    $enEntries = $index['indices']['by_locale']['en'] ?? [];
    expect($enEntries)->not->toBeEmpty();
});
