<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Deploy\DeployConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: rakun deploy:status [env]
 *
 * Displays the current release and last N releases on the remote server.
 * Fetches data from deploy.php v2 via HMAC-authenticated action=status.
 */
#[AsCommand(name: 'deploy:status', description: 'Show deployment status on the remote server')]
final class DeployStatusCommand extends Command
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
            return Command::FAILURE;
        }

        // Only FTP driver supports status via deploy.php; SFTP/git don't have the endpoint
        if ($config->method !== 'ftp') {
            $output->writeln(
                "<comment>deploy:status is only available for FTP deployments that have deploy.php installed.</comment>"
            );
            return Command::SUCCESS;
        }

        $secret     = (string) ($config->deploySecret ?? '');
        $deployUrl  = $this->buildDeployUrl($config);
        $statusBody = (string) json_encode(['action' => 'status']);

        [$httpStatus, $response] = $this->callDeployPhp($deployUrl, $statusBody, $secret, $config->verifySsl);

        if ($httpStatus !== 200) {
            $output->writeln("<error>deploy.php returned HTTP {$httpStatus}: {$response}</error>");
            return Command::FAILURE;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($response, true);
        if (!is_array($data) || !($data['ok'] ?? false)) {
            $output->writeln("<error>Unexpected response from deploy.php: {$response}</error>");
            return Command::FAILURE;
        }

        $io = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);
        $io->section('Remote Server Information');
        $io->table(
            ['Property', 'Value'],
            [
                ['PHP Version', $data['php'] ?? 'Unknown'],
                ['OS', $data['os'] ?? 'Unknown'],
            ]
        );

        $current  = (string) ($data['current'] ?? 'None');
        $manifest = is_array($data['manifest'] ?? null) ? $data['manifest'] : [];
        $releases = is_array($data['releases'] ?? null) ? $data['releases'] : [];
        $diskKb   = (int) ($data['disk_usage_kb'] ?? 0);
        $diskMb   = round($diskKb / 1024, 1);
        $php      = (string) ($data['php'] ?? 'unknown');

        $output->writeln('');
        $output->writeln("<info>Environment:    {$environment}</info>");
        $output->writeln("<info>Active release: {$current}</info>");

        if (!empty($manifest)) {
            $builtAt  = (string) ($manifest['built_at'] ?? '');
            $gitSha   = (string) ($manifest['git_sha'] ?? '');
            $strategy = (string) ($manifest['strategy'] ?? '');
            $output->writeln("<comment>Built at:       {$builtAt}</comment>");
            $output->writeln("<comment>Git SHA:        {$gitSha}</comment>");
            $output->writeln("<comment>Strategy:       {$strategy}</comment>");
        }

        $output->writeln("<comment>PHP version:    {$php}</comment>");
        $output->writeln("<comment>Disk (releases): {$diskMb} MB</comment>");
        $output->writeln('');

        if (!empty($releases)) {
            $table = new Table($output);
            $table->setHeaders(['Release ID', 'Status']);
            foreach ($releases as $releaseId) {
                $status = $releaseId === $current ? '<info>[active]</info>' : '';
                $table->addRow([$releaseId, $status]);
            }
            $table->render();
            $output->writeln('');
        }

        return Command::SUCCESS;
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
            $host   = (string) ($parsed['host'] ?? $config->domain);
            $port   = isset($parsed['port']) ? ":{$parsed['port']}" : '';
            return "{$scheme}://{$host}{$port}/deploy.php";
        }

        $proto = $config->secure ? 'https' : 'http';
        return "{$proto}://{$config->domain}/deploy.php";
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
