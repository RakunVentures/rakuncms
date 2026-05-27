<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\Drivers\FtpDriver;
use Rkn\Cms\Deploy\HealthChecker;
use Tests\Helpers\DeployStubServer;

DeployStubServer::bootstrap();

it('stub: rollback reverts to a previous release', function () {
    $root     = DeployStubServer::root();
    $release1 = 'rb-release-001aaa';
    $release2 = 'rb-release-002bbb';

    DeployStubServer::clearLock();
    DeployStubServer::buildTestZip($release1);
    DeployStubServer::buildTestZip($release2);

    $body    = (string) json_encode(['action' => 'activate', 'release_id' => $release1]);
    $headers = DeployStubServer::hmacHeaders($body);
    [$s1] = DeployStubServer::request($body, $headers);
    expect($s1)->toBe(200, "First activate of {$release1} failed");

    DeployStubServer::clearLock();

    $body    = (string) json_encode(['action' => 'activate', 'release_id' => $release2]);
    $headers = DeployStubServer::hmacHeaders($body);
    [$s2, $r2] = DeployStubServer::request($body, $headers);
    expect($s2)->toBe(200, "Second activate of {$release2} failed: {$r2}");

    expect(basename((string) readlink("{$root}/current")))->toBe($release2);

    $body    = (string) json_encode(['action' => 'rollback', 'to' => $release1]);
    $headers = DeployStubServer::hmacHeaders($body);

    [$status, $response] = DeployStubServer::request($body, $headers);

    expect($status)->toBe(200);
    $data = json_decode($response, true);
    expect($data['ok'])->toBeTrue()
        ->and($data['rolled_to'])->toBe($release1);

    expect(basename((string) readlink("{$root}/current")))->toBe($release1);
});

it('FtpDriver: rollback() sends "to" field when rollbackTo is set on config', function () {
    $root     = DeployStubServer::root();
    $port     = DeployStubServer::port();
    $secret   = DeployStubServer::secret();
    $release1 = 'ftpdrv-rb-001';
    $release2 = 'ftpdrv-rb-002';

    DeployStubServer::clearLock();
    DeployStubServer::buildTestZip($release1);
    DeployStubServer::buildTestZip($release2);

    $body1    = (string) json_encode(['action' => 'activate', 'release_id' => $release1]);
    $headers1 = DeployStubServer::hmacHeaders($body1);
    [$s1]     = DeployStubServer::request($body1, $headers1);
    expect($s1)->toBe(200, "Activate {$release1} failed");

    DeployStubServer::clearLock();

    $body2    = (string) json_encode(['action' => 'activate', 'release_id' => $release2]);
    $headers2 = DeployStubServer::hmacHeaders($body2);
    [$s2, $r2] = DeployStubServer::request($body2, $headers2);
    expect($s2)->toBe(200, "Activate {$release2} failed: {$r2}");

    $config              = new DeployConfig();
    $config->environment = 'production';
    $config->method      = 'ftp';
    $config->strategy    = 'lean';
    $config->host        = "127.0.0.1:{$port}";
    $config->secure      = false;
    $config->deploySecret = $secret;
    $config->healthUrl   = "http://127.0.0.1:{$port}/health";
    $config->rollbackTo  = $release1;

    $messages = [];
    $logger   = function (string $m) use (&$messages): void {
        $messages[] = $m;
    };

    $driver = new FtpDriver($root);
    $result = $driver->rollback($config, $logger);

    expect($result)->toBeTrue();
    expect(basename((string) readlink("{$root}/current")))->toBe($release1);
    $allLogs = implode(' ', $messages);
    expect($allLogs)->toContain($release1);
});

it('FtpDriver: health-check failure triggers auto-rollback via rollback()', function () {
    $root    = DeployStubServer::root();
    $port    = DeployStubServer::port();
    $secret  = DeployStubServer::secret();
    $release = 'ftpdrv-autorollback-001';

    DeployStubServer::clearLock();
    DeployStubServer::buildTestZip($release);

    $body = (string) json_encode(['action' => 'activate', 'release_id' => $release]);
    [$s]  = DeployStubServer::request($body, DeployStubServer::hmacHeaders($body));
    expect($s)->toBe(200, 'Pre-condition: activate must succeed');

    $unhealthyChecker = new HealthChecker(
        retries: 0,
        backoffSec: [],
        verifySsl: false,
        timeoutSec: 3,
    );

    $config              = new DeployConfig();
    $config->environment = 'production';
    $config->method      = 'ftp';
    $config->strategy    = 'lean';
    $config->host        = "127.0.0.1:{$port}";
    $config->secure      = false;
    $config->deploySecret = $secret;
    $config->healthUrl   = "http://127.0.0.1:{$port}/no-health-endpoint";

    $healthResult = $unhealthyChecker->check($config->healthUrl, fn (string $m) => null);
    expect($healthResult)->toBeFalse();

    $driver = new FtpDriver($root);
    $rollbackResult = $driver->rollback($config, fn (string $m) => null);

    expect(is_bool($rollbackResult))->toBeTrue();
});
