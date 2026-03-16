<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Tools\BoostApplyTool;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_boost_mcp_' . uniqid();
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

test('has correct tool metadata', function () {
    $tool = new BoostApplyTool($this->tmpDir);

    expect($tool->name())->toBe('boost-apply');
    expect($tool->description())->toContain('archetype');

    $schema = $tool->inputSchema();
    expect($schema['type'])->toBe('object');
    expect($schema['required'])->toContain('archetype');
    expect($schema['required'])->toContain('name');
    expect($schema['properties'])->toHaveKey('archetype');
    expect($schema['properties'])->toHaveKey('name');
});

test('applies blog archetype successfully', function () {
    $tool = new BoostApplyTool($this->tmpDir);
    $result = $tool->execute([
        'archetype' => 'blog',
        'name' => 'Test Blog',
        'locale' => 'es',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['archetype'])->toBe('blog');
    expect($result['files_created'])->toBeGreaterThan(0);

    // Verify files exist
    expect(file_exists("{$this->tmpDir}/content/blog/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/blog/first-post.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/templates/_layouts/base.twig"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/public/assets/css/style.css"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/config/rakun.yaml"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/_globals/site.yaml"))->toBeTrue();
});

test('applies docs archetype successfully', function () {
    $tool = new BoostApplyTool($this->tmpDir);
    $result = $tool->execute([
        'archetype' => 'docs',
        'name' => 'Test Docs',
    ]);

    expect($result['success'])->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/docs/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/docs/01.getting-started.md"))->toBeTrue();
});

test('returns error for unknown archetype', function () {
    $tool = new BoostApplyTool($this->tmpDir);
    $result = $tool->execute([
        'archetype' => 'nonexistent',
        'name' => 'Test',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('nonexistent');
    expect($result['available'])->toBeArray();
});

test('profile data is included in result', function () {
    $tool = new BoostApplyTool($this->tmpDir);
    $result = $tool->execute([
        'archetype' => 'blog',
        'name' => 'My Site',
        'description' => 'A test site',
        'locale' => 'en',
        'author' => 'Tester',
    ]);

    expect($result['profile']['name'])->toBe('My Site');
    expect($result['profile']['description'])->toBe('A test site');
    expect($result['profile']['locale'])->toBe('en');
    expect($result['profile']['author'])->toBe('Tester');
});

test('site name appears in globals', function () {
    $tool = new BoostApplyTool($this->tmpDir);
    $tool->execute([
        'archetype' => 'blog',
        'name' => 'Awesome Site',
    ]);

    $globals = file_get_contents("{$this->tmpDir}/content/_globals/site.yaml");
    expect($globals)->toContain('Awesome Site');
});
