<?php

declare(strict_types=1);

use Tests\Helpers\DeployStubServer;

DeployStubServer::bootstrap();

it('stub: activate succeeds and updates current symlink', function () {
    $releaseId = '2026-05-26_143022_a1b2c3d';

    DeployStubServer::clearLock();
    DeployStubServer::buildTestZip($releaseId);

    $body    = (string) json_encode(['action' => 'activate', 'release_id' => $releaseId]);
    $headers = DeployStubServer::hmacHeaders($body);

    [$status, $response] = DeployStubServer::request($body, $headers);

    expect($status)->toBe(200);
    $data = json_decode($response, true);
    expect($data['ok'])->toBeTrue()
        ->and($data['release'])->toBe($releaseId);

    $root = DeployStubServer::root();
    expect(is_link("{$root}/current"))->toBeTrue()
        ->and(basename((string) readlink("{$root}/current")))->toBe($releaseId);
});

it('stub: activate rejects a ZIP with zip-slip path traversal (leading ../)', function () {
    $root      = DeployStubServer::root();
    $secret    = DeployStubServer::secret();
    $releaseId = 'zipslip-test-001';
    $zipPath   = "{$root}/releases/{$releaseId}.zip";
    $hmacPath  = "{$root}/releases/{$releaseId}.zip.hmac";

    DeployStubServer::clearLock();

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('../../../etc/passwd', 'evil');
    $zip->close();

    $hmac = hash_hmac('sha256', (string) file_get_contents($zipPath), $secret);
    file_put_contents($hmacPath, $hmac);

    $body    = (string) json_encode(['action' => 'activate', 'release_id' => $releaseId]);
    $headers = DeployStubServer::hmacHeaders($body);

    [$status, $response] = DeployStubServer::request($body, $headers);

    expect($status)->toBe(400);
    $data = json_decode($response, true);
    expect($data['error'] ?? '')->not->toBeEmpty();

    @unlink($zipPath);
    @unlink($hmacPath);
});

it('stub: activate rejects a ZIP with corrupted HMAC', function () {
    $root      = DeployStubServer::root();
    $releaseId = 'bad-hmac-test-001';
    $zipPath   = "{$root}/releases/{$releaseId}.zip";
    $hmacPath  = "{$root}/releases/{$releaseId}.zip.hmac";

    DeployStubServer::clearLock();

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('index.php', '<?php echo "ok";');
    $zip->close();

    file_put_contents($hmacPath, 'wronghmacvalue');

    $body    = (string) json_encode(['action' => 'activate', 'release_id' => $releaseId]);
    $headers = DeployStubServer::hmacHeaders($body);

    [$status, $response] = DeployStubServer::request($body, $headers);

    expect($status)->toBe(400);
    $data = json_decode($response, true);
    expect($data['error'] ?? '')->toContain('HMAC');

    @unlink($zipPath);
    @unlink($hmacPath);
});
