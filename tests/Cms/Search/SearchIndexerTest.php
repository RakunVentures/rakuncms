<?php

declare(strict_types=1);

use Rkn\Cms\Search\SearchIndexer;

test('tokenizes text correctly', function () {
    $indexer = new SearchIndexer('/tmp');
    $words = $indexer->tokenize('Hello, World! This is a test of PHP tokenization.');

    expect($words)->toContain('hello');
    expect($words)->toContain('world');
    expect($words)->toContain('test');
    expect($words)->toContain('php');
    expect($words)->toContain('tokenization');
    // Stop words should be excluded
    expect($words)->not->toContain('this');
    expect($words)->not->toContain('is');
    expect($words)->not->toContain('a');
    expect($words)->not->toContain('of');
});

test('excludes stop words in English', function () {
    $indexer = new SearchIndexer('/tmp');
    $words = $indexer->tokenize('the cat is on the mat and it was a big one');

    expect($words)->toContain('cat');
    expect($words)->toContain('mat');
    expect($words)->toContain('big');
    expect($words)->toContain('one');
    expect($words)->not->toContain('the');
    expect($words)->not->toContain('is');
    expect($words)->not->toContain('on');
    expect($words)->not->toContain('and');
    expect($words)->not->toContain('it');
    expect($words)->not->toContain('was');
    expect($words)->not->toContain('a');
});

test('excludes stop words in Spanish', function () {
    $indexer = new SearchIndexer('/tmp');
    $words = $indexer->tokenize('el gato está en la casa de los vecinos');

    expect($words)->toContain('gato');
    expect($words)->toContain('casa');
    expect($words)->toContain('vecinos');
    expect($words)->not->toContain('el');
    expect($words)->not->toContain('en');
    expect($words)->not->toContain('la');
    expect($words)->not->toContain('de');
    expect($words)->not->toContain('los');
});

test('tokenizes empty text to empty array', function () {
    $indexer = new SearchIndexer('/tmp');
    expect($indexer->tokenize(''))->toBe([]);
});

test('removes duplicate words', function () {
    $indexer = new SearchIndexer('/tmp');
    $words = $indexer->tokenize('php php php coding coding');

    expect($words)->toBe(['php', 'coding']);
});

test('handles special characters and punctuation', function () {
    $indexer = new SearchIndexer('/tmp');
    $words = $indexer->tokenize('C++ is great! What about PHP8.2?');

    expect($words)->toContain('great');
    expect($words)->toContain('what');
});

test('filters short words under 2 characters', function () {
    $indexer = new SearchIndexer('/tmp');
    $words = $indexer->tokenize('I go to x y z park');

    expect($words)->not->toContain('i');
    expect($words)->not->toContain('x');
    expect($words)->not->toContain('y');
    expect($words)->not->toContain('z');
    expect($words)->toContain('go');
    expect($words)->toContain('park');
});
