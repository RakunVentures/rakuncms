<?php

declare(strict_types=1);

use Rkn\Cms\Integrations\StripeButtonGenerator;

test('returns empty when no links configured', function () {
    $gen = new StripeButtonGenerator();
    expect($gen->render('basic'))->toBe('');
    expect($gen->renderAll())->toBe('');
});

test('returns empty when links array is empty', function () {
    $gen = new StripeButtonGenerator(['links' => []]);
    expect($gen->renderAll())->toBe('');
});

test('renders single button by ID', function () {
    $gen = new StripeButtonGenerator([
        'links' => [
            ['id' => 'basic', 'label' => 'Plan Basico', 'url' => 'https://buy.stripe.com/XXX', 'description' => '$9/mes'],
        ],
    ]);
    $html = $gen->render('basic');

    expect($html)->toContain('Plan Basico');
    expect($html)->toContain('https://buy.stripe.com/XXX');
});

test('returns empty for unknown link ID', function () {
    $gen = new StripeButtonGenerator([
        'links' => [
            ['id' => 'basic', 'label' => 'Plan Basico', 'url' => 'https://buy.stripe.com/XXX'],
        ],
    ]);

    expect($gen->render('nonexistent'))->toBe('');
});

test('renders all buttons wrapped in container', function () {
    $gen = new StripeButtonGenerator([
        'links' => [
            ['id' => 'basic', 'label' => 'Plan Basico', 'url' => 'https://buy.stripe.com/XXX'],
            ['id' => 'premium', 'label' => 'Plan Premium', 'url' => 'https://buy.stripe.com/YYY'],
        ],
    ]);
    $html = $gen->renderAll();

    expect($html)->toContain('rkn-stripe-buttons');
    expect($html)->toContain('Plan Basico');
    expect($html)->toContain('Plan Premium');
});

test('includes target blank on links', function () {
    $gen = new StripeButtonGenerator([
        'links' => [
            ['id' => 'basic', 'label' => 'Basic', 'url' => 'https://buy.stripe.com/XXX'],
        ],
    ]);
    $html = $gen->render('basic');

    expect($html)->toContain('target="_blank"');
});

test('includes noopener noreferrer', function () {
    $gen = new StripeButtonGenerator([
        'links' => [
            ['id' => 'basic', 'label' => 'Basic', 'url' => 'https://buy.stripe.com/XXX'],
        ],
    ]);
    $html = $gen->render('basic');

    expect($html)->toContain('rel="noopener noreferrer"');
});

test('renders description when provided', function () {
    $gen = new StripeButtonGenerator([
        'links' => [
            ['id' => 'basic', 'label' => 'Basic', 'url' => 'https://buy.stripe.com/XXX', 'description' => '$9/mes'],
        ],
    ]);
    $html = $gen->render('basic');

    expect($html)->toContain('$9/mes');
    expect($html)->toContain('rkn-stripe-desc');
});

test('omits description span when not provided', function () {
    $gen = new StripeButtonGenerator([
        'links' => [
            ['id' => 'basic', 'label' => 'Basic', 'url' => 'https://buy.stripe.com/XXX'],
        ],
    ]);
    $html = $gen->render('basic');

    expect($html)->not->toContain('rkn-stripe-desc');
});

test('uses primary style by default', function () {
    $gen = new StripeButtonGenerator([
        'links' => [
            ['id' => 'basic', 'label' => 'Basic', 'url' => 'https://buy.stripe.com/XXX'],
        ],
    ]);
    $html = $gen->render('basic');

    expect($html)->toContain('background:#635bff');
    expect($html)->toContain('color:#fff');
});

test('uses outline style when configured', function () {
    $gen = new StripeButtonGenerator([
        'links' => [
            ['id' => 'basic', 'label' => 'Basic', 'url' => 'https://buy.stripe.com/XXX'],
        ],
        'button_style' => 'outline',
    ]);
    $html = $gen->render('basic');

    expect($html)->toContain('border:2px solid #635bff');
    expect($html)->toContain('color:#635bff');
    expect($html)->toContain('background:transparent');
});

test('uses minimal style when configured', function () {
    $gen = new StripeButtonGenerator([
        'links' => [
            ['id' => 'basic', 'label' => 'Basic', 'url' => 'https://buy.stripe.com/XXX'],
        ],
        'button_style' => 'minimal',
    ]);
    $html = $gen->render('basic');

    expect($html)->toContain('text-decoration:underline');
    expect($html)->toContain('color:#635bff');
});

test('escapes HTML in label and description', function () {
    $gen = new StripeButtonGenerator([
        'links' => [
            ['id' => 'xss', 'label' => '<script>alert(1)</script>', 'url' => 'https://buy.stripe.com/XXX', 'description' => '<img onerror=alert(1)>'],
        ],
    ]);
    $html = $gen->render('xss');

    expect($html)->not->toContain('<script>alert');
    expect($html)->not->toContain('<img onerror');
});
