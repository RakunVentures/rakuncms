<?php

declare(strict_types=1);

use Tests\Cms\Boost\BoostTestHelper;

test('boost entries contain valid frontmatter YAML', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    BoostTestHelper::run($tmpDir, [
        '--archetype' => 'blog',
        '--name'      => 'Test',
    ]);

    $content = file_get_contents("{$tmpDir}/content/blog/first-post.md");
    expect($content)->toStartWith("---\n");
    expect(substr_count($content, "---\n"))->toBeGreaterThanOrEqual(2);
});

test('boost CSS includes archetype-specific styles', function () {
    $tmpDir = $this->makeTempDir('rkn-boost-');
    BoostTestHelper::run($tmpDir, [
        '--archetype' => 'blog',
        '--name'      => 'Test',
    ]);

    $css = file_get_contents("{$tmpDir}/public/assets/css/style.css");
    expect($css)->toContain('.post-card');
    expect($css)->toContain('.blog-layout');
});
