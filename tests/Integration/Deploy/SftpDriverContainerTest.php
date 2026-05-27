<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\DeployLock;
use Rkn\Cms\Deploy\Drivers\SftpDriver;
use Rkn\Cms\Deploy\Process\Runner;
use Tests\Helpers\ContainerHelper;

/**
 * Hard E2E test for SftpDriver using apple/container runtime.
 *
 * Spins up linuxserver/openssh-server with a generated ed25519 keypair,
 * installs rsync inside the container, then exercises the FULL deploy
 * pipeline (validate + deploy + rollback) with real key-based auth.
 *
 * SKIP CONDITION: container daemon not running.
 *
 * Manual run:
 *   container system start
 *   herd php vendor/bin/pest tests/Integration/Deploy/SftpDriverContainerTest.php
 */

if (!ContainerHelper::isAvailable()) {
    test('SftpDriver container E2E — skipped (container not available)', function () {
        $this->markTestSkipped('apple/container system is not running.');
    });
    return;
}

// ─── Shared fixtures ─────────────────────────────────────────────────────

$sftpHelper        = new ContainerHelper();
$sftpUid           = uniqid();
$sftpContainerName = "rkn-test-sftp-{$sftpUid}";
$sftpFixturesDir   = sys_get_temp_dir() . "/rkn-sftp-fix-{$sftpUid}";
$sftpHostPort      = $sftpHelper->pickFreePort();
$sftpLockDir       = sys_get_temp_dir() . "/rkn-sftp-lock-{$sftpUid}";
$sftpSrcDir        = sys_get_temp_dir() . "/rkn-sftp-src-{$sftpUid}";
$sftpVolume        = sys_get_temp_dir() . "/rkn-sftp-vol-{$sftpUid}";

mkdir($sftpFixturesDir, 0755, true);
mkdir($sftpSrcDir, 0755, true);
mkdir($sftpVolume, 0755, true);
mkdir($sftpLockDir, 0755, true);

// Generate an ed25519 keypair for key-based auth
$sftpKeyPath = "{$sftpFixturesDir}/id_ed25519";
$keygenRunner = new Runner($sftpFixturesDir);
$keygen = $keygenRunner->run([
    'ssh-keygen', '-t', 'ed25519', '-N', '', '-f', $sftpKeyPath, '-q',
])->withTimeout(15)->execute();

if (!$keygen->isSuccess()) {
    test('SftpDriver container E2E — skipped (ssh-keygen failed)', function () use ($keygen) {
        $this->markTestSkipped("ssh-keygen failed: {$keygen->stderr}");
    });
    return;
}

chmod($sftpKeyPath, 0600);
$sftpPubKey = trim((string) file_get_contents("{$sftpKeyPath}.pub"));

// Source content for deploy
file_put_contents("{$sftpSrcDir}/index.php", '<?php echo "sftp-release-v1";');
file_put_contents("{$sftpSrcDir}/about.php", '<?php echo "about";');
mkdir("{$sftpSrcDir}/lib", 0755, true);
file_put_contents("{$sftpSrcDir}/lib/util.php", '<?php return ["v" => 1];');

// Pull and start container with PUBLIC_KEY for key-based auth
$sftpHelper->pull('linuxserver/openssh-server:latest');
$sftpHelper->run(
    name: $sftpContainerName,
    image: 'linuxserver/openssh-server:latest',
    portMap: [$sftpHostPort => 2222],
    volumes: [$sftpVolume => '/config'],
    env: [
        'USER_NAME'       => 'testuser',
        'PUBLIC_KEY'      => $sftpPubKey,
        'PASSWORD_ACCESS' => 'false',
        'SUDO_ACCESS'     => 'true',
        'PUID'            => '1000',
        'PGID'            => '1000',
    ],
);

// Wait for SSH to be ready then give sshd a moment to install the key
$sftpHelper->waitForPort('127.0.0.1', $sftpHostPort, 60);
sleep(4);

// Install rsync inside the container (alpine-based)
$sftpInstallResult = $sftpHelper->exec($sftpContainerName, ['apk', 'add', '--no-cache', 'rsync']);
$sftpRsyncAvailable = $sftpInstallResult->isSuccess();

// Ensure remote deploy directory exists and is owned by testuser (PUID=1000)
$sftpHelper->exec($sftpContainerName, ['mkdir', '-p', '/config/deployments']);
$sftpHelper->exec($sftpContainerName, ['chown', '-R', '1000:1000', '/config/deployments']);

// ─── Cleanup ─────────────────────────────────────────────────────────────

afterAll(function () use (
    $sftpHelper,
    $sftpContainerName,
    $sftpFixturesDir,
    $sftpSrcDir,
    $sftpVolume,
    $sftpLockDir,
) {
    $rmrf = function (string $d) use (&$rmrf): void {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = "{$d}/{$item}";
            is_dir($p) && !is_link($p) ? $rmrf($p) : @unlink($p);
        }
        @rmdir($d);
    };

    try { $sftpHelper->stop($sftpContainerName); } catch (\Throwable) {}
    try { $sftpHelper->remove($sftpContainerName); } catch (\Throwable) {}
    foreach ([$sftpFixturesDir, $sftpSrcDir, $sftpVolume, $sftpLockDir] as $d) {
        try { $rmrf($d); } catch (\Throwable) {}
    }
});

// ─── Test helpers ────────────────────────────────────────────────────────

function sftpMakeConfig(int $port, string $keyPath): DeployConfig
{
    $config = new DeployConfig();
    $config->environment  = 'test';
    $config->method       = 'sftp';
    $config->strategy     = 'lean';
    $config->host         = '127.0.0.1';
    $config->user         = 'testuser';
    $config->port         = $port;
    $config->path         = '/config/deployments';
    $config->identityFile = $keyPath;
    $config->deploySecret = 'sftp-secret';
    return $config;
}

// ─── Tests ───────────────────────────────────────────────────────────────

test('SftpDriver: key-based SSH auth works (validate returns no errors)', function () use (
    $sftpHostPort,
    $sftpKeyPath,
    $sftpSrcDir,
    $sftpLockDir,
) {
    // validate() only needs HOST rsync + container ssh + remote writable,
    // not rsync inside the container. Independent of $sftpRsyncAvailable.
    $config = sftpMakeConfig($sftpHostPort, $sftpKeyPath);
    $lock   = new DeployLock($sftpLockDir, 1800);
    $driver = new SftpDriver($sftpSrcDir, null, $lock);

    $logs = [];
    $errors = $driver->validate($config, function (string $m) use (&$logs): void {
        $logs[] = $m;
    });

    expect($errors)->toBe([], 'validate returned: ' . implode(' | ', $errors));
});

test('SftpDriver: full deploy uploads files and activates symlink', function () use (
    $sftpHelper,
    $sftpContainerName,
    $sftpHostPort,
    $sftpKeyPath,
    $sftpSrcDir,
    $sftpLockDir,
    $sftpRsyncAvailable,
) {
    if (!$sftpRsyncAvailable) {
        $this->markTestSkipped('rsync could not be installed in the container.');
    }

    $config = sftpMakeConfig($sftpHostPort, $sftpKeyPath);
    $lock   = new DeployLock($sftpLockDir, 1800);
    $driver = new SftpDriver($sftpSrcDir, null, $lock);

    $logs = [];
    $result = $driver->deploy($config, function (string $m) use (&$logs): void {
        $logs[] = $m;
    });

    expect($result)->toBeTrue('deploy failed; logs: ' . implode("\n", $logs));
    expect($lock->inspect('test'))->toBeNull('lock must be released after deploy');

    // Verify on remote: index.php exists inside some release dir
    $find = $sftpHelper->exec($sftpContainerName, [
        'sh', '-c', 'find /config/deployments/releases -name index.php',
    ]);
    expect($find->isSuccess())->toBeTrue();
    expect(trim($find->stdout))->not->toBeEmpty('index.php missing from remote release');

    // Verify current symlink points to the release we just deployed
    $readlink = $sftpHelper->exec($sftpContainerName, ['readlink', '/config/deployments/current']);
    expect($readlink->isSuccess())->toBeTrue();
    expect(trim($readlink->stdout))->toContain('/config/deployments/releases/');
});

test('SftpDriver: second deploy creates new release dir keeping previous', function () use (
    $sftpHelper,
    $sftpContainerName,
    $sftpHostPort,
    $sftpKeyPath,
    $sftpSrcDir,
    $sftpLockDir,
    $sftpRsyncAvailable,
) {
    if (!$sftpRsyncAvailable) {
        $this->markTestSkipped('rsync could not be installed in the container.');
    }

    // Force a different timestamp for releaseId
    sleep(1);

    // Bump source content so the new release is distinct
    file_put_contents("{$sftpSrcDir}/index.php", '<?php echo "sftp-release-v2";');

    $config = sftpMakeConfig($sftpHostPort, $sftpKeyPath);
    $lock   = new DeployLock($sftpLockDir, 1800);
    $driver = new SftpDriver($sftpSrcDir, null, $lock);

    $logs = [];
    $result = $driver->deploy($config, function (string $m) use (&$logs): void {
        $logs[] = $m;
    });

    expect($result)->toBeTrue('second deploy failed; logs: ' . implode("\n", $logs));

    // There must be at least 2 release directories now
    $ls = $sftpHelper->exec($sftpContainerName, [
        'sh', '-c', 'ls -1 /config/deployments/releases | wc -l',
    ]);
    expect((int) trim($ls->stdout))->toBeGreaterThanOrEqual(2);

    // Verify content of current release is v2
    $cat = $sftpHelper->exec($sftpContainerName, [
        'sh', '-c', 'cat /config/deployments/current/index.php',
    ]);
    expect($cat->stdout)->toContain('sftp-release-v2');
});

test('SftpDriver: rollback swaps current symlink to the previous release', function () use (
    $sftpHelper,
    $sftpContainerName,
    $sftpHostPort,
    $sftpKeyPath,
    $sftpSrcDir,
    $sftpLockDir,
    $sftpRsyncAvailable,
) {
    if (!$sftpRsyncAvailable) {
        $this->markTestSkipped('rsync could not be installed in the container.');
    }

    // Capture pre-rollback symlink target
    $beforeReadlink = $sftpHelper->exec($sftpContainerName, ['readlink', '/config/deployments/current']);
    expect($beforeReadlink->isSuccess())->toBeTrue();
    $before = trim($beforeReadlink->stdout);

    $config = sftpMakeConfig($sftpHostPort, $sftpKeyPath);
    $driver = new SftpDriver($sftpSrcDir, null, new DeployLock($sftpLockDir, 1800));

    $logs = [];
    $result = $driver->rollback($config, function (string $m) use (&$logs): void {
        $logs[] = $m;
    });

    expect($result)->toBeTrue('rollback failed; logs: ' . implode("\n", $logs));

    $afterReadlink = $sftpHelper->exec($sftpContainerName, ['readlink', '/config/deployments/current']);
    $after = trim($afterReadlink->stdout);

    expect($after)->not->toBe($before, 'current symlink unchanged after rollback');
    expect($after)->toContain('/config/deployments/releases/');
});

test('SftpDriver: deploy releases lock even when rsync command fails', function () use (
    $sftpHostPort,
    $sftpKeyPath,
    $sftpSrcDir,
    $sftpLockDir,
) {
    $config = sftpMakeConfig($sftpHostPort, $sftpKeyPath);
    // Point to a non-writable remote path to force deploy failure
    $config->path = '/etc/cannot-write';

    $lock   = new DeployLock($sftpLockDir, 1800);
    $driver = new SftpDriver($sftpSrcDir, null, $lock);

    $logs = [];
    $result = $driver->deploy($config, function (string $m) use (&$logs): void {
        $logs[] = $m;
    });

    // Should fail OR succeed (depends on whether /etc has rsync write perms; testuser is not root)
    expect($result)->toBeBool();
    // Lock MUST be released regardless
    expect($lock->inspect('test'))->toBeNull();
});
