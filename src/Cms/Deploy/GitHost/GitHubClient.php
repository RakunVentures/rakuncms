<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\GitHost;

/**
 * Minimal GitHub REST API v3 client for the deploy:setup-github pipeline.
 *
 * Surfaces three GitHub operations needed by the GitHub-pull deploy model:
 *   - Repository existence/metadata
 *   - Deploy keys (add/list/remove with idempotency)
 *   - Webhooks (add/list/remove with idempotency)
 *
 * Authentication uses a Personal Access Token (classic or fine-grained) via the
 * `Authorization: Bearer <pat>` header. Required scopes:
 *   - Classic PAT:    `repo` + `admin:repo_hook`
 *   - Fine-grained:   "Administration: Read & write" + "Webhooks: Read & write"
 *                     + "Contents: Read" on the target repository.
 *
 * Idempotency: ensureDeployKey() and ensureWebhook() check existing resources by
 * stable identity (key blob for SSH keys, hook URL for webhooks). They never
 * create duplicates.
 */
final class GitHubClient
{
    private readonly HttpTransport $transport;

    public function __construct(
        private readonly string $token,
        private readonly string $apiBaseUrl = 'https://api.github.com',
        int $timeout = 30,
        bool $verifySsl = true,
        ?HttpTransport $transport = null,
    ) {
        $this->transport = $transport ?? new CurlTransport($verifySsl, $timeout);
    }

    // -------------------------------------------------------------------------
    // Repository
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null Null when the repo is missing or token cannot see it.
     */
    public function getRepo(string $owner, string $repo): ?array
    {
        $response = $this->request('GET', "/repos/{$owner}/{$repo}");

        if ($response->statusCode === 404) {
            return null;
        }

        $this->assertSuccess($response, "Failed to fetch repo {$owner}/{$repo}");

        return $this->decode($response->body);
    }

    public function ensureRepoExists(string $owner, string $repo): bool
    {
        return $this->getRepo($owner, $repo) !== null;
    }

    // -------------------------------------------------------------------------
    // Deploy keys
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDeployKeys(string $owner, string $repo): array
    {
        $response = $this->request('GET', "/repos/{$owner}/{$repo}/keys");
        $this->assertSuccess($response, "Failed to list deploy keys for {$owner}/{$repo}");

        $decoded = $this->decode($response->body);
        $out = [];
        foreach ($decoded as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Add a deploy key. Caller is responsible for idempotency unless using ensureDeployKey().
     *
     * @return array<string, mixed>
     */
    public function addDeployKey(
        string $owner,
        string $repo,
        string $title,
        string $publicKey,
        bool $readOnly = true,
    ): array {
        $response = $this->request('POST', "/repos/{$owner}/{$repo}/keys", [
            'title' => $title,
            'key' => $publicKey,
            'read_only' => $readOnly,
        ]);

        $this->assertSuccess($response, "Failed to add deploy key '{$title}' to {$owner}/{$repo}");

        return $this->decode($response->body);
    }

    public function removeDeployKey(string $owner, string $repo, int $keyId): void
    {
        $response = $this->request('DELETE', "/repos/{$owner}/{$repo}/keys/{$keyId}");

        if ($response->statusCode !== 204 && $response->statusCode !== 404) {
            $this->assertSuccess($response, "Failed to remove deploy key {$keyId} from {$owner}/{$repo}");
        }
    }

    /**
     * Idempotent: if a deploy key with the same key blob already exists, return it.
     * Otherwise create a new one with the given title. Compares only the algo+blob
     * parts of the key (comment field is ignored, since GitHub does not always
     * preserve it).
     *
     * @return array<string, mixed>
     */
    public function ensureDeployKey(
        string $owner,
        string $repo,
        string $title,
        string $publicKey,
        bool $readOnly = true,
    ): array {
        $needle = self::normalizeSshKey($publicKey);

        foreach ($this->listDeployKeys($owner, $repo) as $existing) {
            $existingKey = is_string($existing['key'] ?? null) ? $existing['key'] : '';
            if (self::normalizeSshKey($existingKey) === $needle) {
                return $existing;
            }
        }

        return $this->addDeployKey($owner, $repo, $title, $publicKey, $readOnly);
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listWebhooks(string $owner, string $repo): array
    {
        $response = $this->request('GET', "/repos/{$owner}/{$repo}/hooks");
        $this->assertSuccess($response, "Failed to list webhooks for {$owner}/{$repo}");

        $decoded = $this->decode($response->body);
        $out = [];
        foreach ($decoded as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<int, string> $events
     * @return array<string, mixed>
     */
    public function addWebhook(
        string $owner,
        string $repo,
        string $url,
        array $events = ['push'],
        bool $insecureSsl = false,
        ?string $secret = null,
    ): array {
        $config = [
            'url' => $url,
            'content_type' => 'json',
            'insecure_ssl' => $insecureSsl ? '1' : '0',
        ];
        if ($secret !== null && $secret !== '') {
            $config['secret'] = $secret;
        }

        $response = $this->request('POST', "/repos/{$owner}/{$repo}/hooks", [
            'name' => 'web',
            'active' => true,
            'events' => array_values($events),
            'config' => $config,
        ]);

        $this->assertSuccess($response, "Failed to add webhook to {$owner}/{$repo}");

        return $this->decode($response->body);
    }

    public function removeWebhook(string $owner, string $repo, int $hookId): void
    {
        $response = $this->request('DELETE', "/repos/{$owner}/{$repo}/hooks/{$hookId}");

        if ($response->statusCode !== 204 && $response->statusCode !== 404) {
            $this->assertSuccess($response, "Failed to remove webhook {$hookId} from {$owner}/{$repo}");
        }
    }

    /**
     * Idempotent: if a webhook with the same URL already exists, return it as-is.
     * Otherwise create a new one with the requested events + insecure_ssl + secret.
     *
     * Note: this method does NOT reconcile an existing hook's events list, active
     * flag, insecure_ssl, or secret against the requested values. If those need to
     * change, remove the hook first (removeWebhook) and re-create it. This keeps
     * the method safe to call repeatedly without surprising users who tuned the
     * hook by hand.
     *
     * @param array<int, string> $events
     * @return array<string, mixed>
     */
    public function ensureWebhook(
        string $owner,
        string $repo,
        string $url,
        array $events = ['push'],
        bool $insecureSsl = false,
        ?string $secret = null,
    ): array {
        foreach ($this->listWebhooks($owner, $repo) as $hook) {
            $config = $hook['config'] ?? null;
            if (!is_array($config)) {
                continue;
            }
            $hookUrl = is_string($config['url'] ?? null) ? $config['url'] : '';
            if ($hookUrl === $url) {
                return $hook;
            }
        }

        return $this->addWebhook($owner, $repo, $url, $events, $insecureSsl, $secret);
    }

    // -------------------------------------------------------------------------
    // Internal HTTP
    // -------------------------------------------------------------------------

    /**
     * @param array<mixed>|null $jsonBody
     */
    private function request(string $method, string $path, ?array $jsonBody = null): HttpResponse
    {
        $url = $this->apiBaseUrl . '/' . ltrim($path, '/');

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $this->token,
            'User-Agent' => 'rakuncms-deploy',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        $body = '';
        if ($jsonBody !== null) {
            $body = json_encode($jsonBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $headers['Content-Type'] = 'application/json';
        }

        return $this->transport->send($method, $url, $headers, $body);
    }

    private function assertSuccess(HttpResponse $response, string $context): void
    {
        if ($response->statusCode >= 200 && $response->statusCode < 300) {
            return;
        }

        $msg = $this->extractErrorMessage($response->body);
        $detail = $msg !== null ? ": {$msg}" : '';
        throw new GitHubApiException(
            "{$context} (HTTP {$response->statusCode}){$detail}",
        );
    }

    private function extractErrorMessage(string $body): ?string
    {
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }
        return null;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GitHubApiException('GitHub response is not valid JSON: ' . json_last_error_msg());
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalize an OpenSSH key for stable comparison: keep only `algo<space>blob`,
     * dropping any trailing comment and whitespace variations.
     */
    private static function normalizeSshKey(string $key): string
    {
        $parts = preg_split('/\s+/', trim($key)) ?: [];
        if (count($parts) < 2) {
            return trim($key);
        }
        return $parts[0] . ' ' . $parts[1];
    }
}
