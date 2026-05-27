<?php

declare(strict_types=1);

use Tests\Helpers\DeployStubServer;

DeployStubServer::bootstrap();

it('stub: ping returns 200 with valid HMAC', function () {
    $body    = (string) json_encode(['action' => 'ping']);
    $headers = DeployStubServer::hmacHeaders($body);

    [$status, $response] = DeployStubServer::request($body, $headers);

    expect($status)->toBe(200);
    $data = json_decode($response, true);
    expect($data['ok'])->toBeTrue()
        ->and($data['version'])->toBe(2);
});

it('stub: status returns 200 with release info', function () {
    $body    = (string) json_encode(['action' => 'status']);
    $headers = DeployStubServer::hmacHeaders($body);

    [$status, $response] = DeployStubServer::request($body, $headers);

    expect($status)->toBe(200);
    $data = json_decode($response, true);
    expect($data['ok'])->toBeTrue()
        ->and(array_key_exists('releases', $data))->toBeTrue();
});
