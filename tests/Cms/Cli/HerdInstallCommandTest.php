<?php

declare(strict_types=1);

use Rkn\Cms\Cli\HerdInstallCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_herd_' . uniqid();
    mkdir($this->tmpDir . '/config', 0755, true);
    mkdir($this->tmpDir . '/public', 0755, true);

    file_put_contents($this->tmpDir . '/config/rakun.yaml', "site:\n  name: Test Site\n");
    file_put_contents($this->tmpDir . '/public/index.php', "<?php\n");

    $this->originalDir = getcwd();
    chdir($this->tmpDir);
});

afterEach(function () {
    chdir($this->originalDir);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tmpDir);
});

test('local install generates LocalValetDriver.php', function () {
    $app = new Application();
    $app->add(new HerdInstallCommand());
    $tester = new CommandTester($app->find('herd:install'));
    $tester->execute(['--local' => true]);

    expect($tester->getStatusCode())->toBe(0);
    expect(file_exists($this->tmpDir . '/LocalValetDriver.php'))->toBeTrue();
});

test('local driver contains class LocalValetDriver', function () {
    $app = new Application();
    $app->add(new HerdInstallCommand());
    $tester = new CommandTester($app->find('herd:install'));
    $tester->execute(['--local' => true]);

    $content = file_get_contents($this->tmpDir . '/LocalValetDriver.php');
    expect($content)->toContain('class LocalValetDriver');
});

test('local driver serves() returns true', function () {
    $app = new Application();
    $app->add(new HerdInstallCommand());
    $tester = new CommandTester($app->find('herd:install'));
    $tester->execute(['--local' => true]);

    $content = file_get_contents($this->tmpDir . '/LocalValetDriver.php');
    expect($content)->toContain('function serves(');
    expect($content)->toContain('return true;');
});

test('local driver frontControllerPath points to public/index.php', function () {
    $app = new Application();
    $app->add(new HerdInstallCommand());
    $tester = new CommandTester($app->find('herd:install'));
    $tester->execute(['--local' => true]);

    $content = file_get_contents($this->tmpDir . '/LocalValetDriver.php');
    expect($content)->toContain('frontControllerPath');
    expect($content)->toContain("'/public/index.php'");
});

test('local driver isStaticFile checks public directory', function () {
    $app = new Application();
    $app->add(new HerdInstallCommand());
    $tester = new CommandTester($app->find('herd:install'));
    $tester->execute(['--local' => true]);

    $content = file_get_contents($this->tmpDir . '/LocalValetDriver.php');
    expect($content)->toContain('isStaticFile');
    expect($content)->toContain("'/public'");
});

test('local install output confirms installation', function () {
    $app = new Application();
    $app->add(new HerdInstallCommand());
    $tester = new CommandTester($app->find('herd:install'));
    $tester->execute(['--local' => true]);

    $output = $tester->getDisplay();
    expect($output)->toContain('LocalValetDriver.php installed');
});

test('global driver source contains config/rakun.yaml detection', function () {
    $driverPath = dirname(__DIR__, 3) . '/src/Cms/Herd/RakunCmsValetDriver.php';
    $content = file_get_contents($driverPath);

    expect($content)->toContain('config/rakun.yaml');
});

test('global driver source contains isStaticFile with public path', function () {
    $driverPath = dirname(__DIR__, 3) . '/src/Cms/Herd/RakunCmsValetDriver.php';
    $content = file_get_contents($driverPath);

    expect($content)->toContain('isStaticFile');
    expect($content)->toContain("'/public'");
});

test('global driver source contains frontControllerPath', function () {
    $driverPath = dirname(__DIR__, 3) . '/src/Cms/Herd/RakunCmsValetDriver.php';
    $content = file_get_contents($driverPath);

    expect($content)->toContain('frontControllerPath');
    expect($content)->toContain("'/public/index.php'");
});

test('global install fails when Herd is not installed', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $this->tmpDir . '/nonexistent_home';

    try {
        $app = new Application();
        $app->add(new HerdInstallCommand());
        $tester = new CommandTester($app->find('herd:install'));
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(1);
        expect($tester->getDisplay())->toContain('Herd drivers directory not found');
    } finally {
        if ($originalHome !== null) {
            $_SERVER['HOME'] = $originalHome;
        }
    }
});
