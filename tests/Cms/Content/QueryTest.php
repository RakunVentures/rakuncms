<?php

declare(strict_types=1);

use Rkn\Cms\Content\Query;

beforeEach(function () {
    $this->index = [
        'entries' => [
            'pages/home' => [
                'title' => 'Inicio',
                'slug' => 'home',
                'collection' => 'pages',
                'locale' => 'es',
                'file' => 'content/pages/home.md',
                'order' => 1,
                'template' => 'pages/home',
                'date' => null,
                'draft' => false,
                'meta' => [],
                'slugs' => [],
                'mtime' => 1000,
            ],
            'pages/about' => [
                'title' => 'Nosotros',
                'slug' => 'nosotros',
                'collection' => 'pages',
                'locale' => 'es',
                'file' => 'content/pages/about.md',
                'order' => 2,
                'template' => 'pages/about',
                'date' => null,
                'draft' => false,
                'meta' => [],
                'slugs' => ['es' => 'nosotros', 'en' => 'about'],
                'mtime' => 2000,
            ],
            'pages/about.en' => [
                'title' => 'About Us',
                'slug' => 'about',
                'collection' => 'pages',
                'locale' => 'en',
                'file' => 'content/pages/about.en.md',
                'order' => 2,
                'template' => 'pages/about',
                'date' => null,
                'draft' => false,
                'meta' => [],
                'slugs' => ['es' => 'nosotros', 'en' => 'about'],
                'mtime' => 2000,
            ],
            'habitaciones/01.coco' => [
                'title' => 'Coco',
                'slug' => 'coco',
                'collection' => 'habitaciones',
                'locale' => 'es',
                'file' => 'content/habitaciones/01.coco.md',
                'order' => 1,
                'template' => null,
                'date' => null,
                'draft' => false,
                'meta' => ['beds' => 'Queen + individual', 'premium' => false],
                'slugs' => ['es' => 'coco', 'en' => 'coco'],
                'mtime' => 3000,
            ],
            'habitaciones/02.cereza' => [
                'title' => 'Cereza',
                'slug' => 'cereza',
                'collection' => 'habitaciones',
                'locale' => 'es',
                'file' => 'content/habitaciones/02.cereza.md',
                'order' => 2,
                'template' => null,
                'date' => null,
                'draft' => false,
                'meta' => ['beds' => 'King size', 'premium' => false],
                'slugs' => ['es' => 'cereza', 'en' => 'cereza'],
                'mtime' => 4000,
            ],
        ],
        'indices' => [
            'by_collection' => [
                'pages' => ['pages/home', 'pages/about', 'pages/about.en'],
                'habitaciones' => ['habitaciones/01.coco', 'habitaciones/02.cereza'],
            ],
            'by_locale' => [
                'es' => ['pages/home', 'pages/about', 'habitaciones/01.coco', 'habitaciones/02.cereza'],
                'en' => ['pages/about.en'],
            ],
            'by_locale_slug' => [
                'pages:es:nosotros' => 'pages/about',
                'pages:en:about' => 'pages/about.en',
                'habitaciones:es:coco' => 'habitaciones/01.coco',
                'habitaciones:es:cereza' => 'habitaciones/02.cereza',
            ],
            'by_tag' => [],
            'by_date' => [],
        ],
        'meta' => [
            'built_at' => time(),
            'entry_count' => 5,
            'collections' => ['pages', 'habitaciones'],
        ],
    ];
});

test('queries all entries', function () {
    $query = new Query($this->index);
    $results = $query->get();

    expect($results)->toHaveCount(5);
});

test('filters by collection', function () {
    $query = new Query($this->index);
    $results = $query->collection('habitaciones')->get();

    expect($results)->toHaveCount(2);
    expect($results[0]->collection())->toBe('habitaciones');
});

test('filters by locale', function () {
    $query = new Query($this->index);
    $results = $query->locale('en')->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->locale())->toBe('en');
});

test('filters by collection and locale', function () {
    $query = new Query($this->index);
    $results = $query->collection('pages')->locale('es')->get();

    expect($results)->toHaveCount(2);
});

test('sorts by field ascending', function () {
    $query = new Query($this->index);
    $results = $query->collection('habitaciones')->sort('order', 'asc')->get();

    expect($results[0]->slug())->toBe('coco');
    expect($results[1]->slug())->toBe('cereza');
});

test('sorts by field descending', function () {
    $query = new Query($this->index);
    $results = $query->collection('habitaciones')->sort('order', 'desc')->get();

    expect($results[0]->slug())->toBe('cereza');
    expect($results[1]->slug())->toBe('coco');
});

test('limits results', function () {
    $query = new Query($this->index);
    $results = $query->locale('es')->limit(2)->get();

    expect($results)->toHaveCount(2);
});

test('first returns single entry or null', function () {
    $query = new Query($this->index);
    $entry = $query->collection('habitaciones')->first();

    expect($entry)->not->toBeNull();
    expect($entry->collection())->toBe('habitaciones');
});

test('count returns total matching entries', function () {
    $query = new Query($this->index);
    expect($query->collection('pages')->count())->toBe(3);
    expect($query->locale('en')->count())->toBe(1);
});

test('findBySlug finds entry by collection locale slug', function () {
    $query = new Query($this->index);
    $entry = $query->findBySlug('habitaciones', 'es', 'coco');

    expect($entry)->not->toBeNull();
    expect($entry->title())->toBe('Coco');
});

test('findBySlug returns null for missing entry', function () {
    $query = new Query($this->index);
    $entry = $query->findBySlug('habitaciones', 'es', 'nonexistent');

    expect($entry)->toBeNull();
});

test('where filters by condition', function () {
    $query = new Query($this->index);
    $results = $query->where('title', 'contains', 'co')->get();

    // Should match "Coco"
    expect(count($results))->toBeGreaterThanOrEqual(1);
    $titles = array_map(fn ($e) => $e->title(), $results);
    expect($titles)->toContain('Coco');
});
