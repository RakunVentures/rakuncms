<?php

declare(strict_types=1);

use Rkn\Cms\Content\Paginator;
use Rkn\Cms\Content\Query;

beforeEach(function () {
    // Create 25 blog entries for pagination testing
    $entries = [];
    $byCollection = [];
    $byLocale = [];
    $byLocaleSlug = [];

    for ($i = 1; $i <= 25; $i++) {
        $key = 'blog/post-' . $i;
        $entries[$key] = [
            'title' => 'Post ' . $i,
            'slug' => 'post-' . $i,
            'collection' => 'blog',
            'locale' => 'en',
            'file' => 'content/blog/post-' . $i . '.md',
            'order' => $i,
            'template' => null,
            'date' => '2025-01-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'draft' => false,
            'meta' => [],
            'slugs' => [],
            'mtime' => 1000 + $i,
        ];
        $byCollection['blog'][] = $key;
        $byLocale['en'][] = $key;
        $byLocaleSlug['blog:en:post-' . $i] = $key;
    }

    $this->index = [
        'entries' => $entries,
        'indices' => [
            'by_collection' => $byCollection,
            'by_locale' => $byLocale,
            'by_locale_slug' => $byLocaleSlug,
            'by_tag' => [],
            'by_date' => [],
        ],
        'meta' => [
            'built_at' => time(),
            'entry_count' => 25,
            'collections' => ['blog'],
        ],
    ];
});

test('paginates correctly with 25 items and 10 per page', function () {
    $query = (new Query($this->index))->collection('blog')->sort('order', 'asc');
    $pager = new Paginator($query, perPage: 10, currentPage: 1);

    expect($pager->totalItems())->toBe(25);
    expect($pager->totalPages())->toBe(3);
    expect($pager->currentPage())->toBe(1);
    expect($pager->items())->toHaveCount(10);
    expect($pager->items()[0]->title())->toBe('Post 1');
});

test('page 1 has no previous page', function () {
    $query = (new Query($this->index))->collection('blog')->sort('order', 'asc');
    $pager = new Paginator($query, perPage: 10, currentPage: 1);

    expect($pager->hasPreviousPage())->toBeFalse();
    expect($pager->previousPageUrl())->toBe('');
    expect($pager->hasNextPage())->toBeTrue();
});

test('last page has no next page', function () {
    $query = (new Query($this->index))->collection('blog')->sort('order', 'asc');
    $pager = new Paginator($query, perPage: 10, currentPage: 3);

    expect($pager->hasNextPage())->toBeFalse();
    expect($pager->nextPageUrl())->toBe('');
    expect($pager->hasPreviousPage())->toBeTrue();
});

test('page 2 items are correctly offset', function () {
    $query = (new Query($this->index))->collection('blog')->sort('order', 'asc');
    $pager = new Paginator($query, perPage: 10, currentPage: 2);

    expect($pager->items())->toHaveCount(10);
    expect($pager->items()[0]->title())->toBe('Post 11');
    expect($pager->items()[9]->title())->toBe('Post 20');
});

test('last page has correct remaining items', function () {
    $query = (new Query($this->index))->collection('blog')->sort('order', 'asc');
    $pager = new Paginator($query, perPage: 10, currentPage: 3);

    expect($pager->items())->toHaveCount(5);
    expect($pager->items()[0]->title())->toBe('Post 21');
});

test('toArray includes complete metadata', function () {
    $query = (new Query($this->index))->collection('blog')->sort('order', 'asc');
    $pager = new Paginator($query, perPage: 10, currentPage: 2);
    $array = $pager->toArray();

    expect($array['current_page'])->toBe(2);
    expect($array['total_pages'])->toBe(3);
    expect($array['total_items'])->toBe(25);
    expect($array['per_page'])->toBe(10);
    expect($array['has_next'])->toBeTrue();
    expect($array['has_previous'])->toBeTrue();
    expect($array['items'])->toHaveCount(10);
});

test('page out of range returns empty items', function () {
    $query = (new Query($this->index))->collection('blog')->sort('order', 'asc');
    $pager = new Paginator($query, perPage: 10, currentPage: 99);

    expect($pager->items())->toBeEmpty();
    expect($pager->totalPages())->toBe(3);
});

test('negative page treated as page 1', function () {
    $query = (new Query($this->index))->collection('blog')->sort('order', 'asc');
    $pager = new Paginator($query, perPage: 10, currentPage: -1);

    expect($pager->currentPage())->toBe(1);
    expect($pager->items())->toHaveCount(10);
});

test('single page when perPage exceeds total items', function () {
    $query = (new Query($this->index))->collection('blog')->sort('order', 'asc');
    $pager = new Paginator($query, perPage: 50, currentPage: 1);

    expect($pager->totalPages())->toBe(1);
    expect($pager->totalItems())->toBe(25);
    expect($pager->items())->toHaveCount(25);
    expect($pager->hasNextPage())->toBeFalse();
    expect($pager->hasPreviousPage())->toBeFalse();
});

test('page URLs are generated correctly', function () {
    $query = (new Query($this->index))->collection('blog')->sort('order', 'asc');
    $pager = new Paginator($query, perPage: 10, currentPage: 2);

    // Page 1 returns base URL (no /page/1)
    expect($pager->pageUrl(1))->not->toContain('/page/');
    // Page 2+ includes /page/N
    expect($pager->pageUrl(2))->toContain('/page/2');
    expect($pager->pageUrl(3))->toContain('/page/3');
});
