<?php

declare(strict_types=1);

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Template\Extensions\MediaExtension;

beforeEach(function () {
    $this->ext = new MediaExtension();
});

function makeEntryWithMeta(array $meta): Entry
{
    return Entry::fromArray([
        'title'      => 'X',
        'slug'       => 'x',
        'collection' => 'blog',
        'locale'     => 'es',
        'file'       => '/tmp/x.md',
        'meta'       => $meta,
    ]);
}

test('article_image returns meta.image for the wide variant', function () {
    $entry = makeEntryWithMeta(['image' => '/uploads/wide.webp']);
    expect($this->ext->articleImage($entry, 'wide'))->toBe('/uploads/wide.webp');
});

test('article_image returns meta.image_portrait when present', function () {
    $entry = makeEntryWithMeta([
        'image' => '/uploads/wide.webp',
        'image_portrait' => '/uploads/portrait.webp',
    ]);
    expect($this->ext->articleImage($entry, 'portrait'))->toBe('/uploads/portrait.webp');
});

test('article_image falls back to image when portrait is missing', function () {
    $entry = makeEntryWithMeta(['image' => '/uploads/wide.webp']);
    expect($this->ext->articleImage($entry, 'portrait'))->toBe('/uploads/wide.webp');
});

test('article_image square falls back through portrait to image', function () {
    $onlyWide = makeEntryWithMeta(['image' => '/u/wide.webp']);
    expect($this->ext->articleImage($onlyWide, 'square'))->toBe('/u/wide.webp');

    $widePortrait = makeEntryWithMeta([
        'image' => '/u/wide.webp',
        'image_portrait' => '/u/portrait.webp',
    ]);
    expect($this->ext->articleImage($widePortrait, 'square'))->toBe('/u/portrait.webp');

    $all = makeEntryWithMeta([
        'image' => '/u/wide.webp',
        'image_portrait' => '/u/portrait.webp',
        'image_square' => '/u/square.webp',
    ]);
    expect($this->ext->articleImage($all, 'square'))->toBe('/u/square.webp');
});

test('article_image returns empty string when nothing is set', function () {
    $entry = makeEntryWithMeta([]);
    expect($this->ext->articleImage($entry, 'wide'))->toBe('');
    expect($this->ext->articleImage($entry, 'portrait'))->toBe('');
    expect($this->ext->articleImage($entry, 'square'))->toBe('');
});

test('article_image accepts an array entry (Yoyo paginate shape)', function () {
    $arrayEntry = [
        'title' => 'X',
        'meta'  => ['image' => '/u/a.webp', 'image_portrait' => '/u/p.webp'],
    ];
    expect($this->ext->articleImage($arrayEntry, 'wide'))->toBe('/u/a.webp');
    expect($this->ext->articleImage($arrayEntry, 'portrait'))->toBe('/u/p.webp');
});

test('article_image accepts a flat array (no meta wrapper)', function () {
    $flat = ['image' => '/u/wide.webp', 'image_portrait' => '/u/portrait.webp'];
    expect($this->ext->articleImage($flat, 'wide'))->toBe('/u/wide.webp');
    expect($this->ext->articleImage($flat, 'portrait'))->toBe('/u/portrait.webp');
});

test('article_image defaults unknown variants to wide', function () {
    $entry = makeEntryWithMeta(['image' => '/u/wide.webp']);
    expect($this->ext->articleImage($entry, 'banner-9000'))->toBe('/u/wide.webp');
});

test('article_image registers an article_image Twig function', function () {
    $functions = $this->ext->getFunctions();
    expect($functions)->toHaveCount(1);
    expect($functions[0]->getName())->toBe('article_image');
});
