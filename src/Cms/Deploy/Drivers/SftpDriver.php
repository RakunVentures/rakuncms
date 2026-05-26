<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\Drivers;

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\DeployLock;
use Rkn\Cms\Deploy\Process\Runner;
use Rkn\Cms\Deploy\TransportInterface;
use RuntimeException;

/**
 * SFTP deployment using rsync over SSH (primary) or phpseclib (fallback).
 *
 * Strategy detection (D5 from deploy-plesk-sync.md):
 *   1. rsync binary in PATH → rsync -avzL via Runner (array args, zero shell injection)
 *   2. class_exists(\phpseclib3\Net\SFTP) → phpseclib pure-PHP upload
 *   3. Neither → RuntimeException with install hint
 *
 * Remote structure (same as FtpDriver):
 *   releases/{YYYY-MM-DD_HHMMSS_{git_sha7}}/  ← code
 *   current                                    ← symlink to active release
 *   shared/                                    ← persistent (content, uploads, .env)
 */
final class SftpDriver implements TransportInterface
{
    private const STRATEGY_RSYNC = 'rsync';
    private const STRATEGY_PHPSECLIB = 'phpseclib';

    public function __construct(
        private readonly string $basePath,
        private readonly ?Runner $runner = null,
        private readonly ?DeployLock $lock = null,
    ) {}

    /**
     * @return array<string> Empty = valid, non-empty = error messages
     */
    public function validate(DeployConfig $config, callable $logger): array
    {
        $errors = [];

        // Check local lock
        $lock = $this->getLock();
        if (!$lock->isStale($config->environment)) {
            $data = $lock->inspect($config->environment);
            $releaseId = is_array($data) ? (string) ($data['release_id'] ?? 'unknown') : 'unknown';
            $errors[] = "Local lock is active for environment '{$config->environment}' (release: {$releaseId}). Wait or remove ~/.rakun/deploy-{$config->environment}.lock";
            return $errors;
        }

        // Check strategy availability
        try {
            $this->detectStrategy();
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
            return $errors;
        }

        // Check SSH connectivity
        $sshErrors = $this->checkSshConnectivity($config);
        if (!empty($sshErrors)) {
            $errors = array_merge($errors, $sshErrors);
            return $errors;
        }

        // Check remote path writability
        $writeErrors = $this->checkRemoteWritable($config);
        $errors = array_merge($errors, $writeErrors);

        return $errors;
    }

    public function deploy(DeployConfig $config, callable $logger): bool
    {
        $lock = $this->getLock();

        $releaseId = $this->buildReleaseId($config);
        $logger("<info>SFTP deploy: release {$releaseId}</info>");

        if (!$lock->acquire($config->environment, $releaseId)) {
            $logger('<error>Cannot acquire local deploy lock</error>');
            return false;
        }

        try {
            $strategy = $this->detectStrategy();
            $logger("<comment>Strategy: {$strategy}</comment>");

            if ($strategy === self::STRATEGY_RSYNC) {
                return $this->deployViaRsync($config, $releaseId, $logger);
            }

            return $this->deployViaPhpseclib($config, $releaseId, $logger);
        } finally {
            $lock->release($config->environment);
        }
    }

    public function rollback(DeployConfig $config, callable $logger): bool
    {
        $strategy = $this->safeDetectStrategy();

        if ($strategy === self::STRATEGY_RSYNC) {
            return $this->rollbackViaRsync($config, $logger);
        }

        if ($strategy === self::STRATEGY_PHPSECLIB) {
            $logger('<comment>phpseclib strategy does not support atomic rollback. No action taken.</comment>');
            return true; // No-op, not a failure
        }

        $logger('<comment>SFTP rollback: no strategy available, skipping.</comment>');
        return true;
    }

    // ─── Strategy detection ──────────────────────────────────────────────────

    /**
     * @throws RuntimeException if neither rsync nor phpseclib is available
     */
    private function detectStrategy(): string
    {
        $runner = $this->getRunner();
        $result = $runner->run(['which', 'rsync'])->withTimeout(10)->execute();
        if ($result->isSuccess() && trim($result->stdout) !== '') {
            return self::STRATEGY_RSYNC;
        }

        if (class_exists('\phpseclib3\Net\SFTP')) {
            return self::STRATEGY_PHPSECLIB;
        }

        throw new RuntimeException(
            "No SFTP transport available. Install rsync (apt-get install rsync / brew install rsync) "
            . "or add phpseclib: `composer require phpseclib/phpseclib`"
        );
    }

    private function safeDetectStrategy(): ?string
    {
        try {
            return $this->detectStrategy();
        } catch (RuntimeException) {
            return null;
        }
    }

    // ─── rsync deployment ────────────────────────────────────────────────────

    private function deployViaRsync(DeployConfig $config, string $releaseId, callable $logger): bool
    {
        $user     = (string) ($config->user ?? '');
        $host     = $config->host;
        $port     = $config->port;
        $path     = $config->path;
        $target   = "{$user}@{$host}:{$path}/releases/{$releaseId}/";
        $sshCmd   = "ssh -p {$port} -o BatchMode=yes -o StrictHostKeyChecking=accept-new";

        $runner = $this->getRunner();

        $cmd = array_merge(
            ['rsync', '-avzL', '--delete', '-e', $sshCmd],
            $this->buildExcludeArgs(),
            ["{$this->basePath}/", $target],
        );

        $logger("<comment>rsync to {$target}</comment>");
        $result = $runner->run($cmd)->withTimeout(300)->execute();

        if (!$result->isSuccess()) {
            $logger("<error>rsync failed (exit {$result->exitCode}):\n{$result->stderr}</error>");
            return false;
        }

        // Create shared directories and activate symlinks via SSH
        $sshCommands = $this->buildSshActivationCommands($path, $releaseId);
        $sshResult = $runner->run([
            'ssh', '-p', (string) $port,
            '-o', 'BatchMode=yes',
            "{$user}@{$host}",
            $sshCommands,
        ])->withTimeout(30)->execute();

        if (!$sshResult->isSuccess()) {
            $logger("<error>SSH activation failed:\n{$sshResult->stderr}</error>");
            return false;
        }

        $logger("<info>rsync deploy and activation complete.</info>");
        return true;
    }

    private function buildSshActivationCommands(string $path, string $releaseId): string
    {
        return <<<SHELL
        set -e
        mkdir -p {$path}/shared/content {$path}/shared/uploads {$path}/shared/cache {$path}/shared/logs
        mkdir -p {$path}/releases/{$releaseId}
        [ -f {$path}/shared/.env ] && cp {$path}/shared/.env {$path}/releases/{$releaseId}/.env || true
        ln -sfn ../../shared/content {$path}/releases/{$releaseId}/content 2>/dev/null || true
        ln -sfn ../../shared/uploads {$path}/releases/{$releaseId}/uploads 2>/dev/null || true
        ln -sfn ../../shared/cache   {$path}/releases/{$releaseId}/cache   2>/dev/null || true
        ln -sfn {$path}/releases/{$releaseId} {$path}/current_new
        mv -Tf {$path}/current_new {$path}/current
        SHELL;
    }

    private function rollbackViaRsync(DeployConfig $config, callable $logger): bool
    {
        $user   = (string) ($config->user ?? '');
        $host   = $config->host;
        $port   = $config->port;
        $path   = $config->path;
        $runner = $this->getRunner();

        // Find the penultimate release by mtime on the remote
        $lsResult = $runner->run([
            'ssh', '-p', (string) $port,
            '-o', 'BatchMode=yes',
            "{$user}@{$host}",
            "ls -1dt {$path}/releases/*/",
        ])->withTimeout(15)->execute();

        if (!$lsResult->isSuccess()) {
            $logger('<error>Cannot list remote releases for rollback</error>');
            return false;
        }

        $dirs = array_filter(array_map('trim', explode("\n", $lsResult->stdout)));
        $dirs = array_values($dirs);

        if (count($dirs) < 2) {
            $logger('<comment>No previous release available for rollback.</comment>');
            return false;
        }

        $previousDir = $dirs[1]; // Second newest = penultimate

        $swapResult = $runner->run([
            'ssh', '-p', (string) $port,
            '-o', 'BatchMode=yes',
            "{$user}@{$host}",
            "ln -sfn {$previousDir} {$path}/current_new && mv -Tf {$path}/current_new {$path}/current",
        ])->withTimeout(15)->execute();

        if (!$swapResult->isSuccess()) {
            $logger("<error>Rollback symlink swap failed:\n{$swapResult->stderr}</error>");
            return false;
        }

        $logger("<info>Rolled back to: {$previousDir}</info>");
        return true;
    }

    // ─── phpseclib deployment ────────────────────────────────────────────────

    private function deployViaPhpseclib(DeployConfig $config, string $releaseId, callable $logger): bool
    {
        if (!class_exists('\phpseclib3\Net\SFTP')) {
            $logger('<error>phpseclib3\Net\SFTP not found</error>');
            return false;
        }

        /** @var \phpseclib3\Net\SFTP $sftp */
        $sftp = new \phpseclib3\Net\SFTP($config->host, $config->port);
        if (!$sftp->login((string) $config->user, (string) $config->pass)) {
            $logger('<error>phpseclib SFTP login failed</error>');
            return false;
        }

        $remotePath = $config->path;
        $logger("<comment>phpseclib SFTP: uploading to {$remotePath}/releases/{$releaseId}/</comment>");

        // phpseclib fallback: direct copy to remote path (no atomic releases)
        $sftp->mkdir("{$remotePath}/releases/{$releaseId}", -1, true);

        $uploaded = 0;
        $defaultExclude = ['.git', '.DS_Store', 'cache/pages', 'cache/templates', 'tests'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            $localPath    = $file->getRealPath();
            $relativePath = ltrim(substr((string) $localPath, strlen(rtrim((string) realpath($this->basePath), '/'))), '/');

            foreach ($defaultExclude as $ex) {
                if (str_starts_with($relativePath, $ex)) {
                    continue 2;
                }
            }

            $remoteFile = "{$remotePath}/releases/{$releaseId}/{$relativePath}";

            if ($file->isDir()) {
                $sftp->mkdir($remoteFile, -1, true);
            } else {
                if (!$sftp->put($remoteFile, $localPath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
                    $logger("<error>Failed to upload: {$relativePath}</error>");
                    return false;
                }
                $uploaded++;
            }
        }

        // Create current symlink (phpseclib has no symlink native support in all versions)
        // Fall through to activation via ssh if available, else just log
        $logger("<info>phpseclib uploaded {$uploaded} files to {$remotePath}/releases/{$releaseId}/</info>");
        $logger('<comment>Note: phpseclib mode does not perform atomic symlink swap. Manual activation required.</comment>');

        return true;
    }

    // ─── SSH validation ──────────────────────────────────────────────────────

    /**
     * @return array<string>
     */
    private function checkSshConnectivity(DeployConfig $config): array
    {
        $runner = $this->getRunner();
        $result = $runner->run([
            'ssh',
            '-p', (string) $config->port,
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=10',
            '-o', 'StrictHostKeyChecking=accept-new',
            "{$config->user}@{$config->host}",
            'echo ok',
        ])->withTimeout(15)->execute();

        if (!$result->isSuccess() || trim($result->stdout) !== 'ok') {
            return [
                "SSH connection failed to {$config->user}@{$config->host}:{$config->port}. "
                . "Ensure SSH keys are set up and server is reachable. Error: " . trim($result->stderr),
            ];
        }

        return [];
    }

    /**
     * @return array<string>
     */
    private function checkRemoteWritable(DeployConfig $config): array
    {
        $runner = $this->getRunner();
        $result = $runner->run([
            'ssh',
            '-p', (string) $config->port,
            '-o', 'BatchMode=yes',
            "{$config->user}@{$config->host}",
            "test -w {$config->path} && echo writable || echo not-writable",
        ])->withTimeout(10)->execute();

        if (!$result->isSuccess() || trim($result->stdout) !== 'writable') {
            return ["Remote path {$config->path} is not writable on {$config->host}"];
        }

        return [];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function buildReleaseId(DeployConfig $config): string
    {
        $base = date('Y-m-d_His');
        // Try to get git sha
        $runner = $this->getRunner();
        $result = $runner->run(['git', 'rev-parse', '--short=7', 'HEAD'])->withTimeout(10)->execute();
        $sha7 = $result->isSuccess() ? trim($result->stdout) : 'unknown';
        return "{$base}_{$sha7}";
    }

    /**
     * @return array<string>
     */
    private function buildExcludeArgs(): array
    {
        $excludes = ['.git/', 'cache/pages/', 'cache/templates/', 'tests/', '.DS_Store', 'node_modules/'];
        $args = [];
        foreach ($excludes as $exclude) {
            $args[] = "--exclude={$exclude}";
        }
        return $args;
    }

    private function getRunner(): Runner
    {
        return $this->runner ?? new Runner($this->basePath);
    }

    private function getLock(): DeployLock
    {
        if ($this->lock !== null) {
            return $this->lock;
        }
        $lockDir = (string) ($_SERVER['HOME'] ?? sys_get_temp_dir()) . '/.rakun';
        return new DeployLock($lockDir);
    }
}
