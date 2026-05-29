<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

use Rkn\Cms\Deploy\PleskApiException;

/**
 * Discovers Plesk server capabilities for a domain using REST + CLI gateway.
 *
 * Mapping (Phase 1 — deploy-plesk-api.md §6 sprint API-2):
 *   getDocumentRoot  → GET /domains, GET /domains/{id}  (REST native)
 *   hasShellAccess   → cliCall('domain', ['--info', $domain])
 *   getPhpInfo       → cliCall('domain', ['--info', $domain])  (shared stdout, cached)
 *   getGitInfo       → cliCall('extension', ['--call', 'git', '--list', '-domain', $domain])
 *                      + cliCall('extension', ['--call', 'git', '--info', '-domain', X, '-repo', Y])
 *
 * Each method is independently try/caught so that a partial failure (e.g. git extension
 * not installed) does not abort the entire discovery operation.
 */
final class Inspector
{
    /** @var array<string, ?array<mixed>> Cached GET /domains body, keyed by 'all'. */
    private array $domainListCache = [];

    /** @var array<string, ?int> domain name → Plesk domain id (or null when not found) */
    private array $domainIdCache = [];

    /** @var array<int, array<string, mixed>> Plesk domain id → GET /domains/{id} body */
    private array $domainDetailCache = [];

    /** @var array<string, string> domain name → stdout from `domain --info` */
    private array $domainInfoStdoutCache = [];

    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * Get the document root path for a domain.
     *
     * Falls back to '/httpdocs' if the information cannot be retrieved.
     */
    public function getDocumentRoot(string $domain): string
    {
        try {
            $detail = $this->fetchDomainDetail($domain);
            if ($detail === null) {
                return $this->parseDocRootFromInfoStdout($domain) ?? '/httpdocs';
            }

            foreach (['www_root', 'docroot', 'document_root'] as $key) {
                if (isset($detail[$key]) && is_string($detail[$key]) && $detail[$key] !== '') {
                    return $detail[$key];
                }
            }

            $hosting = $detail['hosting'] ?? null;
            if (is_array($hosting)) {
                foreach (['www_root', 'docroot', 'document_root'] as $key) {
                    if (isset($hosting[$key]) && is_string($hosting[$key]) && $hosting[$key] !== '') {
                        return $hosting[$key];
                    }
                }
            }

            return $this->parseDocRootFromInfoStdout($domain) ?? '/httpdocs';
        } catch (PleskApiException) {
            return $this->parseDocRootFromInfoStdout($domain) ?? '/httpdocs';
        } catch (\Throwable) {
            return '/httpdocs';
        }
    }

    /**
     * Check whether shell access is enabled for the domain.
     *
     * Returns:
     *   true  — shell is set to a real shell (/bin/bash, /bin/sh, etc.)
     *   false — shell is /sbin/nologin, /bin/false, forbidden, or similar
     *   null  — information could not be determined
     */
    public function hasShellAccess(string $domain): ?bool
    {
        try {
            $stdout = $this->getDomainInfoStdout($domain);
            if ($stdout === null) {
                return null;
            }

            $shell = $this->parseStdoutField($stdout, [
                "SSH access to the server shell under the subscription's system user",
                'SSH access',
                'Shell access',
                'Shell',
            ]);
            if ($shell === null) {
                return null;
            }

            $shellLower = strtolower(trim($shell));
            $disabled = ['/sbin/nologin', '/bin/false', 'false', 'none', 'forbidden', 'disabled', ''];

            return !in_array($shellLower, $disabled, true);
        } catch (PleskApiException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get PHP configuration for the domain.
     *
     * @return array{version: string, handler: string}|null
     */
    public function getPhpInfo(string $domain): ?array
    {
        try {
            $stdout = $this->getDomainInfoStdout($domain);
            if ($stdout === null) {
                return null;
            }

            $version = $this->parseStdoutField($stdout, ['PHP version', 'PHP']);
            $handler = $this->parseStdoutField($stdout, ['PHP handler', 'PHP handler type']);

            if ($version === null && $handler === null) {
                return null;
            }

            if ($version !== null && preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $m)) {
                $version = $m[1];
            }

            return [
                'version' => $version ?? 'unknown',
                'handler' => $handler !== null ? strtolower($handler) : 'unknown',
            ];
        } catch (PleskApiException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get Git repository information for the domain.
     *
     * @return array{repo_name: string, webhook_url: ?string, active_branch: ?string, deploy_path: ?string}|null
     */
    public function getGitInfo(string $domain): ?array
    {
        try {
            $listResult = $this->client->cliCall('extension', [
                '--call', 'git',
                '--list',
                '-domain', $domain,
            ]);

            if (!$listResult->isSuccess()) {
                return null;
            }

            $repoName = $this->parseFirstRepoName($listResult->stdout);
            if ($repoName === null) {
                return null;
            }

            $infoResult = $this->client->cliCall('extension', [
                '--call', 'git',
                '--info',
                '-domain', $domain,
                '-repo', $repoName,
            ]);

            $infoStdout = $infoResult->isSuccess() ? $infoResult->stdout : '';

            return [
                'repo_name' => $repoName,
                'webhook_url' => $this->parseStdoutField($infoStdout, ['Webhook URL', 'Webhook']),
                'active_branch' => $this->parseStdoutField($infoStdout, ['Active branch', 'Branch']),
                'deploy_path' => $this->parseStdoutField($infoStdout, ['Deploy path', 'Deployment path']),
            ];
        } catch (PleskApiException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the Plesk-generated SSH deploy public key for a domain.
     *
     * Plesk generates a per-domain RSA keypair the first time `--get-public-key` is called
     * for that domain (or when a pull repo with remote SSH URL is created). The returned
     * value is the public key in OpenSSH format, ready to be uploaded as a deploy key to
     * GitHub/GitLab/Bitbucket. The corresponding private key never leaves the server.
     *
     * Stdout shape:
     *   The domain "xyz.rkn.mx" public key is: ssh-rsa AAAAB3NzaC1y...
     */
    public function getGitDeployPublicKey(string $domain): ?string
    {
        try {
            $result = $this->client->cliCall('extension', [
                '--call', 'git',
                '--get-public-key',
                '-domain', $domain,
            ]);

            if (!$result->isSuccess()) {
                return null;
            }

            // Capture algorithm + base64 blob + optional comment
            if (preg_match('/(ssh-(?:rsa|ed25519|dss|ecdsa)\s+\S+(?:\s+\S+)?)/i', $result->stdout, $m)) {
                return trim($m[1]);
            }

            return null;
        } catch (PleskApiException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get detailed configuration for a specific repository.
     *
     * Differs from getGitInfo() in two ways:
     *   - Takes an explicit repo name (no auto-discovery of the first repo)
     *   - Returns a typed GitRepoInfo DTO with all fields including remote URL,
     *     repository type (push/pull), and post-deploy action flags — needed for
     *     the GitHub-pull pipeline.
     *
     * Uses the `-name` flag (the modern Plesk Git extension flag; `-repo` was
     * the legacy name and is no longer accepted on 18.0.78+).
     */
    public function getGitRepoInfo(string $domain, string $repoName): ?GitRepoInfo
    {
        try {
            $result = $this->client->cliCall('extension', [
                '--call', 'git',
                '--info',
                '-domain', $domain,
                '-name', $repoName,
            ]);

            if (!$result->isSuccess()) {
                return null;
            }

            $stdout = $result->stdout;

            return new GitRepoInfo(
                domain: $this->parseStdoutField($stdout, ['Domain name']) ?? $domain,
                repoName: $this->parseStdoutField($stdout, ['Repository name']) ?? $repoName,
                deploymentPath: $this->parseStdoutField($stdout, ['Deployment path', 'Deploy path']),
                deploymentMode: $this->parseStdoutField($stdout, ['Deployment mode']),
                activeBranch: $this->parseStdoutField($stdout, ['Active branch', 'Branch']),
                repositoryType: strtolower($this->parseStdoutField($stdout, ['Repository type']) ?? 'push'),
                remoteUrl: $this->parseStdoutField($stdout, ['Remote URL']),
                webhookUrl: $this->parseStdoutField($stdout, ['Webhook URL']),
                skipSslVerification: $this->parseFlagEnabled($stdout, ['Skip SSL verification']),
                runPostDeployActions: $this->parseFlagEnabled($stdout, ['Run Post-Deploy Actions']),
                actions: $this->parseActionsBlock($stdout),
            );
        } catch (PleskApiException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read the last commit SHA Plesk has pulled into the deployment path for a repo.
     *
     * Used to poll Plesk after triggering a GitHub-pull deploy: when the returned SHA
     * matches the SHA the developer just pushed to GitHub, the new release is live.
     *
     * Stdout shape on success (Plesk 18):
     *   The last commit ID is: <40-char-sha>
     */
    public function getGitLastCommit(string $domain, string $repoName): ?string
    {
        try {
            $result = $this->client->cliCall('extension', [
                '--call', 'git',
                '--get-last-commit',
                '-domain', $domain,
                '-name', $repoName,
            ]);

            if (!$result->isSuccess()) {
                return null;
            }

            if (preg_match('/\b([0-9a-f]{7,40})\b/i', $result->stdout, $m)) {
                return strtolower($m[1]);
            }

            return null;
        } catch (PleskApiException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Run full discovery for a domain.
     *
     * Calls all four discovery methods independently with 500ms spacing between calls
     * to avoid tripping Plesk's bruteforce rate-limit on rapid-fire requests.
     *
     * @return array{
     *   domain: string,
     *   has_shell: bool|null,
     *   git: array{repo_name: string, webhook_url: ?string, active_branch: ?string, deploy_path: ?string}|null,
     *   php: array{version: string, handler: string}|null,
     *   doc_root: string,
     *   discovered_at: string
     * }
     */
    public function discover(string $domain): array
    {
        $docRoot = '/httpdocs';
        try {
            $docRoot = $this->getDocumentRoot($domain);
        } catch (\Throwable) {
        }
        usleep(500_000);

        $hasShell = null;
        try {
            $hasShell = $this->hasShellAccess($domain);
        } catch (\Throwable) {
        }
        usleep(500_000);

        $phpInfo = null;
        try {
            $phpInfo = $this->getPhpInfo($domain);
        } catch (\Throwable) {
        }
        usleep(500_000);

        $git = null;
        try {
            $git = $this->getGitInfo($domain);
        } catch (\Throwable) {
        }

        return [
            'domain' => $domain,
            'has_shell' => $hasShell,
            'git' => $git,
            'php' => $phpInfo,
            'doc_root' => $docRoot,
            'discovered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers (REST + CLI)
    // -------------------------------------------------------------------------

    /**
     * Resolve the Plesk numeric domain id from its name. Cached per Inspector instance.
     */
    public function findDomainId(string $domain): ?int
    {
        if (array_key_exists($domain, $this->domainIdCache)) {
            return $this->domainIdCache[$domain];
        }

        $list = $this->fetchDomainList();
        if ($list === null) {
            return $this->domainIdCache[$domain] = null;
        }

        $needle = strtolower($domain);
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $item['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }

            if (strtolower($name) !== $needle) {
                continue;
            }

            $id = $item['id'] ?? null;
            if (is_int($id)) {
                return $this->domainIdCache[$domain] = $id;
            }
            if (is_string($id) && ctype_digit($id)) {
                return $this->domainIdCache[$domain] = (int) $id;
            }
        }

        return $this->domainIdCache[$domain] = null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchDomainList(): ?array
    {
        if (array_key_exists('all', $this->domainListCache)) {
            $cached = $this->domainListCache['all'];
            return $cached === null ? null : $this->castDomainList($cached);
        }

        try {
            $body = $this->client->restGet('domains');
        } catch (PleskApiException) {
            return ($this->domainListCache['all'] = null);
        }

        $this->domainListCache['all'] = $body;
        return $this->castDomainList($body);
    }

    /**
     * Plesk returns the domains either as a flat array or wrapped in {data: [...]}.
     *
     * @param array<mixed> $body
     * @return array<int, array<string, mixed>>
     */
    private function castDomainList(array $body): array
    {
        if (isset($body['data']) && is_array($body['data'])) {
            $body = $body['data'];
        }

        $result = [];
        foreach ($body as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDomainDetail(string $domain): ?array
    {
        $id = $this->findDomainId($domain);
        if ($id === null) {
            return null;
        }

        if (isset($this->domainDetailCache[$id])) {
            return $this->domainDetailCache[$id];
        }

        try {
            $body = $this->client->restGet("domains/{$id}");
        } catch (PleskApiException) {
            return null;
        }

        if (isset($body['data']) && is_array($body['data'])) {
            $body = $body['data'];
        }

        /** @var array<string, mixed> $body */
        return $this->domainDetailCache[$id] = $body;
    }

    /**
     * Fetch and cache the stdout from `plesk bin domain --info <name>` via CLI gateway.
     */
    private function getDomainInfoStdout(string $domain): ?string
    {
        if (array_key_exists($domain, $this->domainInfoStdoutCache)) {
            return $this->domainInfoStdoutCache[$domain];
        }

        try {
            $result = $this->client->cliCall('domain', ['--info', $domain]);
        } catch (PleskApiException) {
            return null;
        }

        if (!$result->isSuccess()) {
            return null;
        }

        return $this->domainInfoStdoutCache[$domain] = $result->stdout;
    }

    private function parseDocRootFromInfoStdout(string $domain): ?string
    {
        $stdout = $this->getDomainInfoStdout($domain);
        if ($stdout === null) {
            return null;
        }

        return $this->parseStdoutField($stdout, ['--WWW-Root--', 'Document root', 'Doc root', 'Hosting root', 'WWW root']);
    }

    /**
     * Parse a "Field name: value" line from CLI stdout.
     *
     * @param array<int, string> $fieldAliases  Names to try in order; first match wins.
     */
    private function parseStdoutField(string $stdout, array $fieldAliases): ?string
    {
        foreach ($fieldAliases as $field) {
            $pattern = '/^[ \t]*' . preg_quote($field, '/') . '[ \t]*:[ \t]*(.+?)[ \t]*$/mi';
            if (preg_match($pattern, $stdout, $m)) {
                $value = trim($m[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Parse a Plesk-style "enabled"/"disabled" flag from CLI stdout.
     *
     * @param array<int, string> $fieldAliases
     */
    private function parseFlagEnabled(string $stdout, array $fieldAliases): bool
    {
        $value = $this->parseStdoutField($stdout, $fieldAliases);
        if ($value === null) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['enabled', 'true', 'yes', 'on', '1'], true);
    }

    /**
     * Parse the multi-line "Actions:" block from `extension --call git --info` stdout.
     *
     * Plesk renders post-deploy actions as a label line followed by one or more
     * continuation lines that are indented (leading spaces or tabs). Example:
     *
     *   Actions: composer install --no-dev --optimize-autoloader
     *            rm -rf cache/pages/* cache/templates/* cache/content-index.php
     *   Skip SSL verification: disabled
     *
     * Returns:
     *   - array<int, string>  → registered actions (one entry per line, trimmed)
     *   - empty array         → label found but value blank ("Actions:" with nothing)
     *   - null                → label not present in stdout (older Plesk extension)
     *
     * Used by {@see Provisioner::createGitPullRepo} to decide whether a re-run
     * with a changed {@code --post-deploy} payload needs to push an update.
     */
    private function parseActionsBlock(string $stdout): ?array
    {
        $lines = preg_split('/\r?\n/', $stdout) ?: [];
        $found = false;
        $collected = [];
        $firstAfterLabelIndent = null;

        foreach ($lines as $line) {
            if (!$found) {
                if (preg_match('/^[ \t]*(?:Actions|Post-Deploy Actions|Post-deploy actions)[ \t]*:[ \t]*(.*)$/i', $line, $m)) {
                    $found = true;
                    $first = trim($m[1]);
                    if ($first !== '') {
                        $collected[] = $first;
                    }
                }
                continue;
            }

            // Stop on a new top-level "Field: value" line (zero-indent, has a colon).
            if (preg_match('/^[A-Za-z][A-Za-z0-9 _-]*:[ \t]/', $line)) {
                break;
            }

            $trimmed = rtrim($line);
            if ($trimmed === '') {
                if ($collected !== []) {
                    break;
                }
                continue;
            }

            // Continuation lines are indented; once we leave indentation we stop.
            if (!preg_match('/^[ \t]+/', $line)) {
                break;
            }

            $value = trim($trimmed);
            if ($value !== '') {
                $collected[] = $value;
            }
        }

        return $found ? $collected : null;
    }

    private function parseFirstRepoName(string $stdout): ?string
    {
        $stdoutLower = strtolower($stdout);
        if (
            str_contains($stdoutLower, 'no repositories')
            || str_contains($stdoutLower, 'no git repositories')
            || trim($stdout) === ''
        ) {
            return null;
        }

        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9._\/-]+\.git)\b/', $line, $m)) {
                return $m[1];
            }

            if (preg_match('/^Repository\s*name\s*:\s*(.+)$/i', $line, $m)) {
                return trim($m[1]);
            }

            if (preg_match('/^[A-Za-z0-9._\/-]+$/', $line)) {
                return $line;
            }
        }

        return null;
    }
}
