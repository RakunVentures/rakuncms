<?php

declare(strict_types=1);

use Rkn\Cms\Cli\DbDumpCommand;
use Rkn\Cms\Content\ContentDraft;
use Rkn\Cms\Content\Storage\FileContentStorage;
use Rkn\Cms\Content\Storage\MysqlContentStorage;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

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

    $this->tempDir = sys_get_temp_dir() . '/rakun-dbdump-test-' . uniqid();
    mkdir($this->tempDir . '/config', 0755, true);
    mkdir($this->tempDir . '/storage/backups', 0755, true);
    file_put_contents($this->tempDir . '/.env', '');

    // Write a config/rakun.yaml pointing to our test database
    file_put_contents($this->tempDir . '/config/rakun.yaml', <<<'YAML'
content:
  driver: mysql
  mysql:
    host: "127.0.0.1"
    port: 3306
    database: "rakuncms_test"
    username: "root"
    password: ""
YAML);

    $this->cache   = new FileContentStorage($this->tempDir, 'en');
    $this->storage = new MysqlContentStorage($this->pdo, $this->cache); // This ensures schema is created

    // Clean tables before starting
    $this->pdo->exec('DELETE FROM content_tags');
    $this->pdo->exec('DELETE FROM content_revisions');
    $this->pdo->exec('DELETE FROM contents');

    // Insert real data via storage (no mocks — directives-zero)
    $this->storage->write(new ContentDraft(
        'blog',
        'en',
        'hello-world',
        ['title' => 'Hello World', 'tags' => ['news', 'tech']],
        'This is the body content of the blog post.'
    ));

    $this->originalDir = getcwd();
    chdir($this->tempDir);

    // Force the command to boot a fresh Application from THIS temp config,
    // not a stale singleton left by an earlier test in the suite.
    $prop = new ReflectionProperty(\Rkn\Framework\Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

afterEach(function () {
    chdir($this->originalDir);

    $prop = new ReflectionProperty(\Rkn\Framework\Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);

    // Clean tables
    if (isset($this->pdo)) {
        $this->pdo->exec('DELETE FROM content_tags');
        $this->pdo->exec('DELETE FROM content_revisions');
        $this->pdo->exec('DELETE FROM contents');
    }

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

test('db:dump exports schema and content correctly', function () {
    $app = new Application();
    $app->addCommand(new DbDumpCommand());

    $outputPath = $this->tempDir . '/dump.sql';
    $tester = new CommandTester($app->find('db:dump'));
    $tester->execute(['--output' => $outputPath]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists($outputPath))->toBeTrue();

    $sqlContent = file_get_contents($outputPath);
    expect($sqlContent)->toContain('DROP TABLE IF EXISTS `contents`');
    expect($sqlContent)->toContain('CREATE TABLE `contents`');
    expect($sqlContent)->toContain('INSERT INTO `contents`');
    expect($sqlContent)->toContain('hello-world');
    expect($sqlContent)->toContain('Hello World');

    expect($sqlContent)->toContain('DROP TABLE IF EXISTS `content_revisions`');
    expect($sqlContent)->toContain('CREATE TABLE `content_revisions`');
    expect($sqlContent)->toContain('INSERT INTO `content_revisions`');

    expect($sqlContent)->toContain('DROP TABLE IF EXISTS `content_tags`');
    expect($sqlContent)->toContain('CREATE TABLE `content_tags`');
    expect($sqlContent)->toContain('INSERT INTO `content_tags`');
    expect($sqlContent)->toContain('news');
    expect($sqlContent)->toContain('tech');
});

test('db:dump can exclude revisions', function () {
    $app = new Application();
    $app->addCommand(new DbDumpCommand());

    $outputPath = $this->tempDir . '/dump_no_revisions.sql';
    $tester = new CommandTester($app->find('db:dump'));
    $tester->execute([
        '--output' => $outputPath,
        '--exclude-revisions' => true
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists($outputPath))->toBeTrue();

    $sqlContent = file_get_contents($outputPath);
    expect($sqlContent)->toContain('DROP TABLE IF EXISTS `contents`');
    expect($sqlContent)->not->toContain('DROP TABLE IF EXISTS `content_revisions`');
});
