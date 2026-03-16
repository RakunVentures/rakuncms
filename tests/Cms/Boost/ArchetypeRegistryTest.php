<?php

declare(strict_types=1);

use Rkn\Cms\Boost\ArchetypeRegistry;
use Rkn\Cms\Boost\Archetypes\BlogArchetype;
use Rkn\Cms\Boost\Archetypes\DocsArchetype;

test('registers and retrieves archetypes', function () {
    $registry = new ArchetypeRegistry();
    $blog = new BlogArchetype();
    $registry->register($blog);

    expect($registry->has('blog'))->toBeTrue();
    expect($registry->get('blog'))->toBe($blog);
    expect($registry->has('nonexistent'))->toBeFalse();
    expect($registry->get('nonexistent'))->toBeNull();
});

test('lists all registered archetypes', function () {
    $registry = new ArchetypeRegistry();
    $registry->register(new BlogArchetype());
    $registry->register(new DocsArchetype());

    $all = $registry->all();
    expect($all)->toHaveCount(2);
    expect($all[0]->name())->toBe('blog');
    expect($all[1]->name())->toBe('docs');
});

test('names returns registered archetype names', function () {
    $registry = new ArchetypeRegistry();
    $registry->register(new BlogArchetype());
    $registry->register(new DocsArchetype());

    expect($registry->names())->toBe(['blog', 'docs']);
});

test('withDefaults registers all 6 archetypes', function () {
    $registry = ArchetypeRegistry::withDefaults();

    expect($registry->names())->toContain('blog');
    expect($registry->names())->toContain('docs');
    expect($registry->names())->toContain('business');
    expect($registry->names())->toContain('portfolio');
    expect($registry->names())->toContain('catalog');
    expect($registry->names())->toContain('multilingual');
    expect($registry->all())->toHaveCount(6);
});
