<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Tools\ProjectInfoTool;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_mcp_test_' . uniqid();
    mkdir($this->tmpDir . '/content/pages', 0755, true);
    mkdir($this->tmpDir . '/content/blog', 0755, true);
    mkdir($this->tmpDir . '/templates/_layouts', 0755, true);
    mkdir($this->tmpDir . '/config', 0755, true);
    mkdir($this->tmpDir . '/public', 0755, true);

    file_put_contents($this->tmpDir . '/composer.json', json_encode([
        'name' => 'test/project',
        'require' => ['php' => '^8.2', 'rkn/cms' => '^0.1'],
        'require-dev' => ['pestphp/pest' => '^3.0'],
    ]));

    file_put_contents($this->tmpDir . '/content/pages/home.md', "---\ntitle: Home\n---\nWelcome");
    file_put_contents($this->tmpDir . '/content/pages/about.md', "---\ntitle: About\n---\nAbout us");
    file_put_contents($this->tmpDir . '/content/blog/post.md', "---\ntitle: Post\n---\nContent");

    file_put_contents($this->tmpDir . '/templates/home.twig', '<h1>Home</h1>');
    file_put_contents($this->tmpDir . '/templates/_layouts/base.twig', '<html></html>');
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

test('returns PHP version and project path', function () {
    $tool = new ProjectInfoTool($this->tmpDir);
    $result = $tool->execute([]);

    expect($result['php_version'])->toBe(PHP_VERSION);
    expect($result['project_path'])->toBe($this->tmpDir);
});

test('reads composer.json data', function () {
    $tool = new ProjectInfoTool($this->tmpDir);
    $result = $tool->execute([]);

    expect($result['project_name'])->toBe('test/project');
    expect($result['require'])->toContain('php');
    expect($result['require'])->toContain('rkn/cms');
});

test('counts collections and entries', function () {
    $tool = new ProjectInfoTool($this->tmpDir);
    $result = $tool->execute([]);

    expect($result['collections'])->toHaveKey('pages');
    expect($result['collections']['pages'])->toBe(2);
    expect($result['collections']['blog'])->toBe(1);
});

test('counts templates', function () {
    $tool = new ProjectInfoTool($this->tmpDir);
    $result = $tool->execute([]);

    expect($result['template_count'])->toBe(2);
});

test('detects existing directories', function () {
    $tool = new ProjectInfoTool($this->tmpDir);
    $result = $tool->execute([]);

    expect($result['directories']['content'])->toBeTrue();
    expect($result['directories']['templates'])->toBeTrue();
    expect($result['directories']['config'])->toBeTrue();
    expect($result['directories']['public'])->toBeTrue();
    expect($result['directories']['lang'])->toBeFalse();
});
