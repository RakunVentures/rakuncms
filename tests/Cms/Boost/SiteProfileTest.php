<?php

declare(strict_types=1);

use Rkn\Cms\Boost\SiteProfile;

test('creates profile with all fields', function () {
    $profile = new SiteProfile(
        name: 'My Site',
        description: 'A test site',
        locale: 'en',
        author: 'Test Author',
        archetype: 'blog',
    );

    expect($profile->name)->toBe('My Site');
    expect($profile->description)->toBe('A test site');
    expect($profile->locale)->toBe('en');
    expect($profile->author)->toBe('Test Author');
    expect($profile->archetype)->toBe('blog');
});

test('has sensible defaults', function () {
    $profile = new SiteProfile(name: 'Test');

    expect($profile->description)->toBe('');
    expect($profile->locale)->toBe('es');
    expect($profile->author)->toBe('');
    expect($profile->archetype)->toBe('blog');
});

test('toArray returns all fields', function () {
    $profile = new SiteProfile(
        name: 'Test',
        description: 'Desc',
        locale: 'es',
        author: 'Author',
        archetype: 'docs',
    );

    $array = $profile->toArray();
    expect($array)->toHaveKeys(['name', 'description', 'locale', 'author', 'archetype']);
    expect($array['name'])->toBe('Test');
    expect($array['archetype'])->toBe('docs');
});
