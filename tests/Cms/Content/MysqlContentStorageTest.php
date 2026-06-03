<?php

declare(strict_types=1);

use Rkn\Cms\Content\ContentDraft;
use Rkn\Cms\Content\Storage\FileContentStorage;
use Rkn\Cms\Content\Storage\MysqlContentStorage;

/**
 * Fase 1: MysqlContentStorage = SSoT en MySQL + revisiones + regeneración del
 * caché .md. Corre contra la BD real `rakuncms_test` (skip si MySQL no está).
 */

beforeEach(function () {
    try {
        $this->pdo = new PDO(
            'mysql:host=127.0.0.1;port=3306;dbname=rakuncms_test',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
        );
    } catch (\Throwable $e) {
        $this->markTestSkipped('MySQL rakuncms_test not available: ' . $e->getMessage());
    }

    $this->tempDir = sys_get_temp_dir() . '/rakun-mcs-' . uniqid();
    mkdir($this->tempDir . '/content/blog', 0755, true);

    $this->cache   = new FileContentStorage($this->tempDir, 'en');
    $this->storage = new MysqlContentStorage($this->pdo, $this->cache); // ensureSchema()

    // Isolate.
    $this->pdo->exec('DELETE FROM content_tags');
    $this->pdo->exec('DELETE FROM content_revisions');
    $this->pdo->exec('DELETE FROM contents');
});

afterEach(function () {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        $cleanup = function (string $dir) use (&$cleanup): void {
            foreach (new DirectoryIterator($dir) as $item) {
                if ($item->isDot()) continue;
                $item->isDir() ? $cleanup($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($dir);
        };
        $cleanup($this->tempDir);
    }
});

test('write persists to MySQL (SSoT) and regenerates the .md cache', function () {
    $this->storage->write(new ContentDraft(
        'blog',
        'en',
        'hola',
        ['title' => 'Hola', 'tags' => ['php', 'cms'], 'date' => '2026-01-15'],
        'Cuerpo del post.',
    ));

    $row = $this->pdo->query("SELECT * FROM contents WHERE slug = 'hola'")->fetch(PDO::FETCH_ASSOC);
    expect($row)->not->toBeFalse();
    expect($row['title'])->toBe('Hola');
    expect($row['status'])->toBe('published');
    expect($row['full_slug'])->toBe('hola');

    // Cache .md regenerated (so the render path/index see it).
    expect(file_exists($this->tempDir . '/content/blog/hola.en.md'))->toBeTrue();

    $tags = $this->pdo->query('SELECT tag FROM content_tags')->fetchAll(PDO::FETCH_COLUMN);
    expect($tags)->toContain('php');
    expect($tags)->toContain('cms');
});

test('read returns the source of truth from the database', function () {
    $this->storage->write(new ContentDraft('blog', 'en', 'hola', ['title' => 'Hola'], 'Cuerpo del post.'));

    $body = $this->storage->read('blog', 'en', 'hola');

    expect($body)->not->toBeNull();
    expect($body->frontmatter['title'])->toBe('Hola');
    expect($body->body)->toBe('Cuerpo del post.');
});

test('writing twice upserts the row and records two revisions', function () {
    $this->storage->write(new ContentDraft('blog', 'en', 'hola', ['title' => 'V1'], 'body 1'));
    $this->storage->write(new ContentDraft('blog', 'en', 'hola', ['title' => 'V2'], 'body 2'));

    $count = (int) $this->pdo->query("SELECT COUNT(*) FROM contents WHERE slug = 'hola'")->fetchColumn();
    expect($count)->toBe(1); // upsert, not duplicate

    $revisions = $this->storage->revisions('blog', 'en', 'hola');
    expect($revisions)->toHaveCount(2);
    expect($revisions[0]['title'])->toBe('V2'); // newest first

    $body = $this->storage->read('blog', 'en', 'hola');
    expect($body->body)->toBe('body 2');
});

test('delete removes the DB row and the cache file', function () {
    $this->storage->write(new ContentDraft('blog', 'en', 'tmp', ['title' => 'T'], 'x'));
    expect(file_exists($this->tempDir . '/content/blog/tmp.en.md'))->toBeTrue();

    expect($this->storage->delete('blog', 'en', 'tmp'))->toBeTrue();
    expect($this->storage->read('blog', 'en', 'tmp'))->toBeNull();
    expect(file_exists($this->tempDir . '/content/blog/tmp.en.md'))->toBeFalse();
});

test('listKeys enumerates the stored entries from MySQL', function () {
    $this->storage->write(new ContentDraft('blog', 'en', 'a', ['title' => 'A'], 'a'));
    $this->storage->write(new ContentDraft('blog', 'es', 'b', ['title' => 'B'], 'b'));

    $keys = array_map(
        fn ($r): string => "{$r->slug}:{$r->locale}",
        iterator_to_array($this->storage->listKeys(), false),
    );

    expect($keys)->toContain('a:en');
    expect($keys)->toContain('b:es');
});
