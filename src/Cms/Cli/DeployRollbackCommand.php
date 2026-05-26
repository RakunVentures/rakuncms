<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\TransportInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: rakun deploy:rollback [env] [--to=release-id]
 *
 * Reverts the active release on the server.
 * Without --to: rolls back to the penultimate release (driver decides).
 * With --to: targets a specific release ID.
 */
#[AsCommand(name: 'deploy:rollback', description: 'Rollback to a previous release on the server')]
final class DeployRollbackCommand extends Command
{
    public function __construct(
        private readonly ?TransportInterface $injectedDriver = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('environment', InputArgument::OPTIONAL, 'Target environment', 'production');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Specific release ID to rollback to');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = (string) $input->getArgument('environment');
        $targetId    = (string) ($input->getOption('to') ?? '');
        $basePath    = $this->findBasePath();
        $t0          = microtime(true);

        $logger = fn (string $m) => $output->writeln($m);

        try {
            $config = DeployConfig::load($basePath, $environment);
        } catch (\Throwable $e) {
            $output->writeln("<error>Config load failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $driver = $this->injectedDriver ?? $this->makeDriver($config, $basePath);
        if ($driver === null) {
            $output->writeln("<error>Transport method '{$config->method}' is not supported.</error>");
            return Command::FAILURE;
        }

        // If --to is specified and the driver is FTP, inject it into the payload
        // by overriding config (KISS: driver.rollback handles the --to param through config extension)
        // We rely on the driver's rollback to post action=rollback to deploy.php
        // For SFTP with rsync, the driver finds the penultimate.
        // The --to option is forwarded via a modified config approach.
        if ($targetId !== '') {
            $config->rollbackTo = $targetId;
        }

        $output->writeln("<info>Rolling back '{$environment}'...</info>");
        $ok = $driver->rollback($config, $logger);

        $ms = (int) round((microtime(true) - $t0) * 1000);

        if ($ok) {
            $output->writeln("<info>Rollback completed in {$ms}ms.</info>");
            return Command::SUCCESS;
        }

        $output->writeln("<error>Rollback failed ({$ms}ms).</error>");
        return Command::FAILURE;
    }

    private function makeDriver(DeployConfig $config, string $basePath): ?TransportInterface
    {
        return match ($config->method) {
            'git'  => new \Rkn\Cms\Deploy\Drivers\GitDriver($basePath),
            'ftp'  => new \Rkn\Cms\Deploy\Drivers\FtpDriver($basePath),
            'sftp' => new \Rkn\Cms\Deploy\Drivers\SftpDriver($basePath),
            default => null,
        };
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
