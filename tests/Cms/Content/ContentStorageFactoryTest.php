<?php

declare(strict_types=1);

use Rkn\Cms\Content\ContentStorageFactory;
use Rkn\Cms\Content\Storage\FileContentStorage;
use Rkn\Cms\Content\Storage\MysqlContentStorage;
use Rkn\Framework\Application;

/**
 * Fase 1: la factoría resuelve el driver de contenido (file|mysql) desde config,
 * con fallback no-fatal a file si MySQL no está disponible.
 */

afterEach(function () {
    $prop = new ReflectionProperty(Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function bootContentFixture(string $dir, string $rakunYaml): void
{
    mkdir($dir . '/config', 0755, true);
    mkdir($dir . '/content', 0755, true);
    file_put_contents($dir . '/.env', '');
    file_put_contents($dir . '/config/rakun.yaml', $rakunYaml);
    new Application($dir);
}

test('returns FileContentStorage by default', function () {
    $dir = $this->makeTempDir();
    bootContentFixture($dir, "site:\n  default_locale: en\n");

    expect(ContentStorageFactory::make($dir))->toBeInstanceOf(FileContentStorage::class);
});

test('returns MysqlContentStorage when driver is mysql', function () {
    try {
        new PDO('mysql:host=127.0.0.1;port=3306;dbname=rakuncms_test', 'root', '', [PDO::ATTR_TIMEOUT => 3]);
    } catch (\Throwable $e) {
        $this->markTestSkipped('MySQL rakuncms_test not available');
    }

    $dir = $this->makeTempDir();
    bootContentFixture($dir, <<<'YAML'
    site:
      default_locale: en
    content:
      driver: mysql
      mysql:
        host: 127.0.0.1
        port: 3306
        database: rakuncms_test
        username: root
        password: ""
    YAML);

    expect(ContentStorageFactory::make($dir))->toBeInstanceOf(MysqlContentStorage::class);
});

test('falls back to FileContentStorage when the mysql connection fails', function () {
    $dir = $this->makeTempDir();
    bootContentFixture($dir, <<<'YAML'
    site:
      default_locale: en
    content:
      driver: mysql
      mysql:
        host: 127.0.0.1
        port: 3306
        database: __rakun_nonexistent_db__
        username: root
        password: "wrong-on-purpose"
    YAML);

    expect(ContentStorageFactory::make($dir))->toBeInstanceOf(FileContentStorage::class);
});
