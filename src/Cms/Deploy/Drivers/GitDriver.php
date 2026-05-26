<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\Drivers;

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\Process\Runner;
use Rkn\Cms\Deploy\TransportInterface;

/**
 * Git-based deployment driver.
 *
 * Supports two strategies:
 *   - lean:       git push remote source_branch:target_branch  (direct push)
 *   - fat-branch: composer install --no-dev → git commit artifact → force-push → cleanup
 *
 * ALL external commands go through Runner (Symfony Process wrapper).
 * ZERO shell_exec / exec / passthru / system / proc_open calls allowed here.
 */
final class GitDriver implements TransportInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    // -------------------------------------------------------------------------
    // TransportInterface: validate
    // -------------------------------------------------------------------------

    /**
     * @return array<string> Empty = OK, non-empty = error messages.
     */
    public function validate(DeployConfig $config, callable $logger): array
    {
        $errors = [];
        $runner = new Runner($this->basePath, $logger);

        // 1. Check .git directory exists
        if (!is_dir("{$this->basePath}/.git")) {
            $errors[] = "No .git directory found in '{$this->basePath}'. Run 'git init' first.";
            return $errors; // Cannot continue without a git repo
        }

        // 2. Check the configured remote exists
        $remote = $config->remote ?? 'plesk';
        $remoteUrlResult = $runner->run(['git', 'remote', 'get-url', $remote])->withTimeout(15)->execute();
        if (!$remoteUrlResult->isSuccess()) {
            // Fallback: try git config --get
            $fallbackResult = $runner->run(['git', 'config', '--get', "remote.{$remote}.url"])
                ->withTimeout(15)
                ->execute();
            if (!$fallbackResult->isSuccess()) {
                $errors[] = "Git remote '{$remote}' does not exist. Run: git remote add {$remote} <url>";
            }
        }

        // 3. Check source branch exists locally
        $sourceBranch = $config->sourceBranch;
        $branchResult = $runner->run(['git', 'rev-parse', '--verify', $sourceBranch])->withTimeout(15)->execute();
        if (!$branchResult->isSuccess()) {
            $errors[] = "Source branch '{$sourceBranch}' does not exist locally.";
        }

        // 4. Check working directory is clean (unless --allow-dirty)
        if (!$config->allowDirty) {
            $statusResult = $runner->run(['git', 'status', '--porcelain'])->withTimeout(15)->execute();
            if ($statusResult->isSuccess() && trim($statusResult->stdout) !== '') {
                $errors[] = <<<MSG
Working directory is not clean. Commit or stash your changes before deploying.
  Hint: use --allow-dirty flag to bypass this check (not recommended for production).
  Dirty files:
{$statusResult->stdout}
MSG;
            }
        }

        return $errors;
    }

    // -------------------------------------------------------------------------
    // TransportInterface: deploy
    // -------------------------------------------------------------------------

    public function deploy(DeployConfig $config, callable $logger): bool
    {
        $logger("<info>Starting Git deployment to environment: {$config->environment}</info>");

        if ($config->strategy === 'fat') {
            return $this->deployFatBranch($config, $logger);
        }

        return $this->deployLean($config, $logger);
    }

    // -------------------------------------------------------------------------
    // TransportInterface: rollback
    // -------------------------------------------------------------------------

    public function rollback(DeployConfig $config, callable $logger): bool
    {
        if ($config->strategy === 'fat') {
            $logger('<comment>Git fat-branch rollback: cleanup already handled in finally block.</comment>');
            return true;
        }

        // Lean: push already happened. Cannot auto-rollback a push.
        $logger('<comment>Lean Git deploy has no automatic rollback; revert manually if needed (e.g. git revert).</comment>');
        return true;
    }

    // -------------------------------------------------------------------------
    // Private: lean deploy
    // -------------------------------------------------------------------------

    private function deployLean(DeployConfig $config, callable $logger): bool
    {
        $runner = new Runner($this->basePath, $logger);
        $remote = $config->remote ?? 'plesk';
        $source = $config->sourceBranch;
        $target = $config->targetBranch;

        $logger("<info>Push lean: {$remote} {$source} → {$target}</info>");

        // Fetch first to detect conflicts before pushing
        $logger("Fetching {$remote}...");
        $fetchResult = $runner->run(['git', 'fetch', $remote])->withTimeout(60)->execute();
        if (!$fetchResult->isSuccess()) {
            $logger("<comment>Fetch failed (non-fatal): {$fetchResult->stderr}</comment>");
        }

        // Push
        $logger("Pushing {$source}:{$target} to {$remote}...");
        $pushResult = $runner->run(['git', 'push', $remote, "{$source}:{$target}"])
            ->withTimeout(120)
            ->execute();

        if (!$pushResult->isSuccess()) {
            $stderr = $pushResult->stderr;
            $message = $this->interpretPushError($stderr);
            $logger("<error>Push failed: {$message}</error>");
            $logger("<error>Full stderr: {$stderr}</error>");
            return false;
        }

        $logger('<info>Push succeeded.</info>');
        return true;
    }

    /**
     * Interpret common push failure messages and return a human-friendly string.
     */
    private function interpretPushError(string $stderr): string
    {
        if (str_contains($stderr, 'non-fast-forward') || str_contains($stderr, 'rejected')) {
            return 'Push rejected (non-fast-forward). The remote has commits your local branch does not. Run: git pull --rebase';
        }
        if (str_contains($stderr, 'Authentication failed') || str_contains($stderr, 'Permission denied')) {
            return 'Authentication failed. Check your SSH key or credential helper.';
        }
        if (str_contains($stderr, 'Could not resolve host') || str_contains($stderr, 'Connection refused') || str_contains($stderr, 'No route to host')) {
            return 'Host unreachable. Check network connectivity and the remote URL.';
        }
        return 'Unknown push error — check stderr above.';
    }

    // -------------------------------------------------------------------------
    // Private: fat-branch deploy
    // -------------------------------------------------------------------------

    private function deployFatBranch(DeployConfig $config, callable $logger): bool
    {
        $runner = new Runner($this->basePath, $logger);
        $remote = $config->remote ?? 'plesk';
        $env = $config->environment;
        $target = $config->targetBranch;

        // Capture state before any mutations
        $origBranchResult = $runner->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])
            ->withTimeout(10)
            ->execute();
        $origBranch = trim($origBranchResult->stdout);

        $origHeadResult = $runner->run(['git', 'rev-parse', 'HEAD'])->withTimeout(10)->execute();
        $origHead = trim($origHeadResult->stdout);

        $tempBranch = "deploy/{$env}";
        $composerCmd = Runner::resolveComposer($this->basePath, $config->composerBin);

        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $sha7 = substr($origHead, 0, 7);

        $state = [
            'origBranch' => $origBranch,
            'origHead' => $origHead,
            'tempBranch' => $tempBranch,
            'createdTemp' => false,
            'composerRestored' => false,
        ];

        $success = false;

        try {
            $logger("<info>Preparing fat-branch deployment (includes vendor/)...</info>");

            // 1. Create temp branch (not orphan — inherits files)
            $checkoutResult = $runner->run(['git', 'checkout', '-b', $tempBranch])
                ->withTimeout(30)
                ->execute();
            if (!$checkoutResult->isSuccess()) {
                throw new \RuntimeException("Failed to create temp branch '{$tempBranch}': {$checkoutResult->stderr}");
            }
            $state['createdTemp'] = true;

            // 2. Composer install --no-dev
            $logger('Running composer install --no-dev --optimize-autoloader --classmap-authoritative...');
            $composerResult = $runner
                ->run(array_merge($composerCmd, [
                    'install',
                    '--no-dev',
                    '--optimize-autoloader',
                    '--classmap-authoritative',
                    '--no-interaction',
                ]))
                ->withTimeout(300)
                ->execute();
            if (!$composerResult->isSuccess()) {
                throw new \RuntimeException("composer install --no-dev failed: {$composerResult->stderr}");
            }

            // 3. Stage vendor + all other changes
            $logger('Staging artifact files...');
            $runner->run(['git', 'add', '-f', 'vendor/'])->withTimeout(30)->execute();
            $runner->run(['git', 'add', '-A'])->withTimeout(30)->execute();

            // 4. Commit
            $commitMsg = "Deploy artifact {$env} {$timestamp} {$sha7}";
            $commitResult = $runner
                ->run(['git', 'commit', '-m', $commitMsg, '--allow-empty'])
                ->withTimeout(30)
                ->execute();
            if (!$commitResult->isSuccess()) {
                throw new \RuntimeException("git commit failed: {$commitResult->stderr}");
            }

            // 5. Force-push temp branch as target
            $logger("<info>Force-pushing {$tempBranch} → {$remote}/{$target}...</info>");
            $pushResult = $runner
                ->run(['git', 'push', '-f', $remote, "{$tempBranch}:{$target}"])
                ->withTimeout(120)
                ->execute();
            if (!$pushResult->isSuccess()) {
                throw new \RuntimeException("Force push failed: {$pushResult->stderr}");
            }

            $success = true;
        } catch (\Throwable $e) {
            $logger("<error>Fat deploy failed: {$e->getMessage()}</error>");
            $success = false;
        } finally {
            $this->cleanupFat($runner, $state, $composerCmd, $logger);
        }

        return $success;
    }

    /**
     * Restore local repo state after fat-branch deploy (success or failure).
     * MUST NOT throw — any exception here is logged and swallowed.
     *
     * @param array{origBranch: string, origHead: string, tempBranch: string, createdTemp: bool, composerRestored: bool} $state
     * @param array<string> $composerCmd
     */
    private function cleanupFat(Runner $runner, array &$state, array $composerCmd, callable $logger): void
    {
        // Restore original branch
        if ($state['createdTemp']) {
            try {
                $checkoutResult = $runner->run(['git', 'checkout', $state['origBranch']])
                    ->withTimeout(30)
                    ->execute();

                if (!$checkoutResult->isSuccess()) {
                    // Try resetting first (staged changes might block checkout)
                    $runner->run(['git', 'reset', '--hard', 'HEAD'])->withTimeout(15)->execute();
                    $checkoutResult = $runner->run(['git', 'checkout', $state['origBranch']])
                        ->withTimeout(30)
                        ->execute();
                }

                if (!$checkoutResult->isSuccess()) {
                    // Last resort: checkout by SHA
                    $logger("<comment>Branch checkout failed; falling back to SHA {$state['origHead']}</comment>");
                    $runner->run(['git', 'checkout', $state['origHead']])->withTimeout(30)->execute();
                }
            } catch (\Throwable $e) {
                $logger("<error>CRITICAL: Could not restore original branch: {$e->getMessage()}</error>");
            }

            // Delete temp branch
            try {
                $runner->run(['git', 'branch', '-D', $state['tempBranch']])->withTimeout(15)->execute();
            } catch (\Throwable $e) {
                $logger("<comment>Could not delete temp branch '{$state['tempBranch']}': {$e->getMessage()}</comment>");
            }
        }

        // Restore dev dependencies
        if (!$state['composerRestored']) {
            try {
                $logger('Restoring local dev dependencies (composer install)...');
                $runner->run(array_merge($composerCmd, ['install', '--no-interaction']))
                    ->withTimeout(300)
                    ->execute();
                $state['composerRestored'] = true;
            } catch (\Throwable $e) {
                $logger("<error>Could not restore dev dependencies: {$e->getMessage()}</error>");
            }
        }
    }
}
