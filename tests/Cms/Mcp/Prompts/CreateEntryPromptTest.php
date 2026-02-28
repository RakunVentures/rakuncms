<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Prompts\CreateEntryPrompt;
use Rkn\Cms\Mcp\Prompts\CreateCollectionPrompt;
use Rkn\Cms\Mcp\Prompts\CreateComponentPrompt;

test('CreateEntryPrompt generates structured instructions', function () {
    $prompt = new CreateEntryPrompt('/tmp/nonexistent');
    $result = $prompt->get(['collection' => 'blog', 'title' => 'My New Post']);

    expect($result['messages'])->toHaveCount(1);
    $text = $result['messages'][0]['content']['text'];

    expect($text)->toContain('blog');
    expect($text)->toContain('my-new-post');
    expect($text)->toContain('frontmatter');
    expect($text)->toContain('index:rebuild');
});

test('CreateEntryPrompt handles locale variants', function () {
    $prompt = new CreateEntryPrompt('/tmp/nonexistent');
    $result = $prompt->get(['collection' => 'pages', 'locale' => 'en', 'title' => 'About']);

    $text = $result['messages'][0]['content']['text'];
    expect($text)->toContain('.en.md');
});

test('CreateCollectionPrompt generates full scaffold instructions', function () {
    $prompt = new CreateCollectionPrompt();
    $result = $prompt->get(['name' => 'products']);

    $text = $result['messages'][0]['content']['text'];

    expect($text)->toContain('products');
    expect($text)->toContain('_collection.yaml');
    expect($text)->toContain('index.twig');
    expect($text)->toContain('show.twig');
    expect($text)->toContain('index:rebuild');
});

test('CreateComponentPrompt generates Yoyo component instructions', function () {
    $prompt = new CreateComponentPrompt();
    $result = $prompt->get(['name' => 'NewsletterForm']);

    $text = $result['messages'][0]['content']['text'];

    expect($text)->toContain('NewsletterForm');
    expect($text)->toContain('newsletter-form');
    expect($text)->toContain('Clickfwd\Yoyo\Component');
    expect($text)->toContain('yoyo:method');
    expect($text)->toContain('yoyo_render');
});
