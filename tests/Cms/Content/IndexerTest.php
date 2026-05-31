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

test('indexes nested section files with unique keys', function () {
    mkdir($this->tmpDir . '/content/docs/getting-started', 0755, true);
    mkdir($this->tmpDir . '/content/docs/advanced', 0755, true);

    file_put_contents($this->tmpDir . '/content/docs/getting-started/intro.md', <<<'MD'
---
title: Getting Started Intro
---

Hola
MD);

    file_put_contents($this->tmpDir . '/content/docs/advanced/intro.md', <<<'MD'
---
title: Advanced Intro
---

Hola
MD);

    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    expect($index['entries'])->toHaveKey('docs/getting-started/intro');
    expect($index['entries'])->toHaveKey('docs/advanced/intro');
    expect($index['entries']['docs/getting-started/intro']['section'])->toBe('getting-started');
    expect($index['entries']['docs/advanced/intro']['section'])->toBe('advanced');
    expect($index['entries']['docs/getting-started/intro']['title'])->toBe('Getting Started Intro');
    expect($index['entries']['docs/advanced/intro']['title'])->toBe('Advanced Intro');
});

test('builds by_section index keyed by collection:section', function () {
    mkdir($this->tmpDir . '/content/docs/getting-started', 0755, true);

    file_put_contents($this->tmpDir . '/content/docs/getting-started/intro.md', <<<'MD'
---
title: Intro
---

Hola
MD);

    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    expect($index['indices']['by_section'])->toHaveKey('docs:getting-started');
    expect($index['indices']['by_section']['docs:getting-started'])->toContain('docs/getting-started/intro');
});

test('loads _section.yaml manifest with localized titles', function () {
    mkdir($this->tmpDir . '/content/docs/getting-started', 0755, true);

    file_put_contents($this->tmpDir . '/content/docs/getting-started/_section.yaml', <<<'YAML'
title: Getting Started
titles:
  es: Primeros Pasos
  fr: Premiers Pas
order: 10
icon: rocket
YAML);

    file_put_contents($this->tmpDir . '/content/docs/getting-started/intro.md', <<<'MD'
---
title: Intro
---

Hola
MD);

    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    $section = $index['indices']['sections']['docs']['getting-started'] ?? null;
    expect($section)->not->toBeNull();
    expect($section['title'])->toBe('Getting Started');
    expect($section['titles']['es'])->toBe('Primeros Pasos');
    expect($section['titles']['fr'])->toBe('Premiers Pas');
    expect($section['order'])->toBe(10);
    expect($section['icon'])->toBe('rocket');
});

test('builds nested URL with section path', function () {
    mkdir($this->tmpDir . '/content/docs/getting-started', 0755, true);

    file_put_contents($this->tmpDir . '/content/docs/getting-started/intro.md', <<<'MD'
---
title: Intro
---
MD);

    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    $entry = $index['entries']['docs/getting-started/intro'];
    // default_locale defaults to "es" in this fixture (no config file)
    // For non-default locale the URL would carry /xx prefix; here locale==default.
    expect($entry['url'])->toBe('/docs/getting-started/intro');
});

test('skips files and directories starting with underscore', function () {
    mkdir($this->tmpDir . '/content/docs/_drafts', 0755, true);

    file_put_contents($this->tmpDir . '/content/docs/_drafts/wip.md', <<<'MD'
---
title: Work in progress
---
MD);

    file_put_contents($this->tmpDir . '/content/docs/_aux.md', <<<'MD'
---
title: Aux
---
MD);

    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    foreach (array_keys($index['entries']) as $key) {
        expect($key)->not->toContain('_drafts');
        expect($key)->not->toContain('_aux');
    }
});

test('indexes by collection:locale:nested-slug', function () {
    mkdir($this->tmpDir . '/content/docs/getting-started', 0755, true);

    file_put_contents($this->tmpDir . '/content/docs/getting-started/intro.md', <<<'MD'
---
title: Intro
---
MD);

    $indexer = new Indexer($this->tmpDir);
    $index = $indexer->rebuild();

    // detectLocale falls back to defaultLocale, which is "es" without config
    expect($index['indices']['by_locale_slug'])->toHaveKey('docs:es:getting-started/intro');
});
