<?php

declare(strict_types=1);

use Rkn\Cms\Cli\IndexRebuildCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Locks the --base contract for index:rebuild. Sin esta opción, el comando
 * resolvía solo via app('base_path') o getcwd() — y en producción shared
 * hosting, ejecutar desde el wrong cwd silenciosamente apuntaba al sitio
 * equivocado (o ni siquiera arrancaba). Mismo patrón que cache:clear.
 */

beforeEach(function () {
    $this->base = sys_get_temp_dir() . '/rkn-index-rebuild-' . uniqid();
    mkdir($this->base . '/content/blog', 0755, true);
    mkdir($this->base . '/config', 0755, true);
    mkdir($this->base . '/cache', 0755, true);

    file_put_contents($this->base . '/config/rakun.yaml', <<<'YAML'
site:
  default_locale: es
collections:
  blog:
    name: "Blog"
YAML);

    file_put_contents($this->base . '/content/blog/hello.es.md', <<<'MD'
---
title: "Hello"
date: "2026-01-01 00:00:00"
status: "publish"
---
Body.
MD);

    $this->tester = new CommandTester(new IndexRebuildCommand());
});

afterEach(function () {
    if (!is_dir($this->base)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($this->base);
});

it('fails with a clear error when --base does not exist', function () {
    $exitCode = $this->tester->execute(['--base' => '/does/not/exist-' . uniqid()]);

    expect($exitCode)->not->toBe(0)
        ->and($this->tester->getDisplay())->toContain('Base path does not exist');
});

it('accepts --base and reports rebuild outcome', function () {
    $exitCode = $this->tester->execute(['--base' => $this->base]);

    expect($exitCode)->toBe(0)
        ->and($this->tester->getDisplay())->toContain('Rebuilding content index');
});
