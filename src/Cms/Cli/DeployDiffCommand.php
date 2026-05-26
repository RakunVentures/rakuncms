<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\ReleaseManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: rakun deploy:diff [env]
 *
 * Downloads the manifest of the active remote release via deploy.php action=status,
 * builds a local manifest from the working tree, and shows the diff.
 *
 * Exit codes:
 *   0 = no differences (identical manifests)
 *   1 = differences exist (useful for CI checks)
 *   2 = command error (config load failed, deploy.php unreachable, etc.)
 */
#[AsCommand(name: 'deploy:diff', description: 'Show diff between local working tree and active remote release')]
final class DeployDiffCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('environment', InputArgument::OPTIONAL, 'Target environment', 'production');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = (string) $input->getArgument('environment');
        $basePath    = $this->findBasePath();

        try {
            $config = DeployConfig::load($basePath, $environment);
        } catch (\Throwable $e) {
            $output->writeln("<error>Config load failed: {$e->getMessage()}</error>");
            return 2;
        }

        if ($config->method !== 'ftp') {
            $output->writeln(
                "<comment>deploy:diff is only available for FTP deployments that have deploy.php installed.</comment>"
            );
            return 2;
        }

        $secret    = (string) ($config->deploySecret ?? '');
        $deployUrl = $this->buildDeployUrl($config);

        // 1. Fetch remote status (includes manifest JSON)
        $statusBody = (string) json_encode(['action' => 'status']);
        [$httpStatus, $response] = $this->callDeployPhp($deployUrl, $statusBody, $secret, $config->verifySsl);

        if ($httpStatus !== 200) {
            $output->writeln("<error>deploy.php returned HTTP {$httpStatus}: {$response}</error>");
            return 2;
        }

        /** @var array<string, mixed>|null $statusData */
        $statusData = json_decode($response, true);
        if (!is_array($statusData) || !($statusData['ok'] ?? false)) {
            $output->writeln("<error>Unexpected response: {$response}</error>");
            return 2;
        }

        $remoteManifestData = $statusData['manifest'] ?? null;
        if (!is_array($remoteManifestData) || empty($remoteManifestData)) {
            $output->writeln('<comment>No manifest found on remote server. Remote may be on deploy.php v1.</comment>');
            return 2;
        }

        $remoteManifest = ReleaseManifest::fromJson((string) json_encode($remoteManifestData));

        // 2. Build local manifest from working tree
        $localManifest = ReleaseManifest::fromDirectory(
            dirPath: $basePath,
            releaseId: '__dryrun__',
            gitSha: null,
            phpVersionTarget: null,
            strategy: $config->strategy,
        );

        // 3. Diff
        $diff = $localManifest->diff($remoteManifest);

        $added    = $diff['added'];
        $removed  = $diff['removed'];
        $modified = $diff['modified'];

        $totalChanges = count($added) + count($removed) + count($modified);

        if ($totalChanges === 0) {
            $output->writeln("<info>No differences — local working tree matches active remote release.</info>");
            return Command::SUCCESS; // exit 0
        }

        $output->writeln("<comment>Differences between local and remote ({$remoteManifest->releaseId}):</comment>");
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['Change', 'File', 'Details']);

        foreach ($added as $path) {
            $table->addRow(['<info>added</info>', $path, '']);
        }
        foreach ($removed as $path) {
            $table->addRow(['<error>removed</error>', $path, '']);
        }
        foreach ($modified as $path) {
            // Find sha256 of local vs remote for this file
            $localSha  = '';
            $remoteSha = '';
            foreach ($localManifest->files as $f) {
                if ($f['path'] === $path) {
                    $localSha = substr($f['sha256'], 0, 7);
                    break;
                }
            }
            foreach ($remoteManifest->files as $f) {
                if ($f['path'] === $path) {
                    $remoteSha = substr($f['sha256'], 0, 7);
                    break;
                }
            }
            $table->addRow(['<comment>modified</comment>', $path, "{$remoteSha} → {$localSha}"]);
        }

        $table->render();
        $addedCount    = count($added);
        $removedCount  = count($removed);
        $modifiedCount = count($modified);

        $output->writeln('');
        $output->writeln(
            "<comment>Summary: {$totalChanges} change(s) "
            . "({$addedCount} added, {$removedCount} removed, {$modifiedCount} modified)</comment>"
        );

        return Command::FAILURE; // exit 1 = differences exist
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function callDeployPhp(string $url, string $body, string $secret, bool $verifySsl = true): array
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
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
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
        if (!empty($config->healthUrl)) {
            $parsed = parse_url($config->healthUrl);
            $scheme = (string) ($parsed['scheme'] ?? 'https');
            $host   = (string) ($parsed['host'] ?? $config->host);
            $port   = isset($parsed['port']) ? ":{$parsed['port']}" : '';
            return "{$scheme}://{$host}{$port}/deploy.php";
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
