<?php

declare(strict_types=1);

use Rkn\Cms\Cli\DbDumpCommand;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Guard-path coverage for db:dump. These exercise the early validation returns
 * (wrong driver, missing database name) and run WITHOUT a live MySQL — the
 * command bails before opening any connection. Each test asserts the specific
 * guard message, so removing the guard it targets makes the test fail.
 */

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/rakun-dbdump-guards-' . uniqid();
    mkdir($this->tempDir . '/config', 0755, true);
    file_put_contents($this->tempDir . '/.env', '');

    $this->originalDir = getcwd();
    chdir($this->tempDir);

    // Boot a fresh Application from THIS temp config, not a stale singleton.
    $prop = new ReflectionProperty(\Rkn\Framework\Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

afterEach(function () {
    chdir($this->originalDir);

    $prop = new ReflectionProperty(\Rkn\Framework\Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);

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

test('db:dump fails when content.driver is not mysql', function () {
    file_put_contents($this->tempDir . '/config/rakun.yaml', <<<'YAML'
    content:
      driver: file
    YAML);

    $outputPath = $this->tempDir . '/should-not-exist.sql';
    $app = new ConsoleApplication();
    $app->addCommand(new DbDumpCommand());
    $tester = new CommandTester($app->find('db:dump'));
    $tester->execute(['--output' => $outputPath]);

    expect($tester->getStatusCode())->toBe(1);
    expect($tester->getDisplay())->toContain('content.driver');
    expect(file_exists($outputPath))->toBeFalse();
});

test('db:dump fails when the mysql database name is empty', function () {
    file_put_contents($this->tempDir . '/config/rakun.yaml', <<<'YAML'
    content:
      driver: mysql
      mysql:
        host: "127.0.0.1"
        port: 3306
        username: "root"
        password: ""
    YAML);

    $outputPath = $this->tempDir . '/should-not-exist.sql';
    $app = new ConsoleApplication();
    $app->addCommand(new DbDumpCommand());
    $tester = new CommandTester($app->find('db:dump'));
    $tester->execute(['--output' => $outputPath]);

    expect($tester->getStatusCode())->toBe(1);
    expect($tester->getDisplay())->toContain('No MySQL database name');
    expect(file_exists($outputPath))->toBeFalse();
});
