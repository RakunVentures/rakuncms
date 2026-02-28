<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: rakun serve
 *
 * Starts PHP's built-in development server.
 */
#[AsCommand(name: 'serve', description: 'Start the development server')]
final class ServeCommand extends Command
{

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind to', 'localhost')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to listen on', '8000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (string) $input->getOption('port');

        $basePath = $this->findBasePath();
        $docRoot = $basePath . '/public';

        if (!is_dir($docRoot)) {
            $output->writeln('<error>Public directory not found: ' . $docRoot . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>RakunCMS development server started:</info> http://%s:%s',
            $host,
            $port,
        ));
        $output->writeln('Press Ctrl+C to stop.');

        $command = sprintf(
            '%s -S %s:%s -t %s',
            PHP_BINARY,
            $host,
            $port,
            escapeshellarg($docRoot),
        );

        passthru($command, $exitCode);

        return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
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
