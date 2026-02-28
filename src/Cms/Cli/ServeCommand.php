<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

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
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to listen on', '8080');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');

        $port = $this->handlePortConflict($host, $port, $input, $output);
        if ($port === false) {
            return Command::SUCCESS;
        }

        $basePath = $this->findBasePath();
        $docRoot = $basePath . '/public';

        if (!is_dir($docRoot)) {
            $output->writeln('<error>Public directory not found: ' . $docRoot . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>RakunCMS development server started for %s:</info> http://%s:%s',
            basename($basePath),
            $host,
            $port,
        ));
        $output->writeln('Document root: ' . $docRoot);
        $output->writeln('Press Ctrl+C to stop.');

        $command = sprintf(
            '%s -S %s:%s -t %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host),
            $port,
            escapeshellarg($docRoot),
        );

        passthru($command, $exitCode);

        return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function handlePortConflict(string $host, int $port, InputInterface $input, OutputInterface $output): int|false
    {
        while ($this->isPortInUse($host, $port)) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion(
                sprintf('<comment>El puerto %d ya está en uso. ¿Qué deseas hacer?</comment>', $port),
                [
                    'kill' => 'Detener el proceso actual y usar este puerto',
                    'next' => 'Lanzar el servidor en otro puerto disponible',
                    'cancel' => 'Cancelar'
                ],
                'next'
            );

            $answer = $helper->ask($input, $output, $question);

            if ($answer === 'cancel') {
                $output->writeln('Operación cancelada.');
                return false;
            }

            if ($answer === 'kill') {
                $this->killProcessOnPort($port, $output);
                sleep(1); // Dar tiempo al sistema operativo para liberar el puerto
                if ($this->isPortInUse($host, $port)) {
                    $output->writeln('<error>No se pudo liberar el puerto. Intentando con otro...</error>');
                    $port++;
                }
            } elseif ($answer === 'next') {
                $port++;
            }
        }

        return $port;
    }

    private function isPortInUse(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errorCode, $errorMessage, 1);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    private function killProcessOnPort(int $port, OutputInterface $output): void
    {
        $os = PHP_OS_FAMILY;
        if ($os === 'Darwin' || $os === 'Linux') {
            $pid = shell_exec("lsof -t -i TCP:{$port} -s TCP:LISTEN");
            if ($pid) {
                shell_exec("kill -9 " . trim((string)$pid));
                $output->writeln("<info>Proceso anterior (PID: " . trim((string)$pid) . ") detenido exitosamente.</info>");
            } else {
                $output->writeln("<error>No se pudo encontrar el proceso usando el puerto {$port}.</error>");
            }
        } else {
            $output->writeln("<error>La detención automática de procesos no está soportada en Windows. Por favor libera el puerto manualmente.</error>");
        }
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
