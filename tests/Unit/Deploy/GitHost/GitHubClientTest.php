<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\GitHost\FakeTransport;
use Rkn\Cms\Deploy\GitHost\GitHubApiException;
use Rkn\Cms\Deploy\GitHost\GitHubClient;

/**
 * @return array{GitHubClient, FakeTransport}
 */
function makeGitHubClient(string $token = 'ghp_test'): array
{
    $transport = new FakeTransport();
    $client = new GitHubClient(
        token: $token,
        transport: $transport,
    );
    return [$client, $transport];
}

describe('GitHubClient::getRepo()', function (): void {
    it('returns decoded JSON on 200', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, (string) json_encode([
            'id' => 1296269,
            'full_name' => 'octocat/Hello-World',
            'default_branch' => 'main',
        ]));

        $repo = $client->getRepo('octocat', 'Hello-World');

        expect($repo)->not->toBeNull();
        expect($repo['full_name'])->toBe('octocat/Hello-World');
        expect($repo['default_branch'])->toBe('main');
    });

    it('returns null on 404 (repo missing or token cannot see it)', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(404, (string) json_encode(['message' => 'Not Found']));

        expect($client->getRepo('octocat', 'ghost-repo'))->toBeNull();
    });

    it('throws GitHubApiException on 401', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(401, (string) json_encode(['message' => 'Bad credentials']));

        expect(fn () => $client->getRepo('octocat', 'Hello-World'))
            ->toThrow(GitHubApiException::class, 'Bad credentials');
    });

    it('throws GitHubApiException on 500', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(500, (string) json_encode(['message' => 'Server crash']));

        expect(fn () => $client->getRepo('octocat', 'Hello-World'))
            ->toThrow(GitHubApiException::class, 'Server crash');
    });

    it('sends Authorization Bearer header with the PAT', function (): void {
        [$client, $transport] = makeGitHubClient('ghp_my_secret_pat');
        $transport->queueResponse(200, '{"id":1}');

        $client->getRepo('octocat', 'Hello-World');

        $req = $transport->lastRequest();
        expect($req['headers']['Authorization'])->toBe('Bearer ghp_my_secret_pat');
        expect($req['headers']['Accept'])->toBe('application/vnd.github+json');
        expect($req['headers']['X-GitHub-Api-Version'])->toBe('2022-11-28');
    });

    it('builds the correct URL', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, '{}');

        $client->getRepo('my-org', 'my-repo');

        $req = $transport->lastRequest();
        expect($req['method'])->toBe('GET');
        expect($req['url'])->toBe('https://api.github.com/repos/my-org/my-repo');
    });
});

describe('GitHubClient::ensureRepoExists()', function (): void {
    it('returns true on 200', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, '{"id":1}');

        expect($client->ensureRepoExists('octocat', 'Hello-World'))->toBeTrue();
    });

    it('returns false on 404', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(404, '{}');

        expect($client->ensureRepoExists('octocat', 'ghost'))->toBeFalse();
    });
});

describe('GitHubClient::ensureDeployKey()', function (): void {
    it('creates the key when none exist', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, '[]');
        $transport->queueResponse(201, (string) json_encode([
            'id' => 42,
            'title' => 'plesk.test',
            'key' => 'ssh-rsa AAAAB3NzaC1y_NEW',
            'read_only' => true,
        ]));

        $key = $client->ensureDeployKey('o', 'r', 'plesk.test', 'ssh-rsa AAAAB3NzaC1y_NEW deploy@plesk');

        expect($key['id'])->toBe(42);
        expect($transport->requestCount())->toBe(2);

        $created = $transport->recorded()[1];
        expect($created['method'])->toBe('POST');
        expect($created['url'])->toBe('https://api.github.com/repos/o/r/keys');
        $body = json_decode($created['body'], true);
        expect($body['title'])->toBe('plesk.test');
        expect($body['key'])->toBe('ssh-rsa AAAAB3NzaC1y_NEW deploy@plesk');
        expect($body['read_only'])->toBeTrue();
    });

    it('is idempotent: returns existing key matching by normalized blob (comment ignored)', function (): void {
        [$client, $transport] = makeGitHubClient();
        // Same key blob but different comment string from what we'll request.
        $transport->queueResponse(200, (string) json_encode([
            ['id' => 99, 'title' => 'plesk.test', 'key' => 'ssh-rsa AAAAB3NzaC1y_SAMEBLOB old-comment'],
        ]));

        $existing = $client->ensureDeployKey('o', 'r', 'plesk.test', "ssh-rsa AAAAB3NzaC1y_SAMEBLOB    deploy@plesk");

        expect($existing['id'])->toBe(99);
        // Only ONE request (the list call). NO POST.
        expect($transport->requestCount())->toBe(1);
        $req = $transport->recorded()[0];
        expect($req['method'])->toBe('GET');
    });

    it('creates a new key when existing keys have different blobs', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, (string) json_encode([
            ['id' => 1, 'key' => 'ssh-rsa OTHERBLOB'],
        ]));
        $transport->queueResponse(201, (string) json_encode(['id' => 2, 'key' => 'ssh-rsa NEWBLOB']));

        $key = $client->ensureDeployKey('o', 'r', 'title', 'ssh-rsa NEWBLOB');

        expect($key['id'])->toBe(2);
        expect($transport->requestCount())->toBe(2);
    });

    it('skips list items that are not arrays', function (): void {
        [$client, $transport] = makeGitHubClient();
        // Some scalar item — should be silently skipped, then a POST should follow.
        $transport->queueResponse(200, '[1, 2, "garbage"]');
        $transport->queueResponse(201, (string) json_encode(['id' => 7, 'key' => 'ssh-rsa K']));

        $key = $client->ensureDeployKey('o', 'r', 't', 'ssh-rsa K');

        expect($key['id'])->toBe(7);
    });
});

describe('GitHubClient::removeDeployKey()', function (): void {
    it('treats 204 as success', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(204, '');

        // Must not throw.
        $client->removeDeployKey('o', 'r', 42);

        $req = $transport->lastRequest();
        expect($req['method'])->toBe('DELETE');
        expect($req['url'])->toBe('https://api.github.com/repos/o/r/keys/42');
    });

    it('treats 404 as success (already gone — idempotent)', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(404, '{}');

        $client->removeDeployKey('o', 'r', 999);

        expect($transport->requestCount())->toBe(1);
    });

    it('throws on 500', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(500, (string) json_encode(['message' => 'kaboom']));

        expect(fn () => $client->removeDeployKey('o', 'r', 1))
            ->toThrow(GitHubApiException::class, 'kaboom');
    });
});

describe('GitHubClient::ensureWebhook()', function (): void {
    it('creates the webhook when none exist', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, '[]');
        $transport->queueResponse(201, (string) json_encode([
            'id' => 12345,
            'config' => ['url' => 'https://plesk.test/hook'],
        ]));

        $hook = $client->ensureWebhook('o', 'r', 'https://plesk.test/hook');

        expect($hook['id'])->toBe(12345);
        $created = $transport->recorded()[1];
        $body = json_decode($created['body'], true);
        expect($body['name'])->toBe('web');
        expect($body['active'])->toBeTrue();
        expect($body['events'])->toBe(['push']);
        expect($body['config']['url'])->toBe('https://plesk.test/hook');
        expect($body['config']['content_type'])->toBe('json');
        expect($body['config']['insecure_ssl'])->toBe('0');
        expect($body['config'])->not->toHaveKey('secret');
    });

    it('serializes insecure_ssl=true as "1"', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, '[]');
        $transport->queueResponse(201, '{"id":1}');

        $client->ensureWebhook('o', 'r', 'https://plesk.test/h', ['push'], true, null);

        $body = json_decode($transport->recorded()[1]['body'], true);
        expect($body['config']['insecure_ssl'])->toBe('1');
    });

    it('includes secret in config when provided non-empty', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, '[]');
        $transport->queueResponse(201, '{"id":1}');

        $client->ensureWebhook('o', 'r', 'https://plesk.test/h', ['push'], false, 's3cret');

        $body = json_decode($transport->recorded()[1]['body'], true);
        expect($body['config']['secret'])->toBe('s3cret');
    });

    it('does not include secret when null or empty string', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, '[]');
        $transport->queueResponse(201, '{"id":1}');

        $client->ensureWebhook('o', 'r', 'https://plesk.test/h', ['push'], false, '');

        $body = json_decode($transport->recorded()[1]['body'], true);
        expect($body['config'])->not->toHaveKey('secret');
    });

    it('is idempotent: returns existing hook matching by URL', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, (string) json_encode([
            ['id' => 1, 'config' => ['url' => 'https://other.test/hook']],
            ['id' => 7, 'config' => ['url' => 'https://plesk.test/hook']],
        ]));

        $hook = $client->ensureWebhook('o', 'r', 'https://plesk.test/hook');

        expect($hook['id'])->toBe(7);
        // No POST.
        expect($transport->requestCount())->toBe(1);
    });

    it('ignores hooks whose config is not an array', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, (string) json_encode([
            ['id' => 1, 'config' => 'broken'],
        ]));
        $transport->queueResponse(201, '{"id":99}');

        $hook = $client->ensureWebhook('o', 'r', 'https://plesk.test/hook');

        expect($hook['id'])->toBe(99);
    });
});

describe('GitHubClient::removeWebhook()', function (): void {
    it('204 → success, no throw', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(204, '');

        $client->removeWebhook('o', 'r', 42);
        expect($transport->requestCount())->toBe(1);
    });

    it('404 → success (already removed)', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(404, '{}');

        $client->removeWebhook('o', 'r', 42);
        expect($transport->requestCount())->toBe(1);
    });

    it('throws on 422', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(422, (string) json_encode(['message' => 'unprocessable']));

        expect(fn () => $client->removeWebhook('o', 'r', 42))
            ->toThrow(GitHubApiException::class);
    });
});

describe('GitHubClient JSON handling', function (): void {
    it('throws GitHubApiException when response is not valid JSON', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, 'NOT_JSON{{{');

        expect(fn () => $client->getRepo('o', 'r'))
            ->toThrow(GitHubApiException::class, 'not valid JSON');
    });

    it('reports a meaningful message on 4xx error with json message field', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(422, (string) json_encode(['message' => 'Validation Failed']));

        expect(fn () => $client->listDeployKeys('o', 'r'))
            ->toThrow(GitHubApiException::class, 'Validation Failed');
    });

    it('falls back to bare HTTP code when body has no message', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(403, '');

        expect(fn () => $client->listDeployKeys('o', 'r'))
            ->toThrow(GitHubApiException::class, 'HTTP 403');
    });
});

describe('FakeTransport', function (): void {
    it('throws when queue is empty', function (): void {
        [$client] = makeGitHubClient();

        expect(fn () => $client->getRepo('o', 'r'))
            ->toThrow(GitHubApiException::class, 'no queued response');
    });

    it('records every request in order', function (): void {
        [$client, $transport] = makeGitHubClient();
        $transport->queueResponse(200, '{}');
        $transport->queueResponse(200, '[]');

        $client->getRepo('a', 'b');
        $client->listDeployKeys('a', 'b');

        expect($transport->requestCount())->toBe(2);
        expect($transport->recorded()[0]['url'])->toContain('/repos/a/b');
        expect($transport->recorded()[1]['url'])->toContain('/repos/a/b/keys');
    });
});
