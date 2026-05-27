<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskAuthException;
use Rkn\Cms\Deploy\PleskResponseException;
use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\FakeTransport;

describe('Client::xmlRpcCall()', function (): void {
    it('sends POST to XML-RPC agent endpoint', function (): void {
        $xmlResponse = file_get_contents(__DIR__ . '/../../../../Fixtures/plesk-xmlrpc/subscription-info-success.xml');
        $transport = new FakeTransport();
        $transport->queueResponse(200, $xmlResponse ?: '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        $client->xmlRpcCall('<packet version="1.6.9.0"><subscription><get/></subscription></packet>');

        $req = $transport->lastRequest();
        expect($req['method'])->toBe('POST');
        expect($req['url'])->toBe('https://plesk.example.com:8443/enterprise/control/agent.php');
    });

    it('sends API key in KEY header', function (): void {
        $xmlResponse = file_get_contents(__DIR__ . '/../../../../Fixtures/plesk-xmlrpc/subscription-info-success.xml');
        $transport = new FakeTransport();
        $transport->queueResponse(200, $xmlResponse ?: '');
        $client = new Client('https://plesk.example.com:8443', 'test-api-key', transport: $transport);

        $client->xmlRpcCall('<packet version="1.6.9.0"><subscription><get/></subscription></packet>');

        $req = $transport->lastRequest();
        expect($req['headers']['KEY'])->toBe('test-api-key');
    });

    it('returns decoded array on successful XML response', function (): void {
        $xmlResponse = file_get_contents(__DIR__ . '/../../../../Fixtures/plesk-xmlrpc/subscription-info-success.xml');
        $transport = new FakeTransport();
        $transport->queueResponse(200, $xmlResponse ?: '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        $result = $client->xmlRpcCall('<packet version="1.6.9.0"><subscription><get/></subscription></packet>');

        expect($result)->toBeArray();
        expect($result)->toHaveKey('subscription');
    });

    it('throws PleskAuthException on 401', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(401, '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->xmlRpcCall('<packet version="1.6.9.0"/>'))
            ->toThrow(PleskAuthException::class);
    });

    it('throws PleskResponseException on 500', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(500, '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->xmlRpcCall('<packet version="1.6.9.0"/>'))
            ->toThrow(PleskResponseException::class);
    });

    it('throws PleskResponseException on malformed XML', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, '<<NOT XML>>>');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->xmlRpcCall('<packet version="1.6.9.0"/>'))
            ->toThrow(PleskResponseException::class);
    });

    it('throws PleskResponseException on Plesk-level error in XML body', function (): void {
        $xmlResponse = file_get_contents(__DIR__ . '/../../../../Fixtures/plesk-xmlrpc/error-403.xml');
        $transport = new FakeTransport();
        $transport->queueResponse(200, $xmlResponse ?: '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->xmlRpcCall('<packet version="1.6.9.0"><subscription><get/></subscription></packet>'))
            ->toThrow(PleskResponseException::class);
    });
});
