<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApiException;
use Rkn\Cms\Deploy\PleskAuthException;
use Rkn\Cms\Deploy\PleskEndpointNotFoundException;
use Rkn\Cms\Deploy\PleskResponseException;
use Rkn\Cms\Deploy\PleskTransportException;
use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\FakeTransport;
use Rkn\Cms\Deploy\PleskApi\HttpResponse;

describe('Client::restGet()', function (): void {
    it('sends GET request to correct REST v2 URL', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, json_encode(['data' => []]) ?: '{}');
        $client = new Client('https://plesk.example.com:8443', 'testkey', transport: $transport);

        $client->restGet('domains');

        $req = $transport->lastRequest();
        expect($req)->not->toBeNull();
        expect($req['method'])->toBe('GET');
        expect($req['url'])->toBe('https://plesk.example.com:8443/api/v2/domains');
    });

    it('appends query params to the URL', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, json_encode(['data' => []]) ?: '{}');
        $client = new Client('https://plesk.example.com:8443', 'testkey', transport: $transport);

        $client->restGet('domains', ['name' => 'example.com']);

        $req = $transport->lastRequest();
        expect($req['url'])->toContain('name=example.com');
    });

    it('includes X-API-Key header', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, '[]');
        $client = new Client('https://plesk.example.com:8443', 'my-secret-key', transport: $transport);

        $client->restGet('domains');

        $req = $transport->lastRequest();
        expect($req['headers']['X-API-Key'])->toBe('my-secret-key');
    });

    it('returns decoded JSON array on 200', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, json_encode(['domains' => [['name' => 'example.com']]]) ?: '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        $result = $client->restGet('domains');

        expect($result)->toHaveKey('domains');
        expect($result['domains'][0]['name'])->toBe('example.com');
    });

    it('throws PleskAuthException on 401', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(401, json_encode(['message' => 'Invalid API key']) ?: '');
        $client = new Client('https://plesk.example.com:8443', 'badkey', transport: $transport);

        expect(fn () => $client->restGet('domains'))
            ->toThrow(PleskAuthException::class);
    });

    it('throws PleskAuthException on 403', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(403, json_encode(['message' => 'Forbidden']) ?: '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->restGet('domains'))
            ->toThrow(PleskAuthException::class);
    });

    it('throws PleskEndpointNotFoundException on 404', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(404, '{}');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->restGet('not-real-endpoint'))
            ->toThrow(PleskEndpointNotFoundException::class);
    });

    it('throws PleskResponseException on 500', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(500, json_encode(['message' => 'Internal error']) ?: '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->restGet('domains'))
            ->toThrow(PleskResponseException::class);
    });

    it('throws PleskResponseException on malformed JSON', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, 'NOT_JSON{{{');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->restGet('domains'))
            ->toThrow(PleskResponseException::class);
    });

    it('returns empty array on 200 with empty body', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        $result = $client->restGet('domains');

        expect($result)->toBe([]);
    });

    it('throws PleskTransportException on status 0', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(0, '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->restGet('domains'))
            ->toThrow(PleskTransportException::class);
    });

    it('PleskAuthException is a subclass of PleskApiException', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(401, '{}');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->restGet('domains'))
            ->toThrow(PleskApiException::class);
    });

    it('PleskEndpointNotFoundException carries status code 404', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(404, '{}');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        try {
            $client->restGet('not-real-endpoint');
        } catch (PleskEndpointNotFoundException $e) {
            expect($e->getStatusCode())->toBe(404);
        }
    });
});

describe('Client::restPost()', function (): void {
    it('sends POST with JSON body', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, '{}');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        $client->restPost('auth/keys', ['description' => 'deploy-key']);

        $req = $transport->lastRequest();
        expect($req['method'])->toBe('POST');
        $decoded = json_decode($req['body'], true);
        expect($decoded['description'])->toBe('deploy-key');
    });
});

describe('Client::xmlRpcCall()', function (): void {
    it('sends POST to XML-RPC agent endpoint', function (): void {
        $xmlResponse = file_get_contents(__DIR__ . '/../../../Fixtures/plesk-xmlrpc/subscription-info-success.xml');
        $transport = new FakeTransport();
        $transport->queueResponse(200, $xmlResponse ?: '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        $client->xmlRpcCall('<packet version="1.6.9.0"><subscription><get/></subscription></packet>');

        $req = $transport->lastRequest();
        expect($req['method'])->toBe('POST');
        expect($req['url'])->toBe('https://plesk.example.com:8443/enterprise/control/agent.php');
    });

    it('sends API key in KEY header', function (): void {
        $xmlResponse = file_get_contents(__DIR__ . '/../../../Fixtures/plesk-xmlrpc/subscription-info-success.xml');
        $transport = new FakeTransport();
        $transport->queueResponse(200, $xmlResponse ?: '');
        $client = new Client('https://plesk.example.com:8443', 'test-api-key', transport: $transport);

        $client->xmlRpcCall('<packet version="1.6.9.0"><subscription><get/></subscription></packet>');

        $req = $transport->lastRequest();
        expect($req['headers']['KEY'])->toBe('test-api-key');
    });

    it('returns decoded array on successful XML response', function (): void {
        $xmlResponse = file_get_contents(__DIR__ . '/../../../Fixtures/plesk-xmlrpc/subscription-info-success.xml');
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
        $xmlResponse = file_get_contents(__DIR__ . '/../../../Fixtures/plesk-xmlrpc/error-403.xml');
        $transport = new FakeTransport();
        $transport->queueResponse(200, $xmlResponse ?: '');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->xmlRpcCall('<packet version="1.6.9.0"><subscription><get/></subscription></packet>'))
            ->toThrow(PleskResponseException::class);
    });
});

describe('Client host normalization', function (): void {
    it('strips trailing slash from host', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, '{}');
        $client = new Client('https://plesk.example.com:8443/', 'key', transport: $transport);

        $client->restGet('domains');

        $req = $transport->lastRequest();
        expect($req['url'])->not->toContain('//api');
    });
});

describe('FakeTransport', function (): void {
    it('throws PleskTransportException when queue is empty', function (): void {
        $transport = new FakeTransport();
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        expect(fn () => $client->restGet('domains'))
            ->toThrow(PleskTransportException::class);
    });

    it('records all requests', function (): void {
        $transport = new FakeTransport();
        $transport->queueResponse(200, '{}');
        $transport->queueResponse(200, '{}');
        $client = new Client('https://plesk.example.com:8443', 'key', transport: $transport);

        $client->restGet('domains');
        $client->restGet('clients');

        expect($transport->requestCount())->toBe(2);
        expect($transport->recorded()[0]['url'])->toContain('domains');
        expect($transport->recorded()[1]['url'])->toContain('clients');
    });
});
