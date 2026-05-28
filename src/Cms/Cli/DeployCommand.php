<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\Drivers\GitDriver;
use Rkn\Cms\Deploy\Drivers\WebhookDispatcher;
use Rkn\Cms\Deploy\HealthChecker;
use Rkn\Cms\Deploy\TransportInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: rakun deploy
 *
 * Pipeline (D5 from deploy-plesk-git.md):
 *   [1] DeployConfig::load($env)      ← fail → exit 1
 *   [2] $driver->validate()           ← fail → exit 1
 *   [3] $driver->deploy()             ← fail → rollback, exit 1
 *   [4] $webhook->dispatch()          ← fail → warning only, continue
 *   [5] $health->check()              ← fail → rollback, exit 1
 *
 * Each step logs timestamp and duration.
 */
#[AsCommand(name: 'deploy', description: 'Deploy the project to a remote server')]
final class DeployCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'environment',
            InputArgument::OPTIONAL,
            'The environment to deploy to',
            'production',
        );
        $this->addOption(
            'allow-dirty',
            null,
            InputOption::VALUE_NONE,
            'Bypass the clean working directory check (not recommended for production)',
        );
        $this->addOption(
            'no-health-check',
            null,
            InputOption::VALUE_NONE,
            'Skip the post-deploy health check',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = (string) $input->getArgument('environment');
        $allowDirty = (bool) $input->getOption('allow-dirty');
        $skipHealth = (bool) $input->getOption('no-health-check');

        $basePath = $this->findBasePath();
        $deployStart = microtime(true);

        $logger = function (string $message) use ($output): void {
            $output->writeln($message);
        };

        $output->writeln("<info>Initializing deployment to '{$environment}'...</info>");

        // ---- [1] Load config ----
        $t1 = microtime(true);
        try {
            $config = DeployConfig::load($basePath, $environment);
            $config->allowDirty = $allowDirty;
        } catch (\Throwable $e) {
            $output->writeln("<error>[1] Config load failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
        $output->writeln($this->timing('[1] Config', $t1));

        // ---- [2] Build driver ----
        $driver = $this->makeDriver($config, $basePath);
        if ($driver === null) {
            $output->writeln("<error>Transport method '{$config->method}' is not supported.</error>");
            return Command::FAILURE;
        }

        // ---- [3] Validate ----
        $t3 = microtime(true);
        $errors = $driver->validate($config, $logger);
        if (!empty($errors)) {
            $output->writeln("<error>[2] Validation failed:</error>");
            foreach ($errors as $err) {
                $output->writeln("  - {$err}");
            }
            return Command::FAILURE;
        }
        $output->writeln($this->timing('[2] Validate', $t3));

        // ---- [4] Deploy ----
        $t4 = microtime(true);
        $output->writeln("<info>[3] Deploying...</info>");
        $deployed = $driver->deploy($config, $logger);
        $output->writeln($this->timing('[3] Deploy', $t4));

        if (!$deployed) {
            $output->writeln('<error>[3] Deploy failed. Running rollback...</error>');
            $driver->rollback($config, $logger);
            return Command::FAILURE;
        }

        // ---- [5] Webhook (warning on failure, never aborts deploy) ----
        if ($config->webhookUrl !== null && $config->webhookUrl !== '') {
            $t5 = microtime(true);
            $output->writeln('<info>[4] Dispatching webhook...</info>');

            $dispatcher = new WebhookDispatcher(
                url: $config->webhookUrl,
                secret: $config->webhookSecret,
                verifySsl: $config->verifySsl,
            );

            $webhookOk = $dispatcher->dispatch([
                'event' => 'deploy',
                'environment' => $config->environment,
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ], $logger);

            $output->writeln($this->timing('[4] Webhook', $t5));

            if (!$webhookOk) {
                $output->writeln('<comment>[4] Webhook failed — deployment proceeded anyway.</comment>');
            }
        }

        // ---- [6] Health check ----
        if (!$skipHealth && $config->healthUrl !== null && $config->healthUrl !== '') {
            $t6 = microtime(true);
            $output->writeln('<info>[5] Running health check...</info>');

            $checker = new HealthChecker(verifySsl: $config->verifySsl);
            $healthy = $checker->check($config->healthUrl, $logger);
            $output->writeln($this->timing('[5] Health', $t6));

            if (!$healthy) {
                $output->writeln('<error>[5] Health check failed; rolling back...</error>');
                $driver->rollback($config, $logger);
                return Command::FAILURE;
            }
        } elseif ($skipHealth) {
            $output->writeln('<comment>[5] Health check skipped (--no-health-check).</comment>');
        }

        // ---- [7] Cleanup old releases (FTP only, via deploy.php) ----
        if ($config->method === 'ftp' && $config->healthUrl !== null && $config->healthUrl !== '') {
            $output->writeln('<info>[6] Cleaning up old releases...</info>');
            $this->triggerCleanup($config, $logger);
        }

        $totalMs = round((microtime(true) - $deployStart) * 1000);
        $output->writeln("<info>Deployment to '{$environment}' completed successfully in {$totalMs}ms.</info>");
        return Command::SUCCESS;
    }

    private function triggerCleanup(DeployConfig $config, callable $logger): void
    {
        $secret    = (string) ($config->deploySecret ?? '');
        if ($secret === '') {
            return;
        }

        $parsed    = parse_url((string) $config->healthUrl);
        $scheme    = (string) ($parsed['scheme'] ?? 'https');
        $host      = (string) ($parsed['host'] ?? $config->host);
        $deployUrl = "{$scheme}://{$host}/deploy.php";

        $keepReleases = max(1, (int) ($config->discovered['keep_releases'] ?? 5));
        $body         = (string) json_encode(['action' => 'cleanup', 'keep' => $keepReleases]);
        $timestamp    = time();
        $signature    = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $ch = curl_init($deployUrl);
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 30,
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

        if ($status === 200) {
            ($logger)("<comment>[6] Cleanup complete (keeping {$keepReleases} releases).</comment>");
        } else {
            ($logger)("<comment>[6] Cleanup warning: deploy.php returned HTTP {$status}.</comment>");
        }
    }

    private function makeDriver(DeployConfig $config, string $basePath): ?TransportInterface
    {
        return match ($config->method) {
            'git' => new GitDriver($basePath),
            'github-pull' => new \Rkn\Cms\Deploy\Drivers\GitHubPullDriver($basePath),
            'ftp' => new \Rkn\Cms\Deploy\Drivers\FtpDriver($basePath),
            'sftp' => new \Rkn\Cms\Deploy\Drivers\SftpDriver($basePath),
            default => null,
        };
    }

    private function timing(string $label, float $start): string
    {
        $ms = round((microtime(true) - $start) * 1000);
        return "<comment>{$label}: {$ms}ms</comment>";
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
