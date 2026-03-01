<?php

declare(strict_types=1);

use Rkn\Cms\Template\Extensions\IntegrationsExtension;

test('registers 6 Twig functions', function () {
    $ext = new IntegrationsExtension();
    $functions = $ext->getFunctions();

    expect($functions)->toHaveCount(6);
});

test('all functions have correct names', function () {
    $ext = new IntegrationsExtension();
    $functions = $ext->getFunctions();

    $names = array_map(fn ($f) => $f->getName(), $functions);

    expect($names)->toContain('whatsapp_button');
    expect($names)->toContain('newsletter_form');
    expect($names)->toContain('stripe_button');
    expect($names)->toContain('stripe_buttons');
    expect($names)->toContain('gumroad_button');
    expect($names)->toContain('gumroad_buttons');
});

test('all functions are marked as html safe', function () {
    $ext = new IntegrationsExtension();
    $functions = $ext->getFunctions();

    foreach ($functions as $function) {
        expect($function->getSafe(new \Twig\Node\Expression\ConstantExpression('', 0)))->toContain('html');
    }
});

test('extends AbstractExtension', function () {
    $ext = new IntegrationsExtension();

    expect($ext)->toBeInstanceOf(\Twig\Extension\AbstractExtension::class);
});
