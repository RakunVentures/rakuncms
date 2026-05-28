<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\FakeTransport;
use Rkn\Cms\Deploy\PleskApi\Inspector;
use Rkn\Cms\Deploy\PleskApi\Provisioner;
use Rkn\Cms\Deploy\PleskApiException;

$fixturesDir = __DIR__ . '/../../../Fixtures/plesk-rest';

if (!function_exists('cliBody')) {
    function cliBody(string $stdout, int $code = 0, string $stderr = ''): string
    {
        return (string) json_encode([
            'code' => $code,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ]);
    }
}

function makeProvisioner(): array
{
    $transport = new FakeTransport();
    $client = new Client(
        'https://plesk.test:8443',
        'key',
        transport: $transport,
        sleeper: static fn (int $s) => null,
    );
    $inspector = new Inspector($client);
    return [new Provisioner($client, $inspector), $transport];
}

describe('Provisioner::enableShellAccess()', function () use ($fixturesDir): void {
    it('is a no-op when shell is already /bin/bash', function () use ($fixturesDir): void {
        [$provisioner, $transport] = makeProvisioner();
        $stdout = (string) file_get_contents("{$fixturesDir}/cli-domain-info-bash.txt");

        $transport->queueResponse(200, cliBody($stdout));

        expect($provisioner->enableShellAccess('xyz.rkn.mx'))->toBeTrue();
        expect($transport->requestCount())->toBe(1);
    });

    it('invokes subscription --update-php when shell is disabled', function () use ($fixturesDir): void {
        [$provisioner, $transport] = makeProvisioner();
        $stdoutDisabled = (string) file_get_contents("{$fixturesDir}/cli-domain-info-noshell.txt");

        $transport->queueResponse(200, cliBody($stdoutDisabled));
        $transport->queueResponse(200, cliBody(''));

        expect($provisioner->enableShellAccess('noshell.example.com'))->toBeTrue();
        expect($transport->requestCount())->toBe(2);

        $mutating = $transport->recorded()[1];
        expect($mutating['url'])->toContain('/api/v2/cli/subscription/call');
        $body = json_decode($mutating['body'], true);
        expect($body['params'])->toBe(['--update-php', 'noshell.example.com', '-shell', '/bin/bash']);
    });

    it('throws PleskApiException when CLI exits non-zero', function () use ($fixturesDir): void {
        [$provisioner, $transport] = makeProvisioner();
        $stdoutDisabled = (string) file_get_contents("{$fixturesDir}/cli-domain-info-noshell.txt");

        $transport->queueResponse(200, cliBody($stdoutDisabled));
        $transport->queueResponse(200, cliBody('', 2, 'subscription not found'));

        expect(fn () => $provisioner->enableShellAccess('noshell.example.com'))
            ->toThrow(PleskApiException::class);
    });
});

describe('Provisioner::createGitRepo()', function () use ($fixturesDir): void {
    it('returns existing repo info when name matches', function () use ($fixturesDir): void {
        [$provisioner, $transport] = makeProvisioner();
        $listStdout = (string) file_get_contents("{$fixturesDir}/cli-git-list-one.txt");
        $infoStdout = (string) file_get_contents("{$fixturesDir}/cli-git-info.txt");

        $transport->queueResponse(200, cliBody($listStdout));
        $transport->queueResponse(200, cliBody($infoStdout));

        $info = $provisioner->createGitRepo('xyz.rkn.mx', 'rakuncms.git');

        expect($info['repo_name'])->toBe('rakuncms.git');
        expect($info['active_branch'])->toBe('main');
        expect($transport->requestCount())->toBe(2);
    });

    it('creates a new repo when none exists', function () use ($fixturesDir): void {
        [$provisioner, $transport] = makeProvisioner();
        $emptyStdout = (string) file_get_contents("{$fixturesDir}/cli-git-list-empty.txt");
        $listStdout = (string) file_get_contents("{$fixturesDir}/cli-git-list-one.txt");
        $infoStdout = (string) file_get_contents("{$fixturesDir}/cli-git-info.txt");

        $transport->queueResponse(200, cliBody($emptyStdout));   // Inspector::getGitInfo list (empty)
        $transport->queueResponse(200, cliBody(''));              // create-repo CLI call
        $transport->queueResponse(200, cliBody($listStdout));    // Inspector::getGitInfo list (after create)
        $transport->queueResponse(200, cliBody($infoStdout));    // Inspector::getGitInfo info

        $info = $provisioner->createGitRepo(
            'xyz.rkn.mx',
            'rakuncms.git',
            '/var/www/vhosts/xyz.rkn.mx/httpdocs',
        );

        expect($info['repo_name'])->toBe('rakuncms.git');

        $create = $transport->recorded()[1];
        expect($create['url'])->toContain('/api/v2/cli/extension/call');
        $createBody = json_decode($create['body'], true);
        expect($createBody['params'])->toBe([
            '--call', 'git',
            '--create-repo',
            '-domain', 'xyz.rkn.mx',
            '-repo', 'rakuncms.git',
            '-deploy-mode', 'automatic',
            '-deploy-path', '/var/www/vhosts/xyz.rkn.mx/httpdocs',
        ]);
    });
});

describe('Provisioner::createFtpSubaccount()', function (): void {
    it('returns existing user when login already exists for the domain', function (): void {
        [$provisioner, $transport] = makeProvisioner();
        $transport->queueResponse(200, (string) json_encode([
            ['login' => 'deploy', 'domain_id' => 13, 'home' => '/httpdocs'],
        ]));

        $user = $provisioner->createFtpSubaccount(13, 'deploy', 'irrelevant', '/httpdocs');

        expect($user['created'])->toBeFalse();
        expect($user['login'])->toBe('deploy');
        expect($transport->requestCount())->toBe(1);
    });

    it('creates a new user via POST /ftpusers when login is absent', function (): void {
        [$provisioner, $transport] = makeProvisioner();
        $transport->queueResponse(200, '[]');
        $transport->queueResponse(201, (string) json_encode([
            'login' => 'deploy',
            'home' => '/httpdocs',
            'id' => 99,
        ]));

        $user = $provisioner->createFtpSubaccount(13, 'deploy', 's3cret!', '/httpdocs');

        expect($user['created'])->toBeTrue();
        expect($user['login'])->toBe('deploy');

        $create = $transport->recorded()[1];
        expect($create['method'])->toBe('POST');
        expect($create['url'])->toContain('/api/v2/ftpusers');
        $body = json_decode($create['body'], true);
        expect($body)->toBe([
            'name' => 'deploy',
            'password' => 's3cret!',
            'home' => '/httpdocs',
            'parent_domain' => ['id' => 13],
            'permissions' => ['read' => true, 'write' => true],
        ]);
    });
});
