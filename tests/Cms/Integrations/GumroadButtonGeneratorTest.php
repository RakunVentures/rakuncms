<?php

declare(strict_types=1);

use Rkn\Cms\Integrations\GumroadButtonGenerator;

test('returns empty when no products configured', function () {
    $gen = new GumroadButtonGenerator();
    expect($gen->render('my-ebook'))->toBe('');
    expect($gen->renderAll())->toBe('');
});

test('returns empty when products array is empty', function () {
    $gen = new GumroadButtonGenerator(['products' => []]);
    expect($gen->renderAll())->toBe('');
});

test('renders single button by product ID', function () {
    $gen = new GumroadButtonGenerator([
        'products' => [
            ['id' => 'my-ebook', 'label' => 'Comprar eBook', 'description' => '$19'],
        ],
    ]);
    $html = $gen->render('my-ebook');

    expect($html)->toContain('Comprar eBook');
    expect($html)->toContain('https://gumroad.com/l/my-ebook');
});

test('returns empty for unknown product ID', function () {
    $gen = new GumroadButtonGenerator([
        'products' => [
            ['id' => 'my-ebook', 'label' => 'Comprar eBook'],
        ],
    ]);

    expect($gen->render('nonexistent'))->toBe('');
});

test('renders all buttons wrapped in container', function () {
    $gen = new GumroadButtonGenerator([
        'products' => [
            ['id' => 'my-ebook', 'label' => 'Comprar eBook'],
            ['id' => 'course-xyz', 'label' => 'Acceder al Curso'],
        ],
    ]);
    $html = $gen->renderAll();

    expect($html)->toContain('rkn-gumroad-buttons');
    expect($html)->toContain('Comprar eBook');
    expect($html)->toContain('Acceder al Curso');
});

test('uses gumroad-button CSS class', function () {
    $gen = new GumroadButtonGenerator([
        'products' => [
            ['id' => 'my-ebook', 'label' => 'eBook'],
        ],
    ]);
    $html = $gen->render('my-ebook');

    expect($html)->toContain('class="gumroad-button"');
});

test('generates correct gumroad URL', function () {
    $gen = new GumroadButtonGenerator([
        'products' => [
            ['id' => 'test-product', 'label' => 'Test'],
        ],
    ]);
    $html = $gen->render('test-product');

    expect($html)->toContain('href="https://gumroad.com/l/test-product"');
});

test('renders description when provided', function () {
    $gen = new GumroadButtonGenerator([
        'products' => [
            ['id' => 'my-ebook', 'label' => 'eBook', 'description' => '$19'],
        ],
    ]);
    $html = $gen->render('my-ebook');

    expect($html)->toContain('$19');
    expect($html)->toContain('rkn-gumroad-desc');
});

test('omits description span when not provided', function () {
    $gen = new GumroadButtonGenerator([
        'products' => [
            ['id' => 'my-ebook', 'label' => 'eBook'],
        ],
    ]);
    $html = $gen->render('my-ebook');

    expect($html)->not->toContain('rkn-gumroad-desc');
});

test('renders gumroad script when overlay is true', function () {
    $gen = new GumroadButtonGenerator([
        'products' => [
            ['id' => 'my-ebook', 'label' => 'eBook'],
        ],
        'overlay' => true,
    ]);

    expect($gen->renderScript())->toContain('https://gumroad.com/js/gumroad.js');
});

test('renders gumroad script by default', function () {
    $gen = new GumroadButtonGenerator([
        'products' => [
            ['id' => 'my-ebook', 'label' => 'eBook'],
        ],
    ]);

    expect($gen->renderScript())->toContain('https://gumroad.com/js/gumroad.js');
});

test('does not render script when overlay is false', function () {
    $gen = new GumroadButtonGenerator([
        'products' => [
            ['id' => 'my-ebook', 'label' => 'eBook'],
        ],
        'overlay' => false,
    ]);

    expect($gen->renderScript())->toBe('');
});

test('does not render script when no products', function () {
    $gen = new GumroadButtonGenerator(['overlay' => true]);

    expect($gen->renderScript())->toBe('');
});

test('escapes HTML in label and description', function () {
    $gen = new GumroadButtonGenerator([
        'products' => [
            ['id' => 'xss', 'label' => '<script>alert(1)</script>', 'description' => '<img onerror=alert(1)>'],
        ],
    ]);
    $html = $gen->render('xss');

    expect($html)->not->toContain('<script>alert');
    expect($html)->not->toContain('<img onerror');
});
