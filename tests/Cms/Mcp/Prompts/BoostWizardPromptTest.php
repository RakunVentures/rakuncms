<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Prompts\BoostWizardPrompt;

test('has correct prompt metadata', function () {
    $prompt = new BoostWizardPrompt();

    expect($prompt->name())->toBe('boost-wizard');
    expect($prompt->description())->toContain('archetype');
});

test('arguments include archetype, name, and locale', function () {
    $prompt = new BoostWizardPrompt();
    $args = $prompt->arguments();

    $names = array_column($args, 'name');
    expect($names)->toContain('archetype');
    expect($names)->toContain('name');
    expect($names)->toContain('locale');

    // All optional
    foreach ($args as $arg) {
        expect($arg['required'])->toBeFalse();
    }
});

test('returns messages with archetype list', function () {
    $prompt = new BoostWizardPrompt();
    $result = $prompt->get([]);

    expect($result)->toHaveKey('messages');
    expect($result['messages'])->toBeArray()->not->toBeEmpty();

    $text = $result['messages'][0]['content']['text'];
    expect($text)->toContain('blog');
    expect($text)->toContain('docs');
    expect($text)->toContain('business');
    expect($text)->toContain('portfolio');
    expect($text)->toContain('catalog');
    expect($text)->toContain('multilingual');
});

test('includes pre-selected archetype when provided', function () {
    $prompt = new BoostWizardPrompt();
    $result = $prompt->get(['archetype' => 'docs']);

    $text = $result['messages'][0]['content']['text'];
    expect($text)->toContain('pre-selected');
    expect($text)->toContain('docs');
});

test('includes pre-set name when provided', function () {
    $prompt = new BoostWizardPrompt();
    $result = $prompt->get(['name' => 'My Cool Site']);

    $text = $result['messages'][0]['content']['text'];
    expect($text)->toContain('My Cool Site');
});

test('includes pre-set locale when provided', function () {
    $prompt = new BoostWizardPrompt();
    $result = $prompt->get(['locale' => 'en']);

    $text = $result['messages'][0]['content']['text'];
    expect($text)->toContain('en');
});

test('instructions mention boost-apply tool', function () {
    $prompt = new BoostWizardPrompt();
    $result = $prompt->get([]);

    $text = $result['messages'][0]['content']['text'];
    expect($text)->toContain('boost-apply');
});

test('instructions mention BOOST placeholder replacement', function () {
    $prompt = new BoostWizardPrompt();
    $result = $prompt->get([]);

    $text = $result['messages'][0]['content']['text'];
    expect($text)->toContain('BOOST');
    expect($text)->toContain('placeholder');
});
