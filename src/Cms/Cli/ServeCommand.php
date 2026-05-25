<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * CLI command: rakun serve
 *
 * Starts PHP's built-in development server with auto-cache clear on file changes.
 */
#[AsCommand(name: 'serve', description: 'Start the development server with auto-cache clear')]
final class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind to', 'localhost')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to listen on', '8080')
            ->addOption('no-watch', null, InputOption::VALUE_NONE, 'Disable auto-cache clear on file changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');
        $watch = !$input->getOption('no-watch');

        $basePath = $this->findBasePath();

        // Validate worktree: Is another instance already serving this project?
        if (!$this->handleWorktreeConflict($basePath, $input, $output)) {
            return Command::SUCCESS;
        }

        $port = $this->handlePortConflict($host, $port, $input, $output);
        if ($port === false) {
            return Command::SUCCESS;
        }

        $docRoot = $basePath . '/public';
        
        if (!is_dir($docRoot)) {
            $output->writeln('<error>Public directory not found: ' . $docRoot . '</error>');
            return Command::FAILURE;
        }

        // Clear cache on startup
        $output->writeln('<info>Cleaning up cache before startup...</info>');
        try {
            $clearCommand = $this->getApplication()->find('cache:clear');
            $clearCommand->run(new ArrayInput([]), $output);
        } catch (\Throwable $e) {
            $output->writeln('<error>Startup cache clear failed: ' . $e->getMessage() . '</error>');
        }

        $output->writeln(sprintf(
            '<info>RakunCMS development server started for %s:</info> http://%s:%s',
            basename($basePath),
            $host,
            $port,
        ));
        $output->writeln('Document root: ' . $docRoot);

        // Record this process in the lock file
        $this->createLockFile($basePath, $host, $port);
        
        if ($watch) {
            $output->writeln('<info>Watching for file changes in content/, config/ and templates/...</info>');
            $this->startWatcher($basePath, $output);
        }

        $output->writeln('Press Ctrl+C to stop.');

        // Support Laravel Herd Dump Server
        putenv('VAR_DUMPER_FORMAT=server');
        putenv('VAR_DUMPER_SERVER=127.0.0.1:9912');

        $command = sprintf(
            '%s -d display_errors=1 -d display_startup_errors=1 -d error_reporting=E_ALL -d log_errors=1 -S %s:%s -t %s %s/index.php',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host),
            $port,
            escapeshellarg($docRoot),
            escapeshellarg($docRoot),
        );

        passthru($command, $exitCode);

        // Cleanup lock on exit
        $this->removeLockFile($basePath);

        return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function handleWorktreeConflict(string $basePath, InputInterface $input, OutputInterface $output): bool
    {
        $lockFile = $basePath . '/cache/serve.lock';
        if (!file_exists($lockFile)) {
            return true;
        }

        $data = json_decode(file_get_contents($lockFile), true);
        $pid = $data['pid'] ?? null;
        $port = $data['port'] ?? 'unknown';
        $host = $data['host'] ?? 'localhost';

        if ($pid && $this->isProcessRunning((int)$pid)) {
            $output->writeln(sprintf('<comment>Atención: Ya existe un servidor corriendo para este proyecto (PID: %d) en http://%s:%s</comment>', $pid, $host, $port));
            
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion(
                '¿Qué deseas hacer?',
                [
                    'attach'    => 'Usar el proceso actual (no lanzar uno nuevo)',
                    'terminate' => 'Terminar el proceso actual y lanzar uno nuevo',
                    'subsequent' => 'Lanzar un servicio subsecuente (varias instancias)',
                    'cancel'    => 'Cancelar'
                ],
                'attach'
            );

            $answer = $helper->ask($input, $output, $question);

            if ($answer === 'cancel') {
                return false;
            }

            if ($answer === 'attach') {
                $output->writeln('<info>Usando proceso existente. El servidor ya está disponible.</info>');
                return false;
            }

            if ($answer === 'terminate') {
                $output->writeln('<info>Terminando proceso anterior...</info>');
                shell_exec("kill -9 $pid");
                $this->removeLockFile($basePath);
                sleep(1);
                return true;
            }

            if ($answer === 'subsequent') {
                $output->writeln('<info>Lanzando instancia adicional...</info>');
                return true;
            }
        }

        // Process not running, cleanup stale lock
        $this->removeLockFile($basePath);
        return true;
    }

    private function isProcessRunning(int $pid): bool
    {
        if (function_exists('posix_getpgid')) {
            return posix_getpgid($pid) !== false;
        }
        $os = PHP_OS_FAMILY;
        if ($os === 'Darwin' || $os === 'Linux') {
            $output = shell_exec("ps -p $pid");
            return strpos((string)$output, (string)$pid) !== false;
        }
        return false;
    }

    private function createLockFile(string $basePath, string $host, int $port): void
    {
        $lockFile = $basePath . '/cache/serve.lock';
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($lockFile, json_encode([
            'pid' => getmypid(),
            'port' => $port,
            'host' => $host,
            'started_at' => date('Y-m-d H:i:s')
        ]));
        
        // Ensure cleanup even on unexpected PHP exit
        register_shutdown_function(fn() => $this->removeLockFile($basePath));
    }

    private function removeLockFile(string $basePath): void
    {
        $lockFile = $basePath . '/cache/serve.lock';
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    /**
     * Simple background watcher that monitors mtime of files.
     */
    private function startWatcher(string $basePath, OutputInterface $output): void
    {
        $pid = pcntl_fork();

        if ($pid == -1) {
            $output->writeln('<error>Could not fork process for watcher.</error>');
            return;
        }

        if ($pid > 0) {
            // Parent continues to start the server
            return;
        }

        // Child process becomes the watcher
        $watchDirs = [
            $basePath . '/content',
            $basePath . '/config',
            $basePath . '/templates',
        ];

        $lastHash = $this->calculateDirsHash($watchDirs);

        while (true) {
            sleep(1);
            $currentHash = $this->calculateDirsHash($watchDirs);

            if ($currentHash !== $lastHash) {
                $output->writeln("\n<comment>[" . date('H:i:s') . "] Change detected. Clearing cache...</comment>");
                
                // Invoke cache:clear command internally
                try {
                    $command = $this->getApplication()->find('cache:clear');
                    $command->run(new ArrayInput([]), $output);
                } catch (\Throwable $e) {
                    $output->writeln('<error>Auto-cache clear failed: ' . $e->getMessage() . '</error>');
                }
                
                $lastHash = $currentHash;
            }
        }
    }

    private function calculateDirsHash(array $dirs): string
    {
        $files = '';
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                $files .= $file->getPathname() . $file->getMTime();
            }
        }
        return md5($files);
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
