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
            ->addOption('no-watch', null, InputOption::VALUE_NONE, 'Disable auto-cache clear on file changes')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, 'PHP_CLI_SERVER_WORKERS — concurrent worker processes', '4')
            ->addOption('no-rdc', null, InputOption::VALUE_NONE, 'Do not delegate to Rakun Dev Console even if rdc is installed')
            ->addOption('detach', null, InputOption::VALUE_NONE, 'With rdc delegation: adopt and return without attaching to the logs')
            ->addOption('stop', null, InputOption::VALUE_NONE, 'Stop the rdc-supervised server for this site');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');
        $watch = !$input->getOption('no-watch');
        $workers = max(1, (int) $input->getOption('workers'));

        $basePath = $this->findBasePath();

        if ($input->getOption('stop')) {
            return $this->stopRdcSupervised($basePath, $output);
        }

        // Delegate supervision to Rakun Dev Console when available: the dev
        // server then shows up in the RDC app with status, logs, health and
        // queue metrics, survives crashes (launchd respawn) and outlives this
        // terminal. Falls back silently to the local php -S below.
        if (!$input->getOption('no-rdc')) {
            $delegated = $this->delegateToRdc($basePath, $host, $port, (bool) $input->getOption('detach'), $output);
            if ($delegated !== null) {
                return $delegated;
            }
        }

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

        // Live-reload stamp: middleware in dev mode reads its mtime via long-poll.
        $stampFile = $basePath . '/cache/.dev-reload-stamp';
        $this->touchReloadStamp($stampFile);

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
            $output->writeln('<info>Browser live-reload active at /__dev/reload (long polling).</info>');
            $this->startWatcher($basePath, $stampFile, $output);
        }

        $output->writeln('Press Ctrl+C to stop.');

        // Support Laravel Herd Dump Server
        putenv('VAR_DUMPER_FORMAT=server');
        putenv('VAR_DUMPER_SERVER=127.0.0.1:9912');

        // Activate DevReloadMiddleware inside spawned `php -S` workers.
        putenv('RAKUN_DEV_RELOAD=1');
        putenv('RAKUN_DEV_RELOAD_STAMP=' . $stampFile);

        // Concurrent workers so the live-reload polling never starves real requests.
        putenv('PHP_CLI_SERVER_WORKERS=' . $workers);
        $output->writeln(sprintf('<info>PHP_CLI_SERVER_WORKERS=%d</info>', $workers));

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
    private function startWatcher(string $basePath, string $stampFile, OutputInterface $output): void
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

        /** @phpstan-ignore-next-line watcher process runs until terminated */
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

                $this->touchReloadStamp($stampFile);

                $lastHash = $currentHash;
            }
        }
    }

    private function touchReloadStamp(string $stampFile): void
    {
        $dir = dirname($stampFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @touch($stampFile);
    }

    /**
     * @param list<string> $dirs
     */
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

    // ------------------------------------------------------------------
    // Rakun Dev Console (rdc) delegation
    // ------------------------------------------------------------------

    /**
     * Delegate supervision to Rakun Dev Console via `rdc site:adopt`.
     *
     * Returns an exit code when delegation handled the request, or null to
     * fall back to the local php -S server. Delegation only happens when ALL
     * of these hold:
     *   - rdc is on PATH,
     *   - stdout is a TTY — this is the recursion guard: the launchd plist
     *     written by rdc runs `rakun serve` itself, without a TTY,
     *   - RDC_SUPERVISED is not set (belt and braces; the rdc plist sets it),
     *   - `rdc site:adopt` answered ok (otherwise we warn and fall back).
     *
     * On success the terminal attaches to the supervised logs: Ctrl+C only
     * detaches, the server keeps running under launchd. `rakun serve --stop`
     * (or the RDC app) stops it.
     */
    private function delegateToRdc(string $basePath, string $host, int $port, bool $detach, OutputInterface $output): ?int
    {
        if (getenv('RDC_SUPERVISED') === '1') {
            return null;
        }

        if (!function_exists('stream_isatty') || !@stream_isatty(STDOUT)) {
            return null;
        }

        $rdc = $this->findRdcBinary();
        if ($rdc === null) {
            return null;
        }

        $command = sprintf(
            '%s site:adopt %s --host=%s --port=%d --format=json 2>/dev/null',
            escapeshellarg($rdc),
            escapeshellarg($basePath),
            escapeshellarg($host),
            $port,
        );
        $raw = shell_exec($command);
        $envelope = is_string($raw) ? json_decode($raw, true) : null;
        $data = is_array($envelope) && is_array($envelope['data'] ?? null) ? $envelope['data'] : null;

        if ($data === null || ($envelope['ok'] ?? false) !== true) {
            $output->writeln('<comment>rdc está instalado pero site:adopt falló; usando el servidor local (usa --no-rdc para omitir este intento).</comment>');
            return null;
        }

        $workspace = (string) ($data['workspace'] ?? '');
        $site = (string) ($data['site'] ?? '');
        $process = (string) ($data['process'] ?? 'serve');
        $url = (string) ($data['url'] ?? "http://{$host}:{$port}");
        $pid = $data['pid'] ?? null;

        $this->writeAdoptReference($basePath, $workspace, $site, $process, $url);

        $output->writeln("<info>Supervisado por Rakun Dev Console:</info> {$url}" . ($pid !== null ? " (PID {$pid})" : ''));
        $output->writeln("Registrado como {$workspace}/{$site} · proceso '{$process}' — visible en la app de RDC.");
        $output->writeln('El servidor corre bajo launchd: sobrevive a esta terminal y se reinicia solo.');
        $output->writeln("Detener: <comment>rakun serve --stop</comment> (o desde Rakun Dev Console).");

        if ($detach) {
            return Command::SUCCESS;
        }

        $output->writeln('Streaming de logs (Ctrl+C desconecta; el servidor sigue corriendo)...');
        $output->writeln('');

        // Swallow SIGINT in this process so Ctrl+C only kills the child log
        // stream and we still get to print the detach notice below.
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function (): void {
            });
        }

        passthru(sprintf(
            '%s process:logs %s %s %s --follow',
            escapeshellarg($rdc),
            escapeshellarg($workspace),
            escapeshellarg($site),
            escapeshellarg($process),
        ));

        $output->writeln('');
        $output->writeln("<info>Desconectado de los logs. El servidor sigue corriendo en {$url}.</info>");
        $output->writeln('Detener: <comment>rakun serve --stop</comment> (o desde Rakun Dev Console).');

        return Command::SUCCESS;
    }

    /**
     * Stop the rdc-supervised server adopted for this site.
     */
    private function stopRdcSupervised(string $basePath, OutputInterface $output): int
    {
        $rdc = $this->findRdcBinary();
        if ($rdc === null) {
            $output->writeln('<error>rdc no está instalado; --stop solo aplica a servidores supervisados por Rakun Dev Console.</error>');
            return Command::FAILURE;
        }

        $reference = $this->readAdoptReference($basePath);
        if ($reference === null) {
            $output->writeln('<error>No hay registro de adopción rdc para este sitio (cache/rdc-adopt.json).</error>');
            $output->writeln('Detenlo desde la app de Rakun Dev Console o con: rdc process:disable <workspace> <site> serve');
            return Command::FAILURE;
        }

        passthru(sprintf(
            '%s process:disable %s %s %s',
            escapeshellarg($rdc),
            escapeshellarg($reference['workspace']),
            escapeshellarg($reference['site']),
            escapeshellarg($reference['process']),
        ), $exitCode);

        if ($exitCode === 0) {
            $output->writeln('<info>Servidor supervisado detenido.</info>');
            return Command::SUCCESS;
        }

        return Command::FAILURE;
    }

    private function findRdcBinary(): ?string
    {
        $path = trim((string) shell_exec('command -v rdc 2>/dev/null'));

        return $path !== '' ? $path : null;
    }

    /**
     * Persist which rdc workspace/site/process adopted this site so that
     * `rakun serve --stop` can resolve the disable target without asking rdc.
     */
    private function writeAdoptReference(string $basePath, string $workspace, string $site, string $process, string $url): void
    {
        $dir = "{$basePath}/cache";
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents("{$dir}/rdc-adopt.json", json_encode([
            'workspace' => $workspace,
            'site' => $site,
            'process' => $process,
            'url' => $url,
            'adopted_at' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @return array{workspace: string, site: string, process: string}|null
     */
    private function readAdoptReference(string $basePath): ?array
    {
        $file = "{$basePath}/cache/rdc-adopt.json";
        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }

        $workspace = $data['workspace'] ?? null;
        $site = $data['site'] ?? null;
        $process = $data['process'] ?? null;
        if (!is_string($workspace) || !is_string($site) || !is_string($process) || $workspace === '' || $site === '' || $process === '') {
            return null;
        }

        return ['workspace' => $workspace, 'site' => $site, 'process' => $process];
    }
}
