<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Tools\BoostArchetypesTool;

test('returns all 6 archetypes', function () {
    $tool = new BoostArchetypesTool();
    $result = $tool->execute([]);

    expect($result)->toHaveKey('archetypes');
    expect($result['archetypes'])->toHaveCount(6);
});

test('each archetype has name, description, and collections', function () {
    $tool = new BoostArchetypesTool();
    $result = $tool->execute([]);

    foreach ($result['archetypes'] as $archetype) {
        expect($archetype)->toHaveKeys(['name', 'description', 'collections']);
        expect($archetype['name'])->toBeString()->not->toBeEmpty();
        expect($archetype['description'])->toBeString()->not->toBeEmpty();
        expect($archetype['collections'])->toBeArray()->not->toBeEmpty();
    }
});

test('has correct tool metadata', function () {
    $tool = new BoostArchetypesTool();

    expect($tool->name())->toBe('boost-archetypes');
    expect($tool->description())->toContain('archetype');
    expect($tool->inputSchema()['type'])->toBe('object');
});

test('includes expected archetype names', function () {
    $tool = new BoostArchetypesTool();
    $result = $tool->execute([]);

    $names = array_column($result['archetypes'], 'name');
    expect($names)->toContain('blog');
    expect($names)->toContain('docs');
    expect($names)->toContain('business');
    expect($names)->toContain('portfolio');
    expect($names)->toContain('catalog');
    expect($names)->toContain('multilingual');
});
