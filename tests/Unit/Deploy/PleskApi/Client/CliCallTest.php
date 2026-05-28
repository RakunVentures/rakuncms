<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\CliResult;
use Rkn\Cms\Deploy\PleskApi\FakeTransport;
use Rkn\Cms\Deploy\PleskAuthException;

describe('Client::cliCall()', function (): void {
    it('POSTs to /api/v2/cli/{id}/call with {"params": [...]} body', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, (string) json_encode([
            'code' => 0,
            'stdout' => 'ok',
            'stderr' => '',
        ]));
        $client = new Client(
            'https://plesk.example.com',
            'key',
            transport: $transport,
            sleeper: static fn () => null,
        );

        $client->cliCall('domain', ['--info', 'xyz.rkn.mx']);

        $req = $transport->lastRequest();
        expect($req['method'])->toBe('POST');
        expect($req['url'])->toBe('https://plesk.example.com/api/v2/cli/domain/call');
        $body = json_decode($req['body'], true);
        expect($body)->toBe(['params' => ['--info', 'xyz.rkn.mx']]);
    });

    it('returns a CliResult with code/stdout/stderr', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, (string) json_encode([
            'code' => 0,
            'stdout' => 'Domain info: ...',
            'stderr' => '',
        ]));
        $client = new Client(
            'https://plesk.example.com',
            'key',
            transport: $transport,
            sleeper: static fn () => null,
        );

        $result = $client->cliCall('domain', ['--info', 'xyz.rkn.mx']);

        expect($result)->toBeInstanceOf(CliResult::class);
        expect($result->code)->toBe(0);
        expect($result->stdout)->toBe('Domain info: ...');
        expect($result->isSuccess())->toBeTrue();
    });

    it('does NOT throw when CLI exit code is non-zero', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, (string) json_encode([
            'code' => 1,
            'stdout' => '',
            'stderr' => 'Domain not found',
        ]));
        $client = new Client(
            'https://plesk.example.com',
            'key',
            transport: $transport,
            sleeper: static fn () => null,
        );

        $result = $client->cliCall('domain', ['--info', 'missing.com']);

        expect($result->code)->toBe(1);
        expect($result->stderr)->toBe('Domain not found');
        expect($result->isSuccess())->toBeFalse();
    });

    it('escapes commandId for URL', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, (string) json_encode(['code' => 0, 'stdout' => '', 'stderr' => '']));
        $client = new Client(
            'https://plesk.example.com',
            'key',
            transport: $transport,
            sleeper: static fn () => null,
        );

        $client->cliCall('php_handler', ['--list']);

        expect($transport->lastRequest()['url'])
            ->toBe('https://plesk.example.com/api/v2/cli/php_handler/call');
    });
});

describe('Client retry-on-401-empty (D7)', function (): void {
    it('retries up to 3 times when body is empty on 401, then throws PleskAuthException', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(401, '');
        $transport->queueResponse(401, '');
        $transport->queueResponse(401, '');
        $client = new Client(
            'https://plesk.example.com',
            'key',
            transport: $transport,
            sleeper: static fn () => null,
        );

        expect(fn () => $client->restGet('server'))
            ->toThrow(PleskAuthException::class);
        expect($transport->requestCount())->toBe(3);
    });

    it('recovers when retry succeeds before exhaustion', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(401, '');
        $transport->queueResponse(200, (string) json_encode(['ok' => true]));
        $client = new Client(
            'https://plesk.example.com',
            'key',
            transport: $transport,
            sleeper: static fn () => null,
        );

        $result = $client->restGet('server');

        expect($result)->toBe(['ok' => true]);
        expect($transport->requestCount())->toBe(2);
    });

    it('does NOT retry when 401 carries a non-empty body (real auth failure)', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(401, (string) json_encode(['message' => 'Invalid API key']));
        $client = new Client(
            'https://plesk.example.com',
            'badkey',
            transport: $transport,
            sleeper: static fn () => null,
        );

        expect(fn () => $client->restGet('server'))
            ->toThrow(PleskAuthException::class);
        expect($transport->requestCount())->toBe(1);
    });
});

describe('Client::withBasicAuth()', function (): void {
    it('sends Authorization: Basic instead of X-API-Key', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(201, (string) json_encode(['key' => 'newkey-uuid']));

        $client = Client::withBasicAuth(
            'https://plesk.example.com',
            'admin',
            's3cret',
            transport: $transport,
            sleeper: static fn () => null,
        );

        $client->restPost('auth/keys', ['description' => 'rakuncms-prod']);

        $req = $transport->lastRequest();
        $expected = 'Basic ' . base64_encode('admin:s3cret');
        expect($req['headers']['Authorization'] ?? null)->toBe($expected);
        expect($req['headers']['X-API-Key'] ?? null)->toBeNull();
    });
});
