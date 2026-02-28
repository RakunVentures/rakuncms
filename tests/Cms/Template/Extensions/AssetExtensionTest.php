<?php

declare(strict_types=1);

use Rkn\Cms\Template\Extensions\AssetExtension;

test('asset extension provides asset function', function () {
    $ext = new AssetExtension();
    $functions = $ext->getFunctions();

    expect($functions)->toHaveCount(1);
    expect($functions[0]->getName())->toBe('asset');
});
