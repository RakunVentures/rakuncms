<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Deploy\DeployConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use RuntimeException;

/**
 * CLI command: rakun deploy:install
 *
 * Installs the remote deploy.php v2 helper to the server via FTP.
 * Also ensures shared/.env exists on the remote with DEPLOY_SECRET.
 * Validates the installation with a HMAC-signed ping.
 *
 * Safe to re-run: will not overwrite shared/.env if it already exists.
 * If a v1 deploy.php is detected, it is renamed to deploy.php.v1.bak.
 */
#[AsCommand(name: 'deploy:install', description: 'Install the remote deploy.php v2 helper script')]
final class DeployInstallCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('environment', InputArgument::OPTIONAL, 'The environment to install to', 'production');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = (string) $input->getArgument('environment');
        $basePath    = $this->findBasePath();

        try {
            $config = DeployConfig::load($basePath, $environment);

            if ($config->method !== 'ftp') {
                $output->writeln(
                    "<info>deploy:install is only required for FTP deployments. "
                    . "Git/SFTP handle activation natively.</info>"
                );
                return Command::SUCCESS;
            }

            if (empty($config->deploySecret)) {
                $output->writeln(
                    "<error>deploy_secret is not configured. "
                    . "Set DEPLOY_SECRET in your .env and reference it in deploy.yaml.</error>"
                );
                return Command::FAILURE;
            }

            $output->writeln("<info>Installing deploy.php v2 to {$config->host}...</info>");

            $conn = $this->connectFtp($config);

            try {
                // Step 1: Check if v1 deploy.php exists (no X-Rakun-Signature header accepted)
                $this->migrateV1IfPresent($conn, $config->path, $output);

                // Step 2: Upload stub v2 (do NOT replace placeholder — v2 reads secret from shared/.env)
                $stubPath = __DIR__ . '/../Deploy/Resources/deploy.php.stub';
                $stub     = (string) file_get_contents($stubPath);

                // v2 stub reads DEPLOY_SECRET from shared/.env — no token substitution needed
                $tmp = tempnam(sys_get_temp_dir(), 'rakun-deploy-install-');
                if ($tmp === false) {
                    throw new RuntimeException('Cannot create temp file');
                }
                file_put_contents($tmp, $stub);

                $remotePath = "{$config->path}/deploy.php";
                if (!ftp_put($conn, $remotePath, $tmp, FTP_BINARY)) {
                    throw new RuntimeException("Failed to upload deploy.php to {$remotePath}");
                }
                unlink($tmp);
                $output->writeln("<info>deploy.php v2 uploaded to {$remotePath}</info>");

                // Step 3: Ensure shared/.env with DEPLOY_SECRET (do NOT overwrite if exists)
                $remoteEnv = "{$config->path}/shared/.env";
                $this->ensureSharedEnv($conn, $remoteEnv, (string) $config->deploySecret, $output);

                // Step 4: Validate installation via HMAC ping
                $deployUrl = $this->buildDeployUrl($config);
                $pingOk    = $this->ping($deployUrl, (string) $config->deploySecret);

                if ($pingOk) {
                    $output->writeln("<info>Ping successful — deploy.php v2 is operational at {$deployUrl}</info>");
                } else {
                    $output->writeln(
                        "<comment>Warning: ping to {$deployUrl} did not return 200. "
                        . "Check that the web server serves {$config->path}/deploy.php directly.</comment>"
                    );
                }

            } finally {
                ftp_close($conn);
            }

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function connectFtp(DeployConfig $config): \FTP\Connection
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
        return $conn;
    }

    private function migrateV1IfPresent(\FTP\Connection $conn, string $remotePath, OutputInterface $output): void
    {
        // Try to rename the old file (best-effort)
        $v1Path  = "{$remotePath}/deploy.php";
        $v1Bak   = "{$remotePath}/deploy.php.v1.bak";

        // Check if current deploy.php is v1 by looking at its size
        // v1 stub is ~1.5KB, v2 is ~10KB+
        $size = @ftp_size($conn, $v1Path);
        if ($size !== false && $size > 0 && $size < 3000) {
            if (@ftp_rename($conn, $v1Path, $v1Bak)) {
                $output->writeln("<comment>v1 deploy.php backed up to deploy.php.v1.bak</comment>");
            }
        }
    }

    private function ensureSharedEnv(
        \FTP\Connection $conn,
        string $remoteEnv,
        string $secret,
        OutputInterface $output,
    ): void {
        // Check if shared/.env already exists
        $size = @ftp_size($conn, $remoteEnv);
        if ($size !== false && $size > 0) {
            $output->writeln("<comment>shared/.env already exists (not overwriting DEPLOY_SECRET)</comment>");
            return;
        }

        // Create shared dir if needed
        @ftp_mkdir($conn, dirname($remoteEnv));

        $tmp = tempnam(sys_get_temp_dir(), 'rakun-env-');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create temp file for .env');
        }
        file_put_contents($tmp, "DEPLOY_SECRET={$secret}\n");

        if (!ftp_put($conn, $remoteEnv, $tmp, FTP_BINARY)) {
            unlink($tmp);
            throw new RuntimeException("Failed to upload shared/.env to {$remoteEnv}");
        }

        unlink($tmp);
        $output->writeln("<info>shared/.env created with DEPLOY_SECRET</info>");
    }

    private function ping(string $deployUrl, string $secret): bool
    {
        $body      = (string) json_encode(['action' => 'ping']);
        $timestamp = time();
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $ch = curl_init($deployUrl);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "X-Rakun-Signature: {$signature}",
                "X-Rakun-Timestamp: {$timestamp}",
            ],
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status === 200;
    }

    private function buildDeployUrl(DeployConfig $config): string
    {
        if (!empty($config->healthUrl)) {
            $parsed = parse_url($config->healthUrl);
            $scheme = (string) ($parsed['scheme'] ?? 'https');
            $host   = (string) ($parsed['host'] ?? $config->host);
            return "{$scheme}://{$host}/deploy.php";
        }

        $proto = $config->secure ? 'https' : 'http';
        return "{$proto}://{$config->host}/deploy.php";
    }

    private function findBasePath(): string
    {
        try {
            $app = \Rkn\Framework\Application::getInstance();
            if ($app !== null) {
                return $app->getBasePath();
            }
        } catch (\Throwable) {
        }

        return getcwd() ?: dirname(__DIR__, 3);
    }
}
