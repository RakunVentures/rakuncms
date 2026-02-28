<?php

declare(strict_types=1);

use Rkn\Cms\Events\Event;
use Rkn\Cms\Events\EventDispatcher;

test('dispatches event to registered listener', function () {
    $dispatcher = new EventDispatcher();
    $received = null;

    $dispatcher->listen('test.event', function (Event $event) use (&$received) {
        $received = $event;
    });

    $event = new Event('test.event', ['key' => 'value']);
    $dispatcher->dispatch($event);

    expect($received)->not->toBeNull();
    expect($received->name())->toBe('test.event');
    expect($received->get('key'))->toBe('value');
});

test('listener receives event with correct payload', function () {
    $dispatcher = new EventDispatcher();
    $payloads = [];

    $dispatcher->listen('entry.published', function (Event $event) use (&$payloads) {
        $payloads[] = $event->payload();
    });

    $dispatcher->dispatch(new Event('entry.published', ['title' => 'My Post', 'collection' => 'blog']));

    expect($payloads)->toHaveCount(1);
    expect($payloads[0]['title'])->toBe('My Post');
    expect($payloads[0]['collection'])->toBe('blog');
});

test('multiple listeners receive the same event', function () {
    $dispatcher = new EventDispatcher();
    $calls = 0;

    $dispatcher->listen('build.completed', function () use (&$calls) { $calls++; });
    $dispatcher->listen('build.completed', function () use (&$calls) { $calls++; });
    $dispatcher->listen('build.completed', function () use (&$calls) { $calls++; });

    $dispatcher->dispatch(new Event('build.completed'));

    expect($calls)->toBe(3);
});

test('events without listeners do not fail', function () {
    $dispatcher = new EventDispatcher();
    $event = $dispatcher->dispatch(new Event('no.listeners'));

    expect($event->name())->toBe('no.listeners');
});

test('wildcard listener receives all events', function () {
    $dispatcher = new EventDispatcher();
    $events = [];

    $dispatcher->listen('*', function (Event $event) use (&$events) {
        $events[] = $event->name();
    });

    $dispatcher->dispatch(new Event('event.one'));
    $dispatcher->dispatch(new Event('event.two'));

    expect($events)->toBe(['event.one', 'event.two']);
});

test('stopPropagation prevents further listeners', function () {
    $dispatcher = new EventDispatcher();
    $calls = [];

    $dispatcher->listen('test', function (Event $event) use (&$calls) {
        $calls[] = 'first';
        $event->stopPropagation();
    });

    $dispatcher->listen('test', function () use (&$calls) {
        $calls[] = 'second';
    });

    $dispatcher->dispatch(new Event('test'));

    expect($calls)->toBe(['first']);
});

test('hasListeners returns true when listeners exist', function () {
    $dispatcher = new EventDispatcher();
    $dispatcher->listen('my.event', fn () => null);

    expect($dispatcher->hasListeners('my.event'))->toBeTrue();
    expect($dispatcher->hasListeners('no.event'))->toBeFalse();
});

test('hasListeners returns true when wildcard listener exists', function () {
    $dispatcher = new EventDispatcher();
    $dispatcher->listen('*', fn () => null);

    expect($dispatcher->hasListeners('any.event'))->toBeTrue();
});

test('removeListeners clears specific event listeners', function () {
    $dispatcher = new EventDispatcher();
    $dispatcher->listen('test', fn () => null);

    $dispatcher->removeListeners('test');

    expect($dispatcher->hasListeners('test'))->toBeFalse();
});

test('removeListeners with null clears all listeners', function () {
    $dispatcher = new EventDispatcher();
    $dispatcher->listen('one', fn () => null);
    $dispatcher->listen('two', fn () => null);

    $dispatcher->removeListeners();

    expect($dispatcher->hasListeners('one'))->toBeFalse();
    expect($dispatcher->hasListeners('two'))->toBeFalse();
});

test('event get returns default for missing key', function () {
    $event = new Event('test', ['a' => 1]);

    expect($event->get('a'))->toBe(1);
    expect($event->get('b', 'default'))->toBe('default');
    expect($event->get('c'))->toBeNull();
});
