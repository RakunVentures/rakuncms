<?php

declare(strict_types=1);

use Rkn\Cms\Content\Query;
use Rkn\Cms\Content\TaxonomyRouter;

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
                'meta' => ['category' => 'tutorials'],
                'slugs' => [],
                'tags' => ['php', 'cms'],
                'mtime' => 1000,
            ],
            'blog/post-2' => [
                'title' => 'JavaScript Post',
                'slug' => 'post-2',
                'collection' => 'blog',
                'locale' => 'en',
                'file' => 'content/blog/post-2.md',
                'order' => 2,
                'template' => null,
                'date' => '2024-03-20',
                'draft' => false,
                'meta' => ['category' => 'guides'],
                'slugs' => [],
                'tags' => ['javascript', 'cms'],
                'mtime' => 2000,
            ],
            'blog/post-3' => [
                'title' => 'Post en Español',
                'slug' => 'post-3',
                'collection' => 'blog',
                'locale' => 'es',
                'file' => 'content/blog/post-3.es.md',
                'order' => 3,
                'template' => null,
                'date' => '2024-01-20',
                'draft' => false,
                'meta' => ['category' => 'tutorials'],
                'slugs' => [],
                'tags' => ['php'],
                'mtime' => 3000,
            ],
        ],
        'indices' => [
            'by_collection' => [
                'blog' => ['blog/post-1', 'blog/post-2', 'blog/post-3'],
            ],
            'by_locale' => [
                'en' => ['blog/post-1', 'blog/post-2'],
                'es' => ['blog/post-3'],
            ],
            'by_locale_slug' => [
                'blog:en:post-1' => 'blog/post-1',
                'blog:en:post-2' => 'blog/post-2',
                'blog:es:post-3' => 'blog/post-3',
            ],
            'by_tag' => [
                'php' => ['blog/post-1', 'blog/post-3'],
                'cms' => ['blog/post-1', 'blog/post-2'],
                'javascript' => ['blog/post-2'],
            ],
            'by_date' => [
                '2024-01' => ['blog/post-1', 'blog/post-3'],
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

test('resolves tag route', function () {
    $router = new TaxonomyRouter();
    $query = new Query($this->index);
    $result = $router->resolve(['blog', 'tag', 'php'], 'en', $query);

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('tag');
    expect($result['collection'])->toBe('blog');
    expect($result['value'])->toBe('php');
    expect($result['query']->get())->toHaveCount(1); // Only en:php = post-1
});

test('resolves category route', function () {
    $router = new TaxonomyRouter();
    $query = new Query($this->index);
    $result = $router->resolve(['blog', 'category', 'tutorials'], 'en', $query);

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('category');
    expect($result['value'])->toBe('tutorials');
});

test('resolves year archive route', function () {
    $router = new TaxonomyRouter();
    $query = new Query($this->index);
    $result = $router->resolve(['blog', 'archive', '2024'], 'en', $query);

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('archive');
    expect($result['value'])->toBe('2024');
});

test('resolves year-month archive route', function () {
    $router = new TaxonomyRouter();
    $query = new Query($this->index);
    $result = $router->resolve(['blog', 'archive', '2024', '01'], 'en', $query);

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('archive');
    expect($result['value'])->toBe('2024-01');
});

test('returns null for unknown taxonomy type', function () {
    $router = new TaxonomyRouter();
    $query = new Query($this->index);
    $result = $router->resolve(['blog', 'unknown', 'value'], 'en', $query);

    expect($result)->toBeNull();
});

test('returns null for too few segments', function () {
    $router = new TaxonomyRouter();
    $query = new Query($this->index);

    expect($router->resolve(['blog', 'tag'], 'en', $query))->toBeNull();
    expect($router->resolve(['blog'], 'en', $query))->toBeNull();
    expect($router->resolve([], 'en', $query))->toBeNull();
});

test('returns null for invalid year in archive', function () {
    $router = new TaxonomyRouter();
    $query = new Query($this->index);
    $result = $router->resolve(['blog', 'archive', 'abc'], 'en', $query);

    expect($result)->toBeNull();
});

test('tag route respects locale filter', function () {
    $router = new TaxonomyRouter();
    $query = new Query($this->index);

    $en = $router->resolve(['blog', 'tag', 'php'], 'en', $query);
    $es = $router->resolve(['blog', 'tag', 'php'], 'es', $query);

    expect($en['query']->get())->toHaveCount(1);
    expect($es['query']->get())->toHaveCount(1);
    expect($en['query']->get()[0]->locale())->toBe('en');
    expect($es['query']->get()[0]->locale())->toBe('es');
});

test('allTags returns sorted tag list', function () {
    $router = new TaxonomyRouter();
    $tags = $router->allTags($this->index['indices']);

    expect($tags)->toBe(['cms', 'javascript', 'php']);
});

test('allDatePeriods returns sorted periods', function () {
    $router = new TaxonomyRouter();
    $periods = $router->allDatePeriods($this->index['indices']);

    expect($periods)->toBe(['2024-01', '2024-03']);
});
