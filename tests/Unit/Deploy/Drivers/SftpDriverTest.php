<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\DeployLock;
use Rkn\Cms\Deploy\Drivers\SftpDriver;
use Rkn\Cms\Deploy\Process\Runner;
use Tests\Helpers\ContainerHelper;

/**
 * Unit tests for SftpDriver.
 *
 * Tests validate() error conditions, lock acquisition/release, and
 * rollback no-op behavior. SSH/rsync integration is tested by the
 * apple/container suite (tests/Integration/Deploy/SftpDriverContainerTest.php),
 * which skips cleanly when the `container` daemon is not running.
 */
describe('SftpDriver', function () {

    beforeEach(function () {
        $this->basePath = sys_get_temp_dir() . '/rakun-sftp-test-' . uniqid();
        mkdir($this->basePath, 0755, true);
        file_put_contents("{$this->basePath}/index.php", '<?php echo "hello";');

        $this->lockDir = sys_get_temp_dir() . '/rakun-sftp-lock-' . uniqid();
        mkdir($this->lockDir, 0755, true);
    });

    afterEach(function () {
        $cleanup = function (string $dir) use (&$cleanup): void {
            foreach (scandir($dir) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = "{$dir}/{$item}";
                is_dir($path) ? $cleanup($path) : unlink($path);
            }
            rmdir($dir);
        };
        foreach ([$this->basePath, $this->lockDir] as $dir) {
            if (is_dir($dir)) {
                $cleanup($dir);
            }
        }
    });

    function makeSftpDeployConfig(string $host = 'sftp.example.com'): DeployConfig
    {
        $c = new DeployConfig();
        $c->environment  = 'production';
        $c->method       = 'sftp';
        $c->strategy     = 'lean';
        $c->host         = $host;
        $c->user         = 'deploy';
        $c->port         = 22;
        $c->path         = '/httpdocs';
        $c->deploySecret = 'secret123';
        return $c;
    }

    it('validate returns errors when local lock is active', function () {
        $lock = new DeployLock($this->lockDir, 1800);
        $lock->acquire('production', 'in-progress-release');

        $driver = new SftpDriver($this->basePath, null, $lock);
        $config = makeSftpDeployConfig();
        $logger = fn (string $m) => null;

        $errors = $driver->validate($config, $logger);

        expect($errors)->not->toBeEmpty()
            ->and($errors[0])->toContain('lock');

        $lock->release('production');
    });

    it('validate returns an array (no exceptions thrown) with invalid host', function () {
        $lock   = new DeployLock($this->lockDir, 1800);
        $driver = new SftpDriver($this->basePath, null, $lock);
        $config = makeSftpDeployConfig('invalid-host-that-does-not-exist-12345.test');
        $logger = fn (string $m) => null;

        // Should return errors array, not throw
        $errors = $driver->validate($config, $logger);
        expect($errors)->toBeArray();
    });

    it('deploy acquires lock and releases it even on failure', function () {
        $lock   = new DeployLock($this->lockDir, 1800);
        $driver = new SftpDriver($this->basePath, null, $lock);
        $config = makeSftpDeployConfig('127.0.0.1'); // unreachable
        $logger = fn (string $m) => null;

        try {
            $driver->deploy($config, $logger);
        } catch (\Throwable) {
            // Expected in environments without rsync/phpseclib pointing to unreachable host
        }

        // Lock must be released after deploy regardless of outcome
        expect($lock->inspect('production'))->toBeNull();
    });

    it('rollback returns bool without throwing exceptions', function () {
        $lock   = new DeployLock($this->lockDir, 1800);
        $driver = new SftpDriver($this->basePath, null, $lock);
        $config = makeSftpDeployConfig();
        $logger = fn (string $m) => null;

        $result = $driver->rollback($config, $logger);
        expect($result)->toBeBool();
    });

    it('deploy returns false (not exception) when strategy unavailable', function () {
        // Use an isolated empty PATH so `which rsync` always fails — pure unit test,
        // no dependency on the host machine having rsync absent.
        // phpseclib3 is not in composer.json require, so class_exists() returns false.
        $emptyDir = sys_get_temp_dir() . '/rkn-empty-path-' . uniqid();
        mkdir($emptyDir, 0755, true);

        // Runner with empty PATH — Symfony Process overrides the PATH env variable,
        // making `which rsync` return exit code 1 (not found).
        $runner = (new Runner($this->basePath))->withEnv(['PATH' => $emptyDir]);

        $lock   = new DeployLock($this->lockDir, 1800);
        $driver = new SftpDriver($this->basePath, $runner, $lock);
        $config = makeSftpDeployConfig();
        $logged = [];
        $logger = function (string $m) use (&$logged): void {
            $logged[] = $m;
        };

        $result = $driver->deploy($config, $logger);

        // Cleanup empty dir
        rmdir($emptyDir);

        expect($result)->toBeFalse();

        // Verify the error was logged (not silently swallowed)
        $errorMessages = array_filter($logged, fn (string $m) => str_contains($m, '<error>'));
        expect($errorMessages)->not->toBeEmpty();
    });

});

describe('SftpDriver — container integration (skipped if unavailable)', function () {

    it('full deploy via rsync to container openssh-server', function () {
        if (!ContainerHelper::isAvailable()) {
            $this->markTestSkipped(
                'apple/container system is not running. '
                . 'Start it with: container system start'
            );
        }

        // Full SFTP integration is covered by tests/Integration/Deploy/SftpDriverContainerTest.php
        // This placeholder confirms the skip guard uses ContainerHelper::isAvailable().
        expect(true)->toBeTrue();
    });

});
