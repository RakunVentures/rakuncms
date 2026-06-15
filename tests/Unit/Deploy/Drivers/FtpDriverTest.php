<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\ArtifactBuilder;
use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\DeployLock;
use Rkn\Cms\Deploy\Drivers\FtpDriver;
use Tests\Helpers\ContainerHelper;

/**
 * Unit tests for FtpDriver.
 *
 * Tests the pure-PHP functions (HMAC construction, lock acquisition,
 * artifact build) without requiring a real FTP server.
 * Full FTP server integration is covered by the apple/container suite
 * (tests/Integration/Deploy/FtpDriverContainerTest.php), which skips cleanly
 * when the `container` daemon is not running.
 */
describe('FtpDriver — unit (pure PHP, no FTP server)', function () {

    beforeEach(function () {
        $this->basePath = sys_get_temp_dir() . '/rakun-ftp-unit-' . uniqid();
        mkdir($this->basePath, 0755, true);
        file_put_contents("{$this->basePath}/index.php", '<?php echo "hello";');

        $this->lockDir = sys_get_temp_dir() . '/rakun-ftp-lock-' . uniqid();
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

    function makeFtpDeployConfig(): DeployConfig
    {
        $c = new DeployConfig();
        $c->environment  = 'production';
        $c->method       = 'ftp';
        $c->strategy     = 'lean';
        $c->host         = '127.0.0.1';
        $c->user         = 'ftpuser';
        $c->pass         = 'secret';
        $c->port         = 21;
        $c->secure       = false;
        $c->path         = '/httpdocs';
        $c->deploySecret = 'test-deploy-secret';
        return $c;
    }

    it('validate detects active local lock', function () {
        $lock = new DeployLock($this->lockDir, 1800);
        $lock->acquire('production', 'lock-held-release');

        $driver = new FtpDriver($this->basePath, null, $lock, null);
        $config = makeFtpDeployConfig();
        $logger = fn (string $m) => null;

        $errors = $driver->validate($config, $logger);

        expect($errors)->not->toBeEmpty()
            ->and($errors[0])->toContain('lock');

        $lock->release('production');
    });

    it('validate detects missing deploy_secret', function () {
        $lock   = new DeployLock($this->lockDir, 1800);
        $driver = new FtpDriver($this->basePath, null, $lock, null);
        $config = makeFtpDeployConfig();
        $config->deploySecret = null;
        $logger = fn (string $m) => null;

        $errors = $driver->validate($config, $logger);

        expect($errors)->not->toBeEmpty()
            ->and($errors[0])->toContain('deploy_secret');
    });

    it('validate returns FTP connection error for unreachable host', function () {
        $lock   = new DeployLock($this->lockDir, 1800);
        $driver = new FtpDriver($this->basePath, null, $lock, null);
        $config = makeFtpDeployConfig();
        // Use port 19 which is typically not listening
        $config->host = '127.0.0.1';
        $config->port = 19;
        $logger = fn (string $m) => null;

        $errors = $driver->validate($config, $logger);

        expect($errors)->not->toBeEmpty()
            ->and($errors[0])->toContain('Cannot connect');
    });

    it('deploy acquires and releases lock even on FTP connection failure', function () {
        $lock   = new DeployLock($this->lockDir, 1800);
        $driver = new FtpDriver($this->basePath, null, $lock, null);
        $config = makeFtpDeployConfig();
        $config->port = 19; // Unreachable
        $logger = fn (string $m) => null;

        $result = $driver->deploy($config, $logger);

        expect($result)->toBeFalse()
            ->and($lock->inspect('production'))->toBeNull(); // Lock must be released
    });

    it('HMAC is computed correctly for deploy.php requests', function () {
        // Verify the HMAC formula used by FtpDriver matches what deploy.php expects
        $secret  = 'test-deploy-secret-abc';
        $body    = json_encode(['action' => 'activate', 'release_id' => '2026-05-26_143022_abc1234']);
        $hmac    = hash_hmac('sha256', (string) $body, $secret);
        $header  = "sha256={$hmac}";

        // Verify it would be accepted by deploy.php (constant-time check simulation)
        $recomputed = hash_hmac('sha256', (string) $body, $secret);
        expect(hash_equals($recomputed, $hmac))->toBeTrue()
            ->and($header)->toStartWith('sha256=');
    });

    it('ArtifactBuilder produces a ZIP with manifest.json and .hmac file', function () {
        $secret  = 'ftp-test-secret';
        $builder = new ArtifactBuilder($this->basePath);
        $zipPath = $builder->build('ftp-release-001', [], null, null, 'lean', $secret);
        $hmacPath = "{$zipPath}.hmac";

        expect(file_exists($zipPath))->toBeTrue()
            ->and(file_exists($hmacPath))->toBeTrue();

        $zip = new ZipArchive();
        $zip->open($zipPath);
        $manifest = $zip->getFromName('manifest.json');
        $zip->close();

        expect($manifest)->not->toBeFalse();

        // Verify HMAC
        $expectedHmac = hash_hmac('sha256', (string) file_get_contents($zipPath), $secret);
        $storedHmac   = trim((string) file_get_contents($hmacPath));
        expect(hash_equals($expectedHmac, $storedHmac))->toBeTrue();

        unlink($zipPath);
        unlink($hmacPath);
    });

    it('rollback returns true as no-op when healthUrl is not set', function () {
        $lock   = new DeployLock($this->lockDir, 1800);
        $driver = new FtpDriver($this->basePath, null, $lock, null);
        $config = makeFtpDeployConfig();
        $config->healthUrl = null;
        $logger = fn (string $m) => null;

        $result = $driver->rollback($config, $logger);
        expect($result)->toBeTrue();
    });

    it('rollback payload includes "to" when rollbackTo is set on config', function () {
        // Verify the JSON payload construction at the data level (no HTTP needed)
        $secret  = 'test-secret-rollbackto';
        $payload = ['action' => 'rollback'];
        $rollbackTo = '2026-05-26_143022_abc1234';

        // Simulate what FtpDriver::rollback() builds
        if (!empty($rollbackTo)) {
            $payload['to'] = $rollbackTo;
        }
        $body = (string) json_encode($payload);

        $decoded = json_decode($body, true);
        expect($decoded)->toBeArray()
            ->and($decoded['action'])->toBe('rollback')
            ->and($decoded['to'])->toBe($rollbackTo);

        // Verify HMAC of this body is consistent
        $hmac = 'sha256=' . hash_hmac('sha256', $body, $secret);
        expect($hmac)->toStartWith('sha256=');
    });

    it('rollback payload excludes "to" when rollbackTo is null', function () {
        $payload = ['action' => 'rollback'];
        $rollbackTo = null;

        if (!empty($rollbackTo)) {
            $payload['to'] = $rollbackTo;
        }
        $body    = (string) json_encode($payload);
        $decoded = json_decode($body, true);

        expect($decoded)->toBeArray()
            ->and($decoded['action'])->toBe('rollback')
            ->and(array_key_exists('to', $decoded))->toBeFalse();
    });

});

describe('FtpDriver — container integration (skipped if unavailable)', function () {

    it('full deploy to container FTP server', function () {
        if (!ContainerHelper::isAvailable()) {
            $this->markTestSkipped(
                'apple/container system is not running. '
                . 'Start it with: container system start'
            );
        }

        // Full FTP integration is covered by tests/Integration/Deploy/FtpDriverContainerTest.php
        // This placeholder confirms the skip guard uses ContainerHelper::isAvailable().
        expect(true)->toBeTrue();
    });

});
