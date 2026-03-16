<?php

declare(strict_types=1);

use Rkn\Cms\Boost\ArchetypeRegistry;
use Rkn\Cms\Boost\SiteProfile;

$profile = new SiteProfile(
    name: 'Test Site',
    description: 'A test site',
    locale: 'es',
    author: 'Tester',
    archetype: 'blog',
);

$registry = ArchetypeRegistry::withDefaults();

foreach ($registry->all() as $archetype) {
    $name = $archetype->name();

    test("{$name}: has name and description", function () use ($archetype) {
        expect($archetype->name())->toBeString()->not->toBeEmpty();
        expect($archetype->description())->toBeString()->not->toBeEmpty();
    });

    test("{$name}: returns collections with name and config", function () use ($archetype) {
        $collections = $archetype->collections();
        expect($collections)->toBeArray()->not->toBeEmpty();

        foreach ($collections as $collection) {
            expect($collection)->toHaveKeys(['name', 'config']);
            expect($collection['name'])->toBeString()->not->toBeEmpty();
            expect($collection['config'])->toBeArray();
        }
    });

    test("{$name}: returns entries with required fields", function () use ($archetype, $profile) {
        $entries = $archetype->entries($profile);
        expect($entries)->toBeArray()->not->toBeEmpty();

        foreach ($entries as $entry) {
            expect($entry)->toHaveKeys(['collection', 'filename', 'frontmatter', 'content']);
            expect($entry['collection'])->toBeString()->not->toBeEmpty();
            expect($entry['filename'])->toBeString()->toEndWith('.md');
            expect($entry['frontmatter'])->toBeArray();
            expect($entry['content'])->toBeString();
        }
    });

    test("{$name}: returns templates as path => content map", function () use ($archetype, $profile) {
        $templates = $archetype->templates($profile);
        expect($templates)->toBeArray()->not->toBeEmpty();

        foreach ($templates as $path => $content) {
            expect($path)->toBeString()->toContain('templates/');
            expect($content)->toBeString()->not->toBeEmpty();
        }
    });

    test("{$name}: includes base layout template", function () use ($archetype, $profile) {
        $templates = $archetype->templates($profile);
        expect($templates)->toHaveKey('templates/_layouts/base.twig');
    });

    test("{$name}: returns CSS string", function () use ($archetype, $profile) {
        $css = $archetype->css($profile);
        expect($css)->toBeString()->not->toBeEmpty();
        expect($css)->toContain(':root');
        expect($css)->toContain('--primary-color');
    });

    test("{$name}: returns config with site key", function () use ($archetype, $profile) {
        $config = $archetype->config($profile);
        expect($config)->toBeArray();
        expect($config)->toHaveKey('site');
        expect($config['site'])->toHaveKey('default_locale');
    });

    test("{$name}: returns globals with title", function () use ($archetype, $profile) {
        $globals = $archetype->globals($profile);
        expect($globals)->toBeArray();
        expect($globals)->toHaveKey('title');
        expect($globals['title'])->toBe('Test Site');
    });

    test("{$name}: entries reference valid collections", function () use ($archetype, $profile) {
        $collectionNames = array_map(fn($c) => $c['name'], $archetype->collections());
        $entries = $archetype->entries($profile);

        foreach ($entries as $entry) {
            expect($collectionNames)->toContain($entry['collection']);
        }
    });
}

// Blog-specific tests
test('blog: has 3 posts and 3 pages', function () use ($registry, $profile) {
    $blog = $registry->get('blog');
    $entries = $blog->entries($profile);

    $posts = array_filter($entries, fn($e) => $e['collection'] === 'blog');
    $pages = array_filter($entries, fn($e) => $e['collection'] === 'pages');

    expect($posts)->toHaveCount(3);
    expect($pages)->toHaveCount(3);
});

// Docs-specific tests
test('docs: has 5 docs and 1 page', function () use ($registry, $profile) {
    $docs = $registry->get('docs');
    $entries = $docs->entries($profile);

    $docEntries = array_filter($entries, fn($e) => $e['collection'] === 'docs');
    $pages = array_filter($entries, fn($e) => $e['collection'] === 'pages');

    expect($docEntries)->toHaveCount(5);
    expect($pages)->toHaveCount(1);
});

test('docs: entries have order field', function () use ($registry, $profile) {
    $docs = $registry->get('docs');
    $entries = $docs->entries($profile);

    $docEntries = array_filter($entries, fn($e) => $e['collection'] === 'docs');
    foreach ($docEntries as $entry) {
        expect($entry['frontmatter'])->toHaveKey('order');
    }
});

// Business-specific tests
test('business: has 3 services and 3 pages', function () use ($registry, $profile) {
    $business = $registry->get('business');
    $entries = $business->entries($profile);

    $services = array_filter($entries, fn($e) => $e['collection'] === 'services');
    $pages = array_filter($entries, fn($e) => $e['collection'] === 'pages');

    expect($services)->toHaveCount(3);
    expect($pages)->toHaveCount(3);
});

// Portfolio-specific tests
test('portfolio: has 4 projects and 2 pages', function () use ($registry, $profile) {
    $portfolio = $registry->get('portfolio');
    $entries = $portfolio->entries($profile);

    $projects = array_filter($entries, fn($e) => $e['collection'] === 'projects');
    $pages = array_filter($entries, fn($e) => $e['collection'] === 'pages');

    expect($projects)->toHaveCount(4);
    expect($pages)->toHaveCount(2);
});

// Catalog-specific tests
test('catalog: has 3 collections', function () use ($registry) {
    $catalog = $registry->get('catalog');
    $collections = $catalog->collections();
    expect($collections)->toHaveCount(3);

    $names = array_map(fn($c) => $c['name'], $collections);
    expect($names)->toContain('products');
    expect($names)->toContain('categories');
    expect($names)->toContain('pages');
});

test('catalog: has products with price field', function () use ($registry, $profile) {
    $catalog = $registry->get('catalog');
    $entries = $catalog->entries($profile);

    $products = array_filter($entries, fn($e) => $e['collection'] === 'products');
    foreach ($products as $product) {
        expect($product['frontmatter'])->toHaveKey('price');
        expect($product['frontmatter'])->toHaveKey('currency');
    }
});

// Multilingual-specific tests
test('multilingual: has entries in two locales', function () use ($registry) {
    $multi = $registry->get('multilingual');
    $profileEs = new SiteProfile(name: 'Test', locale: 'es');
    $entries = $multi->entries($profileEs);

    $altLocaleEntries = array_filter($entries, fn($e) => str_contains($e['filename'], '.en.'));
    $primaryEntries = array_filter($entries, fn($e) => !str_contains($e['filename'], '.en.'));

    expect($altLocaleEntries)->not->toBeEmpty();
    expect($primaryEntries)->not->toBeEmpty();
});

test('multilingual: config includes locales array', function () use ($registry) {
    $multi = $registry->get('multilingual');
    $profileEs = new SiteProfile(name: 'Test', locale: 'es');
    $config = $multi->config($profileEs);

    expect($config['site'])->toHaveKey('locales');
    expect($config['site']['locales'])->toBe(['es', 'en']);
});

test('multilingual: templates include lang-switcher partial', function () use ($registry) {
    $multi = $registry->get('multilingual');
    $profileEs = new SiteProfile(name: 'Test', locale: 'es');
    $templates = $multi->templates($profileEs);

    expect($templates)->toHaveKey('templates/_partials/lang-switcher.twig');
});
