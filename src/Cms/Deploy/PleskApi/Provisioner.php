<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

use Rkn\Cms\Deploy\PleskApiException;

/**
 * Provisions Plesk resources for a domain (REST + CLI gateway, REST-only client).
 *
 * Every operation is idempotent: it asks Inspector for current state first,
 * and only mutates when the desired state is not already present. The methods
 * NEVER throw on idempotency — they only throw {@see PleskApiException} on
 * transport/auth failure or when the underlying CLI tool reports a non-zero
 * exit code on a mutating call.
 */
final class Provisioner
{
    public function __construct(
        private readonly Client $client,
        private readonly Inspector $inspector,
    ) {}

    /**
     * Enable shell access for the domain.
     *
     * Idempotent: if shell is already enabled, returns true immediately without mutating.
     *
     * @throws PleskApiException If the CLI call fails or shell still appears disabled afterwards.
     */
    public function enableShellAccess(string $domain, string $shell = '/bin/bash'): bool
    {
        if ($this->inspector->hasShellAccess($domain) === true) {
            return true;
        }

        $result = $this->client->cliCall('subscription', [
            '--update-php',
            $domain,
            '-shell',
            $shell,
        ]);

        if (!$result->isSuccess()) {
            $stderr = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;
            throw new PleskApiException(
                "Plesk CLI 'subscription --update-php -shell' failed (exit {$result->code}): {$stderr}",
                0,
            );
        }

        return true;
    }

    /**
     * Create a Git repository for the domain in Plesk.
     *
     * Idempotent: if a repository with the same name already exists, returns its info.
     *
     * @return array{repo_name: string, webhook_url: ?string, active_branch: ?string, deploy_path: ?string}
     * @throws PleskApiException If creation fails.
     */
    public function createGitRepo(
        string $domain,
        string $name = 'rakuncms.git',
        ?string $deployPath = null,
    ): array {
        $existing = $this->inspector->getGitInfo($domain);
        if ($existing !== null && $existing['repo_name'] === $name) {
            return $existing;
        }

        $params = [
            '--call', 'git',
            '--create-repo',
            '-domain', $domain,
            '-repo', $name,
            '-deploy-mode', 'automatic',
        ];

        if ($deployPath !== null && $deployPath !== '') {
            $params[] = '-deploy-path';
            $params[] = $deployPath;
        }

        $result = $this->client->cliCall('extension', $params);

        if (!$result->isSuccess()) {
            $stderr = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;
            throw new PleskApiException(
                "Plesk CLI 'extension --call git --create-repo' failed (exit {$result->code}): {$stderr}",
                0,
            );
        }

        $info = $this->inspector->getGitInfo($domain);

        return $info ?? [
            'repo_name' => $name,
            'webhook_url' => null,
            'active_branch' => null,
            'deploy_path' => $deployPath,
        ];
    }

    /**
     * Create (or reconcile) a Plesk Git "pull" repository that mirrors an external remote
     * such as GitHub, GitLab or Bitbucket. Plesk will pull from the remote on every webhook
     * hit and (optionally) run shell actions after each deploy.
     *
     * Idempotent: if a repo with the same name already exists for the domain and matches
     * the requested remoteUrl + branch, returns the existing info. If the repo exists but
     * differs, it is updated via `--update`. If it does not exist, it is created.
     *
     * @param array<int, string>|null $actions Shell commands to run after every pull.
     *                                          Passed to Plesk as a single newline-joined
     *                                          string with `-run-actions -actions "..."`.
     *
     * @throws PleskApiException If the underlying CLI call fails.
     */
    public function createGitPullRepo(
        string $domain,
        string $repoName,
        string $remoteUrl,
        string $branch,
        string $deploymentPath,
        string $deploymentMode = 'automatic',
        ?array $actions = null,
        bool $skipSslVerification = false,
    ): GitRepoInfo {
        $existing = $this->inspector->getGitRepoInfo($domain, $repoName);

        $needsCreate = $existing === null;
        $needsUpdate = false;

        if ($existing !== null) {
            $needsUpdate = $existing->remoteUrl !== $remoteUrl
                || $existing->activeBranch !== $branch
                || $existing->deploymentPath !== $deploymentPath
                || strtolower((string) $existing->deploymentMode) !== strtolower($deploymentMode)
                || $existing->skipSslVerification !== $skipSslVerification
                || $this->actionsDiffer($existing->actions, $actions);
        }

        if (!$needsCreate && !$needsUpdate) {
            return $existing;
        }

        $params = [
            '--call', 'git',
            $needsCreate ? '--create' : '--update',
            '-domain', $domain,
            '-name', $repoName,
            '-remote-url', $remoteUrl,
            '-active-branch', $branch,
            '-deployment-mode', $deploymentMode,
            '-deployment-path', $deploymentPath,
        ];

        if ($skipSslVerification) {
            $params[] = '-skip-ssl-verification';
            $params[] = 'true';
        }

        if ($actions !== null && $actions !== []) {
            $params[] = '-run-actions';
            $params[] = 'true';
            $params[] = '-actions';
            $params[] = implode("\n", $actions);
        }

        $result = $this->client->cliCall('extension', $params);

        if (!$result->isSuccess()) {
            $stderr = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;
            $op = $needsCreate ? 'create' : 'update';
            throw new PleskApiException(
                "Plesk CLI 'extension --call git --{$op}' failed (exit {$result->code}): {$stderr}",
                0,
            );
        }

        $info = $this->inspector->getGitRepoInfo($domain, $repoName);
        if ($info === null) {
            throw new PleskApiException(
                "Plesk Git repo '{$repoName}' on '{$domain}' was created but could not be read back.",
                0,
            );
        }

        return $info;
    }

    /**
     * Decide whether the registered post-deploy actions differ from the requested set.
     *
     * Comparison is order-sensitive and trims whitespace per entry. Returns:
     *   - false when both sides are null (caller didn't care, server unparsed).
     *   - false when the user-requested set is null (no override requested).
     *   - true  when the server returned null but the user requested a non-empty set
     *     (we cannot prove equivalence, so we force an update — safer than silent drift).
     *   - true  when normalized entry lists differ in count or content.
     *
     * @param array<int, string>|null $existing
     * @param array<int, string>|null $requested
     */
    private function actionsDiffer(?array $existing, ?array $requested): bool
    {
        if ($requested === null) {
            return false;
        }

        $normalize = static function (array $arr): array {
            $out = [];
            foreach ($arr as $line) {
                $trimmed = trim((string) $line);
                if ($trimmed !== '') {
                    $out[] = $trimmed;
                }
            }
            return $out;
        };

        $req = $normalize($requested);

        if ($existing === null) {
            return $req !== [];
        }

        return $normalize($existing) !== $req;
    }

    /**
     * Trigger a pull/deploy on a Plesk Git repository.
     *
     * For pull repos, this forces Plesk to fetch the latest commits from the remote and
     * (when deployment mode is automatic) run post-deploy actions. Useful as a manual
     * trigger when bypassing the webhook (e.g. after pushing to GitHub from CI).
     *
     * Async mode uses `--async-deploy` which does fetch + deploy internally and returns
     * immediately. Sync mode performs `--fetch` (pull from GitHub) then `--deploy`
     * (copy bare → deployment_path + run post-deploy actions) sequentially. The two
     * separate calls are required because Plesk's `--deploy` alone does NOT pull
     * new commits — it only deploys whatever the bare repo currently has.
     *
     * @throws PleskApiException If any CLI call fails.
     */
    public function triggerGitDeploy(string $domain, string $repoName, bool $async = false): bool
    {
        $existing = $this->inspector->getGitRepoInfo($domain, $repoName);
        if ($existing === null) {
            throw new PleskApiException(
                "Cannot trigger deploy: Plesk Git repo '{$repoName}' does not exist on '{$domain}'.",
                0,
            );
        }

        if ($async) {
            return $this->callGitExtension(
                'async-deploy',
                ['--call', 'git', '--async-deploy', '-domain', $domain, '-name', $repoName],
            );
        }

        $this->callGitExtension(
            'fetch',
            ['--call', 'git', '--fetch', '-domain', $domain, '-name', $repoName],
        );

        return $this->callGitExtension(
            'deploy',
            ['--call', 'git', '--deploy', '-domain', $domain, '-name', $repoName],
        );
    }

    /**
     * @param array<int, string> $params
     * @throws PleskApiException
     */
    private function callGitExtension(string $op, array $params): bool
    {
        $result = $this->client->cliCall('extension', $params);

        if (!$result->isSuccess()) {
            $stderr = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;
            throw new PleskApiException(
                "Plesk CLI 'extension --call git --{$op}' failed (exit {$result->code}): {$stderr}",
                0,
            );
        }

        return true;
    }

    /**
     * Remove a Plesk Git repository. Idempotent: returns true if the repo did not exist.
     *
     * @throws PleskApiException If the CLI call fails for any reason other than "not found".
     */
    public function removeGitRepo(string $domain, string $repoName): bool
    {
        $existing = $this->inspector->getGitRepoInfo($domain, $repoName);
        if ($existing === null) {
            return true;
        }

        $result = $this->client->cliCall('extension', [
            '--call', 'git',
            '--remove',
            '-domain', $domain,
            '-name', $repoName,
        ]);

        if (!$result->isSuccess()) {
            $stderr = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;
            throw new PleskApiException(
                "Plesk CLI 'extension --call git --remove' failed (exit {$result->code}): {$stderr}",
                0,
            );
        }

        return true;
    }

    /**
     * Repoint a (sub)domain's document root via Plesk CLI.
     *
     * The {@code $wwwRoot} argument MUST be RELATIVE to the subscription root
     * (e.g. {@code "xyz.rkn.mx/current/public"}). Passing an absolute path makes
     * Plesk concatenate the subscription root onto it, producing
     * {@code /var/www/vhosts/<sub>//var/www/...} and the call will silently land
     * on a non-existent directory. The CLI is itself idempotent — calling it with
     * the current value is a no-op — so no Inspector pre-check is performed.
     *
     * Plesk applies www-root changes immediately to nginx/PHP-FPM. No daemon
     * restart is required.
     *
     * @throws PleskApiException If the CLI call fails.
     */
    public function setSubdomainDocumentRoot(string $domain, string $wwwRoot): bool
    {
        $relative = ltrim($wwwRoot, '/');
        if ($relative === '') {
            throw new PleskApiException(
                "setSubdomainDocumentRoot: wwwRoot must be subscription-relative and non-empty",
                0,
            );
        }

        $result = $this->client->cliCall('subdomain', [
            '--update',
            $domain,
            '-www-root',
            $relative,
        ]);

        if (!$result->isSuccess()) {
            $stderr = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;
            throw new PleskApiException(
                "Plesk CLI 'subdomain --update -www-root' failed (exit {$result->code}): {$stderr}",
                0,
            );
        }

        return true;
    }

    /**
     * Create an FTP subaccount under the domain.
     *
     * Idempotent: if an FTP user with the given login already exists for this domain,
     * the existing record is returned WITHOUT the password (Plesk never re-exposes it).
     *
     * @return array{login: string, home: ?string, created: bool, raw: array<mixed>}
     * @throws PleskApiException If the REST call fails.
     */
    public function createFtpSubaccount(
        int $domainId,
        string $login,
        string $password,
        string $home = '/',
    ): array {
        try {
            $existing = $this->client->restGet('ftpusers');
        } catch (PleskApiException) {
            $existing = [];
        }

        $list = $this->extractFtpUserList($existing);

        foreach ($list as $user) {
            $userLogin = $user['login'] ?? $user['name'] ?? null;
            if (!is_string($userLogin) || strtolower($userLogin) !== strtolower($login)) {
                continue;
            }

            $userDomainId = $user['domain_id'] ?? $user['parent_domain_id'] ?? null;
            if (is_int($userDomainId) && $userDomainId !== $domainId) {
                continue;
            }
            if (is_string($userDomainId) && (int) $userDomainId !== $domainId) {
                continue;
            }

            $userHome = isset($user['home']) && is_string($user['home']) ? $user['home'] : null;

            return [
                'login' => $userLogin,
                'home' => $userHome,
                'created' => false,
                'raw' => $user,
            ];
        }

        $payload = [
            'name' => $login,
            'password' => $password,
            'home' => $home,
            'parent_domain' => ['id' => $domainId],
            'permissions' => ['read' => true, 'write' => true],
        ];

        $response = $this->client->restPost('ftpusers', $payload);

        $createdHome = null;
        if (isset($response['home']) && is_string($response['home'])) {
            $createdHome = $response['home'];
        }

        return [
            'login' => $login,
            'home' => $createdHome ?? $home,
            'created' => true,
            'raw' => $response,
        ];
    }

    /**
     * @param array<mixed> $body
     * @return array<int, array<string, mixed>>
     */
    private function extractFtpUserList(array $body): array
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
}
