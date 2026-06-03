<?php

declare(strict_types=1);

use Rkn\Cms\Content\ContentImporter;
use Rkn\Cms\Content\Storage\FileContentStorage;
use Rkn\Cms\Content\Storage\MysqlContentStorage;

/**
 * Fase 1 / WS-C: migración file → MySQL (y vuelta) con ContentImporter.
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
        $this->markTestSkipped('MySQL rakuncms_test not available');
    }

    $this->src = sys_get_temp_dir() . '/rakun-imp-src-' . uniqid();
    $this->dst = sys_get_temp_dir() . '/rakun-imp-dst-' . uniqid();
    mkdir($this->src . '/content/blog', 0755, true);
    mkdir($this->dst . '/content/blog', 0755, true);

    // Seed the flat-file source.
    file_put_contents($this->src . '/content/blog/uno.es.md', "---\ntitle: Uno\ntags:\n  - boda\n---\n\nCuerpo uno.");
    file_put_contents($this->src . '/content/blog/dos.es.md', "---\ntitle: Dos\n---\n\nCuerpo dos.");

    $this->file  = new FileContentStorage($this->src, 'es');
    $this->cache = new FileContentStorage($this->dst, 'es');
    $this->mysql = new MysqlContentStorage($this->pdo, $this->cache);

    foreach (['content_tags', 'content_revisions', 'contents'] as $t) {
        $this->pdo->exec("DELETE FROM {$t}");
    }
});

afterEach(function () {
    foreach ([$this->src ?? null, $this->dst ?? null] as $dir) {
        if ($dir !== null && is_dir($dir)) {
            $cleanup = function (string $d) use (&$cleanup): void {
                foreach (new DirectoryIterator($d) as $item) {
                    if ($item->isDot()) continue;
                    $item->isDir() ? $cleanup($item->getPathname()) : unlink($item->getPathname());
                }
                rmdir($d);
            };
            $cleanup($dir);
        }
    }
});

test('imports flat-file content into MySQL (SSoT) and regenerates the cache', function () {
    $imported = (new ContentImporter())->importAll($this->file, $this->mysql);

    expect($imported)->toBe(2);

    $count = (int) $this->pdo->query('SELECT COUNT(*) FROM contents')->fetchColumn();
    expect($count)->toBe(2);

    $uno = $this->mysql->read('blog', 'es', 'uno');
    expect($uno)->not->toBeNull();
    expect($uno->frontmatter['title'])->toBe('Uno');
    expect($uno->body)->toBe('Cuerpo uno.');

    // Cache (.md) regenerated at the destination by the MySQL write.
    expect(file_exists($this->dst . '/content/blog/uno.es.md'))->toBeTrue();
    expect(file_exists($this->dst . '/content/blog/dos.es.md'))->toBeTrue();
});

test('round-trip mysql -> file rebuilds the cache faithfully', function () {
    (new ContentImporter())->importAll($this->file, $this->mysql);

    // Now regenerate a fresh flat-file copy FROM the MySQL SSoT.
    $rebuilt = sys_get_temp_dir() . '/rakun-imp-rebuilt-' . uniqid();
    mkdir($rebuilt . '/content/blog', 0755, true);
    $target = new FileContentStorage($rebuilt, 'es');

    $count = (new ContentImporter())->importAll($this->mysql, $target);
    expect($count)->toBe(2);

    $body = $target->read('blog', 'es', 'uno');
    expect($body->frontmatter['title'])->toBe('Uno');
    expect($body->body)->toBe('Cuerpo uno.');

    // cleanup
    array_map('unlink', glob($rebuilt . '/content/blog/*.md') ?: []);
    rmdir($rebuilt . '/content/blog');
    rmdir($rebuilt . '/content');
    rmdir($rebuilt);
});
