<?php

declare(strict_types=1);

use Rkn\Framework\Container;
use Rkn\Framework\NotFoundException;

test('can set and get a scalar value', function () {
    $container = new Container();
    $container->set('name', 'RakunCMS');

    expect($container->get('name'))->toBe('RakunCMS');
});

test('can set and get an object', function () {
    $container = new Container();
    $obj = new stdClass();
    $container->set('obj', $obj);

    expect($container->get('obj'))->toBe($obj);
});

test('factory is called lazily and returns singleton', function () {
    $container = new Container();
    $callCount = 0;

    $container->set('service', function () use (&$callCount) {
        $callCount++;
        return new stdClass();
    });

    expect($callCount)->toBe(0);

    $first = $container->get('service');
    expect($callCount)->toBe(1);

    $second = $container->get('service');
    expect($callCount)->toBe(1);
    expect($second)->toBe($first);
});

test('factory receives container', function () {
    $container = new Container();
    $container->set('name', 'test');

    $container->set('greeting', function ($c) {
        return 'Hello ' . $c->get('name');
    });

    expect($container->get('greeting'))->toBe('Hello test');
});

test('has returns true for registered services', function () {
    $container = new Container();
    $container->set('exists', 'yes');

    expect($container->has('exists'))->toBeTrue();
    expect($container->has('missing'))->toBeFalse();
});

test('throws NotFoundException for missing service', function () {
    $container = new Container();
    $container->get('nonexistent');
})->throws(NotFoundException::class, 'Service not found: nonexistent');

test('keys returns all registered service names', function () {
    $container = new Container();
    $container->set('a', 'value');
    $container->set('b', fn () => 'lazy');

    $keys = $container->keys();
    expect($keys)->toContain('a');
    expect($keys)->toContain('b');
});
