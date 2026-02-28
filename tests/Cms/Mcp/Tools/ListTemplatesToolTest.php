<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Tools\ListTemplatesTool;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_mcp_tpl_' . uniqid();
    mkdir($this->tmpDir . '/templates/_layouts', 0755, true);
    mkdir($this->tmpDir . '/templates/_partials', 0755, true);
    mkdir($this->tmpDir . '/templates/pages', 0755, true);

    file_put_contents($this->tmpDir . '/templates/_layouts/base.twig', <<<'TWIG'
<!DOCTYPE html>
<html>
<head>{% block head %}{% endblock %}</head>
<body>
{% include "_partials/nav.twig" %}
{% block content %}{% endblock %}
{% block footer %}{% endblock %}
</body>
</html>
TWIG);

    file_put_contents($this->tmpDir . '/templates/_partials/nav.twig', '<nav>Navigation</nav>');

    file_put_contents($this->tmpDir . '/templates/pages/home.twig', <<<'TWIG'
{% extends "_layouts/base.twig" %}
{% block content %}
<h1>Home</h1>
{% include "_partials/nav.twig" %}
{% endblock %}
TWIG);
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

test('lists all templates recursively', function () {
    $tool = new ListTemplatesTool($this->tmpDir);
    $result = $tool->execute([]);

    expect($result['templates'])->toHaveCount(3);
    $paths = array_column($result['templates'], 'path');
    expect($paths)->toContain('_layouts/base.twig');
    expect($paths)->toContain('_partials/nav.twig');
    expect($paths)->toContain('pages/home.twig');
});

test('detects extends relationships', function () {
    $tool = new ListTemplatesTool($this->tmpDir);
    $result = $tool->execute([]);

    $home = array_filter($result['templates'], fn ($t) => $t['path'] === 'pages/home.twig');
    $home = array_values($home);
    expect($home[0]['extends'])->toBe('_layouts/base.twig');
});

test('detects include relationships', function () {
    $tool = new ListTemplatesTool($this->tmpDir);
    $result = $tool->execute([]);

    $base = array_filter($result['templates'], fn ($t) => $t['path'] === '_layouts/base.twig');
    $base = array_values($base);
    expect($base[0]['includes'])->toContain('_partials/nav.twig');
});

test('detects blocks', function () {
    $tool = new ListTemplatesTool($this->tmpDir);
    $result = $tool->execute([]);

    $base = array_filter($result['templates'], fn ($t) => $t['path'] === '_layouts/base.twig');
    $base = array_values($base);
    expect($base[0]['blocks'])->toContain('head');
    expect($base[0]['blocks'])->toContain('content');
    expect($base[0]['blocks'])->toContain('footer');
});

test('handles missing templates directory', function () {
    $tool = new ListTemplatesTool('/nonexistent/path');
    $result = $tool->execute([]);

    expect($result['templates'])->toBe([]);
});
