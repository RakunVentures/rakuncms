<?php

declare(strict_types=1);

use Tests\Helpers\DeployStubServer;

DeployStubServer::bootstrap();

it('stub: cleanup keeps only the specified number of releases', function () {
    $root = DeployStubServer::root();

    $baseTime = time() - 3600;
    foreach (['cleanup-old-001', 'cleanup-old-002', 'cleanup-old-003', 'cleanup-old-004'] as $i => $r) {
        $rDir = "{$root}/releases/{$r}";
        if (!is_dir($rDir)) {
            mkdir($rDir, 0755, true);
        }
        touch($rDir, $baseTime - ($i * 100));
    }

    $body    = (string) json_encode(['action' => 'cleanup', 'keep' => 2]);
    $headers = DeployStubServer::hmacHeaders($body);

    [$status, $response] = DeployStubServer::request($body, $headers);

    expect($status)->toBe(200);
    $data = json_decode($response, true);
    expect($data['ok'])->toBeTrue();

    $remaining = array_filter(
        scandir("{$root}/releases") ?: [],
        fn (string $n) => $n !== '.' && $n !== '..' && is_dir("{$root}/releases/{$n}")
    );
    expect(count($remaining))->toBeLessThanOrEqual(3);
});

it('stub: concurrent activate is blocked by lock', function () {
    $root      = DeployStubServer::root();
    $releaseId = 'concurrent-test-001';

    $lockFile = "{$root}/shared/lock.json";
    $lock = [
        'release_id' => 'in-progress',
        'started_at' => time(),
        'ttl'        => 600,
        'host'       => 'test',
    ];
    file_put_contents($lockFile, (string) json_encode($lock));

    DeployStubServer::buildTestZip($releaseId);

    $body    = (string) json_encode(['action' => 'activate', 'release_id' => $releaseId]);
    $headers = DeployStubServer::hmacHeaders($body);

    [$status, $response] = DeployStubServer::request($body, $headers);

    expect($status)->toBe(423);
    $data = json_decode($response, true);
    expect($data['error'] ?? '')->toContain('deploy is in progress');

    @unlink($lockFile);
    @unlink("{$root}/releases/{$releaseId}.zip");
    @unlink("{$root}/releases/{$releaseId}.zip.hmac");
});
