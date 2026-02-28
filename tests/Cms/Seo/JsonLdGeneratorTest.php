<?php

declare(strict_types=1);

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Seo\JsonLdGenerator;

test('generates WebSite schema with name and url', function () {
    $gen = new JsonLdGenerator(['site_name' => 'Test Site']);
    $html = $gen->generate(['base_url' => 'https://example.com']);

    expect($html)->toContain('"@type": "WebSite"');
    expect($html)->toContain('"name": "Test Site"');
    expect($html)->toContain('"url": "https://example.com"');
    expect($html)->toContain('SearchAction');
    expect($html)->toContain('application/ld+json');
});

test('WebSite schema falls back to site globals title', function () {
    $gen = new JsonLdGenerator([], ['title' => 'Global Title']);
    $html = $gen->generate(['base_url' => 'https://example.com']);

    expect($html)->toContain('"name": "Global Title"');
});

test('generates Organization schema from config', function () {
    $gen = new JsonLdGenerator([
        'organization' => [
            'name' => 'My Corp',
            'url' => 'https://corp.com',
            'logo' => '/assets/logo.png',
        ],
    ]);
    $html = $gen->generate(['base_url' => 'https://corp.com']);

    expect($html)->toContain('"@type": "Organization"');
    expect($html)->toContain('"name": "My Corp"');
    expect($html)->toContain('"url": "https://corp.com"');
    expect($html)->toContain('"logo": "https://corp.com/assets/logo.png"');
});

test('skips Organization schema when not configured', function () {
    $gen = new JsonLdGenerator();
    $html = $gen->generate([]);

    expect($html)->not->toContain('Organization');
});

test('generates LocalBusiness when configured', function () {
    $gen = new JsonLdGenerator([
        'site_name' => 'My Hotel',
        'local_business' => [
            'type' => 'Hotel',
            'address' => [
                'street' => 'Calle 123',
                'city' => 'Oaxaca',
                'region' => 'Oaxaca',
                'postal_code' => '68000',
                'country' => 'MX',
            ],
            'phone' => '+52 951 123 4567',
            'price_range' => '$$',
        ],
    ]);
    $html = $gen->generate(['base_url' => 'https://example.com']);

    expect($html)->toContain('"@type": "Hotel"');
    expect($html)->toContain('"streetAddress": "Calle 123"');
    expect($html)->toContain('"addressLocality": "Oaxaca"');
    expect($html)->toContain('"telephone": "+52 951 123 4567"');
    expect($html)->toContain('"priceRange": "$$"');
});

test('skips LocalBusiness when not configured', function () {
    $gen = new JsonLdGenerator();
    $html = $gen->generate([]);

    expect($html)->not->toContain('LocalBusiness');
    expect($html)->not->toContain('Hotel');
});

test('generates BreadcrumbList with 3 levels for collection entry', function () {
    $entry = Entry::fromArray([
        'title' => 'Coco Room',
        'slug' => 'coco',
        'collection' => 'habitaciones',
        'locale' => 'es',
        'file' => 'content/habitaciones/coco.md',
        'slugs' => ['es' => 'coco'],
    ]);

    $gen = new JsonLdGenerator();
    $html = $gen->generate(['entry' => $entry, 'base_url' => 'https://example.com']);

    expect($html)->toContain('"@type": "BreadcrumbList"');
    expect($html)->toContain('"name": "Home"');
    expect($html)->toContain('"name": "Habitaciones"');
    expect($html)->toContain('"name": "Coco Room"');
    expect($html)->toContain('"position": 3');
});

test('generates BreadcrumbList with 2 levels for pages', function () {
    $entry = Entry::fromArray([
        'title' => 'About Us',
        'slug' => 'about',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/about.md',
        'slugs' => ['es' => 'about'],
    ]);

    $gen = new JsonLdGenerator();
    $html = $gen->generate(['entry' => $entry, 'base_url' => 'https://example.com']);

    expect($html)->toContain('"@type": "BreadcrumbList"');
    expect($html)->toContain('"name": "Home"');
    expect($html)->toContain('"name": "About Us"');
    // Only 2 levels, no collection level
    expect($html)->not->toContain('"position": 3');
});

test('generates Article schema for blog entries', function () {
    $entry = Entry::fromArray([
        'title' => 'My Blog Post',
        'slug' => 'my-post',
        'collection' => 'blog',
        'locale' => 'es',
        'file' => 'content/blog/my-post.md',
        'date' => '2024-01-15',
        'mtime' => strtotime('2024-02-01'),
        'meta' => [
            'description' => 'A great post',
            'author' => 'John Doe',
            'image' => '/assets/images/post.jpg',
        ],
    ]);

    $gen = new JsonLdGenerator();
    $html = $gen->generate(['entry' => $entry, 'base_url' => 'https://example.com']);

    expect($html)->toContain('"@type": "BlogPosting"');
    expect($html)->toContain('"headline": "My Blog Post"');
    expect($html)->toContain('"datePublished": "2024-01-15"');
    expect($html)->toContain('"dateModified": "2024-02-01"');
    expect($html)->toContain('"name": "John Doe"');
    expect($html)->toContain('"image": "https://example.com/assets/images/post.jpg"');
    expect($html)->toContain('"description": "A great post"');
});

test('does not generate Article schema for pages', function () {
    $entry = Entry::fromArray([
        'title' => 'About',
        'slug' => 'about',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/about.md',
    ]);

    $gen = new JsonLdGenerator();
    $html = $gen->generate(['entry' => $entry, 'base_url' => 'https://example.com']);

    expect($html)->not->toContain('BlogPosting');
    expect($html)->not->toContain('Article');
});

test('produces valid JSON in each script', function () {
    $entry = Entry::fromArray([
        'title' => 'Test',
        'slug' => 'test',
        'collection' => 'blog',
        'locale' => 'es',
        'file' => 'content/blog/test.md',
        'date' => '2024-01-01',
        'meta' => ['description' => 'Test desc'],
    ]);

    $gen = new JsonLdGenerator(['site_name' => 'Site']);
    $html = $gen->generate(['entry' => $entry, 'base_url' => 'https://example.com']);

    // Extract JSON blocks
    preg_match_all('/<script type="application\/ld\+json">\n(.*?)\n<\/script>/s', $html, $matches);

    expect($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $json) {
        $decoded = json_decode($json, true);
        expect($decoded)->not->toBeNull('Invalid JSON: ' . $json);
        expect($decoded['@context'])->toBe('https://schema.org');
    }
});

test('generates separate scripts per schema', function () {
    $entry = Entry::fromArray([
        'title' => 'Post',
        'slug' => 'post',
        'collection' => 'blog',
        'locale' => 'es',
        'file' => 'content/blog/post.md',
        'date' => '2024-01-01',
        'meta' => ['description' => 'Desc'],
    ]);

    $gen = new JsonLdGenerator([
        'site_name' => 'Site',
        'organization' => ['name' => 'Corp', 'url' => 'https://corp.com'],
    ]);
    $html = $gen->generate(['entry' => $entry, 'base_url' => 'https://example.com']);

    $scriptCount = substr_count($html, '<script type="application/ld+json">');

    // WebSite + Organization + BreadcrumbList + BlogPosting = 4
    expect($scriptCount)->toBe(4);
});

test('returns empty string when no entry and no config', function () {
    $gen = new JsonLdGenerator();
    $html = $gen->generate([]);

    expect($html)->toBe('');
});
