<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\FakeTransport;
use Rkn\Cms\Deploy\PleskApi\GitRepoInfo;
use Rkn\Cms\Deploy\PleskApi\Inspector;

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

/**
 * @return array{Inspector, FakeTransport}
 */
function makeInspectorForGhPull(): array
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

describe('Inspector::getGitDeployPublicKey()', function (): void {
    it('parses ssh-rsa key from Plesk CLI stdout', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $stdout = 'The domain "xyz.rkn.mx" public key is: ssh-rsa AAAAB3NzaC1yc2EAAAA_KEYBLOB rakun@plesk';
        $transport->queueResponse(200, cliBody($stdout));

        $key = $inspector->getGitDeployPublicKey('xyz.rkn.mx');

        expect($key)->toBe('ssh-rsa AAAAB3NzaC1yc2EAAAA_KEYBLOB rakun@plesk');
    });

    it('parses ssh-ed25519 key', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $stdout = 'Generated: ssh-ed25519 AAAAC3NzaC1lZDI1NTE5_BLOB plesk-deploy';
        $transport->queueResponse(200, cliBody($stdout));

        expect($inspector->getGitDeployPublicKey('xyz.rkn.mx'))
            ->toBe('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5_BLOB plesk-deploy');
    });

    it('returns null when CLI exits non-zero', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $transport->queueResponse(200, cliBody('', 2, 'extension not installed'));

        expect($inspector->getGitDeployPublicKey('xyz.rkn.mx'))->toBeNull();
    });

    it('returns null when stdout does not contain a recognizable key', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $transport->queueResponse(200, cliBody('no key here'));

        expect($inspector->getGitDeployPublicKey('xyz.rkn.mx'))->toBeNull();
    });

    it('sends the correct CLI params', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $transport->queueResponse(200, cliBody('ssh-rsa AAAA'));

        $inspector->getGitDeployPublicKey('xyz.rkn.mx');

        $req = $transport->lastRequest();
        expect($req['url'])->toContain('/api/v2/cli/extension/call');
        $body = json_decode($req['body'], true);
        expect($body['params'])->toBe([
            '--call', 'git',
            '--get-public-key',
            '-domain', 'xyz.rkn.mx',
        ]);
    });
});

describe('Inspector::getGitLastCommit()', function (): void {
    it('extracts the 40-char SHA from stdout', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $transport->queueResponse(200, cliBody('The last commit ID is: aBcDeF1234567890aBcDeF1234567890aBcDeF12'));

        expect($inspector->getGitLastCommit('xyz.rkn.mx', 'rakuncms-pull'))
            ->toBe('abcdef1234567890abcdef1234567890abcdef12');
    });

    it('extracts a 7-char SHA prefix when only short form is available', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $transport->queueResponse(200, cliBody('current: abc1234'));

        expect($inspector->getGitLastCommit('xyz.rkn.mx', 'rakuncms-pull'))->toBe('abc1234');
    });

    it('returns null when CLI exits non-zero', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $transport->queueResponse(200, cliBody('', 2, 'repo not found'));

        expect($inspector->getGitLastCommit('xyz.rkn.mx', 'no-such-repo'))->toBeNull();
    });

    it('returns null when stdout has no SHA-shaped token', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $transport->queueResponse(200, cliBody('Repository is empty (no commits)'));

        expect($inspector->getGitLastCommit('xyz.rkn.mx', 'r'))->toBeNull();
    });

    it('sends the correct CLI params', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $transport->queueResponse(200, cliBody('sha: abcdef1'));

        $inspector->getGitLastCommit('xyz.rkn.mx', 'rakuncms-pull');

        $req = $transport->lastRequest();
        $body = json_decode($req['body'], true);
        expect($body['params'])->toBe([
            '--call', 'git',
            '--get-last-commit',
            '-domain', 'xyz.rkn.mx',
            '-name', 'rakuncms-pull',
        ]);
    });
});

describe('Inspector::getGitRepoInfo()', function (): void {
    it('returns GitRepoInfo populated from --info stdout (pull repo)', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $stdout = <<<TXT
Domain name: xyz.rkn.mx
Repository name: rakuncms-pull
Deployment path: /
Deployment mode: automatic
Active branch: main
Repository type: pull
Remote URL: git@github.com:octocat/Hello-World.git
Webhook URL: https://plesk.test:8443/modules/git/public/web-hook.php?uuid=abc
Skip SSL verification: disabled
Run Post-Deploy Actions: enabled
TXT;
        $transport->queueResponse(200, cliBody($stdout));

        $info = $inspector->getGitRepoInfo('xyz.rkn.mx', 'rakuncms-pull');

        expect($info)->toBeInstanceOf(GitRepoInfo::class);
        expect($info->domain)->toBe('xyz.rkn.mx');
        expect($info->repoName)->toBe('rakuncms-pull');
        expect($info->repositoryType)->toBe('pull');
        expect($info->isPullRepo())->toBeTrue();
        expect($info->remoteUrl)->toBe('git@github.com:octocat/Hello-World.git');
        expect($info->webhookUrl)->toBe('https://plesk.test:8443/modules/git/public/web-hook.php?uuid=abc');
        expect($info->activeBranch)->toBe('main');
        expect($info->skipSslVerification)->toBeFalse();
        expect($info->runPostDeployActions)->toBeTrue();
    });

    it('parses the multi-line Actions block', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $stdout = <<<TXT
Repository name: r
Repository type: pull
Active branch: main
Actions: composer install --no-dev
         rm -rf cache/*
Skip SSL verification: disabled
Run Post-Deploy Actions: enabled
TXT;
        $transport->queueResponse(200, cliBody($stdout));

        $info = $inspector->getGitRepoInfo('d', 'r');

        expect($info)->not->toBeNull();
        expect($info->actions)->toBe([
            'composer install --no-dev',
            'rm -rf cache/*',
        ]);
    });

    it('returns null when CLI exits non-zero', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $transport->queueResponse(200, cliBody('', 2, 'repo not found'));

        expect($inspector->getGitRepoInfo('xyz.rkn.mx', 'ghost-repo'))->toBeNull();
    });

    it('defaults repository_type to "push" when label absent', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $stdout = "Repository name: x\nActive branch: main\nSkip SSL verification: disabled\nRun Post-Deploy Actions: disabled\n";
        $transport->queueResponse(200, cliBody($stdout));

        $info = $inspector->getGitRepoInfo('xyz.rkn.mx', 'x');

        expect($info)->not->toBeNull();
        expect($info->repositoryType)->toBe('push');
        expect($info->isPullRepo())->toBeFalse();
    });

    it('uses the modern -name flag in CLI params', function (): void {
        [$inspector, $transport] = makeInspectorForGhPull();
        $transport->queueResponse(200, cliBody('Repository name: r'));

        $inspector->getGitRepoInfo('xyz.rkn.mx', 'r');

        $body = json_decode($transport->lastRequest()['body'], true);
        expect($body['params'])->toBe([
            '--call', 'git',
            '--info',
            '-domain', 'xyz.rkn.mx',
            '-name', 'r',
        ]);
    });
});
