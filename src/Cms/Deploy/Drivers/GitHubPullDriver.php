<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\Drivers;

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\GitHost\GitHubApiException;
use Rkn\Cms\Deploy\GitHost\GitHubClient;
use Rkn\Cms\Deploy\PleskApi\Client as PleskClient;
use Rkn\Cms\Deploy\PleskApi\Inspector as PleskInspector;
use Rkn\Cms\Deploy\PleskApi\Provisioner as PleskProvisioner;
use Rkn\Cms\Deploy\PleskApiException;
use Rkn\Cms\Deploy\Process\Runner;
use Rkn\Cms\Deploy\TransportInterface;

/**
 * GitHub-as-origin + Plesk-pull deployment driver.
 *
 * Pipeline:
 *   1. git push origin source_branch:target_branch  → GitHub
 *   2. Capture the SHA just pushed (from local target ref).
 *   3. Trigger Plesk Git extension `--deploy` (sync) or `--async-deploy`.
 *   4. If sync: trust Plesk's exit code. If async: poll `--get-last-commit`
 *      until SHA matches OR timeout.
 *
 * Zero SSH keys leave the developer's machine. Plesk pulls from GitHub using
 * its own per-domain deploy key, registered on GitHub via the GitHub REST API
 * during `rakun deploy:setup-github`.
 *
 * Rollback: informational no-op. The push to GitHub already happened; reverting
 * is a manual `git revert && deploy` cycle (see GitDriver lean for same rationale).
 */
final class GitHubPullDriver implements TransportInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * @return array<string>
     */
    public function validate(DeployConfig $config, callable $logger): array
    {
        $errors = [];
        $runner = new Runner($this->basePath, $logger);

        $gitPath = "{$this->basePath}/.git";
        if (!is_dir($gitPath) && !is_file($gitPath)) {
            return ["No git repository found at '{$this->basePath}'. Run 'git init' first."];
        }

        $remote = $config->remote ?? 'origin';
        $remoteUrlResult = $runner->run(['git', 'remote', 'get-url', $remote])->withTimeout(15)->execute();
        if (!$remoteUrlResult->isSuccess()) {
            $errors[] = "Git remote '{$remote}' does not exist. Run: rakun deploy:setup-github {$config->environment}";
        } else {
            $remoteUrl = trim($remoteUrlResult->stdout);
            if (!self::looksLikeGitHubRemote($remoteUrl)) {
                $errors[] = "Remote '{$remote}' does not point to GitHub (got: {$remoteUrl}).";
            }
        }

        $sourceBranch = $config->sourceBranch;
        $branchResult = $runner->run(['git', 'rev-parse', '--verify', $sourceBranch])->withTimeout(15)->execute();
        if (!$branchResult->isSuccess()) {
            $errors[] = "Source branch '{$sourceBranch}' does not exist locally.";
        }

        if (!$config->allowDirty) {
            $statusResult = $runner->run(['git', 'status', '--porcelain'])->withTimeout(15)->execute();
            if ($statusResult->isSuccess() && trim($statusResult->stdout) !== '') {
                $errors[] = "Working directory is not clean. Commit or stash first, or use --allow-dirty.";
            }
        }

        foreach ([
            'github.owner' => $config->githubOwner,
            'github.repo' => $config->githubRepo,
            'github.token' => $config->githubToken,
            'plesk.host' => $config->pleskHost,
            'plesk.api_key' => $config->pleskApiKey,
            'plesk.repo_name' => $config->pleskRepoName,
            'domain' => $config->domain !== '' ? $config->domain : null,
        ] as $field => $value) {
            if ($value === null || $value === '') {
                $errors[] = "Missing required deploy.yaml field for github-pull: '{$field}'.";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        try {
            $gh = $this->makeGitHubClient($config);
            $exists = $gh->ensureRepoExists((string) $config->githubOwner, (string) $config->githubRepo);
            if (!$exists) {
                $errors[] = "GitHub repo '{$config->githubOwner}/{$config->githubRepo}' is not reachable with the configured token (404 or insufficient scope).";
            }
        } catch (GitHubApiException $e) {
            $errors[] = "GitHub API: {$e->getMessage()}";
        }

        try {
            $plesk = $this->makePleskClients($config);
            $info = $plesk['inspector']->getGitRepoInfo((string) $config->domain, (string) $config->pleskRepoName);
            if ($info === null) {
                $errors[] = "Plesk Git repo '{$config->pleskRepoName}' does not exist on domain '{$config->domain}'. Run: rakun deploy:setup-github {$config->environment}";
            } elseif (!$info->isPullRepo()) {
                $errors[] = "Plesk Git repo '{$config->pleskRepoName}' is type '{$info->repositoryType}', expected 'pull'.";
            }
        } catch (PleskApiException $e) {
            $errors[] = "Plesk API: {$e->getMessage()}";
        }

        return $errors;
    }

    public function deploy(DeployConfig $config, callable $logger): bool
    {
        $runner = new Runner($this->basePath, $logger);
        $remote = $config->remote ?? 'origin';
        $source = $config->sourceBranch;
        $target = $config->targetBranch;
        $domain = (string) $config->domain;
        $repoName = (string) $config->pleskRepoName;

        $logger("<info>[github-pull] Pushing {$source}:{$target} to {$remote} (GitHub)...</info>");

        $fetchResult = $runner->run(['git', 'fetch', $remote])->withTimeout(60)->execute();
        if (!$fetchResult->isSuccess()) {
            $logger("<comment>Fetch failed (non-fatal): {$fetchResult->stderr}</comment>");
        }

        $pushResult = $runner->run(['git', 'push', $remote, "{$source}:{$target}"])
            ->withTimeout(120)
            ->execute();

        if (!$pushResult->isSuccess()) {
            $logger("<error>git push failed: {$pushResult->stderr}</error>");
            return false;
        }
        $logger('<info>Push to GitHub succeeded.</info>');

        $expectedShaResult = $runner->run(['git', 'rev-parse', $source])->withTimeout(15)->execute();
        if (!$expectedShaResult->isSuccess()) {
            $logger("<error>Cannot resolve SHA of '{$source}': {$expectedShaResult->stderr}</error>");
            return false;
        }
        $expectedSha = strtolower(trim($expectedShaResult->stdout));
        $logger("<comment>Expected SHA at remote: {$expectedSha}</comment>");

        try {
            $plesk = $this->makePleskClients($config);
        } catch (\Throwable $e) {
            $logger("<error>Cannot init Plesk client: {$e->getMessage()}</error>");
            return false;
        }

        $logger("<info>[github-pull] Triggering Plesk deploy ({$domain}:{$repoName})...</info>");
        try {
            $plesk['provisioner']->triggerGitDeploy($domain, $repoName, $config->pleskDeployAsync);
        } catch (PleskApiException $e) {
            $logger("<error>Plesk deploy trigger failed: {$e->getMessage()}</error>");
            return false;
        }

        if (!$config->pleskDeployAsync) {
            $logger('<info>Plesk sync deploy returned OK; verifying last commit...</info>');
        } else {
            $logger("<info>Plesk async deploy queued; polling for SHA {$expectedSha}...</info>");
        }

        $reached = $this->pollUntilSha(
            $plesk['inspector'],
            $domain,
            $repoName,
            $expectedSha,
            $config->pleskDeployPollTimeout,
            $config->pleskDeployPollInterval,
            $logger,
        );

        if (!$reached) {
            $logger("<error>Plesk did not reach SHA {$expectedSha} within {$config->pleskDeployPollTimeout}s.</error>");
            return false;
        }

        $logger("<info>Plesk is on SHA {$expectedSha}. Deploy complete.</info>");
        return true;
    }

    public function rollback(DeployConfig $config, callable $logger): bool
    {
        $logger('<comment>github-pull rollback: the push to GitHub already happened. Revert manually: git revert <bad-sha> && rakun deploy ' . $config->environment . '</comment>');
        return true;
    }

    private function pollUntilSha(
        PleskInspector $inspector,
        string $domain,
        string $repoName,
        string $expectedSha,
        int $timeoutSeconds,
        int $intervalSeconds,
        callable $logger,
    ): bool {
        $deadline = microtime(true) + $timeoutSeconds;
        $expectedShort = substr($expectedSha, 0, 7);
        $attempt = 0;

        while (microtime(true) < $deadline) {
            $attempt++;
            $current = $inspector->getGitLastCommit($domain, $repoName);
            if ($current !== null && self::shaMatches($current, $expectedSha)) {
                return true;
            }
            $shown = $current ?? '(unknown)';
            $logger("<comment>  poll #{$attempt}: plesk={$shown}, want={$expectedShort}</comment>");

            if (microtime(true) + $intervalSeconds >= $deadline) {
                break;
            }
            sleep($intervalSeconds);
        }

        return false;
    }

    private static function shaMatches(string $a, string $b): bool
    {
        $a = strtolower($a);
        $b = strtolower($b);
        $n = min(strlen($a), strlen($b));
        if ($n < 7) {
            return false;
        }
        return substr($a, 0, $n) === substr($b, 0, $n);
    }

    private static function looksLikeGitHubRemote(string $url): bool
    {
        $url = strtolower($url);
        return str_contains($url, 'github.com');
    }

    private function makeGitHubClient(DeployConfig $config): GitHubClient
    {
        return new GitHubClient(
            token: (string) $config->githubToken,
            verifySsl: $config->verifySsl,
        );
    }

    /**
     * @return array{client: PleskClient, inspector: PleskInspector, provisioner: PleskProvisioner}
     */
    private function makePleskClients(DeployConfig $config): array
    {
        $client = new PleskClient(
            host: (string) $config->pleskHost,
            apiKey: (string) $config->pleskApiKey,
            verifySsl: $config->pleskVerifySsl,
        );
        $inspector = new PleskInspector($client);
        $provisioner = new PleskProvisioner($client, $inspector);

        return [
            'client' => $client,
            'inspector' => $inspector,
            'provisioner' => $provisioner,
        ];
    }
}
