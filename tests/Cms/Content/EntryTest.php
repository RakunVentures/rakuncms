<?php

declare(strict_types=1);

use Rkn\Cms\Content\Entry;

test('creates entry from array', function () {
    $entry = Entry::fromArray([
        'title' => 'Test Page',
        'slug' => 'test',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/test.md',
        'template' => 'pages/test',
        'order' => 1,
    ]);

    expect($entry->title())->toBe('Test Page');
    expect($entry->slug())->toBe('test');
    expect($entry->collection())->toBe('pages');
    expect($entry->locale())->toBe('es');
    expect($entry->file())->toBe('content/pages/test.md');
    expect($entry->template())->toBe('pages/test');
    expect($entry->order())->toBe(1);
});

test('converts entry to array', function () {
    $data = [
        'title' => 'About',
        'slug' => 'about',
        'collection' => 'pages',
        'locale' => 'en',
        'file' => 'content/pages/about.en.md',
        'template' => null,
        'date' => null,
        'order' => 0,
        'draft' => false,
        'meta' => [],
        'slugs' => ['es' => 'nosotros', 'en' => 'about'],
        'mtime' => 0,
        'tags' => [],
    ];

    $entry = Entry::fromArray($data);
    expect($entry->toArray())->toBe($data);
});

test('returns slug for specific locale', function () {
    $entry = Entry::fromArray([
        'title' => 'Nosotros',
        'slug' => 'nosotros',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/nosotros.md',
        'slugs' => ['es' => 'nosotros', 'en' => 'about'],
    ]);

    expect($entry->slugForLocale('es'))->toBe('nosotros');
    expect($entry->slugForLocale('en'))->toBe('about');
    expect($entry->slugForLocale('fr'))->toBe('nosotros'); // fallback to default slug
});

test('generates URL for pages', function () {
    $entry = Entry::fromArray([
        'title' => 'Spa',
        'slug' => 'spa',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/spa.md',
        'slugs' => ['es' => 'spa', 'en' => 'spa'],
    ]);

    expect($entry->url())->toBe('/es/spa');
});

test('generates URL for collection items', function () {
    $entry = Entry::fromArray([
        'title' => 'Coco',
        'slug' => 'coco',
        'collection' => 'habitaciones',
        'locale' => 'es',
        'file' => 'content/habitaciones/01.coco.md',
        'slugs' => ['es' => 'coco', 'en' => 'coco'],
    ]);

    expect($entry->url())->toBe('/es/habitaciones/coco');
});

test('homepage URL uses locale prefix only', function () {
    $entry = Entry::fromArray([
        'title' => 'Home',
        'slug' => 'home',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/home.md',
    ]);

    expect($entry->url())->toBe('/es/');
});
