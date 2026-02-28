<?php

declare(strict_types=1);

use Rkn\Cms\Cli\BackupCreateCommand;
use Rkn\Cms\Cli\BackupRestoreCommand;
use Rkn\Cms\Cli\BackupListCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/rakun-backup-test-' . uniqid();
    mkdir($this->tempDir . '/content/blog', 0755, true);
    mkdir($this->tempDir . '/content/_globals', 0755, true);
    mkdir($this->tempDir . '/config', 0755, true);
    mkdir($this->tempDir . '/templates/_layouts', 0755, true);
    mkdir($this->tempDir . '/cache', 0755, true);
    mkdir($this->tempDir . '/storage/backups', 0755, true);

    file_put_contents($this->tempDir . '/config/rakun.yaml', <<<'YAML'
site:
  url: "http://localhost:8080"
  default_locale: en
api:
  keys:
    - key: "secret-api-key-123"
      name: "test"
preview:
  token: "my-secret-token"
YAML);

    file_put_contents($this->tempDir . '/content/blog/hello.en.md', <<<'MD'
---
title: "Hello World"
---
Content here.
MD);

    file_put_contents($this->tempDir . '/content/_globals/site.yaml', "title: Test Site\n");
    file_put_contents($this->tempDir . '/templates/_layouts/base.twig', '<html>{{ content }}</html>');

    // Change to temp dir so commands use it as base path
    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalDir);

    $cleanup = function (string $dir) use (&$cleanup): void {
        $items = new DirectoryIterator($dir);
        foreach ($items as $item) {
            if ($item->isDot()) continue;
            if ($item->isDir()) {
                $cleanup($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    };
    if (is_dir($this->tempDir)) {
        $cleanup($this->tempDir);
    }
});

test('backup:create generates valid ZIP file', function () {
    $app = new Application();
    $app->add(new BackupCreateCommand());

    $outputPath = $this->tempDir . '/test-backup.zip';
    $tester = new CommandTester($app->find('backup:create'));
    $tester->execute(['--output' => $outputPath]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists($outputPath))->toBeTrue();

    // Verify ZIP contents
    $zip = new ZipArchive();
    $zip->open($outputPath);

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    expect($names)->toContain('manifest.json');
    expect($names)->toContain('content/blog/hello.en.md');
    expect($names)->toContain('templates/_layouts/base.twig');

    $zip->close();
});

test('backup:create includes content, config, and templates', function () {
    $outputPath = $this->tempDir . '/test-backup.zip';

    $app = new Application();
    $app->add(new BackupCreateCommand());

    $tester = new CommandTester($app->find('backup:create'));
    $tester->execute(['--output' => $outputPath]);

    $zip = new ZipArchive();
    $zip->open($outputPath);

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    // Content included
    expect(array_filter($names, fn ($n) => str_starts_with($n, 'content/')))->not->toBeEmpty();
    // Config included
    expect(array_filter($names, fn ($n) => str_starts_with($n, 'config/')))->not->toBeEmpty();
    // Templates included
    expect(array_filter($names, fn ($n) => str_starts_with($n, 'templates/')))->not->toBeEmpty();

    // Cache excluded
    expect(array_filter($names, fn ($n) => str_starts_with($n, 'cache/')))->toBeEmpty();
    // Vendor excluded
    expect(array_filter($names, fn ($n) => str_starts_with($n, 'vendor/')))->toBeEmpty();

    $zip->close();
});

test('backup:create sanitizes secrets in config', function () {
    $outputPath = $this->tempDir . '/test-backup.zip';

    $app = new Application();
    $app->add(new BackupCreateCommand());

    $tester = new CommandTester($app->find('backup:create'));
    $tester->execute(['--output' => $outputPath]);

    $zip = new ZipArchive();
    $zip->open($outputPath);

    $configContent = $zip->getFromName('config/rakun.yaml');
    expect($configContent)->not->toContain('secret-api-key-123');
    expect($configContent)->not->toContain('my-secret-token');
    expect($configContent)->toContain('REDACTED');

    $zip->close();
});

test('backup:create includes valid manifest.json', function () {
    $outputPath = $this->tempDir . '/test-backup.zip';

    $app = new Application();
    $app->add(new BackupCreateCommand());

    $tester = new CommandTester($app->find('backup:create'));
    $tester->execute(['--output' => $outputPath]);

    $zip = new ZipArchive();
    $zip->open($outputPath);

    $manifestJson = $zip->getFromName('manifest.json');
    $manifest = json_decode($manifestJson, true);

    expect($manifest)->toBeArray();
    expect($manifest['cms'])->toBe('RakunCMS');
    expect($manifest['version'])->toBe('1.0');
    expect($manifest['file_count'])->toBeGreaterThan(0);
    expect($manifest)->toHaveKey('created_at');

    $zip->close();
});

test('backup:restore extracts files with --force', function () {
    // First create a backup
    $outputPath = $this->tempDir . '/test-backup.zip';
    $app = new Application();
    $app->add(new BackupCreateCommand());
    $app->add(new BackupRestoreCommand());

    $tester = new CommandTester($app->find('backup:create'));
    $tester->execute(['--output' => $outputPath]);

    // Delete the original content
    unlink($this->tempDir . '/content/blog/hello.en.md');
    expect(file_exists($this->tempDir . '/content/blog/hello.en.md'))->toBeFalse();

    // Restore
    $tester = new CommandTester($app->find('backup:restore'));
    $tester->execute(['file' => $outputPath, '--force' => true]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists($this->tempDir . '/content/blog/hello.en.md'))->toBeTrue();
});

test('backup:restore without --force shows warning', function () {
    $outputPath = $this->tempDir . '/test-backup.zip';
    $app = new Application();
    $app->add(new BackupCreateCommand());
    $app->add(new BackupRestoreCommand());

    $createTester = new CommandTester($app->find('backup:create'));
    $createTester->execute(['--output' => $outputPath]);

    $restoreTester = new CommandTester($app->find('backup:restore'));
    $restoreTester->execute(['file' => $outputPath]);

    expect($restoreTester->getDisplay())->toContain('overwrite');
});

test('backup:list shows backup files', function () {
    // Create a couple of backups
    $app = new Application();
    $app->add(new BackupCreateCommand());
    $app->add(new BackupListCommand());

    $tester = new CommandTester($app->find('backup:create'));
    $tester->execute([]);

    $listTester = new CommandTester($app->find('backup:list'));
    $listTester->execute([]);

    expect($listTester->getDisplay())->toContain('backup-');
    expect($listTester->getDisplay())->toContain('.zip');
});

test('backup:list --cleanup removes old backups', function () {
    // Create 3 backup files
    $backupDir = $this->tempDir . '/storage/backups';
    file_put_contents($backupDir . '/backup-2024-01-01.zip', 'fake');
    file_put_contents($backupDir . '/backup-2024-01-02.zip', 'fake');
    file_put_contents($backupDir . '/backup-2024-01-03.zip', 'fake');

    $app = new Application();
    $app->add(new BackupListCommand());

    $tester = new CommandTester($app->find('backup:list'));
    $tester->execute(['--cleanup' => '1']);

    // Only 1 should remain
    $remaining = glob($backupDir . '/*.zip') ?: [];
    expect($remaining)->toHaveCount(1);
});
