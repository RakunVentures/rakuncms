<?php

declare(strict_types=1);

use Tests\Cms\Boost\BoostTestHelper;

test('boost creates blog site structure', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    $tester = BoostTestHelper::run($tmpDir, [
        '--archetype' => 'blog',
        '--name'      => 'My Blog',
        '--locale'    => 'es',
    ]);

    expect($tester->getStatusCode())->toBe(0);

    expect(file_exists("{$tmpDir}/content/blog/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/pages/_collection.yaml"))->toBeTrue();

    expect(file_exists("{$tmpDir}/content/blog/first-post.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/blog/second-post.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/blog/third-post.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/pages/index.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/pages/about.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/pages/contact.md"))->toBeTrue();

    expect(file_exists("{$tmpDir}/templates/_layouts/base.twig"))->toBeTrue();
    expect(file_exists("{$tmpDir}/templates/blog/show.twig"))->toBeTrue();
    expect(file_exists("{$tmpDir}/templates/blog/index.twig"))->toBeTrue();
    expect(file_exists("{$tmpDir}/templates/home.twig"))->toBeTrue();

    expect(file_exists("{$tmpDir}/public/assets/css/style.css"))->toBeTrue();

    expect(file_exists("{$tmpDir}/config/rakun.yaml"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/_globals/site.yaml"))->toBeTrue();
});

test('boost creates docs site structure', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    $tester = BoostTestHelper::run($tmpDir, [
        '--archetype' => 'docs',
        '--name'      => 'My Docs',
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists("{$tmpDir}/content/docs/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/docs/01.getting-started.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/templates/docs/show.twig"))->toBeTrue();
});

test('boost creates business site structure', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    $tester = BoostTestHelper::run($tmpDir, [
        '--archetype' => 'business',
        '--name'      => 'My Business',
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists("{$tmpDir}/content/services/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/services/01.service-one.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/templates/services/show.twig"))->toBeTrue();
    expect(file_exists("{$tmpDir}/templates/contact.twig"))->toBeTrue();
});

test('boost creates portfolio site structure', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    $tester = BoostTestHelper::run($tmpDir, [
        '--archetype' => 'portfolio',
        '--name'      => 'My Portfolio',
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists("{$tmpDir}/content/projects/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/projects/project-alpha.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/templates/projects/show.twig"))->toBeTrue();
});

test('boost creates catalog site structure', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    $tester = BoostTestHelper::run($tmpDir, [
        '--archetype' => 'catalog',
        '--name'      => 'My Catalog',
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists("{$tmpDir}/content/products/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/categories/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/products/01.product-one.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/templates/products/show.twig"))->toBeTrue();
    expect(file_exists("{$tmpDir}/templates/categories/show.twig"))->toBeTrue();
});

test('boost creates multilingual site structure', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    $tester = BoostTestHelper::run($tmpDir, [
        '--archetype' => 'multilingual',
        '--name'      => 'My Multilingual Site',
        '--locale'    => 'es',
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists("{$tmpDir}/content/blog/first-post.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/blog/first-post.en.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/pages/about.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/content/pages/about.en.md"))->toBeTrue();
    expect(file_exists("{$tmpDir}/templates/_partials/lang-switcher.twig"))->toBeTrue();
});

test('boost fails with unknown archetype', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    $tester = BoostTestHelper::run($tmpDir, [
        '--archetype' => 'nonexistent',
        '--name'      => 'Test',
    ]);

    expect($tester->getStatusCode())->toBe(1);
    expect($tester->getDisplay())->toContain('Unknown archetype');
});
