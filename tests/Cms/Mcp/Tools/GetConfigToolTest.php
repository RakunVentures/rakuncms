<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Tools\GetConfigTool;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_mcp_config_' . uniqid();
    mkdir($this->tmpDir . '/config', 0755, true);

    file_put_contents($this->tmpDir . '/config/rakun.yaml', <<<'YAML'
site:
  url: "http://localhost:8000"
  default_locale: "es"
  locales:
    - es
    - en
debug: true
cache:
  twig_auto_reload: true
YAML);
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

test('returns full config when no key specified', function () {
    $tool = new GetConfigTool($this->tmpDir);
    $result = $tool->execute([]);

    expect($result['config'])->toHaveKey('site');
    expect($result['config']['site']['default_locale'])->toBe('es');
});

test('returns value by dot-notation key', function () {
    $tool = new GetConfigTool($this->tmpDir);
    $result = $tool->execute(['key' => 'site.default_locale']);

    expect($result['key'])->toBe('site.default_locale');
    expect($result['value'])->toBe('es');
});

test('returns null for missing key', function () {
    $tool = new GetConfigTool($this->tmpDir);
    $result = $tool->execute(['key' => 'nonexistent.key']);

    expect($result['value'])->toBeNull();
});

test('returns array values', function () {
    $tool = new GetConfigTool($this->tmpDir);
    $result = $tool->execute(['key' => 'site.locales']);

    expect($result['value'])->toBe(['es', 'en']);
});

test('returns error when config file missing', function () {
    $tool = new GetConfigTool('/nonexistent/path');
    $result = $tool->execute([]);

    expect($result)->toHaveKey('error');
});
