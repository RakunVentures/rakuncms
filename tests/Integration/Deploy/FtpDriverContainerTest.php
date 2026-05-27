<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\ArtifactBuilder;
use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\DeployLock;
use Rkn\Cms\Deploy\Drivers\FtpDriver;
use Tests\Helpers\ContainerHelper;

/**
 * E2E integration test for FtpDriver using apple/container runtime.
 *
 * SCOPE DECISION: This test validates the FTP *transport* layer only:
 *   - FTP connect, login, passive mode (via FtpDriver::validate())
 *   - Direct ftp_put / ftp_size verification of a real ZIP artifact
 *   - DeployLock acquisition and release under FtpDriver::deploy()
 *
 * The end-to-end activation via deploy.php is NOT tested here because it
 * requires an HTTP server inside the FTP container (which has no PHP runtime).
 * Activation is already fully covered by tests/Integration/Deploy/DeployPhpStubTest.php.
 *
 * Container: delfer/alpine-ftp-server:latest (multi-arch amd64+arm64).
 * Fallback:  garethflowers/ftp-server:latest if delfer is unavailable.
 *
 * SKIP CONDITION: Tests skip cleanly when apple/container is not running.
 * Manual run: container system start && cd rakuncms && herd php vendor/bin/pest tests/Integration/Deploy/FtpDriverContainerTest.php
 *
 * NOTE: beforeAll is not allowed inside describe in Pest. Fixtures are set up
 * at file scope so they are shared across all tests in this file.
 *
 * NOTE on passive ports: delfer/alpine-ftp-server uses ports 21 (control) and
 * MIN_PORT..MAX_PORT (passive data). We map 21001-21010 as passive.
 */

// ─── Skip guard ───────────────────────────────────────────────────────────

if (!ContainerHelper::isAvailable()) {
    test('FtpDriver container E2E — skipped (container not available)', function () {
        $this->markTestSkipped(
            'apple/container system is not running. '
            . 'Manual run: container system start && herd php vendor/bin/pest tests/Integration/Deploy/FtpDriverContainerTest.php'
        );
    });
    return;
}

// ─── Shared fixtures ─────────────────────────────────────────────────────

$ftpHelper         = new ContainerHelper();
$ftpPid            = getmypid();
$ftpUid            = uniqid();
$ftpContainerName  = "rkn-test-ftp-{$ftpPid}-{$ftpUid}";
$ftpTmpVolume      = sys_get_temp_dir() . "/rkn-ftp-vol-{$ftpUid}";
$ftpHostPort       = $ftpHelper->pickFreePort();
$ftpLockDir        = sys_get_temp_dir() . "/rkn-ftp-lock-{$ftpUid}";

// Build minimal source tree
$ftpSrcDir = sys_get_temp_dir() . "/rkn-ftp-src-{$ftpUid}";
mkdir($ftpSrcDir, 0755, true);
file_put_contents("{$ftpSrcDir}/index.php", '<?php echo "ftp-release-v1";');
file_put_contents("{$ftpSrcDir}/app.php", '<?php return ["app" => "RakunCMS"];');
file_put_contents("{$ftpSrcDir}/readme.txt", 'FTP container test artifact.');

mkdir($ftpTmpVolume, 0755, true);
mkdir($ftpLockDir, 0755, true);

// Passive port range (hardcoded 21001-21010, standard enough for most envs)
$ftpMinPassive = 21001;
$ftpMaxPassive = 21010;
$ftpPortMap    = [$ftpHostPort => 21];
for ($ftpP = $ftpMinPassive; $ftpP <= $ftpMaxPassive; $ftpP++) {
    $ftpPortMap[$ftpP] = $ftpP;
}

// Pull image
$ftpImage = 'delfer/alpine-ftp-server:latest';
try {
    $ftpHelper->pull($ftpImage);
} catch (\RuntimeException) {
    $ftpImage = 'garethflowers/ftp-server:latest';
    $ftpHelper->pull($ftpImage);
}

// Start container
$ftpHelper->run(
    name: $ftpContainerName,
    image: $ftpImage,
    portMap: $ftpPortMap,
    volumes: [$ftpTmpVolume => '/ftp'],
    env: [
        'USERS'    => 'testuser|testpass',
        'ADDRESS'  => '127.0.0.1',
        'MIN_PORT' => (string) $ftpMinPassive,
        'MAX_PORT' => (string) $ftpMaxPassive,
    ],
);

// Wait for FTP control port
$ftpHelper->waitForPort('127.0.0.1', $ftpHostPort, 30);
sleep(2); // Allow vsftpd to fully initialize

// ─── Cleanup registration ────────────────────────────────────────────────

afterAll(function () use ($ftpHelper, $ftpContainerName, $ftpTmpVolume, $ftpLockDir, $ftpSrcDir) {
    $cleanup = function (string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $rec = function (string $d) use (&$rec): void {
            foreach (scandir($d) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = "{$d}/{$item}";
                is_dir($path) ? $rec($path) : unlink($path);
            }
            rmdir($d);
        };
        $rec($dir);
    };

    try {
        $ftpHelper->stop($ftpContainerName);
        $ftpHelper->remove($ftpContainerName);
    } catch (\Throwable) {
    }

    try {
        $cleanup($ftpTmpVolume);
    } catch (\Throwable) {
    }

    try {
        $cleanup($ftpLockDir);
    } catch (\Throwable) {
    }

    try {
        $cleanup($ftpSrcDir);
    } catch (\Throwable) {
    }
});

// ─── Tests ───────────────────────────────────────────────────────────────────

test('FtpDriver::validate() connects to container FTP and returns no connection errors', function () use (
    $ftpHostPort,
    $ftpLockDir,
    $ftpSrcDir,
) {
    $config = new DeployConfig();
    $config->environment  = 'test';
    $config->method       = 'ftp';
    $config->strategy     = 'lean';
    $config->host         = '127.0.0.1';
    $config->user         = 'testuser';
    $config->pass         = 'testpass';
    $config->port         = $ftpHostPort;
    $config->secure       = false;
    $config->path         = '/ftp/testuser';
    $config->deploySecret = 'ftp-test-secret-cont3';

    $lock   = new DeployLock($ftpLockDir, 1800);
    $driver = new FtpDriver($ftpSrcDir, null, $lock, null);

    $errors = $driver->validate($config, fn (string $m) => null);

    // FTP connection errors must NOT appear (server is reachable)
    $connErrors = array_filter($errors, fn (string $e) => str_contains($e, 'Cannot connect'));
    expect($connErrors)->toBeEmpty(
        "FTP connection to 127.0.0.1:{$ftpHostPort} should succeed, got: " . implode('; ', $errors)
    );
});

test('direct ftp_put uploads a ZIP artifact with correct size verification', function () use (
    $ftpHostPort,
    $ftpSrcDir,
) {
    // Build a real ZIP artifact
    $builder   = new ArtifactBuilder($ftpSrcDir);
    $releaseId = 'cont3-' . uniqid();
    $zipPath   = $builder->build($releaseId, [], null, null, 'lean', 'ftp-test-secret-cont3');
    $hmacPath  = "{$zipPath}.hmac";

    $conn = @ftp_connect('127.0.0.1', $ftpHostPort, 10);
    expect($conn)->not->toBeFalse(
        "FTP connect to 127.0.0.1:{$ftpHostPort} failed"
    );

    if ($conn === false) {
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }
        if (file_exists($hmacPath)) {
            unlink($hmacPath);
        }
        return;
    }

    try {
        $loginOk = @ftp_login($conn, 'testuser', 'testpass');
        expect($loginOk)->toBeTrue('FTP login failed');

        ftp_pasv($conn, true);

        // Ensure releases dir exists on remote (testuser's writable home is /ftp/testuser)
        @ftp_mkdir($conn, "/ftp/testuser/releases");

        $remoteZip  = "/ftp/testuser/releases/{$releaseId}.zip";
        $remoteHmac = "/ftp/testuser/releases/{$releaseId}.zip.hmac";

        // Upload ZIP
        $uploadOk = ftp_put($conn, $remoteZip, $zipPath, FTP_BINARY);
        expect($uploadOk)->toBeTrue("ftp_put of ZIP failed");

        // Verify size matches
        $localSize  = (int) filesize($zipPath);
        $remoteSize = ftp_size($conn, $remoteZip);
        expect($remoteSize)->toBe($localSize, "Remote ZIP size mismatch");

        // Upload HMAC sidecar
        if (file_exists($hmacPath)) {
            $hmacOk = ftp_put($conn, $remoteHmac, $hmacPath, FTP_BINARY);
            expect($hmacOk)->toBeTrue("ftp_put of HMAC failed");

            $localHmacSize  = (int) filesize($hmacPath);
            $remoteHmacSize = ftp_size($conn, $remoteHmac);
            expect($remoteHmacSize)->toBe($localHmacSize, "Remote HMAC size mismatch");
        }

    } finally {
        ftp_close($conn);
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }
        if (file_exists($hmacPath)) {
            unlink($hmacPath);
        }
    }
});

test('FtpDriver::deploy() releases DeployLock even when deploy.php activation fails', function () use (
    $ftpHostPort,
    $ftpLockDir,
    $ftpSrcDir,
) {
    // FtpDriver::deploy() will upload via FTP but fail at the HTTP activation step
    // (no deploy.php server running). Lock MUST be released in the finally block.
    $config = new DeployConfig();
    $config->environment  = 'test';
    $config->method       = 'ftp';
    $config->strategy     = 'lean';
    $config->host         = '127.0.0.1';
    $config->user         = 'testuser';
    $config->pass         = 'testpass';
    $config->port         = $ftpHostPort;
    $config->secure       = false;
    $config->path         = '/ftp/testuser';
    $config->deploySecret = 'ftp-test-secret-cont3';
    $config->healthUrl    = null; // No HTTP server

    $lock   = new DeployLock($ftpLockDir, 1800);
    $driver = new FtpDriver($ftpSrcDir, null, $lock, null);

    $result = $driver->deploy($config, fn (string $m) => null);

    // Lock MUST be released after deploy
    expect($lock->inspect('test'))->toBeNull();

    // Returns bool (never throws)
    expect($result)->toBeBool();
});
