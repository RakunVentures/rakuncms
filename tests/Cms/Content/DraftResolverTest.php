<?php

declare(strict_types=1);

use Rkn\Cms\Content\DraftResolver;

beforeEach(function () {
    // Create a temporary content directory with a draft entry
    $this->tempDir = sys_get_temp_dir() . '/rakun-draft-test-' . uniqid();
    mkdir($this->tempDir . '/content/blog', 0755, true);

    // Published entry
    file_put_contents($this->tempDir . '/content/blog/published-post.en.md', <<<'MD'
---
title: "Published Post"
draft: false
---
This is published content.
MD);

    // Draft entry
    file_put_contents($this->tempDir . '/content/blog/draft-post.en.md', <<<'MD'
---
title: "My Draft Post"
draft: true
meta:
  description: "A draft post"
---
This is draft content.
MD);

    // Draft entry in Spanish
    file_put_contents($this->tempDir . '/content/blog/borrador.es.md', <<<'MD'
---
title: "Mi Borrador"
draft: true
---
Contenido borrador.
MD);
});

afterEach(function () {
    // Clean up temp directory
    $cleanup = function (string $dir) use (&$cleanup): void {
        $items = new DirectoryIterator($dir);
        foreach ($items as $item) {
            if ($item->isDot()) continue;
            if ($item->isDir()) {
                $cleanup($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    };
    if (is_dir($this->tempDir)) {
        $cleanup($this->tempDir);
    }
});

test('finds draft entry by collection locale and slug', function () {
    $resolver = new DraftResolver($this->tempDir);
    $entry = $resolver->findDraft('blog', 'en', 'draft-post');

    expect($entry)->not->toBeNull();
    expect($entry->title())->toBe('My Draft Post');
    expect($entry->isDraft())->toBeTrue();
    expect($entry->locale())->toBe('en');
});

test('returns null for published entries', function () {
    $resolver = new DraftResolver($this->tempDir);
    $entry = $resolver->findDraft('blog', 'en', 'published-post');

    expect($entry)->toBeNull();
});

test('returns null for nonexistent entry', function () {
    $resolver = new DraftResolver($this->tempDir);
    $entry = $resolver->findDraft('blog', 'en', 'nonexistent');

    expect($entry)->toBeNull();
});

test('returns null for nonexistent collection', function () {
    $resolver = new DraftResolver($this->tempDir);
    $entry = $resolver->findDraft('nonexistent', 'en', 'draft-post');

    expect($entry)->toBeNull();
});

test('finds draft by correct locale', function () {
    $resolver = new DraftResolver($this->tempDir);

    $en = $resolver->findDraft('blog', 'en', 'draft-post');
    $es = $resolver->findDraft('blog', 'es', 'borrador');

    expect($en)->not->toBeNull();
    expect($en->title())->toBe('My Draft Post');
    expect($es)->not->toBeNull();
    expect($es->title())->toBe('Mi Borrador');
});

test('wrong locale returns null', function () {
    $resolver = new DraftResolver($this->tempDir);
    $entry = $resolver->findDraft('blog', 'fr', 'draft-post');

    expect($entry)->toBeNull();
});

test('injects draft banner after body tag', function () {
    $resolver = new DraftResolver($this->tempDir);
    $html = '<html><body><h1>Hello</h1></body></html>';
    $result = $resolver->injectDraftBanner($html);

    expect($result)->toContain('DRAFT PREVIEW');
    expect($result)->toContain('<body>');
    // Banner should be between body and h1
    $bodyPos = strpos($result, '<body>');
    $bannerPos = strpos($result, 'DRAFT PREVIEW');
    $h1Pos = strpos($result, '<h1>');
    expect($bannerPos)->toBeGreaterThan($bodyPos);
    expect($bannerPos)->toBeLessThan($h1Pos);
});

test('injects draft banner at top when no body tag', function () {
    $resolver = new DraftResolver($this->tempDir);
    $html = '<h1>Hello</h1>';
    $result = $resolver->injectDraftBanner($html);

    expect($result)->toStartWith('<div style=');
    expect($result)->toContain('DRAFT PREVIEW');
});

test('isValidToken rejects empty token', function () {
    $resolver = new DraftResolver($this->tempDir);
    expect($resolver->isValidToken(''))->toBeFalse();
});

test('isValidToken rejects when no config token set', function () {
    // No Application bootstrapped = no config = always false
    $resolver = new DraftResolver($this->tempDir);
    expect($resolver->isValidToken('any-token'))->toBeFalse();
});
