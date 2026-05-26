<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\Drivers;

use FTP\Connection;
use Rkn\Cms\Deploy\ArtifactBuilder;
use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\DeployLock;
use Rkn\Cms\Deploy\HealthChecker;
use Rkn\Cms\Deploy\TransportInterface;
use RuntimeException;

/**
 * FTP/FTPS deployment driver using ZIP artifacts and deploy.php v2 remote script.
 *
 * Pipeline per deploy-plesk-sync.md D4:
 *   1. Acquire local lock
 *   2. Build ZIP artifact (with manifest.json + .hmac file)
 *   3. Connect FTP/FTPS, passive mode
 *   4. Ensure remote directory structure
 *   5. Upload ZIP + .hmac (with post-upload size verification + 1 retry)
 *   6. POST deploy.php action=activate with HMAC headers
 *   7. Health check (if configured) → auto-rollback on failure
 *   8. POST deploy.php action=cleanup with keep_releases
 *   9. Release lock, delete local ZIP
 */
final class FtpDriver implements TransportInterface
{
    private const DEPLOY_PHP_TIMEOUT_SEC = 30;
    private const UPLOAD_RETRY_COUNT = 1;

    public function __construct(
        private readonly string $basePath,
        private readonly ?ArtifactBuilder $builder = null,
        private readonly ?DeployLock $lock = null,
        private readonly ?HealthChecker $healthChecker = null,
    ) {}

    /**
     * @return array<string>
     */
    public function validate(DeployConfig $config, callable $logger): array
    {
        $errors = [];

        // Check local lock
        $lock = $this->getLock();
        if (!$lock->isStale($config->environment)) {
            $data = $lock->inspect($config->environment);
            $releaseId = is_array($data) ? (string) ($data['release_id'] ?? 'unknown') : 'unknown';
            $errors[] = "Local lock is active for '{$config->environment}' (release: {$releaseId})";
            return $errors;
        }

        // Check deploy_secret
        if (empty($config->deploySecret)) {
            $errors[] = "deploy_secret is not configured. Set DEPLOY_SECRET in your .env and reference it in deploy.yaml";
        }

        // Check FTP connectivity
        $connErrors = $this->validateFtpConnection($config);
        $errors = array_merge($errors, $connErrors);

        // Check deploy.php is installed (via HTTP ping)
        if (!empty($config->healthUrl)) {
            $baseUrl  = $this->buildDeployUrl($config);
            $pingBody = json_encode(['action' => 'ping']);
            $secret   = (string) ($config->deploySecret ?? '');

            if ($pingBody !== false && $secret !== '') {
                [$status] = $this->callDeployPhp($baseUrl, $pingBody, $secret);
                if ($status !== 200) {
                    $errors[] = "deploy.php is not responding at {$baseUrl}. Run 'rakun deploy:install {$config->environment}' first.";
                }
            }
        }

        return $errors;
    }

    public function deploy(DeployConfig $config, callable $logger): bool
    {
        $lock     = $this->getLock();
        $releaseId = $this->buildReleaseId();

        $logger("<info>FTP deploy: building release {$releaseId}</info>");

        if (!$lock->acquire($config->environment, $releaseId)) {
            $logger('<error>Cannot acquire local deploy lock</error>');
            return false;
        }

        $zipPath  = null;
        $hmacPath = null;

        try {
            // 1. Build artifact
            $builder  = $this->getBuilder();
            $zipPath  = $builder->build(
                releaseId: $releaseId,
                exclude: [],
                gitSha: $this->detectGitSha(),
                phpVersionTarget: null,
                strategy: $config->strategy,
                deploySecret: $config->deploySecret,
            );
            $hmacPath = "{$zipPath}.hmac";
            $logger("<comment>Artifact: " . basename($zipPath) . " (" . number_format((int) filesize($zipPath)) . " bytes)</comment>");

            // 2. Connect FTP
            $conn = $this->connectFtp($config, $logger);

            try {
                // 3. Ensure remote structure
                $this->ensureRemoteStructure($conn, $config->path, $logger);

                // 4. Upload ZIP (with size verification + retry)
                $remoteZip  = "{$config->path}/releases/{$releaseId}.zip";
                $remoteHmac = "{$config->path}/releases/{$releaseId}.zip.hmac";

                $logger("<comment>Uploading ZIP...</comment>");
                $this->uploadWithVerification($conn, $zipPath, $remoteZip, $logger);

                if (file_exists($hmacPath)) {
                    $logger("<comment>Uploading HMAC...</comment>");
                    $this->uploadWithVerification($conn, $hmacPath, $remoteHmac, $logger);
                }
            } finally {
                ftp_close($conn);
            }

            // 5. Activate via deploy.php
            $deployUrl = $this->buildDeployUrl($config);
            $secret    = (string) ($config->deploySecret ?? '');

            $activateBody = (string) json_encode(['action' => 'activate', 'release_id' => $releaseId]);
            $logger("<comment>Calling deploy.php activate...</comment>");
            [$status, $response] = $this->callDeployPhp($deployUrl, $activateBody, $secret);

            if ($status !== 200) {
                throw new RuntimeException("Activation failed (HTTP {$status}): {$response}");
            }

            $activateData = json_decode($response, true);
            if (!is_array($activateData) || !($activateData['ok'] ?? false)) {
                throw new RuntimeException("Activation returned error: {$response}");
            }

            $logger("<info>Activation successful. Release: {$releaseId}</info>");

            // 6. Health check (if configured)
            if (!empty($config->healthUrl)) {
                $checker = $this->getHealthChecker($config);
                $healthy = $checker->check($config->healthUrl, $logger);

                if (!$healthy) {
                    $logger('<error>Health check failed, auto-rolling back...</error>');
                    $this->rollback($config, $logger);
                    return false;
                }
            }

            // 7. Cleanup old releases
            $keepReleases = max(1, (int) ($config->discovered['keep_releases'] ?? 5));
            $cleanupBody  = (string) json_encode(['action' => 'cleanup', 'keep' => $keepReleases]);
            $this->callDeployPhp($deployUrl, $cleanupBody, $secret);

            return true;

        } catch (\Throwable $e) {
            $logger("<error>FTP deploy error: {$e->getMessage()}</error>");
            return false;
        } finally {
            $lock->release($config->environment);
            if ($zipPath !== null && file_exists($zipPath)) {
                unlink($zipPath);
            }
            if ($hmacPath !== null && file_exists($hmacPath)) {
                unlink($hmacPath);
            }
        }
    }

    public function rollback(DeployConfig $config, callable $logger): bool
    {
        $secret = (string) ($config->deploySecret ?? '');

        if ($secret === '') {
            $logger('<comment>FTP rollback skipped: no deploy_secret configured</comment>');
            return true;
        }

        // Only attempt if healthUrl is set (provides a reliable base URL for deploy.php)
        if (empty($config->healthUrl)) {
            $logger('<comment>FTP rollback skipped: health_url not configured (cannot determine deploy.php URL)</comment>');
            return true;
        }

        $deployUrl = $this->buildDeployUrl($config);

        $payload = ['action' => 'rollback'];
        if (!empty($config->rollbackTo)) {
            $payload['to'] = $config->rollbackTo;
        }
        $body = (string) json_encode($payload);
        [$status, $response] = $this->callDeployPhp($deployUrl, $body, $secret);

        if ($status !== 200) {
            $logger("<error>Rollback failed (HTTP {$status}): {$response}</error>");
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !($data['ok'] ?? false)) {
            $logger("<error>Rollback error: {$response}</error>");
            return false;
        }

        $rolledTo = (string) ($data['rolled_to'] ?? 'unknown');
        $logger("<info>Rolled back to: {$rolledTo}</info>");
        return true;
    }

    // ─── FTP helpers ─────────────────────────────────────────────────────────

    /**
     * @return array<string>
     */
    private function validateFtpConnection(DeployConfig $config): array
    {
        $conn = $config->secure
            ? @ftp_ssl_connect($config->host, $config->port, 10)
            : @ftp_connect($config->host, $config->port, 10);

        if ($conn === false) {
            return ["Cannot connect to FTP at {$config->host}:{$config->port}"];
        }

        if (!@ftp_login($conn, (string) $config->user, (string) $config->pass)) {
            ftp_close($conn);
            return ["FTP login failed for user {$config->user} at {$config->host}"];
        }

        ftp_close($conn);
        return [];
    }

    private function connectFtp(DeployConfig $config, callable $logger): Connection
    {
        $conn = $config->secure
            ? @ftp_ssl_connect($config->host, $config->port, 30)
            : @ftp_connect($config->host, $config->port, 30);

        if ($conn === false) {
            throw new RuntimeException("Cannot connect to FTP at {$config->host}:{$config->port}");
        }

        if (!@ftp_login($conn, (string) $config->user, (string) $config->pass)) {
            ftp_close($conn);
            throw new RuntimeException("FTP login failed for user {$config->user}");
        }

        ftp_pasv($conn, true);
        ftp_set_option($conn, FTP_TIMEOUT_SEC, 60);

        $logger("<comment>FTP connected to {$config->host}:{$config->port}</comment>");
        return $conn;
    }

    private function ensureRemoteStructure(Connection $conn, string $path, callable $logger): void
    {
        $dirs = [
            "{$path}/releases",
            "{$path}/shared",
            "{$path}/shared/logs",
            "{$path}/shared/content",
            "{$path}/shared/uploads",
            "{$path}/shared/cache",
            "{$path}/shared/locks",
        ];

        foreach ($dirs as $dir) {
            @ftp_mkdir($conn, $dir);
        }
    }

    private function uploadWithVerification(
        Connection $conn,
        string $localPath,
        string $remotePath,
        callable $logger,
    ): void {
        $localSize = filesize($localPath);

        for ($attempt = 0; $attempt <= self::UPLOAD_RETRY_COUNT; $attempt++) {
            if ($attempt > 0) {
                $logger("<comment>Retrying upload (attempt {$attempt})...</comment>");
            }

            if (!ftp_put($conn, $remotePath, $localPath, FTP_BINARY)) {
                if ($attempt < self::UPLOAD_RETRY_COUNT) {
                    continue;
                }
                throw new RuntimeException("Failed to upload {$localPath} after " . (self::UPLOAD_RETRY_COUNT + 1) . " attempts");
            }

            // Verify size
            $remoteSize = ftp_size($conn, $remotePath);
            if ($remoteSize === $localSize) {
                return; // Success
            }

            $logger("<comment>Size mismatch: local={$localSize}, remote={$remoteSize}. Retrying...</comment>");
        }

        throw new RuntimeException(
            "Upload verification failed for {$remotePath}: size mismatch after retries"
        );
    }

    // ─── HMAC HTTP helpers ───────────────────────────────────────────────────

    /**
     * @return array{0: int, 1: string} [httpStatus, responseBody]
     */
    private function callDeployPhp(string $url, string $body, string $secret): array
    {
        $timestamp = time();
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $ch = curl_init($url);
        if ($ch === false) {
            return [0, 'curl_init failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => self::DEPLOY_PHP_TIMEOUT_SEC,
            CURLOPT_SSL_VERIFYPEER => false, // Shared hosting often has self-signed certs
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "X-Rakun-Signature: {$signature}",
                "X-Rakun-Timestamp: {$timestamp}",
            ],
        ]);

        $response = (string) curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $response];
    }

    private function buildDeployUrl(DeployConfig $config): string
    {
        if (empty($config->healthUrl)) {
            // Fall back to constructing URL from host
            $proto = $config->secure ? 'https' : 'http';
            return "{$proto}://{$config->host}/deploy.php";
        }

        // health_url is typically https://domain.com/health, deploy.php is at root.
        // Preserve port when present (needed for non-standard ports, e.g. local test servers).
        $parsed = parse_url($config->healthUrl);
        $scheme = (string) ($parsed['scheme'] ?? 'https');
        $host   = (string) ($parsed['host'] ?? $config->host);
        $port   = isset($parsed['port']) ? ":{$parsed['port']}" : '';

        return "{$scheme}://{$host}{$port}/deploy.php";
    }

    // ─── Misc helpers ────────────────────────────────────────────────────────

    private function buildReleaseId(): string
    {
        $base = date('Y-m-d_His');
        $sha7 = $this->detectGitSha();
        return "{$base}_{$sha7}";
    }

    private function detectGitSha(): string
    {
        // Use Symfony Process via a fresh Runner to avoid coupling
        $result = (new \Rkn\Cms\Deploy\Process\Runner($this->basePath))
            ->run(['git', 'rev-parse', '--short=7', 'HEAD'])
            ->withTimeout(10)
            ->execute();

        return $result->isSuccess() ? trim($result->stdout) : 'unknown';
    }

    private function getBuilder(): ArtifactBuilder
    {
        return $this->builder ?? new ArtifactBuilder($this->basePath);
    }

    private function getLock(): DeployLock
    {
        if ($this->lock !== null) {
            return $this->lock;
        }
        $lockDir = (string) ($_SERVER['HOME'] ?? sys_get_temp_dir()) . '/.rakun';
        return new DeployLock($lockDir);
    }

    private function getHealthChecker(DeployConfig $config): HealthChecker
    {
        return $this->healthChecker ?? new HealthChecker(verifySsl: $config->verifySsl);
    }
}
