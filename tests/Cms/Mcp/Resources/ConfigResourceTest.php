<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Resources\ConfigResource;
use Rkn\Cms\Mcp\Resources\GuidelinesResource;
use Rkn\Cms\Mcp\Resources\ArchitectureResource;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_mcp_res_' . uniqid();
    mkdir($this->tmpDir . '/config', 0755, true);
    mkdir($this->tmpDir . '/docs', 0755, true);

    file_put_contents($this->tmpDir . '/config/rakun.yaml', "site:\n  url: http://localhost\n  default_locale: es\n");
    file_put_contents($this->tmpDir . '/CLAUDE.md', "# Project Guidelines\n\nFollow KISS/DRY/YAGNI.");
    file_put_contents($this->tmpDir . '/docs/rakuncms-arquitectura-v2.md', "# Architecture\n\nFlat-file CMS.");
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

test('ConfigResource reads rakun.yaml', function () {
    $resource = new ConfigResource($this->tmpDir);

    expect($resource->uri())->toBe('rakun://config');
    expect($resource->mimeType())->toBe('text/yaml');

    $data = $resource->read();
    expect($data['text'])->toContain('default_locale: es');
});

test('ConfigResource handles missing file', function () {
    $resource = new ConfigResource('/nonexistent');
    $data = $resource->read();

    expect($data['text'])->toContain('not found');
});

test('GuidelinesResource reads CLAUDE.md', function () {
    $resource = new GuidelinesResource($this->tmpDir);

    expect($resource->uri())->toBe('rakun://guidelines');

    $data = $resource->read();
    expect($data['text'])->toContain('KISS/DRY/YAGNI');
});

test('GuidelinesResource prefers directives-zero over CLAUDE.md', function () {
    mkdir($this->tmpDir . '/.claude/skills', 0755, true);
    file_put_contents($this->tmpDir . '/.claude/skills/directives-zero.md', '# Directives Zero');

    $resource = new GuidelinesResource($this->tmpDir);
    $data = $resource->read();

    expect($data['text'])->toContain('Directives Zero');
});

test('ArchitectureResource reads docs file', function () {
    $resource = new ArchitectureResource($this->tmpDir);

    expect($resource->uri())->toBe('rakun://architecture');

    $data = $resource->read();
    expect($data['text'])->toContain('Flat-file CMS');
});
