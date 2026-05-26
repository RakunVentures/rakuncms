<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

use RuntimeException;

/**
 * Local PID-based deploy lock with TTL.
 *
 * Prevents concurrent deployments to the same environment.
 *
 * Lock file format (JSON):
 * {
 *   "pid": 12345,
 *   "env": "production",
 *   "release_id": "2026-05-26_143022_a1b2c3d",
 *   "started_at": 1716700000,
 *   "ttl": 1800,
 *   "host": "macbook.local"
 * }
 */
final class DeployLock
{
    public function __construct(
        private readonly string $lockDir,
        private readonly int $ttlSec = 1800,
    ) {}

    /**
     * Acquire the lock for a given environment and release.
     *
     * Returns true if acquired, false if an active non-stale lock exists.
     */
    public function acquire(string $env, string $releaseId): bool
    {
        $lockFile = $this->lockPath($env);

        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0755, true);
        }

        if (file_exists($lockFile)) {
            $existing = $this->readLockFile($lockFile);

            if ($existing !== null && !$this->isStaleData($existing)) {
                return false; // Active lock held by another process
            }
        }

        $lock = [
            'pid'        => getmypid(),
            'env'        => $env,
            'release_id' => $releaseId,
            'started_at' => time(),
            'ttl'        => $this->ttlSec,
            'host'       => (string) php_uname('n'),
        ];

        $written = file_put_contents($lockFile, (string) json_encode($lock), LOCK_EX);
        return $written !== false;
    }

    /**
     * Release the lock for a given environment.
     * Safe to call multiple times (idempotent).
     */
    public function release(string $env): void
    {
        $lockFile = $this->lockPath($env);
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    /**
     * Inspect current lock state.
     *
     * @return array<string, mixed>|null null if no lock file
     */
    public function inspect(string $env): ?array
    {
        $lockFile = $this->lockPath($env);
        if (!file_exists($lockFile)) {
            return null;
        }
        return $this->readLockFile($lockFile);
    }

    /**
     * Check if the lock for the given env is stale (TTL expired or PID dead).
     */
    public function isStale(string $env): bool
    {
        $data = $this->inspect($env);
        if ($data === null) {
            return true; // No lock = no staleness concern
        }
        return $this->isStaleData($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isStaleData(array $data): bool
    {
        $ttl       = (int) ($data['ttl'] ?? $this->ttlSec);
        $startedAt = (int) ($data['started_at'] ?? 0);

        if ((time() - $startedAt) > $ttl) {
            return true; // Expired by TTL
        }

        $pid = (int) ($data['pid'] ?? 0);
        if ($pid > 0 && function_exists('posix_kill')) {
            // posix_kill with signal 0 checks if process exists
            if (!@posix_kill($pid, 0)) {
                return true; // Process is dead
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readLockFile(string $lockFile): ?array
    {
        $contents = file_get_contents($lockFile);
        if ($contents === false) {
            return null;
        }
        $data = json_decode($contents, true);
        return is_array($data) ? $data : null;
    }

    private function lockPath(string $env): string
    {
        $safeEnv = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $env);
        return "{$this->lockDir}/deploy-{$safeEnv}.lock";
    }
}
