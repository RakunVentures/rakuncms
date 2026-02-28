<?php

declare(strict_types=1);

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Seo\MetaTagGenerator;

test('generates description from entry meta', function () {
    $entry = Entry::fromArray([
        'title' => 'Test Page',
        'slug' => 'test',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/test.md',
        'meta' => ['description' => 'A test page description'],
    ]);

    $gen = new MetaTagGenerator();
    $html = $gen->generate(['entry' => $entry]);

    expect($html)->toContain('<meta name="description" content="A test page description">');
});

test('falls back to site globals when entry has no description', function () {
    $entry = Entry::fromArray([
        'title' => 'Test Page',
        'slug' => 'test',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/test.md',
    ]);

    $gen = new MetaTagGenerator([], ['description' => 'Site-wide description']);
    $html = $gen->generate(['entry' => $entry]);

    expect($html)->toContain('<meta name="description" content="Site-wide description">');
});

test('generates canonical from entry URL', function () {
    $entry = Entry::fromArray([
        'title' => 'About',
        'slug' => 'about',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/about.md',
        'slugs' => ['es' => 'about'],
    ]);

    $gen = new MetaTagGenerator();
    $html = $gen->generate(['entry' => $entry, 'base_url' => 'https://example.com']);

    expect($html)->toContain('<link rel="canonical" href="https://example.com/es/about">');
});

test('respects custom canonical in frontmatter', function () {
    $entry = Entry::fromArray([
        'title' => 'About',
        'slug' => 'about',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/about.md',
        'meta' => ['canonical' => 'https://other.com/about'],
    ]);

    $gen = new MetaTagGenerator();
    $html = $gen->generate(['entry' => $entry, 'base_url' => 'https://example.com']);

    expect($html)->toContain('<link rel="canonical" href="https://other.com/about">');
    // Custom canonical overrides auto-generated one
    expect($html)->not->toContain('<link rel="canonical" href="https://example.com/es/about">');
});

test('generates OG tags with correct values', function () {
    $entry = Entry::fromArray([
        'title' => 'My Page',
        'slug' => 'my-page',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/my-page.md',
        'meta' => ['description' => 'Page description', 'image' => '/assets/images/cover.jpg'],
    ]);

    $gen = new MetaTagGenerator(['site_name' => 'Test Site']);
    $html = $gen->generate(['entry' => $entry, 'base_url' => 'https://example.com', 'locale' => 'es']);

    expect($html)->toContain('<meta property="og:title" content="My Page">');
    expect($html)->toContain('<meta property="og:description" content="Page description">');
    expect($html)->toContain('<meta property="og:url" content="https://example.com/es/my-page">');
    expect($html)->toContain('<meta property="og:type" content="website">');
    expect($html)->toContain('<meta property="og:image" content="https://example.com/assets/images/cover.jpg">');
    expect($html)->toContain('<meta property="og:locale" content="es">');
    expect($html)->toContain('<meta property="og:site_name" content="Test Site">');
});

test('og:type is article for blog collection', function () {
    $entry = Entry::fromArray([
        'title' => 'Blog Post',
        'slug' => 'blog-post',
        'collection' => 'blog',
        'locale' => 'es',
        'file' => 'content/blog/blog-post.md',
        'meta' => ['description' => 'A blog post'],
    ]);

    $gen = new MetaTagGenerator();
    $html = $gen->generate(['entry' => $entry]);

    expect($html)->toContain('<meta property="og:type" content="article">');
});

test('og:type defaults to website for pages', function () {
    $entry = Entry::fromArray([
        'title' => 'Page',
        'slug' => 'page',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/page.md',
        'meta' => ['description' => 'A page'],
    ]);

    $gen = new MetaTagGenerator();
    $html = $gen->generate(['entry' => $entry]);

    expect($html)->toContain('<meta property="og:type" content="website">');
});

test('twitter card is summary_large_image with image', function () {
    $entry = Entry::fromArray([
        'title' => 'Page',
        'slug' => 'page',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/page.md',
        'meta' => ['description' => 'Desc', 'image' => '/img/cover.jpg'],
    ]);

    $gen = new MetaTagGenerator(['twitter_handle' => '@test']);
    $html = $gen->generate(['entry' => $entry, 'base_url' => 'https://example.com']);

    expect($html)->toContain('<meta name="twitter:card" content="summary_large_image">');
    expect($html)->toContain('<meta name="twitter:title" content="Page">');
    expect($html)->toContain('<meta name="twitter:description" content="Desc">');
    expect($html)->toContain('<meta name="twitter:image" content="https://example.com/img/cover.jpg">');
    expect($html)->toContain('<meta name="twitter:site" content="@test">');
});

test('twitter card is summary without image', function () {
    $entry = Entry::fromArray([
        'title' => 'Page',
        'slug' => 'page',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/page.md',
        'meta' => ['description' => 'Desc'],
    ]);

    $gen = new MetaTagGenerator();
    $html = $gen->generate(['entry' => $entry]);

    expect($html)->toContain('<meta name="twitter:card" content="summary">');
});

test('generates hreflang tags for all locales plus x-default', function () {
    $gen = new MetaTagGenerator();
    $html = $gen->generate([
        'alternate_urls' => [
            'es' => 'https://example.com/es/about',
            'en' => 'https://example.com/en/about',
        ],
    ]);

    expect($html)->toContain('<link rel="alternate" hreflang="es" href="https://example.com/es/about">');
    expect($html)->toContain('<link rel="alternate" hreflang="en" href="https://example.com/en/about">');
    expect($html)->toContain('<link rel="alternate" hreflang="x-default" href="https://example.com/es/about">');
});

test('generates verification tags from config', function () {
    $gen = new MetaTagGenerator([
        'google_verification' => 'google123',
        'bing_verification' => 'bing456',
    ]);
    $html = $gen->generate([]);

    expect($html)->toContain('<meta name="google-site-verification" content="google123">');
    expect($html)->toContain('<meta name="msvalidate.01" content="bing456">');
});

test('returns empty string when no entry and no config', function () {
    $gen = new MetaTagGenerator();
    $html = $gen->generate([]);

    expect($html)->toBe('');
});

test('generates robots meta tag when set', function () {
    $entry = Entry::fromArray([
        'title' => 'Private',
        'slug' => 'private',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/private.md',
        'meta' => ['robots' => 'noindex,nofollow', 'description' => 'Private page'],
    ]);

    $gen = new MetaTagGenerator();
    $html = $gen->generate(['entry' => $entry]);

    expect($html)->toContain('<meta name="robots" content="noindex,nofollow">');
});

test('escapes special characters in meta content', function () {
    $entry = Entry::fromArray([
        'title' => 'Page with "quotes" & <special>',
        'slug' => 'special',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/special.md',
        'meta' => ['description' => 'Description with "quotes" & <tags>'],
    ]);

    $gen = new MetaTagGenerator();
    $html = $gen->generate(['entry' => $entry]);

    expect($html)->toContain('&quot;quotes&quot;');
    expect($html)->toContain('&amp;');
    expect($html)->not->toContain('<tags>');
});

test('generates keywords tag from frontmatter', function () {
    $entry = Entry::fromArray([
        'title' => 'Page',
        'slug' => 'page',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/page.md',
        'meta' => ['keywords' => 'php, cms, markdown'],
    ]);

    $gen = new MetaTagGenerator();
    $html = $gen->generate(['entry' => $entry]);

    expect($html)->toContain('<meta name="keywords" content="php, cms, markdown">');
});

test('og:type respects frontmatter override', function () {
    $entry = Entry::fromArray([
        'title' => 'Page',
        'slug' => 'page',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/page.md',
        'meta' => ['description' => 'Desc', 'type' => 'article'],
    ]);

    $gen = new MetaTagGenerator();
    $html = $gen->generate(['entry' => $entry]);

    expect($html)->toContain('<meta property="og:type" content="article">');
});
