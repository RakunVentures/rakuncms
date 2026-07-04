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

test('scaffolded config/api.yaml.example is a valid template for server-only API keys', function () {
    $examplePath = $this->tmpDir . '/config/api.yaml.example';
    expect(file_exists($examplePath))->toBeTrue();

    // The real file is created by hand on each server — init must never scaffold it.
    expect(file_exists($this->tmpDir . '/config/api.yaml'))->toBeFalse();

    $parsed = Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($examplePath));
    expect($parsed['enabled'])->toBeTrue();
    expect($parsed['keys'][0]['permissions'])->toBe(['write', 'media']);
    expect($parsed['keys'][1]['permissions'])->toBe(['admin', 'write', 'media']);
});

test('scaffolded .gitignore ignores config/api.yaml but not its example template', function () {
    $gitignore = file_get_contents($this->tmpDir . '/.gitignore');

    expect($gitignore)->toContain('/config/api.yaml');
    // The anchored pattern must not swallow the committed template.
    expect($gitignore)->not->toContain('/config/api.yaml.example');
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

test('scaffolds a deploy composer.json requiring rkn/cms from Packagist', function () {
    $path = $this->tmpDir . '/composer.json';
    expect(file_exists($path))->toBeTrue();

    $manifest = json_decode((string) file_get_contents($path), true);
    expect($manifest)->toBeArray();
    // Deploy manifest: stable rkn/cms from Packagist, no path repositories.
    expect($manifest['require']['rkn/cms'])->toBe('^1.6');
    expect($manifest['minimum-stability'])->toBe('stable');
    expect($manifest)->not->toHaveKey('repositories');
    // Package name is a valid vendor/slug derived from the project dir.
    expect($manifest['name'])->toMatch('#^rkn/[a-z0-9]([a-z0-9-]*[a-z0-9])?$#');
    // Components must be PSR-4 autoloadable under App\Components so Yoyo resolves them.
    expect($manifest['autoload']['psr-4'])->toHaveKey('App\\Components\\');
    expect($manifest['autoload']['psr-4']['App\\Components\\'])->toBe('src/Components/');
});

test('scaffolds a local composer.local.json linking rkn/cms via a path repository', function () {
    $path = $this->tmpDir . '/composer.local.json';
    expect(file_exists($path))->toBeTrue();

    $manifest = json_decode((string) file_get_contents($path), true);
    expect($manifest)->toBeArray();
    // Local dev manifest: wildcard rkn/cms resolved from a local path checkout.
    expect($manifest['require']['rkn/cms'])->toBe('*');
    expect($manifest['minimum-stability'])->toBe('dev');
    expect($manifest['repositories'][0]['type'])->toBe('path');
    expect($manifest['repositories'][0]['url'])->toBe('../rakuncms');
    // Warning against shipping it to a server.
    expect($manifest['_comment'])->toContain('NUNCA lo subas al servidor');
});

test('honours --core-path for the local path repository url', function () {
    $dir = sys_get_temp_dir() . '/rkn_init_core_' . uniqid();
    mkdir($dir, 0755, true);

    $app = new Application();
    $app->addCommand(new InitCommand());
    (new CommandTester($app->find('init')))->execute([
        'path' => $dir,
        '--core-path' => '/Users/elalecs/Projects/RakunCMS/rakuncms',
    ]);

    $manifest = json_decode((string) file_get_contents($dir . '/composer.local.json'), true);
    expect($manifest['repositories'][0]['url'])->toBe('/Users/elalecs/Projects/RakunCMS/rakuncms');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
});

test('scaffolded Counter component lives in the App\\Components namespace Yoyo resolves', function () {
    $counter = file_get_contents($this->tmpDir . '/src/Components/Counter.php');
    expect($counter)->toContain('namespace App\\Components;');
    expect($counter)->not->toContain('namespace Rkn\\Cms\\Components;');
});

test('thin sites use vendor/bin/rakun, so no root rakun wrapper is scaffolded', function () {
    expect(file_exists($this->tmpDir . '/rakun'))->toBeFalse();
});

test('page template resolves from templates root so `template: page` renders', function () {
    // Frontmatter `template: page` looks up templates/page.twig; only _layouts/base.twig
    // belongs under _layouts/. A page.twig hidden in _layouts/ yields a 500 LoaderError.
    expect(file_exists($this->tmpDir . '/templates/page.twig'))->toBeTrue();
    expect(file_exists($this->tmpDir . '/templates/_layouts/page.twig'))->toBeFalse();

    $index = file_get_contents($this->tmpDir . '/content/pages/index.md');
    expect($index)->toContain('template: page');
});

test('scaffolded config declares only the locales it actually ships content for', function () {
    // LocaleDetector redirects "/" to the browser's locale IF it is in site.locales,
    // else to default_locale. If the scaffold left locales undefined, the middleware
    // falls back to ['es','en'] and an English browser is sent to /en/ — which 404s,
    // because init only generates /es/ content. Declaring locales: ['es'] keeps the
    // root landing on /es/ for every visitor until the site adds an /en/ tree.
    $config = Symfony\Component\Yaml\Yaml::parse(
        (string) file_get_contents($this->tmpDir . '/config/rakun.yaml')
    );
    expect($config['site']['default_locale'])->toBe('es');
    expect($config['site']['locales'])->toBe(['es']);

    // The declared locale must have matching content on disk.
    expect(is_dir($this->tmpDir . '/content/pages'))->toBeTrue();
});

test('scaffolded Yoyo counter is wired so the click actually works', function () {
    // The reactive button must use a request-method directive (yoyo:post), not
    // `yoyo:click` — 'click' is neither an HTMX method nor an event attribute, so
    // the compiler leaves it untouched and the button never fires a request.
    // RakunCMS routes Yoyo through POST only, hence yoyo:post (not yoyo:get).
    $counterTwig = file_get_contents($this->tmpDir . '/templates/yoyo/counter.twig');
    expect($counterTwig)->toContain('yoyo:post="increment"');
    expect($counterTwig)->not->toContain('yoyo:click');

    // The view file lives at templates/yoyo/counter.twig, and the Twig loader root
    // is templates/, so the component must render 'yoyo/counter', not 'counter'.
    $counter = file_get_contents($this->tmpDir . '/src/Components/Counter.php');
    expect($counter)->toContain("\$this->view('yoyo/counter'");

    // Markdown is compiled by CommonMark only (no Twig), so entry.content is already
    // HTML — render it raw. The live component demo lives in the template, not the .md.
    $pageTwig = file_get_contents($this->tmpDir . '/templates/page.twig');
    expect($pageTwig)->toContain('entry.content|raw');
    expect($pageTwig)->toContain("yoyo('counter')");

    // The .md must NOT carry a literal {{ yoyo('counter') }} — it would render as dead
    // text through |raw, never as a component.
    $index = file_get_contents($this->tmpDir . '/content/pages/index.md');
    expect($index)->not->toContain("yoyo('counter')");
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
