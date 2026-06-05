<?php

declare(strict_types=1);

use Rkn\Cms\Mcp\McpException;
use Rkn\Cms\Mcp\Tools\CreateEntryTool;
use Rkn\Cms\Mcp\Tools\DeleteEntryTool;
use Rkn\Cms\Mcp\Tools\DeleteMediaTool;
use Rkn\Cms\Mcp\Tools\RunCommandTool;
use Rkn\Cms\Mcp\Tools\UpdateEntryTool;
use Rkn\Cms\Mcp\Tools\UploadMediaTool;

beforeEach(function () {
    $this->tmpDir = $this->makeTempDir('rkn-mcp-admin-');
    mkdir($this->tmpDir . '/config', 0755, true);
    mkdir($this->tmpDir . '/content/blog', 0755, true);
    mkdir($this->tmpDir . '/cache', 0755, true);
    mkdir($this->tmpDir . '/public/assets', 0755, true);
    file_put_contents($this->tmpDir . '/config/rakun.yaml', "site:\n  default_locale: en\n");
});

test('create update and delete entry through MCP tools', function () {
    $created = (new CreateEntryTool($this->tmpDir))->execute([
        'collection' => 'blog',
        'title' => 'MCP Draft',
        'slug' => 'mcp-draft',
        'locale' => 'en',
        'content' => 'Draft body.',
    ]);

    expect($created['ok'])->toBeTrue();
    expect($created['entry']['status'])->toBe('draft');
    expect(is_file($this->tmpDir . '/content/blog/mcp-draft.en.md'))->toBeTrue();

    $updated = (new UpdateEntryTool($this->tmpDir))->execute([
        'collection' => 'blog',
        'slug' => 'mcp-draft',
        'locale' => 'en',
        'title' => 'MCP Updated',
        'content' => 'Updated body.',
        'status' => 'published',
    ]);

    expect($updated['entry']['title'])->toBe('MCP Updated');
    $content = file_get_contents($this->tmpDir . '/content/blog/mcp-draft.en.md');
    expect($content)->toContain('MCP Updated');
    expect($content)->toContain('Updated body.');

    $deleted = (new DeleteEntryTool($this->tmpDir))->execute([
        'collection' => 'blog',
        'slug' => 'mcp-draft',
        'locale' => 'en',
    ]);

    expect($deleted['ok'])->toBeTrue();
    expect(is_file($this->tmpDir . '/content/blog/mcp-draft.en.md'))->toBeFalse();
});

test('upload media validates and copies local file', function () {
    $source = $this->tmpDir . '/source.png';
    file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

    $uploaded = (new UploadMediaTool($this->tmpDir))->execute([
        'source_path' => $source,
        'directory' => 'uploads/blog',
    ]);

    expect($uploaded['ok'])->toBeTrue();
    expect($uploaded['data']['path'])->toStartWith('assets/uploads/blog/source');
    expect(is_file($this->tmpDir . '/public/' . $uploaded['data']['path']))->toBeTrue();

    $deleted = (new DeleteMediaTool($this->tmpDir))->execute(['path' => $uploaded['data']['path']]);

    expect($deleted['ok'])->toBeTrue();
    expect(is_file($this->tmpDir . '/public/' . $uploaded['data']['path']))->toBeFalse();
});

test('run command rejects commands outside the allowlist', function () {
    (new RunCommandTool($this->tmpDir))->execute(['command' => 'deploy']);
})->throws(McpException::class, "Command 'deploy' is not available");

// --- Security regression: input/path safety ---

test('create entry rejects path traversal in the collection name', function () {
    (new CreateEntryTool($this->tmpDir))->execute([
        'collection' => '../../../../tmp/evil',
        'title' => 'x', 'slug' => 'x', 'locale' => 'en', 'content' => 'x',
    ]);
})->throws(McpException::class, 'collection is invalid');

test('create entry rejects path traversal in the locale', function () {
    (new CreateEntryTool($this->tmpDir))->execute([
        'collection' => 'blog', 'title' => 'x', 'slug' => 'x',
        'locale' => '../../../etc/x', 'content' => 'x',
    ]);
})->throws(McpException::class, 'locale is invalid');

test('delete media cannot escape public/assets via traversal', function () {
    // A sensitive file in public/ (not under assets/) must be undeletable.
    file_put_contents($this->tmpDir . '/public/index.php', '<?php // front controller');

    $threw = false;
    try {
        (new DeleteMediaTool($this->tmpDir))->execute(['path' => '../index.php']);
    } catch (McpException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    expect(is_file($this->tmpDir . '/public/index.php'))->toBeTrue(); // preserved
});

test('upload media rejects SVG (stored-XSS vector)', function () {
    $svg = $this->tmpDir . '/evil.svg';
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    (new UploadMediaTool($this->tmpDir))->execute(['source_path' => $svg]);
})->throws(McpException::class, 'MIME type is not allowed');

