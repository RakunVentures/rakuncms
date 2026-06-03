<?php

declare(strict_types=1);

use Rkn\Cms\Content\ContentDraft;
use Rkn\Cms\Content\ContentRef;
use Rkn\Cms\Content\Storage\FileContentStorage;

/**
 * Fase 1 (1a): el seam ContentStorage. FileContentStorage replica el formato .md
 * actual (mismo que escribe ContentApiController), para que MysqlContentStorage
 * pueda enchufarse luego en el mismo seam sin cambiar la ruta de render.
 */

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/rakun-fcs-' . uniqid();
    mkdir($this->tempDir . '/content/blog', 0755, true);
    $this->storage = new FileContentStorage($this->tempDir, 'en');
});

afterEach(function () {
    $cleanup = function (string $dir) use (&$cleanup): void {
        foreach (new DirectoryIterator($dir) as $item) {
            if ($item->isDot()) continue;
            $item->isDir() ? $cleanup($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    };
    if (is_dir($this->tempDir)) {
        $cleanup($this->tempDir);
    }
});

test('write then read round-trips frontmatter and body', function () {
    $ref = $this->storage->write(new ContentDraft(
        'blog',
        'en',
        'hola',
        ['title' => 'Hola', 'tags' => ['php']],
        'Cuerpo markdown.',
    ));

    expect($ref)->toBeInstanceOf(ContentRef::class);
    expect($ref->file)->toBe('content/blog/hola.en.md');
    expect(file_exists($this->tempDir . '/content/blog/hola.en.md'))->toBeTrue();

    $body = $this->storage->read('blog', 'en', 'hola');
    expect($body)->not->toBeNull();
    expect($body->frontmatter['title'])->toBe('Hola');
    expect($body->frontmatter['tags'])->toBe(['php']);
    expect($body->body)->toBe('Cuerpo markdown.');
});

test('read falls back to a no-locale file', function () {
    file_put_contents($this->tempDir . '/content/blog/about.md', "---\ntitle: About\n---\n\nAbout body.");

    $body = $this->storage->read('blog', 'en', 'about');

    expect($body)->not->toBeNull();
    expect($body->frontmatter['title'])->toBe('About');
    expect($body->body)->toBe('About body.');
});

test('read returns null when absent', function () {
    expect($this->storage->read('blog', 'en', 'nope'))->toBeNull();
});

test('delete removes the file and reports success', function () {
    $this->storage->write(new ContentDraft('blog', 'en', 'tmp', ['title' => 'T'], 'x'));

    expect($this->storage->delete('blog', 'en', 'tmp'))->toBeTrue();
    expect($this->storage->read('blog', 'en', 'tmp'))->toBeNull();
    expect($this->storage->delete('blog', 'en', 'tmp'))->toBeFalse();
});

test('listKeys enumerates written entries with locale', function () {
    $this->storage->write(new ContentDraft('blog', 'en', 'a', ['title' => 'A'], 'a'));
    $this->storage->write(new ContentDraft('blog', 'es', 'b', ['title' => 'B'], 'b'));

    $refs  = iterator_to_array($this->storage->listKeys(), false);
    $keys  = array_map(fn (ContentRef $r): string => "{$r->slug}:{$r->locale}", $refs);

    expect($keys)->toContain('a:en');
    expect($keys)->toContain('b:es');
});
