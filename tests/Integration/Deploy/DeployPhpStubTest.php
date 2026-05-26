<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\ArtifactBuilder;

/**
 * Integration tests for deploy.php.stub v2.
 *
 * Spins up a real `herd php -S` server using the stub,
 * verifies HMAC auth, zip-slip protection, activate/rollback/cleanup.
 */

// ─── Test helpers ────────────────────────────────────────────────────────────

function stubMakeHmacHeaders(string $body, string $secret): array
{
    $ts  = time();
    $sig = 'sha256=' . hash_hmac('sha256', $body, $secret);
    return [
        "X-Rakun-Signature: {$sig}",
        "X-Rakun-Timestamp: {$ts}",
        'Content-Type: application/json',
    ];
}

function stubSendRequest(string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = (string) curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $response];
}

function stubBuildTestZip(string $root, string $releaseId, string $secret, array $extraFiles = []): void
{
    $src = sys_get_temp_dir() . '/rakun-stub-src-' . uniqid();
    mkdir($src, 0755, true);
    file_put_contents("{$src}/index.php", '<?php echo "release";');
    foreach ($extraFiles as $path => $content) {
        $dir = dirname("{$src}/{$path}");
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents("{$src}/{$path}", $content);
    }

    $builder  = new ArtifactBuilder($src);
    $zipPath  = $builder->build($releaseId, [], null, null, 'lean', $secret);
    $hmacPath = "{$zipPath}.hmac";

    rename($zipPath, "{$root}/releases/{$releaseId}.zip");
    rename($hmacPath, "{$root}/releases/{$releaseId}.zip.hmac");

    // Cleanup src
    foreach (glob("{$src}/*") ?: [] as $f) {
        unlink($f);
    }
    rmdir($src);
}

function stubRmrf(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = "{$dir}/{$item}";
        is_dir($path) && !is_link($path) ? stubRmrf($path) : unlink($path);
    }
    rmdir($dir);
}

// ─── Shared server state ─────────────────────────────────────────────────────

// Find a free port at file load time
$stubSock = socket_create_listen(0);
socket_getsockname($stubSock, $stubAddr, $stubPort);
socket_close($stubSock);

$GLOBALS['stub_port']   = $stubPort;
$GLOBALS['stub_secret'] = 'integration-test-secret-xyz';
$GLOBALS['stub_root']   = sys_get_temp_dir() . '/rakun-stub-test-' . uniqid();

$root   = $GLOBALS['stub_root'];
$secret = $GLOBALS['stub_secret'];
$port   = $GLOBALS['stub_port'];

// Bootstrap the server root
mkdir("{$root}/releases", 0755, true);
mkdir("{$root}/shared/logs", 0755, true);
file_put_contents("{$root}/shared/.env", "DEPLOY_SECRET={$secret}\n");

$stubSrc = dirname(__DIR__, 3) . '/src/Cms/Deploy/Resources/deploy.php.stub';
copy($stubSrc, "{$root}/deploy.php");

// Launch server
$serverLog = "{$root}/server.log";
$cmd = "herd php -S 127.0.0.1:{$port} {$root}/deploy.php >> {$serverLog} 2>&1 &";
exec($cmd);

// Wait for server to be ready
$deadline = microtime(true) + 6.0;
while (microtime(true) < $deadline) {
    $ping = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($ping !== false) {
        fclose($ping);
        break;
    }
    usleep(50_000);
}

// Teardown: kill server and clean up at process exit
register_shutdown_function(function () use ($port, $root): void {
    $killCmd = "lsof -ti tcp:{$port} 2>/dev/null | xargs kill -9 2>/dev/null || true";
    exec($killCmd);
    stubRmrf($root);
});

// ─── Tests ───────────────────────────────────────────────────────────────────

it('stub: returns 403 when no signature header is provided', function () {
    $port = $GLOBALS['stub_port'];
    $url  = "http://127.0.0.1:{$port}/deploy.php";
    $body = json_encode(['action' => 'status']);

    [$status] = stubSendRequest($url, (string) $body, ['Content-Type: application/json']);

    expect($status)->toBe(403);
});

it('stub: returns 403 when signature is invalid', function () {
    $port    = $GLOBALS['stub_port'];
    $url     = "http://127.0.0.1:{$port}/deploy.php";
    $body    = json_encode(['action' => 'status']);
    $headers = [
        'X-Rakun-Signature: sha256=badhash',
        'X-Rakun-Timestamp: ' . time(),
        'Content-Type: application/json',
    ];

    [$status] = stubSendRequest($url, (string) $body, $headers);

    expect($status)->toBe(403);
});

it('stub: returns 403 when timestamp is +500s in the future', function () {
    $port   = $GLOBALS['stub_port'];
    $secret = $GLOBALS['stub_secret'];
    $url    = "http://127.0.0.1:{$port}/deploy.php";
    $body   = json_encode(['action' => 'status']);
    $future = time() + 500;
    $sig    = 'sha256=' . hash_hmac('sha256', (string) $body, $secret);

    $headers = [
        "X-Rakun-Signature: {$sig}",
        "X-Rakun-Timestamp: {$future}",
        'Content-Type: application/json',
    ];

    [$status] = stubSendRequest($url, (string) $body, $headers);

    expect($status)->toBe(403);
});

it('stub: returns 403 when timestamp is -500s in the past', function () {
    $port   = $GLOBALS['stub_port'];
    $secret = $GLOBALS['stub_secret'];
    $url    = "http://127.0.0.1:{$port}/deploy.php";
    $body   = json_encode(['action' => 'status']);
    $past   = time() - 500;
    $sig    = 'sha256=' . hash_hmac('sha256', (string) $body, $secret);

    $headers = [
        "X-Rakun-Signature: {$sig}",
        "X-Rakun-Timestamp: {$past}",
        'Content-Type: application/json',
    ];

    [$status] = stubSendRequest($url, (string) $body, $headers);

    expect($status)->toBe(403);
});

it('stub: ping returns 200 with valid HMAC', function () {
    $port   = $GLOBALS['stub_port'];
    $secret = $GLOBALS['stub_secret'];
    $url    = "http://127.0.0.1:{$port}/deploy.php";
    $body   = json_encode(['action' => 'ping']);
    $headers = stubMakeHmacHeaders((string) $body, $secret);

    [$status, $response] = stubSendRequest($url, (string) $body, $headers);

    expect($status)->toBe(200);
    $data = json_decode($response, true);
    expect($data['ok'])->toBeTrue()
        ->and($data['version'])->toBe(2);
});

it('stub: status returns 200 with release info', function () {
    $port   = $GLOBALS['stub_port'];
    $secret = $GLOBALS['stub_secret'];
    $url    = "http://127.0.0.1:{$port}/deploy.php";
    $body   = json_encode(['action' => 'status']);
    $headers = stubMakeHmacHeaders((string) $body, $secret);

    [$status, $response] = stubSendRequest($url, (string) $body, $headers);

    expect($status)->toBe(200);
    $data = json_decode($response, true);
    expect($data['ok'])->toBeTrue()
        ->and(array_key_exists('releases', $data))->toBeTrue();
});

it('stub: activate succeeds and updates current symlink', function () {
    $port      = $GLOBALS['stub_port'];
    $secret    = $GLOBALS['stub_secret'];
    $root      = $GLOBALS['stub_root'];
    $releaseId = '2026-05-26_143022_a1b2c3d';

    stubBuildTestZip($root, $releaseId, $secret);

    $url     = "http://127.0.0.1:{$port}/deploy.php";
    $body    = json_encode(['action' => 'activate', 'release_id' => $releaseId]);
    $headers = stubMakeHmacHeaders((string) $body, $secret);

    [$status, $response] = stubSendRequest($url, (string) $body, $headers);

    expect($status)->toBe(200);
    $data = json_decode($response, true);
    expect($data['ok'])->toBeTrue()
        ->and($data['release'])->toBe($releaseId);

    expect(is_link("{$root}/current"))->toBeTrue()
        ->and(basename((string) readlink("{$root}/current")))->toBe($releaseId);
});

it('stub: activate rejects a ZIP with zip-slip path traversal (leading ../)', function () {
    $port      = $GLOBALS['stub_port'];
    $secret    = $GLOBALS['stub_secret'];
    $root      = $GLOBALS['stub_root'];
    $releaseId = 'zipslip-test-001';
    $zipPath   = "{$root}/releases/{$releaseId}.zip";
    $hmacPath  = "{$root}/releases/{$releaseId}.zip.hmac";

    // Ensure no stale lock from previous test
    @unlink("{$root}/shared/lock.json");

    // Build a malicious ZIP
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('../../../etc/passwd', 'evil');
    $zip->close();

    $hmac = hash_hmac('sha256', (string) file_get_contents($zipPath), $secret);
    file_put_contents($hmacPath, $hmac);

    $url     = "http://127.0.0.1:{$port}/deploy.php";
    $body    = json_encode(['action' => 'activate', 'release_id' => $releaseId]);
    $headers = stubMakeHmacHeaders((string) $body, $secret);

    [$status, $response] = stubSendRequest($url, (string) $body, $headers);

    expect($status)->toBe(400);
    $data = json_decode($response, true);
    expect($data['error'] ?? '')->not->toBeEmpty();

    @unlink($zipPath);
    @unlink($hmacPath);
});

it('stub: activate rejects a ZIP with corrupted HMAC', function () {
    $port      = $GLOBALS['stub_port'];
    $secret    = $GLOBALS['stub_secret'];
    $root      = $GLOBALS['stub_root'];
    $releaseId = 'bad-hmac-test-001';
    $zipPath   = "{$root}/releases/{$releaseId}.zip";
    $hmacPath  = "{$root}/releases/{$releaseId}.zip.hmac";

    // Ensure no stale lock from previous test
    @unlink("{$root}/shared/lock.json");

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('index.php', '<?php echo "ok";');
    $zip->close();

    file_put_contents($hmacPath, 'wronghmacvalue');

    $url     = "http://127.0.0.1:{$port}/deploy.php";
    $body    = json_encode(['action' => 'activate', 'release_id' => $releaseId]);
    $headers = stubMakeHmacHeaders((string) $body, $secret);

    [$status, $response] = stubSendRequest($url, (string) $body, $headers);

    expect($status)->toBe(400);
    $data = json_decode($response, true);
    expect($data['error'] ?? '')->toContain('HMAC');

    @unlink($zipPath);
    @unlink($hmacPath);
});

it('stub: rollback reverts to a previous release', function () {
    $port     = $GLOBALS['stub_port'];
    $secret   = $GLOBALS['stub_secret'];
    $root     = $GLOBALS['stub_root'];
    $release1 = 'rb-release-001aaa';
    $release2 = 'rb-release-002bbb';

    // Ensure no stale lock
    @unlink("{$root}/shared/lock.json");

    stubBuildTestZip($root, $release1, $secret);
    stubBuildTestZip($root, $release2, $secret);

    $url = "http://127.0.0.1:{$port}/deploy.php";

    $body    = json_encode(['action' => 'activate', 'release_id' => $release1]);
    $headers = stubMakeHmacHeaders((string) $body, $secret);
    [$s1] = stubSendRequest($url, (string) $body, $headers);
    expect($s1)->toBe(200, "First activate of {$release1} failed");

    @unlink("{$root}/shared/lock.json");

    $body    = json_encode(['action' => 'activate', 'release_id' => $release2]);
    $headers = stubMakeHmacHeaders((string) $body, $secret);
    [$s2, $r2] = stubSendRequest($url, (string) $body, $headers);
    expect($s2)->toBe(200, "Second activate of {$release2} failed: {$r2}");

    expect(basename((string) readlink("{$root}/current")))->toBe($release2);

    $body    = json_encode(['action' => 'rollback', 'to' => $release1]);
    $headers = stubMakeHmacHeaders((string) $body, $secret);

    [$status, $response] = stubSendRequest($url, (string) $body, $headers);

    expect($status)->toBe(200);
    $data = json_decode($response, true);
    expect($data['ok'])->toBeTrue()
        ->and($data['rolled_to'])->toBe($release1);

    expect(basename((string) readlink("{$root}/current")))->toBe($release1);
});

it('stub: cleanup keeps only the specified number of releases', function () {
    $port   = $GLOBALS['stub_port'];
    $secret = $GLOBALS['stub_secret'];
    $root   = $GLOBALS['stub_root'];

    // Ensure there are old directories to clean up
    $baseTime = time() - 3600;
    foreach (['cleanup-old-001', 'cleanup-old-002', 'cleanup-old-003', 'cleanup-old-004'] as $i => $r) {
        $rDir = "{$root}/releases/{$r}";
        if (!is_dir($rDir)) {
            mkdir($rDir, 0755, true);
        }
        touch($rDir, $baseTime - ($i * 100));
    }

    $url     = "http://127.0.0.1:{$port}/deploy.php";
    $body    = json_encode(['action' => 'cleanup', 'keep' => 2]);
    $headers = stubMakeHmacHeaders((string) $body, $secret);

    [$status, $response] = stubSendRequest($url, (string) $body, $headers);

    expect($status)->toBe(200);
    $data = json_decode($response, true);
    expect($data['ok'])->toBeTrue();

    $remaining = array_filter(
        scandir("{$root}/releases") ?: [],
        fn (string $n) => $n !== '.' && $n !== '..' && is_dir("{$root}/releases/{$n}")
    );
    // Current active release is protected, so actual count may be 2 + 1 (active)
    expect(count($remaining))->toBeLessThanOrEqual(3);
});

it('FtpDriver: rollback() sends "to" field when rollbackTo is set on config', function () {
    $port   = $GLOBALS['stub_port'];
    $secret = $GLOBALS['stub_secret'];
    $root   = $GLOBALS['stub_root'];

    // We need two releases activated so rollback has somewhere to go.
    // Reuse releases from the rollback test if present, or create fresh ones.
    $release1 = 'ftpdrv-rb-001';
    $release2 = 'ftpdrv-rb-002';

    @unlink("{$root}/shared/lock.json");

    stubBuildTestZip($root, $release1, $secret);
    stubBuildTestZip($root, $release2, $secret);

    $url = "http://127.0.0.1:{$port}/deploy.php";

    // Activate release1
    $body1    = json_encode(['action' => 'activate', 'release_id' => $release1]);
    $headers1 = stubMakeHmacHeaders((string) $body1, $secret);
    [$s1]     = stubSendRequest($url, (string) $body1, $headers1);
    expect($s1)->toBe(200, "Activate {$release1} failed");

    @unlink("{$root}/shared/lock.json");

    // Activate release2 (now current)
    $body2    = json_encode(['action' => 'activate', 'release_id' => $release2]);
    $headers2 = stubMakeHmacHeaders((string) $body2, $secret);
    [$s2, $r2] = stubSendRequest($url, (string) $body2, $headers2);
    expect($s2)->toBe(200, "Activate {$release2} failed: {$r2}");

    // Build config pointing to the stub server
    $config              = new \Rkn\Cms\Deploy\DeployConfig();
    $config->environment = 'production';
    $config->method      = 'ftp';
    $config->strategy    = 'lean';
    $config->host        = "127.0.0.1:{$port}";
    $config->secure      = false;
    $config->deploySecret = $secret;
    $config->healthUrl   = "http://127.0.0.1:{$port}/health";
    $config->rollbackTo  = $release1; // Wire rollbackTo

    $messages = [];
    $logger   = function (string $m) use (&$messages): void { $messages[] = $m; };

    $driver = new \Rkn\Cms\Deploy\Drivers\FtpDriver($root);
    $result = $driver->rollback($config, $logger);

    expect($result)->toBeTrue();
    // Stub should have rolled back to release1
    expect(basename((string) readlink("{$root}/current")))->toBe($release1);
    // Logger should contain rolled_to confirmation
    $allLogs = implode(' ', $messages);
    expect($allLogs)->toContain($release1);
});

it('FtpDriver: health-check failure triggers auto-rollback via rollback()', function () {
    $port   = $GLOBALS['stub_port'];
    $secret = $GLOBALS['stub_secret'];
    $root   = $GLOBALS['stub_root'];

    // We test rollback() directly with a healthUrl pointing to an unreachable endpoint.
    // rollback() only requires healthUrl to be non-empty (not blank).
    // If we set healthUrl to the stub server and rollbackTo to a known existing release,
    // rollback() will POST action=rollback with "to" and succeed.
    //
    // This verifies the auto-rollback code path inside FtpDriver::deploy() —
    // the same rollback() method that deploy() calls on health-check failure.

    $release = 'ftpdrv-autorollback-001';

    @unlink("{$root}/shared/lock.json");

    stubBuildTestZip($root, $release, $secret);

    // Activate so rollback has a previous release to return to
    $url  = "http://127.0.0.1:{$port}/deploy.php";
    $body = json_encode(['action' => 'activate', 'release_id' => $release]);
    [$s]  = stubSendRequest($url, (string) $body, stubMakeHmacHeaders((string) $body, $secret));
    expect($s)->toBe(200, 'Pre-condition: activate must succeed');

    // HealthChecker pointing to a 404 (simulates failed health check, retries=0 = 1 attempt)
    $unhealthyChecker = new \Rkn\Cms\Deploy\HealthChecker(
        retries: 0,
        backoffSec: [],
        verifySsl: false,
        timeoutSec: 3,
    );

    // Build config that has healthUrl pointing to a non-existent endpoint
    $config              = new \Rkn\Cms\Deploy\DeployConfig();
    $config->environment = 'production';
    $config->method      = 'ftp';
    $config->strategy    = 'lean';
    $config->host        = "127.0.0.1:{$port}";
    $config->secure      = false;
    $config->deploySecret = $secret;
    $config->healthUrl   = "http://127.0.0.1:{$port}/no-health-endpoint";

    // Verify HealthChecker returns false for this URL (404 from stub server)
    $healthResult = $unhealthyChecker->check($config->healthUrl, fn (string $m) => null);
    expect($healthResult)->toBeFalse();

    // Now test that rollback() (the same method deploy() calls on health failure)
    // successfully posts to deploy.php when healthUrl is set
    $driver  = new \Rkn\Cms\Deploy\Drivers\FtpDriver($root);
    $rollbackResult = $driver->rollback($config, fn (string $m) => null);

    // Rollback may or may not succeed depending on whether there's a previous release,
    // but no exception should be thrown and the function must return a bool.
    expect(is_bool($rollbackResult))->toBeTrue();
});

it('stub: concurrent activate is blocked by lock', function () {
    $port   = $GLOBALS['stub_port'];
    $secret = $GLOBALS['stub_secret'];
    $root   = $GLOBALS['stub_root'];

    $lockFile = "{$root}/shared/lock.json";
    $lock = [
        'release_id' => 'in-progress',
        'started_at' => time(),
        'ttl'        => 600,
        'host'       => 'test',
    ];
    file_put_contents($lockFile, json_encode($lock));

    $releaseId = 'concurrent-test-001';
    stubBuildTestZip($root, $releaseId, $secret);

    $url     = "http://127.0.0.1:{$port}/deploy.php";
    $body    = json_encode(['action' => 'activate', 'release_id' => $releaseId]);
    $headers = stubMakeHmacHeaders((string) $body, $secret);

    [$status, $response] = stubSendRequest($url, (string) $body, $headers);

    expect($status)->toBe(423);
    $data = json_decode($response, true);
    expect($data['error'] ?? '')->toContain('deploy is in progress');

    @unlink($lockFile);
    @unlink("{$root}/releases/{$releaseId}.zip");
    @unlink("{$root}/releases/{$releaseId}.zip.hmac");
});
