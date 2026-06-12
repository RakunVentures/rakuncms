<?php

declare(strict_types=1);

use Rkn\Cms\Cli\InitCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_init_' . uniqid();
    mkdir($this->tmpDir, 0755, true);

    $app = new Application();
    $app->addCommand(new InitCommand());
    $tester = new CommandTester($app->find('init'));
    $tester->execute(['path' => $this->tmpDir]);
    $this->tester = $tester;
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

test('init succeeds', function () {
    expect($this->tester->getStatusCode())->toBe(0);
});

test('scaffolded .htaccess does not rewrite to ../cache (Apache 2.4 AH10244)', function () {
    $htaccess = file_get_contents($this->tmpDir . '/public/.htaccess');

    // The page cache lives above the docroot and is served by PHP, not Apache.
    // A rewrite target containing ".." is rejected by Apache 2.4 with a 400.
    expect($htaccess)->not->toContain('..');
    expect($htaccess)->not->toContain('cache/pages');
    // Front-controller fallback must still be present.
    expect($htaccess)->toContain('index.php');
});

test('scaffolded .htaccess forwards the Authorization header to PHP', function () {
    $htaccess = file_get_contents($this->tmpDir . '/public/.htaccess');

    // Apache+PHP-FPM drops the Authorization header unless re-exported: without
    // this rule every Bearer request to the Content API gets a 401.
    expect($htaccess)->toContain('E=HTTP_AUTHORIZATION:%{HTTP:Authorization}');
});

test('scaffolded index.php serves the page cache via PHP middleware', function () {
    // Removing the Apache cache rewrite is only safe because PHP serves it.
    $index = file_get_contents($this->tmpDir . '/public/index.php');
    expect($index)->toContain('PageCacheReader');
});

test('scaffolded .gitignore ignores deps but commits compiled assets', function () {
    $gitignore = file_get_contents($this->tmpDir . '/.gitignore');

    expect($gitignore)->toContain('/node_modules/');
    expect($gitignore)->toContain('/vendor/');
    expect($gitignore)->toContain('/.env');
    // Compiled assets must NOT be ignored — the server has no npm/node.
    expect($gitignore)->not->toContain('public/assets');
    expect($gitignore)->toContain('committed');
});

test('scaffolded compiled CSS is a real committed file (no build step on server)', function () {
    expect(file_exists($this->tmpDir . '/public/assets/css/style.css'))->toBeTrue();
});

test('a pre-existing .gitignore is preserved and augmented idempotently', function () {
    // Re-run init over the already-scaffolded project: lines must not duplicate.
    $app = new Application();
    $app->addCommand(new InitCommand());
    (new CommandTester($app->find('init')))->execute(['path' => $this->tmpDir]);

    $gitignore = file_get_contents($this->tmpDir . '/.gitignore');
    expect(substr_count($gitignore, '/node_modules/'))->toBe(1);
    expect(substr_count($gitignore, '/.env'))->toBeGreaterThanOrEqual(1);
});
