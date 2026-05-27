<?php

declare(strict_types=1);

use Tests\Helpers\DeployStubServer;

DeployStubServer::bootstrap();

it('stub: returns 403 when no signature header is provided', function () {
    $body = (string) json_encode(['action' => 'status']);

    [$status] = DeployStubServer::request($body, ['Content-Type: application/json']);

    expect($status)->toBe(403);
});

it('stub: returns 403 when signature is invalid', function () {
    $body = (string) json_encode(['action' => 'status']);
    $headers = [
        'X-Rakun-Signature: sha256=badhash',
        'X-Rakun-Timestamp: ' . time(),
        'Content-Type: application/json',
    ];

    [$status] = DeployStubServer::request($body, $headers);

    expect($status)->toBe(403);
});

it('stub: returns 403 when timestamp is +500s in the future', function () {
    $body   = (string) json_encode(['action' => 'status']);
    $future = time() + 500;
    $sig    = 'sha256=' . hash_hmac('sha256', $body, DeployStubServer::secret());

    $headers = [
        "X-Rakun-Signature: {$sig}",
        "X-Rakun-Timestamp: {$future}",
        'Content-Type: application/json',
    ];

    [$status] = DeployStubServer::request($body, $headers);

    expect($status)->toBe(403);
});

it('stub: returns 403 when timestamp is -500s in the past', function () {
    $body = (string) json_encode(['action' => 'status']);
    $past = time() - 500;
    $sig  = 'sha256=' . hash_hmac('sha256', $body, DeployStubServer::secret());

    $headers = [
        "X-Rakun-Signature: {$sig}",
        "X-Rakun-Timestamp: {$past}",
        'Content-Type: application/json',
    ];

    [$status] = DeployStubServer::request($body, $headers);

    expect($status)->toBe(403);
});
