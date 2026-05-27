<?php

declare(strict_types=1);

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Seo\MetaTagGenerator;

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
