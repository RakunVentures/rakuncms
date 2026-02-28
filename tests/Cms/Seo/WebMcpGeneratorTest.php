<?php

declare(strict_types=1);

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Seo\WebMcpGenerator;

test('generates JS with feature detection for navigator.modelContext', function () {
    $gen = new WebMcpGenerator();
    $html = $gen->generate([]);

    expect($html)->toContain("if('modelContext' in navigator)");
    expect($html)->toContain('<script>');
    expect($html)->toContain('</script>');
});

test('registers site_search tool with correct schema', function () {
    $gen = new WebMcpGenerator();
    $html = $gen->generate([]);

    expect($html)->toContain("name:'site_search'");
    expect($html)->toContain("description:'Search site content by keyword'");
    expect($html)->toContain("required:['query']");
});

test('registers site_navigation tool with nav data', function () {
    $nav = [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'About', 'url' => '/about'],
    ];

    $gen = new WebMcpGenerator();
    $html = $gen->generate(['nav' => $nav]);

    expect($html)->toContain("name:'site_navigation'");
    expect($html)->toContain('"label":"Home"');
    expect($html)->toContain('"label":"About"');
});

test('registers list_content tool with entries data', function () {
    $entry = Entry::fromArray([
        'title' => 'About Us',
        'slug' => 'about',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/about.md',
    ]);

    $gen = new WebMcpGenerator();
    $html = $gen->generate(['entry' => $entry]);

    expect($html)->toContain("name:'list_content'");
    expect($html)->toContain('"title":"About Us"');
    expect($html)->toContain('"collection":"pages"');
});

test('registers current_page tool with entry metadata', function () {
    $entry = Entry::fromArray([
        'title' => 'My Page',
        'slug' => 'my-page',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/my-page.md',
        'meta' => ['description' => 'Page description'],
    ]);

    $gen = new WebMcpGenerator();
    $html = $gen->generate([
        'entry' => $entry,
        'locale' => 'es',
        'base_url' => 'https://example.com',
    ]);

    expect($html)->toContain("name:'current_page'");
    expect($html)->toContain('"title":"My Page"');
    expect($html)->toContain('"description":"Page description"');
    expect($html)->toContain('"locale":"es"');
    expect($html)->toContain('"collection":"pages"');
});

test('output is wrapped in script tags', function () {
    $gen = new WebMcpGenerator();
    $html = $gen->generate([]);

    expect($html)->toMatch('/^<script>\n.*\n<\/script>$/s');
});

test('handles null entry gracefully', function () {
    $gen = new WebMcpGenerator(['title' => 'Site Title', 'description' => 'Site Desc']);
    $html = $gen->generate(['locale' => 'es', 'base_url' => 'https://example.com']);

    expect($html)->toContain('"title":"Site Title"');
    expect($html)->toContain('"description":"Site Desc"');
    expect($html)->not->toContain('null');
});

test('search tool filters by query', function () {
    $gen = new WebMcpGenerator();
    $html = $gen->generate([]);

    expect($html)->toContain('indexOf(q)');
    expect($html)->toContain('input.query.toLowerCase()');
});

test('list_content tool supports collection filter', function () {
    $gen = new WebMcpGenerator();
    $html = $gen->generate([]);

    expect($html)->toContain('input.collection');
    expect($html)->toContain("collection:{type:'string'");
});
