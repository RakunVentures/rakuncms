<?php

declare(strict_types=1);

use Rkn\Cms\Cli\BoostCommand;
use Rkn\Cms\Cli\InitCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_boost_cmd_' . uniqid();
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tmpDir);
});

test('boost creates blog site structure', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'blog',
        '--name' => 'My Blog',
        '--locale' => 'es',
    ]);

    expect($tester->getStatusCode())->toBe(0);

    // Check collections
    expect(file_exists("{$this->tmpDir}/content/blog/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/pages/_collection.yaml"))->toBeTrue();

    // Check entries
    expect(file_exists("{$this->tmpDir}/content/blog/first-post.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/blog/second-post.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/blog/third-post.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/pages/index.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/pages/about.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/pages/contact.md"))->toBeTrue();

    // Check templates
    expect(file_exists("{$this->tmpDir}/templates/_layouts/base.twig"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/templates/blog/show.twig"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/templates/blog/index.twig"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/templates/home.twig"))->toBeTrue();

    // Check CSS
    expect(file_exists("{$this->tmpDir}/public/assets/css/style.css"))->toBeTrue();

    // Check config
    expect(file_exists("{$this->tmpDir}/config/rakun.yaml"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/_globals/site.yaml"))->toBeTrue();
});

test('boost creates docs site structure', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'docs',
        '--name' => 'My Docs',
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists("{$this->tmpDir}/content/docs/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/docs/01.getting-started.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/templates/docs/show.twig"))->toBeTrue();
});

test('boost creates business site structure', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'business',
        '--name' => 'My Business',
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists("{$this->tmpDir}/content/services/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/services/01.service-one.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/templates/services/show.twig"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/templates/contact.twig"))->toBeTrue();
});

test('boost creates portfolio site structure', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'portfolio',
        '--name' => 'My Portfolio',
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists("{$this->tmpDir}/content/projects/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/projects/project-alpha.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/templates/projects/show.twig"))->toBeTrue();
});

test('boost creates catalog site structure', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'catalog',
        '--name' => 'My Catalog',
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists("{$this->tmpDir}/content/products/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/categories/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/products/01.product-one.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/templates/products/show.twig"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/templates/categories/show.twig"))->toBeTrue();
});

test('boost creates multilingual site structure', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'multilingual',
        '--name' => 'My Multilingual Site',
        '--locale' => 'es',
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists("{$this->tmpDir}/content/blog/first-post.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/blog/first-post.en.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/pages/about.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/pages/about.en.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/templates/_partials/lang-switcher.twig"))->toBeTrue();
});

test('boost fails with unknown archetype', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'nonexistent',
        '--name' => 'Test',
    ]);

    expect($tester->getStatusCode())->toBe(1);
    expect($tester->getDisplay())->toContain('Unknown archetype');
});

test('boost writes site name to globals', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'blog',
        '--name' => 'Awesome Blog',
    ]);

    $globals = file_get_contents("{$this->tmpDir}/content/_globals/site.yaml");
    expect($globals)->toContain('Awesome Blog');
});

test('boost writes locale to config', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'blog',
        '--name' => 'Test',
        '--locale' => 'en',
    ]);

    $config = file_get_contents("{$this->tmpDir}/config/rakun.yaml");
    expect($config)->toContain('en');
});

test('boost output confirms success', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'blog',
        '--name' => 'Test Blog',
    ]);

    $output = $tester->getDisplay();
    expect($output)->toContain('boosted successfully');
    expect($output)->toContain('blog');
});

test('boost entries contain valid frontmatter YAML', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'blog',
        '--name' => 'Test',
    ]);

    $content = file_get_contents("{$this->tmpDir}/content/blog/first-post.md");
    expect($content)->toStartWith("---\n");
    expect(substr_count($content, "---\n"))->toBeGreaterThanOrEqual(2);
});

test('boost CSS includes archetype-specific styles', function () {
    $app = new Application();
    $app->add(new InitCommand());
    $app->add(new BoostCommand());
    $tester = new CommandTester($app->find('boost'));
    $tester->execute([
        'path' => $this->tmpDir,
        '--archetype' => 'blog',
        '--name' => 'Test',
    ]);

    $css = file_get_contents("{$this->tmpDir}/public/assets/css/style.css");
    expect($css)->toContain('.post-card');
    expect($css)->toContain('.blog-layout');
});
