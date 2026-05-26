<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\FakeTransport;
use Rkn\Cms\Deploy\PleskApi\Inspector;

$fixturesDir = __DIR__ . '/../../../Fixtures/plesk-xmlrpc';

function makeInspector(string ...$xmlFiles): array
{
    $transport = new FakeTransport();
    foreach ($xmlFiles as $xmlFile) {
        $body = file_get_contents($xmlFile);
        $transport->queueResponse(200, $body ?: '');
    }
    $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
    return [$client, new Inspector($client), $transport];
}

describe('Inspector::hasShellAccess()', function () use ($fixturesDir): void {
    it('returns true when shell is /bin/bash', function () use ($fixturesDir): void {
        [, $inspector] = makeInspector("{$fixturesDir}/subscription-info-success.xml");

        expect($inspector->hasShellAccess('example.com'))->toBeTrue();
    });

    it('returns false when shell is /sbin/nologin', function () use ($fixturesDir): void {
        [, $inspector] = makeInspector("{$fixturesDir}/subscription-info-no-shell.xml");

        expect($inspector->hasShellAccess('noshell.com'))->toBeFalse();
    });

    it('returns null when transport fails', function (): void {
        $transport = new FakeTransport(); // Empty queue → transport error
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);

        // FakeTransport throws PleskTransportException which is a PleskApiException
        // Inspector catches \Throwable and returns null
        expect($inspector->hasShellAccess('example.com'))->toBeNull();
    });

    it('returns null when XML response contains Plesk error (403)', function () use ($fixturesDir): void {
        $transport = new FakeTransport();
        $xml = file_get_contents("{$fixturesDir}/error-403.xml");
        $transport->queueResponse(200, $xml ?: '');
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);

        // The Plesk error XML causes PleskResponseException inside Client::xmlRpcCall
        // Inspector returns null for any PleskApiException
        expect($inspector->hasShellAccess('example.com'))->toBeNull();
    });
});

describe('Inspector::getGitInfo()', function () use ($fixturesDir): void {
    it('returns null when no repos exist (empty stdout)', function () use ($fixturesDir): void {
        [, $inspector] = makeInspector("{$fixturesDir}/git-list-empty.xml");

        expect($inspector->getGitInfo('example.com'))->toBeNull();
    });

    it('returns repo info with webhook when repo exists', function () use ($fixturesDir): void {
        [, $inspector] = makeInspector(
            "{$fixturesDir}/git-list-with-repo.xml",
            "{$fixturesDir}/git-info-with-webhook.xml",
        );

        $info = $inspector->getGitInfo('example.com');

        expect($info)->not->toBeNull();
        expect($info['repo_name'])->toBe('website');
        expect($info['active_branch'])->toBe('main');
        expect($info['webhook_url'])->toContain('abc123token');
        expect($info['deploy_path'])->toContain('/httpdocs');
    });

    it('returns null on transport failure', function (): void {
        $transport = new FakeTransport();
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);

        expect($inspector->getGitInfo('example.com'))->toBeNull();
    });
});

describe('Inspector::getDocumentRoot()', function () use ($fixturesDir): void {
    it('returns the www_root path from domain/get', function () use ($fixturesDir): void {
        [, $inspector] = makeInspector("{$fixturesDir}/domain-get-php-fpm.xml");

        $root = $inspector->getDocumentRoot('example.com');

        expect($root)->toBe('/var/www/vhosts/example.com/httpdocs');
    });

    it('returns /httpdocs as fallback on transport failure', function (): void {
        $transport = new FakeTransport();
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);

        expect($inspector->getDocumentRoot('example.com'))->toBe('/httpdocs');
    });

    it('returns custom document root', function () use ($fixturesDir): void {
        [, $inspector] = makeInspector("{$fixturesDir}/domain-get-custom-root.xml");

        $root = $inspector->getDocumentRoot('custom.com');

        expect($root)->toBe('/var/www/vhosts/custom.com/public');
    });
});

describe('Inspector::getPhpInfo()', function () use ($fixturesDir): void {
    it('returns PHP 8.2 fpm info for plesk-php82-fpm handler', function () use ($fixturesDir): void {
        [, $inspector] = makeInspector("{$fixturesDir}/domain-get-php-fpm.xml");

        $info = $inspector->getPhpInfo('example.com');

        expect($info)->not->toBeNull();
        expect($info['version'])->toBe('8.2');
        expect($info['handler'])->toBe('fpm');
    });

    it('returns PHP 7.4 fpm info for legacy handler', function () use ($fixturesDir): void {
        [, $inspector] = makeInspector("{$fixturesDir}/domain-get-php74-legacy.xml");

        $info = $inspector->getPhpInfo('legacy.com');

        expect($info)->not->toBeNull();
        expect($info['version'])->toBe('7.4');
        expect($info['handler'])->toBe('fpm');
    });

    it('returns null on transport failure', function (): void {
        $transport = new FakeTransport();
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);

        expect($inspector->getPhpInfo('example.com'))->toBeNull();
    });
});

describe('Inspector::discover()', function () use ($fixturesDir): void {
    it('returns full discovery snapshot with all fields present', function () use ($fixturesDir): void {
        [, $inspector] = makeInspector(
            "{$fixturesDir}/subscription-info-success.xml",  // hasShellAccess
            "{$fixturesDir}/git-list-with-repo.xml",         // getGitInfo (list)
            "{$fixturesDir}/git-info-with-webhook.xml",      // getGitInfo (info)
            "{$fixturesDir}/domain-get-php-fpm.xml",         // getPhpInfo
            "{$fixturesDir}/domain-get-php-fpm.xml",         // getDocumentRoot
        );

        $result = $inspector->discover('example.com');

        expect($result)->toHaveKey('domain');
        expect($result['domain'])->toBe('example.com');
        expect($result['has_shell'])->toBeTrue();
        expect($result['git'])->not->toBeNull();
        expect($result['php'])->not->toBeNull();
        expect($result['doc_root'])->toBe('/var/www/vhosts/example.com/httpdocs');
        expect($result['discovered_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
    });

    it('returns degraded snapshot when shell check fails', function () use ($fixturesDir): void {
        // Only provide responses for git+php+docroot; shell check gets transport error
        $transport = new FakeTransport();
        // Queue: hasShellAccess (fails - empty), getGitInfo list, getGitInfo info, getPhpInfo, getDocumentRoot
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/git-list-empty.xml") ?: '');
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/domain-get-php-fpm.xml") ?: '');
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/domain-get-php-fpm.xml") ?: '');

        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);

        $result = $inspector->discover('example.com');

        // has_shell will be null (first call fails with empty transport)
        expect($result['has_shell'])->toBeNull();
        expect($result)->toHaveKey('discovered_at');
    });

    it('includes discovered_at as ISO-8601', function () use ($fixturesDir): void {
        [, $inspector] = makeInspector(
            "{$fixturesDir}/subscription-info-success.xml",
            "{$fixturesDir}/git-list-empty.xml",
            "{$fixturesDir}/domain-get-php-fpm.xml",
            "{$fixturesDir}/domain-get-php-fpm.xml",
        );

        $result = $inspector->discover('example.com');
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $result['discovered_at']);
        expect($dt)->not->toBeFalse();
    });
});
