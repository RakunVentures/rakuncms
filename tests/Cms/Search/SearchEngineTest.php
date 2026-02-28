<?php

declare(strict_types=1);

use Rkn\Cms\Search\SearchEngine;

beforeEach(function () {
    $this->index = [
        'entries' => [
            'blog/php-post' => [
                'title' => 'Getting Started with PHP',
                'description' => 'Learn PHP programming basics',
                'url' => '/en/blog/php-post',
                'collection' => 'blog',
                'locale' => 'en',
                'tags' => ['php', 'tutorial'],
                'words' => ['getting', 'started', 'php', 'learn', 'programming', 'basics', 'functions', 'classes', 'tutorial'],
            ],
            'blog/js-post' => [
                'title' => 'JavaScript for Beginners',
                'description' => 'Introduction to JavaScript',
                'url' => '/en/blog/js-post',
                'collection' => 'blog',
                'locale' => 'en',
                'tags' => ['javascript', 'tutorial'],
                'words' => ['javascript', 'beginners', 'introduction', 'variables', 'functions', 'tutorial'],
            ],
            'blog/php-advanced' => [
                'title' => 'Advanced PHP Patterns',
                'description' => 'Deep dive into PHP design patterns',
                'url' => '/en/blog/php-advanced',
                'collection' => 'blog',
                'locale' => 'en',
                'tags' => ['php', 'advanced'],
                'words' => ['advanced', 'php', 'patterns', 'deep', 'dive', 'design', 'singleton', 'factory'],
            ],
            'blog/post-es' => [
                'title' => 'Introducción a PHP',
                'description' => 'Aprende PHP desde cero',
                'url' => '/es/blog/post-es',
                'collection' => 'blog',
                'locale' => 'es',
                'tags' => ['php'],
                'words' => ['introducción', 'php', 'aprende', 'cero', 'programación'],
            ],
        ],
        'inverted' => [
            'php' => ['blog/php-post', 'blog/php-advanced', 'blog/post-es'],
            'getting' => ['blog/php-post'],
            'started' => ['blog/php-post'],
            'learn' => ['blog/php-post'],
            'programming' => ['blog/php-post'],
            'basics' => ['blog/php-post'],
            'functions' => ['blog/php-post', 'blog/js-post'],
            'classes' => ['blog/php-post'],
            'tutorial' => ['blog/php-post', 'blog/js-post'],
            'javascript' => ['blog/js-post'],
            'beginners' => ['blog/js-post'],
            'introduction' => ['blog/js-post'],
            'variables' => ['blog/js-post'],
            'advanced' => ['blog/php-advanced'],
            'patterns' => ['blog/php-advanced'],
            'deep' => ['blog/php-advanced'],
            'dive' => ['blog/php-advanced'],
            'design' => ['blog/php-advanced'],
            'singleton' => ['blog/php-advanced'],
            'factory' => ['blog/php-advanced'],
            'introducción' => ['blog/post-es'],
            'aprende' => ['blog/post-es'],
            'cero' => ['blog/post-es'],
            'programación' => ['blog/post-es'],
        ],
    ];
});

test('finds entries by title match', function () {
    $engine = new SearchEngine($this->index);
    $results = $engine->search('PHP');

    expect($results)->not->toBeEmpty();
    $titles = array_column($results, 'title');
    expect($titles)->toContain('Getting Started with PHP');
    expect($titles)->toContain('Advanced PHP Patterns');
});

test('finds entries by content match', function () {
    $engine = new SearchEngine($this->index);
    $results = $engine->search('singleton');

    expect($results)->toHaveCount(1);
    expect($results[0]['title'])->toBe('Advanced PHP Patterns');
});

test('ranks title matches higher than content', function () {
    $engine = new SearchEngine($this->index);
    $results = $engine->search('PHP');

    // All PHP entries should appear, title matches scored higher
    expect(count($results))->toBeGreaterThanOrEqual(2);
    // First result should have higher score
    expect($results[0]['score'])->toBeGreaterThanOrEqual($results[1]['score']);
});

test('respects locale filter', function () {
    $engine = new SearchEngine($this->index);
    $results = $engine->search('PHP', locale: 'en');

    foreach ($results as $result) {
        $entry = $this->index['entries'][$result['key']];
        expect($entry['locale'])->toBe('en');
    }

    $esResults = $engine->search('PHP', locale: 'es');
    foreach ($esResults as $result) {
        $entry = $this->index['entries'][$result['key']];
        expect($entry['locale'])->toBe('es');
    }
});

test('returns empty for no matches', function () {
    $engine = new SearchEngine($this->index);
    $results = $engine->search('xyz-nonexistent-word');

    expect($results)->toBeEmpty();
});

test('returns empty for empty query', function () {
    $engine = new SearchEngine($this->index);
    $results = $engine->search('');

    expect($results)->toBeEmpty();
});

test('respects limit parameter', function () {
    $engine = new SearchEngine($this->index);
    $results = $engine->search('PHP', limit: 1);

    expect($results)->toHaveCount(1);
});

test('results include url and snippet', function () {
    $engine = new SearchEngine($this->index);
    $results = $engine->search('PHP');

    foreach ($results as $result) {
        expect($result)->toHaveKeys(['key', 'title', 'url', 'score', 'snippet']);
        expect($result['url'])->toStartWith('/');
    }
});

test('multi-word query boosts entries matching multiple words', function () {
    $engine = new SearchEngine($this->index);
    $results = $engine->search('advanced PHP patterns');

    // The "Advanced PHP Patterns" entry matches all 3 words
    expect($results[0]['title'])->toBe('Advanced PHP Patterns');
    expect($results[0]['score'])->toBeGreaterThan(10);
});

test('tag matches contribute to score', function () {
    $engine = new SearchEngine($this->index);
    $results = $engine->search('tutorial');

    // Two entries have "tutorial" tag
    expect(count($results))->toBeGreaterThanOrEqual(2);
});
