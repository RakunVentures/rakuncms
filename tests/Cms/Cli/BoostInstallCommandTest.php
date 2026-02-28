<?php

declare(strict_types=1);

use Rkn\Cms\Cli\BoostInstallCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_boost_' . uniqid();
    mkdir($this->tmpDir . '/content/pages', 0755, true);
    mkdir($this->tmpDir . '/content/blog', 0755, true);
    mkdir($this->tmpDir . '/templates/_layouts', 0755, true);
    mkdir($this->tmpDir . '/config', 0755, true);
    mkdir($this->tmpDir . '/cache', 0755, true);

    file_put_contents($this->tmpDir . '/config/rakun.yaml', "site:\n  name: Test Site\n  url: http://localhost\n  default_locale: es\n");
    file_put_contents($this->tmpDir . '/content/pages/home.md', "---\ntitle: Home\n---\nWelcome");
    file_put_contents($this->tmpDir . '/content/blog/post.md', "---\ntitle: Post\ndate: 2025-01-01\n---\nContent");
    file_put_contents($this->tmpDir . '/templates/_layouts/base.twig', "<html>{% block content %}{% endblock %}</html>");

    // chdir to tmpDir so command resolves basePath from getcwd()
    $this->originalDir = getcwd();
    chdir($this->tmpDir);
});

afterEach(function () {
    chdir($this->originalDir);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tmpDir);
});

test('generates CLAUDE.md', function () {
    $app = new Application();
    $app->add(new BoostInstallCommand());
    $tester = new CommandTester($app->find('boost:install'));
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists($this->tmpDir . '/CLAUDE.md'))->toBeTrue();

    $content = file_get_contents($this->tmpDir . '/CLAUDE.md');
    expect($content)->toContain('Test Site');
    expect($content)->toContain('pages');
    expect($content)->toContain('blog');
});

test('generates .mcp.json', function () {
    $app = new Application();
    $app->add(new BoostInstallCommand());
    $tester = new CommandTester($app->find('boost:install'));
    $tester->execute([]);

    expect(file_exists($this->tmpDir . '/.mcp.json'))->toBeTrue();

    $json = json_decode(file_get_contents($this->tmpDir . '/.mcp.json'), true);
    expect($json['mcpServers']['rakuncms']['command'])->toBe('php');
    expect($json['mcpServers']['rakuncms']['args'])->toBe(['rakun', 'mcp:serve']);
});

test('output confirms generated files', function () {
    $app = new Application();
    $app->add(new BoostInstallCommand());
    $tester = new CommandTester($app->find('boost:install'));
    $tester->execute([]);

    $output = $tester->getDisplay();
    expect($output)->toContain('CLAUDE.md');
    expect($output)->toContain('.mcp.json');
    expect($output)->toContain('Boost installed');
});

test('CLAUDE.md includes templates section', function () {
    $app = new Application();
    $app->add(new BoostInstallCommand());
    $tester = new CommandTester($app->find('boost:install'));
    $tester->execute([]);

    $content = file_get_contents($this->tmpDir . '/CLAUDE.md');
    expect($content)->toContain('## Templates');
    expect($content)->toContain('_layouts/base.twig');
});

test('CLAUDE.md includes CLI commands', function () {
    $app = new Application();
    $app->add(new BoostInstallCommand());
    $tester = new CommandTester($app->find('boost:install'));
    $tester->execute([]);

    $content = file_get_contents($this->tmpDir . '/CLAUDE.md');
    expect($content)->toContain('mcp:serve');
    expect($content)->toContain('index:rebuild');
});
