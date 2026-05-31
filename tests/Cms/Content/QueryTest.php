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
                'section' => '',
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
                'section' => '',
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
                'section' => '',
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
                'section' => '',
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
                'section' => '',
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
            'by_section' => [
                'pages:' => ['pages/home', 'pages/about', 'pages/about.en'],
                'habitaciones:' => ['habitaciones/01.coco', 'habitaciones/02.cereza'],
            ],
            'sections' => [
                'pages' => [],
                'habitaciones' => [],
            ],
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

test('section() filters to entries inside a section path', function () {
    $index = $this->index;
    $index['entries']['docs/getting-started/intro'] = [
        'title' => 'Intro',
        'slug' => 'intro',
        'collection' => 'docs',
        'locale' => 'en',
        'section' => 'getting-started',
        'file' => 'content/docs/getting-started/intro.md',
        'order' => 0,
        'template' => null,
        'date' => null,
        'draft' => false,
        'meta' => [],
        'slugs' => [],
        'mtime' => 5000,
        'tags' => [],
        'url' => '/docs/getting-started/intro',
    ];
    $index['entries']['docs/advanced/scaling'] = [
        'title' => 'Scaling',
        'slug' => 'scaling',
        'collection' => 'docs',
        'locale' => 'en',
        'section' => 'advanced',
        'file' => 'content/docs/advanced/scaling.md',
        'order' => 0,
        'template' => null,
        'date' => null,
        'draft' => false,
        'meta' => [],
        'slugs' => [],
        'mtime' => 5001,
        'tags' => [],
        'url' => '/docs/advanced/scaling',
    ];
    $index['indices']['by_collection']['docs'] = ['docs/getting-started/intro', 'docs/advanced/scaling'];
    $index['indices']['by_locale']['en'][] = 'docs/getting-started/intro';
    $index['indices']['by_locale']['en'][] = 'docs/advanced/scaling';
    $index['indices']['by_section']['docs:getting-started'] = ['docs/getting-started/intro'];
    $index['indices']['by_section']['docs:advanced'] = ['docs/advanced/scaling'];

    $query = new Query($index);
    $results = $query->collection('docs')->section('getting-started')->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->slug())->toBe('intro');
    expect($results[0]->section())->toBe('getting-started');
});

test('sections() returns ordered descriptors with locale-resolved title', function () {
    $index = $this->index;
    $index['indices']['sections']['docs'] = [
        'getting-started' => [
            'section' => 'getting-started',
            'title' => 'Getting Started',
            'titles' => ['es' => 'Primeros Pasos', 'fr' => 'Premiers Pas'],
            'order' => 10,
            'icon' => null,
            'meta' => [],
        ],
        'advanced' => [
            'section' => 'advanced',
            'title' => 'Advanced',
            'titles' => ['es' => 'Avanzado'],
            'order' => 30,
            'icon' => null,
            'meta' => [],
        ],
        'core-concepts' => [
            'section' => 'core-concepts',
            'title' => 'Core Concepts',
            'titles' => ['es' => 'Conceptos Centrales'],
            'order' => 20,
            'icon' => null,
            'meta' => [],
        ],
    ];

    $query = new Query($index);
    $sections = $query->collection('docs')->sections('es');

    expect($sections)->toHaveCount(3);
    expect($sections[0]['section'])->toBe('getting-started');
    expect($sections[0]['title'])->toBe('Primeros Pasos');
    expect($sections[1]['section'])->toBe('core-concepts');
    expect($sections[1]['title'])->toBe('Conceptos Centrales');
    expect($sections[2]['section'])->toBe('advanced');
    expect($sections[2]['title'])->toBe('Avanzado');
});

test('sections() falls back to default title when locale missing', function () {
    $index = $this->index;
    $index['indices']['sections']['docs'] = [
        'advanced' => [
            'section' => 'advanced',
            'title' => 'Advanced',
            'titles' => ['es' => 'Avanzado'],
            'order' => 1,
            'icon' => null,
            'meta' => [],
        ],
    ];

    $query = new Query($index);
    $sections = $query->collection('docs')->sections('de');

    expect($sections[0]['title'])->toBe('Advanced');
});

test('sections() returns empty list when no collection set', function () {
    $query = new Query($this->index);
    expect($query->sections('en'))->toBe([]);
});

test('findBySlug resolves nested slug via section path', function () {
    $index = $this->index;
    $index['entries']['docs/getting-started/intro'] = [
        'title' => 'Intro',
        'slug' => 'intro',
        'collection' => 'docs',
        'locale' => 'en',
        'section' => 'getting-started',
        'file' => 'content/docs/getting-started/intro.md',
        'order' => 0,
        'template' => null,
        'date' => null,
        'draft' => false,
        'meta' => [],
        'slugs' => [],
        'mtime' => 5000,
        'tags' => [],
        'url' => '/docs/getting-started/intro',
    ];
    $index['indices']['by_locale_slug']['docs:en:getting-started/intro'] = 'docs/getting-started/intro';

    $query = new Query($index);
    $entry = $query->findBySlug('docs', 'en', 'getting-started/intro');

    expect($entry)->not->toBeNull();
    expect($entry->slug())->toBe('intro');
    expect($entry->section())->toBe('getting-started');
});
