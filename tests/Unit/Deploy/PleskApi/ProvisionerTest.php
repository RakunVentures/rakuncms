<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\FakeTransport;
use Rkn\Cms\Deploy\PleskApi\Inspector;
use Rkn\Cms\Deploy\PleskApi\Provisioner;

$fixturesDir = __DIR__ . '/../../../Fixtures/plesk-xmlrpc';

/**
 * Build a Provisioner with a FakeTransport.
 * xmlResponses are queued in order.
 */
function makeProvisioner(array $xmlFiles): array
{
    $transport = new FakeTransport();
    foreach ($xmlFiles as $xmlFile) {
        $content = is_string($xmlFile) ? file_get_contents($xmlFile) : false;
        $transport->queueResponse(200, $content ?: '');
    }
    $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
    $inspector = new Inspector($client);
    $provisioner = new Provisioner($client, $inspector);
    return [$provisioner, $transport];
}

/**
 * Build a simple OK XML-RPC response for mutation calls (enableShell, createGitRepo, etc.).
 */
function okExtensionXml(): string
{
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <packet version="1.6.9.0">
          <extension>
            <call>
              <result>
                <status>ok</status>
                <stdout></stdout>
                <stderr></stderr>
                <exitcode>0</exitcode>
              </result>
            </call>
          </extension>
        </packet>
        XML;
}

function okDomainSetXml(): string
{
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <packet version="1.6.9.0">
          <domain>
            <set>
              <result>
                <status>ok</status>
              </result>
            </set>
          </domain>
        </packet>
        XML;
}

describe('Provisioner::enableShell()', function () use ($fixturesDir): void {
    it('returns true immediately when shell is already enabled (idempotent)', function () use ($fixturesDir): void {
        $transport = new FakeTransport();
        // hasShellAccess → subscription-info-success.xml (shell = /bin/bash → true)
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/subscription-info-success.xml") ?: '');
        // No further calls should be made
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);
        $provisioner = new Provisioner($client, $inspector);

        $result = $provisioner->enableShell('example.com');

        expect($result)->toBeTrue();
        // Only 1 call was made (the inspection), no mutation
        expect($transport->requestCount())->toBe(1);
    });

    it('makes mutation call when shell is disabled', function () use ($fixturesDir): void {
        $transport = new FakeTransport();
        // hasShellAccess → no-shell (returns false)
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/subscription-info-no-shell.xml") ?: '');
        // domain/set call for enabling shell
        $transport->queueResponse(200, okDomainSetXml());
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);
        $provisioner = new Provisioner($client, $inspector);

        $result = $provisioner->enableShell('noshell.com');

        expect($result)->toBeTrue();
        expect($transport->requestCount())->toBe(2);
    });

    it('attempts mutation when shell state is unknown (null)', function () use ($fixturesDir): void {
        $transport = new FakeTransport();
        // hasShellAccess → domain/set response returned to subscription/get → can't find shell → returns null
        // then the mutation call → also gets a domain/set response
        $transport->queueResponse(200, okDomainSetXml());  // consumed by hasShellAccess (returns null)
        $transport->queueResponse(200, okDomainSetXml());  // consumed by enableShell mutation
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);
        $provisioner = new Provisioner($client, $inspector);

        $result = $provisioner->enableShell('unknown.com');

        expect($result)->toBeTrue();
        expect($transport->requestCount())->toBe(2);
    });

    it('is idempotent: calling twice when shell is already enabled makes only 1 API call each time', function () use ($fixturesDir): void {
        $transport = new FakeTransport();
        // Two inspection calls, each returning shell=enabled
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/subscription-info-success.xml") ?: '');
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/subscription-info-success.xml") ?: '');
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);
        $provisioner = new Provisioner($client, $inspector);

        $provisioner->enableShell('example.com');
        $provisioner->enableShell('example.com');

        // 2 calls total (1 inspection each), 0 mutations
        expect($transport->requestCount())->toBe(2);
    });
});

describe('Provisioner::createGitRepo()', function () use ($fixturesDir): void {
    it('returns existing repo info when repo already exists (idempotent)', function () use ($fixturesDir): void {
        $transport = new FakeTransport();
        // getGitInfo: list → repo found, info → full info
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/git-list-with-repo.xml") ?: '');
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/git-info-with-webhook.xml") ?: '');
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);
        $provisioner = new Provisioner($client, $inspector);

        $info = $provisioner->createGitRepo('example.com', 'website');

        expect($info['repo_name'])->toBe('website');
        // No create call was made
        expect($transport->requestCount())->toBe(2);
    });

    it('creates repo when none exists', function () use ($fixturesDir): void {
        $transport = new FakeTransport();
        // getGitInfo (list) → empty → null
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/git-list-empty.xml") ?: '');
        // create call
        $transport->queueResponse(200, okExtensionXml());
        // update deploy-mode call
        $transport->queueResponse(200, okExtensionXml());
        // getGitInfo after creation (list) → now has repo
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/git-list-with-repo.xml") ?: '');
        // getGitInfo after creation (info)
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/git-info-with-webhook.xml") ?: '');
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);
        $provisioner = new Provisioner($client, $inspector);

        $info = $provisioner->createGitRepo('example.com', 'website');

        expect($info['repo_name'])->toBe('website');
        // 5 calls: check + create + update-mode + re-check list + re-check info
        expect($transport->requestCount())->toBe(5);
    });

    it('returns minimal info when post-creation getGitInfo returns null', function () use ($fixturesDir): void {
        $transport = new FakeTransport();
        // getGitInfo (initial check) → empty
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/git-list-empty.xml") ?: '');
        // create
        $transport->queueResponse(200, okExtensionXml());
        // deploy-mode update
        $transport->queueResponse(200, okExtensionXml());
        // getGitInfo after (empty again — simulates discovery not yet available)
        $transport->queueResponse(200, file_get_contents("{$fixturesDir}/git-list-empty.xml") ?: '');
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);
        $provisioner = new Provisioner($client, $inspector);

        $info = $provisioner->createGitRepo('example.com', 'website', '/httpdocs');

        // Fallback minimal info is returned
        expect($info['repo_name'])->toBe('website');
        expect($info['deploy_path'])->toBe('/httpdocs');
    });
});

describe('Provisioner::setDeployActions()', function () use ($fixturesDir): void {
    it('returns true on successful set', function () use ($fixturesDir): void {
        $transport = new FakeTransport();
        // setDeployActions only makes one call (the update)
        $transport->queueResponse(200, okExtensionXml());
        $client = new Client('https://plesk.test:8443', 'key', transport: $transport);
        $inspector = new Inspector($client);
        $provisioner = new Provisioner($client, $inspector);

        $result = $provisioner->setDeployActions('example.com', 'website', 'composer install');

        expect($result)->toBeTrue();
        expect($transport->requestCount())->toBe(1);
    });
});
