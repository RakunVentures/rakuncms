<?php

declare(strict_types=1);

use Rkn\Cms\Content\TaxonomyGenerator;

beforeEach(function () {
    $this->index = [
        'entries' => [
            'blog/post-1' => [
                'title' => 'PHP Post',
                'slug' => 'post-1',
                'collection' => 'blog',
                'locale' => 'en',
                'file' => 'content/blog/post-1.md',
                'order' => 1,
                'template' => null,
                'date' => '2024-01-15',
                'draft' => false,
                'meta' => [],
                'slugs' => [],
                'tags' => ['php', 'cms'],
                'mtime' => 1000,
            ],
            'blog/post-2' => [
                'title' => 'JS Post',
                'slug' => 'post-2',
                'collection' => 'blog',
                'locale' => 'en',
                'file' => 'content/blog/post-2.md',
                'order' => 2,
                'template' => null,
                'date' => '2024-03-20',
                'draft' => false,
                'meta' => [],
                'slugs' => [],
                'tags' => ['javascript'],
                'mtime' => 2000,
            ],
            'blog/post-3.es' => [
                'title' => 'Post ES',
                'slug' => 'post-3',
                'collection' => 'blog',
                'locale' => 'es',
                'file' => 'content/blog/post-3.es.md',
                'order' => 3,
                'template' => null,
                'date' => '2024-01-20',
                'draft' => false,
                'meta' => [],
                'slugs' => [],
                'tags' => ['php'],
                'mtime' => 3000,
            ],
        ],
        'indices' => [
            'by_collection' => [
                'blog' => ['blog/post-1', 'blog/post-2', 'blog/post-3.es'],
            ],
            'by_locale' => [
                'en' => ['blog/post-1', 'blog/post-2'],
                'es' => ['blog/post-3.es'],
            ],
            'by_locale_slug' => [],
            'by_tag' => [
                'php' => ['blog/post-1', 'blog/post-3.es'],
                'cms' => ['blog/post-1'],
                'javascript' => ['blog/post-2'],
            ],
            'by_date' => [
                '2024-01' => ['blog/post-1', 'blog/post-3.es'],
                '2024-03' => ['blog/post-2'],
            ],
        ],
        'meta' => [
            'built_at' => time(),
            'entry_count' => 3,
            'collections' => ['blog'],
        ],
    ];
});

test('generates tag pages for each locale', function () {
    $gen = new TaxonomyGenerator();
    $pages = $gen->generate($this->index, ['en', 'es']);

    $tagPages = array_filter($pages, fn ($p) => $p['type'] === 'tag');
    expect(count($tagPages))->toBeGreaterThanOrEqual(3);

    // php tag should have pages for both en and es
    $phpPages = array_filter($tagPages, fn ($p) => $p['value'] === 'php');
    $phpLocales = array_column($phpPages, 'locale');
    expect($phpLocales)->toContain('en');
    expect($phpLocales)->toContain('es');
});

test('generates archive pages', function () {
    $gen = new TaxonomyGenerator();
    $pages = $gen->generate($this->index, ['en']);

    $archivePages = array_filter($pages, fn ($p) => $p['type'] === 'archive');
    expect(count($archivePages))->toBeGreaterThanOrEqual(1);

    $urls = array_column($archivePages, 'url');
    expect($urls)->toContain('/en/blog/archive/2024/01');
});

test('tag page URLs follow convention', function () {
    $gen = new TaxonomyGenerator();
    $pages = $gen->generate($this->index, ['en']);

    $phpTag = array_values(array_filter($pages, fn ($p) => $p['type'] === 'tag' && $p['value'] === 'php' && $p['locale'] === 'en'));
    expect($phpTag)->not->toBeEmpty();
    expect($phpTag[0]['url'])->toBe('/en/blog/tag/php');
});

test('skips locales with no matching entries', function () {
    $gen = new TaxonomyGenerator();
    $pages = $gen->generate($this->index, ['en', 'fr']);

    // fr locale has no entries, so no fr pages should exist
    $frPages = array_filter($pages, fn ($p) => $p['locale'] === 'fr');
    expect($frPages)->toBeEmpty();
});

test('entries in taxonomy pages are Query objects', function () {
    $gen = new TaxonomyGenerator();
    $pages = $gen->generate($this->index, ['en']);

    expect($pages)->not->toBeEmpty();
    expect($pages[0]['entries'])->toBeInstanceOf(\Rkn\Cms\Content\Query::class);
});

test('empty index produces no pages', function () {
    $gen = new TaxonomyGenerator();
    $emptyIndex = [
        'entries' => [],
        'indices' => ['by_tag' => [], 'by_date' => [], 'by_collection' => [], 'by_locale' => [], 'by_locale_slug' => []],
        'meta' => ['built_at' => time(), 'entry_count' => 0, 'collections' => []],
    ];
    $pages = $gen->generate($emptyIndex, ['en']);

    expect($pages)->toBeEmpty();
});
