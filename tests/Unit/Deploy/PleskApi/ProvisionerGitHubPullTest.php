<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\FakeTransport;
use Rkn\Cms\Deploy\PleskApi\Inspector;
use Rkn\Cms\Deploy\PleskApi\Provisioner;
use Rkn\Cms\Deploy\PleskApiException;

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
 * @return array{Provisioner, FakeTransport}
 */
function makeProvisionerForGhPull(): array
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

/**
 * Stdout that getGitRepoInfo() can parse into a pull repo with given fields.
 *
 * @param array<int, string>|null $actions
 */
function repoInfoStdout(
    string $domain = 'xyz.rkn.mx',
    string $name = 'rakuncms-pull',
    string $remoteUrl = 'git@github.com:octocat/Hello-World.git',
    string $branch = 'main',
    string $deployPath = '/',
    string $type = 'pull',
    bool $skipSsl = false,
    ?array $actions = null,
): string {
    $lines = [
        "Domain name: {$domain}",
        "Repository name: {$name}",
        "Deployment path: {$deployPath}",
        "Deployment mode: automatic",
        "Active branch: {$branch}",
        "Repository type: {$type}",
        "Remote URL: {$remoteUrl}",
        "Webhook URL: https://plesk.test:8443/modules/git/public/web-hook.php?uuid=abc",
        "Skip SSL verification: " . ($skipSsl ? 'enabled' : 'disabled'),
        "Run Post-Deploy Actions: enabled",
    ];
    if ($actions !== null) {
        $lines[] = "Actions: " . ($actions[0] ?? '');
        for ($i = 1; $i < count($actions); $i++) {
            $lines[] = "         {$actions[$i]}";
        }
    }
    return implode("\n", $lines) . "\n";
}

describe('Provisioner::createGitPullRepo() — idempotency', function (): void {
    it('returns existing repo when all fields match (no CLI mutation issued)', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        $stdout = repoInfoStdout();
        $transport->queueResponse(200, cliBody($stdout));

        $info = $provisioner->createGitPullRepo(
            domain: 'xyz.rkn.mx',
            repoName: 'rakuncms-pull',
            remoteUrl: 'git@github.com:octocat/Hello-World.git',
            branch: 'main',
            deploymentPath: '/',
            deploymentMode: 'automatic',
            actions: null,
            skipSslVerification: false,
        );

        expect($info->repoName)->toBe('rakuncms-pull');
        // ONLY the initial Inspector::getGitRepoInfo() call. No --create or --update.
        expect($transport->requestCount())->toBe(1);
    });

    it('creates a new repo when none exists', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        // First Inspector::getGitRepoInfo() returns "not found"
        $transport->queueResponse(200, cliBody('', 2, 'Git repository not found'));
        // The --create CLI call succeeds
        $transport->queueResponse(200, cliBody(''));
        // Final Inspector::getGitRepoInfo() returns the new repo
        $transport->queueResponse(200, cliBody(repoInfoStdout()));

        $info = $provisioner->createGitPullRepo(
            domain: 'xyz.rkn.mx',
            repoName: 'rakuncms-pull',
            remoteUrl: 'git@github.com:octocat/Hello-World.git',
            branch: 'main',
            deploymentPath: '/',
        );

        expect($info->repoName)->toBe('rakuncms-pull');
        expect($transport->requestCount())->toBe(3);

        $createCall = $transport->recorded()[1];
        $body = json_decode($createCall['body'], true);
        expect($body['params'])->toContain('--create');
        expect($body['params'])->toContain('-name');
        expect($body['params'])->toContain('rakuncms-pull');
        expect($body['params'])->toContain('-remote-url');
    });

    it('issues --update when remote URL differs', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        $transport->queueResponse(200, cliBody(repoInfoStdout(remoteUrl: 'git@github.com:OLD/owner.git')));
        $transport->queueResponse(200, cliBody('')); // --update succeeds
        $transport->queueResponse(200, cliBody(repoInfoStdout(remoteUrl: 'git@github.com:octocat/Hello-World.git')));

        $provisioner->createGitPullRepo(
            domain: 'xyz.rkn.mx',
            repoName: 'rakuncms-pull',
            remoteUrl: 'git@github.com:octocat/Hello-World.git',
            branch: 'main',
            deploymentPath: '/',
        );

        $update = $transport->recorded()[1];
        $body = json_decode($update['body'], true);
        expect($body['params'])->toContain('--update');
    });

    it('issues --update when actions list differs', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        $transport->queueResponse(200, cliBody(repoInfoStdout(actions: ['old action'])));
        $transport->queueResponse(200, cliBody(''));
        $transport->queueResponse(200, cliBody(repoInfoStdout(actions: ['new action 1', 'new action 2'])));

        $provisioner->createGitPullRepo(
            domain: 'xyz.rkn.mx',
            repoName: 'rakuncms-pull',
            remoteUrl: 'git@github.com:octocat/Hello-World.git',
            branch: 'main',
            deploymentPath: '/',
            actions: ['new action 1', 'new action 2'],
        );

        $update = $transport->recorded()[1];
        $body = json_decode($update['body'], true);
        expect($body['params'])->toContain('--update');
        expect($body['params'])->toContain('-run-actions');
        expect($body['params'])->toContain('-actions');
        // -actions value is the newline-joined string of the user-supplied entries
        $actionsIdx = array_search('-actions', $body['params'], true);
        expect($body['params'][$actionsIdx + 1])->toBe("new action 1\nnew action 2");
    });

    it('does NOT issue --update when actions are not requested (null)', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        $transport->queueResponse(200, cliBody(repoInfoStdout(actions: ['existing-action'])));

        $info = $provisioner->createGitPullRepo(
            domain: 'xyz.rkn.mx',
            repoName: 'rakuncms-pull',
            remoteUrl: 'git@github.com:octocat/Hello-World.git',
            branch: 'main',
            deploymentPath: '/',
            actions: null, // caller does not want to override
        );

        expect($info)->not->toBeNull();
        expect($transport->requestCount())->toBe(1);
    });

    it('issues --update when skip_ssl_verification differs', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        $transport->queueResponse(200, cliBody(repoInfoStdout(skipSsl: false)));
        $transport->queueResponse(200, cliBody(''));
        $transport->queueResponse(200, cliBody(repoInfoStdout(skipSsl: true)));

        $provisioner->createGitPullRepo(
            domain: 'xyz.rkn.mx',
            repoName: 'rakuncms-pull',
            remoteUrl: 'git@github.com:octocat/Hello-World.git',
            branch: 'main',
            deploymentPath: '/',
            skipSslVerification: true,
        );

        $update = $transport->recorded()[1];
        $body = json_decode($update['body'], true);
        expect($body['params'])->toContain('--update');
        expect($body['params'])->toContain('-skip-ssl-verification');
    });

    it('throws PleskApiException when CLI --create fails', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        $transport->queueResponse(200, cliBody('', 2, 'not found'));        // getGitRepoInfo
        $transport->queueResponse(200, cliBody('', 3, 'extension error')); // --create failure

        expect(fn () => $provisioner->createGitPullRepo(
            domain: 'xyz.rkn.mx',
            repoName: 'rakuncms-pull',
            remoteUrl: 'git@github.com:octocat/Hello-World.git',
            branch: 'main',
            deploymentPath: '/',
        ))->toThrow(PleskApiException::class, "'extension --call git --create'");
    });
});

describe('Provisioner::triggerGitDeploy()', function (): void {
    it('throws when the repo does not exist', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        $transport->queueResponse(200, cliBody('', 2, 'not found'));

        expect(fn () => $provisioner->triggerGitDeploy('xyz.rkn.mx', 'ghost-repo'))
            ->toThrow(PleskApiException::class, "does not exist");
    });

    it('sync mode issues --fetch then --deploy in order', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        $transport->queueResponse(200, cliBody(repoInfoStdout()));
        $transport->queueResponse(200, cliBody(''));
        $transport->queueResponse(200, cliBody(''));

        $ok = $provisioner->triggerGitDeploy('xyz.rkn.mx', 'rakuncms-pull', async: false);

        expect($ok)->toBeTrue();
        expect($transport->requestCount())->toBe(3);

        $fetchBody = json_decode($transport->recorded()[1]['body'], true);
        expect($fetchBody['params'])->toBe([
            '--call', 'git', '--fetch',
            '-domain', 'xyz.rkn.mx',
            '-name', 'rakuncms-pull',
        ]);

        $deployBody = json_decode($transport->recorded()[2]['body'], true);
        expect($deployBody['params'])->toBe([
            '--call', 'git', '--deploy',
            '-domain', 'xyz.rkn.mx',
            '-name', 'rakuncms-pull',
        ]);
    });

    it('async mode issues a single --async-deploy', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        $transport->queueResponse(200, cliBody(repoInfoStdout()));
        $transport->queueResponse(200, cliBody(''));

        $ok = $provisioner->triggerGitDeploy('xyz.rkn.mx', 'rakuncms-pull', async: true);

        expect($ok)->toBeTrue();
        expect($transport->requestCount())->toBe(2);

        $body = json_decode($transport->recorded()[1]['body'], true);
        expect($body['params'])->toBe([
            '--call', 'git', '--async-deploy',
            '-domain', 'xyz.rkn.mx',
            '-name', 'rakuncms-pull',
        ]);
    });

    it('throws if --fetch fails (sync mode)', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        $transport->queueResponse(200, cliBody(repoInfoStdout()));
        $transport->queueResponse(200, cliBody('', 5, 'remote unreachable'));

        expect(fn () => $provisioner->triggerGitDeploy('xyz.rkn.mx', 'rakuncms-pull'))
            ->toThrow(PleskApiException::class, "git --fetch");
    });

    it('throws if --async-deploy fails', function (): void {
        [$provisioner, $transport] = makeProvisionerForGhPull();
        $transport->queueResponse(200, cliBody(repoInfoStdout()));
        $transport->queueResponse(200, cliBody('', 9, 'queue full'));

        expect(fn () => $provisioner->triggerGitDeploy('xyz.rkn.mx', 'rakuncms-pull', async: true))
            ->toThrow(PleskApiException::class, "git --async-deploy");
    });
});
