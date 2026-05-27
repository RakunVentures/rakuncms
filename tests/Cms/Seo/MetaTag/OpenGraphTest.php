<?php

declare(strict_types=1);

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Seo\MetaTagGenerator;

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
