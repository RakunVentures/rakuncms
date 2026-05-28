<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\FakeTransport;
use Rkn\Cms\Deploy\PleskApi\Inspector;

$fixturesDir = __DIR__ . '/../../../Fixtures/plesk-rest';

/**
 * Encode a CliResult JSON body as returned by POST /api/v2/cli/{id}/call.
 */
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

function makeInspector(): array
{
    $transport = new FakeTransport();
    $client = new Client(
        'https://plesk.test:8443',
        'key',
        transport: $transport,
        sleeper: static fn (int $s) => null,
    );
    return [new Inspector($client), $transport];
}

describe('Inspector::hasShellAccess()', function () use ($fixturesDir): void {
    it('returns true when SSH access is /bin/bash', function () use ($fixturesDir): void {
        [$inspector, $transport] = makeInspector();
        $stdout = (string) file_get_contents("{$fixturesDir}/cli-domain-info-bash.txt");
        $transport->queueResponse(200, cliBody($stdout));

        expect($inspector->hasShellAccess('xyz.rkn.mx'))->toBeTrue();
    });

    it('returns false when SSH access is /sbin/nologin', function () use ($fixturesDir): void {
        [$inspector, $transport] = makeInspector();
        $stdout = (string) file_get_contents("{$fixturesDir}/cli-domain-info-noshell.txt");
        $transport->queueResponse(200, cliBody($stdout));

        expect($inspector->hasShellAccess('noshell.example.com'))->toBeFalse();
    });

    it('returns null when CLI exits non-zero', function (): void {
        [$inspector, $transport] = makeInspector();
        $transport->queueResponse(200, cliBody('', 1, 'Domain not found'));

        expect($inspector->hasShellAccess('does-not-exist.com'))->toBeNull();
    });
});

describe('Inspector::getPhpInfo()', function () use ($fixturesDir): void {
    it('parses PHP version and handler', function () use ($fixturesDir): void {
        [$inspector, $transport] = makeInspector();
        $stdout = (string) file_get_contents("{$fixturesDir}/cli-domain-info-bash.txt");
        $transport->queueResponse(200, cliBody($stdout));

        $php = $inspector->getPhpInfo('xyz.rkn.mx');

        expect($php)->toBe(['version' => '8.2.15', 'handler' => 'fpm']);
    });
});

describe('Inspector::getGitInfo()', function () use ($fixturesDir): void {
    it('returns null when no repositories', function () use ($fixturesDir): void {
        [$inspector, $transport] = makeInspector();
        $emptyStdout = (string) file_get_contents("{$fixturesDir}/cli-git-list-empty.txt");
        $transport->queueResponse(200, cliBody($emptyStdout));

        expect($inspector->getGitInfo('xyz.rkn.mx'))->toBeNull();
    });

    it('returns full info when a repo exists', function () use ($fixturesDir): void {
        [$inspector, $transport] = makeInspector();
        $listStdout = (string) file_get_contents("{$fixturesDir}/cli-git-list-one.txt");
        $infoStdout = (string) file_get_contents("{$fixturesDir}/cli-git-info.txt");
        $transport->queueResponse(200, cliBody($listStdout));
        $transport->queueResponse(200, cliBody($infoStdout));

        $info = $inspector->getGitInfo('xyz.rkn.mx');

        expect($info)->toBe([
            'repo_name' => 'rakuncms.git',
            'webhook_url' => 'https://plesk.rakun.mx:8443/modules/git/public/web-hook.php?token=abc123',
            'active_branch' => 'main',
            'deploy_path' => '/var/www/vhosts/xyz.rkn.mx/httpdocs',
        ]);
    });
});

describe('Inspector::getDocumentRoot()', function () use ($fixturesDir): void {
    it('returns www_root from GET /domains/{id} when available', function (): void {
        [$inspector, $transport] = makeInspector();

        $transport->queueResponse(200, (string) json_encode([
            ['id' => 13, 'name' => 'xyz.rkn.mx'],
        ]));

        $transport->queueResponse(200, (string) json_encode([
            'id' => 13,
            'name' => 'xyz.rkn.mx',
            'www_root' => '/var/www/vhosts/xyz.rkn.mx/httpdocs',
        ]));

        expect($inspector->getDocumentRoot('xyz.rkn.mx'))
            ->toBe('/var/www/vhosts/xyz.rkn.mx/httpdocs');
    });

    it('falls back to CLI stdout Document root field when REST gives no docroot', function () use ($fixturesDir): void {
        [$inspector, $transport] = makeInspector();

        $transport->queueResponse(200, (string) json_encode([
            ['id' => 13, 'name' => 'xyz.rkn.mx'],
        ]));

        $transport->queueResponse(200, (string) json_encode([
            'id' => 13,
            'name' => 'xyz.rkn.mx',
        ]));

        $stdout = (string) file_get_contents("{$fixturesDir}/cli-domain-info-bash.txt");
        $transport->queueResponse(200, cliBody($stdout));

        expect($inspector->getDocumentRoot('xyz.rkn.mx'))
            ->toBe('/var/www/vhosts/xyz.rkn.mx/httpdocs');
    });

    it('returns /httpdocs when all sources fail', function (): void {
        [$inspector, $transport] = makeInspector();
        $transport->queueResponse(500, '{}');

        expect($inspector->getDocumentRoot('unknown.example.com'))->toBe('/httpdocs');
    });
});

describe('Inspector::findDomainId()', function (): void {
    it('returns id when the domain name matches', function (): void {
        [$inspector, $transport] = makeInspector();
        $transport->queueResponse(200, (string) json_encode([
            ['id' => 5, 'name' => 'other.com'],
            ['id' => 13, 'name' => 'xyz.rkn.mx'],
        ]));

        expect($inspector->findDomainId('xyz.rkn.mx'))->toBe(13);
    });

    it('returns null when the domain is absent', function (): void {
        [$inspector, $transport] = makeInspector();
        $transport->queueResponse(200, (string) json_encode([['id' => 5, 'name' => 'other.com']]));

        expect($inspector->findDomainId('missing.com'))->toBeNull();
    });
});
