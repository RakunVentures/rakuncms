<?php

declare(strict_types=1);

use Rkn\Cms\Template\Extensions\SeoExtension;

test('seo extension provides all expected functions', function () {
    $ext = new SeoExtension();
    $functions = $ext->getFunctions();

    $names = array_map(fn ($f) => $f->getName(), $functions);

    expect($names)->toContain('seo_head');
    expect($names)->toContain('seo_jsonld');
    expect($names)->toContain('seo_consent');
    expect($names)->toContain('seo_analytics');
    expect($names)->toContain('seo_webmcp');
});

test('seo extension functions are marked as html safe', function () {
    $ext = new SeoExtension();
    $functions = $ext->getFunctions();

    foreach ($functions as $function) {
        expect($function->getSafe(new \Twig\Node\Expression\ConstantExpression('', 0)))->toContain('html');
    }
});
