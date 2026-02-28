<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\Tools\ListComponentsTool;

test('lists CMS built-in components', function () {
    $tool = new ListComponentsTool('/nonexistent');
    $result = $tool->execute([]);

    // Should find built-in CMS components (ContactForm, Search)
    $names = array_column($result['components'], 'name');
    expect($names)->toContain('ContactForm');
    expect($names)->toContain('Search');
});

test('includes component properties', function () {
    $tool = new ListComponentsTool('/nonexistent');
    $result = $tool->execute([]);

    $search = array_filter($result['components'], fn ($c) => $c['name'] === 'Search');
    $search = array_values($search);
    expect($search)->not->toBeEmpty();
    expect($search[0]['properties'])->toContain('query');
    expect($search[0]['properties'])->toContain('results');
});

test('includes component methods', function () {
    $tool = new ListComponentsTool('/nonexistent');
    $result = $tool->execute([]);

    $search = array_filter($result['components'], fn ($c) => $c['name'] === 'Search');
    $search = array_values($search);
    expect($search[0]['methods'])->toContain('updatedQuery');
    expect($search[0]['methods'])->toContain('render');
});

test('scans project-level components directory', function () {
    $tmpDir = sys_get_temp_dir() . '/rkn_mcp_comp_' . uniqid();
    mkdir($tmpDir . '/src/Components', 0755, true);

    file_put_contents($tmpDir . '/src/Components/MyWidget.php', <<<'PHP'
<?php
class MyWidget {
    public string $title = '';
    public int $count = 0;
    public function refresh(): void {}
    public function render(): string { return ''; }
}
PHP);

    $tool = new ListComponentsTool($tmpDir);
    $result = $tool->execute([]);

    $names = array_column($result['components'], 'name');
    expect($names)->toContain('MyWidget');

    $widget = array_filter($result['components'], fn ($c) => $c['name'] === 'MyWidget');
    $widget = array_values($widget);
    expect($widget[0]['properties'])->toContain('title');
    expect($widget[0]['properties'])->toContain('count');
    expect($widget[0]['methods'])->toContain('refresh');
    expect($widget[0]['methods'])->toContain('render');

    // Cleanup
    unlink($tmpDir . '/src/Components/MyWidget.php');
    rmdir($tmpDir . '/src/Components');
    rmdir($tmpDir . '/src');
    rmdir($tmpDir);
});
