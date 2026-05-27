<?php

declare(strict_types=1);

use Tests\Cms\Boost\BoostTestHelper;

test('boost writes site name to globals', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    BoostTestHelper::run($tmpDir, [
        '--archetype' => 'blog',
        '--name'      => 'Awesome Blog',
    ]);

    $globals = file_get_contents("{$tmpDir}/content/_globals/site.yaml");
    expect($globals)->toContain('Awesome Blog');
});

test('boost writes locale to config', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    BoostTestHelper::run($tmpDir, [
        '--archetype' => 'blog',
        '--name'      => 'Test',
        '--locale'    => 'en',
    ]);

    $config = file_get_contents("{$tmpDir}/config/rakun.yaml");
    expect($config)->toContain('en');
});

test('boost output confirms success', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    $tester = BoostTestHelper::run($tmpDir, [
        '--archetype' => 'blog',
        '--name'      => 'Test Blog',
    ]);

    $output = $tester->getDisplay();
    expect($output)->toContain('boosted successfully');
    expect($output)->toContain('blog');
});
