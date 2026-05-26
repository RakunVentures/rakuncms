<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper over Symfony Process.
 *
 * All external commands in the Deploy module MUST go through this class.
 * shell_exec / exec / passthru / proc_open / system are FORBIDDEN in src/Cms/Deploy/.
 *
 * Usage (fluent builder style):
 *   $result = (new Runner($basePath, $logger))
 *       ->run(['git', 'push', 'plesk', 'main'])
 *       ->withTimeout(60)
 *       ->execute();
 */
final class Runner
{
    /** @var array<string> */
    private array $command = [];

    private int $timeoutSec = 300;

    /** @var array<string, string> */
    private array $env = [];

    private ?string $workingDir = null;

    private ?string $stdin = null;

    /** @var callable|null */
    private readonly mixed $logger;

    public function __construct(
        private readonly string $basePath,
        ?callable $logger = null,
    ) {
        $this->logger = $logger;
    }

    /**
     * Set the command to run (array of argv, never a shell string).
     *
     * @param array<string> $command
     */
    public function run(array $command): static
    {
        $clone = clone $this;
        $clone->command = $command;
        return $clone;
    }

    public function withTimeout(int $seconds): static
    {
        $clone = clone $this;
        $clone->timeoutSec = $seconds;
        return $clone;
    }

    /**
     * @param array<string, string> $env
     */
    public function withEnv(array $env): static
    {
        $clone = clone $this;
        $clone->env = array_merge($this->env, $env);
        return $clone;
    }

    public function withWorkingDir(string $path): static
    {
        $clone = clone $this;
        $clone->workingDir = $path;
        return $clone;
    }

    public function withInput(string $stdin): static
    {
        $clone = clone $this;
        $clone->stdin = $stdin;
        return $clone;
    }

    /**
     * Execute the command and return a ProcessResult.
     * Never throws — wraps all exceptions into a failed ProcessResult.
     */
    public function execute(): ProcessResult
    {
        $cwd = $this->workingDir ?? $this->basePath;
        $env = empty($this->env) ? null : $this->env;

        $process = new Process(
            command: $this->command,
            cwd: $cwd,
            env: $env,
            input: $this->stdin,
            timeout: (float) $this->timeoutSec,
        );

        $startTime = microtime(true);
        $stdoutBuf = '';
        $stderrBuf = '';
        $logger = $this->logger;

        try {
            $process->run(function (string $type, string $buffer) use (&$stdoutBuf, &$stderrBuf, $logger): void {
                if ($type === Process::OUT) {
                    $stdoutBuf .= $buffer;
                    if ($logger !== null) {
                        foreach (explode("\n", rtrim($buffer, "\n")) as $line) {
                            ($logger)("  {$line}");
                        }
                    }
                } else {
                    $stderrBuf .= $buffer;
                    if ($logger !== null) {
                        foreach (explode("\n", rtrim($buffer, "\n")) as $line) {
                            ($logger)("  [stderr] {$line}");
                        }
                    }
                }
            });
        } catch (ProcessTimedOutException) {
            $duration = microtime(true) - $startTime;
            if ($logger !== null) {
                ($logger)("  [timeout] Process exceeded {$this->timeoutSec}s");
            }
            return new ProcessResult(
                exitCode: 124,
                stdout: $stdoutBuf,
                stderr: $stderrBuf . "\n[Process timed out after {$this->timeoutSec}s]",
                command: $this->command,
                duration: $duration,
            );
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            return new ProcessResult(
                exitCode: 1,
                stdout: $stdoutBuf,
                stderr: $stderrBuf . "\n[Process exception: {$e->getMessage()}]",
                command: $this->command,
                duration: $duration,
            );
        }

        $duration = microtime(true) - $startTime;

        return new ProcessResult(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $stdoutBuf,
            stderr: $stderrBuf,
            command: $this->command,
            duration: $duration,
        );
    }

    /**
     * Execute and throw a \RuntimeException if exit code != 0.
     *
     * @throws \RuntimeException
     */
    public function mustExecute(): ProcessResult
    {
        $result = $this->execute();
        if (!$result->isSuccess()) {
            $cmd = implode(' ', $this->command);
            throw new \RuntimeException(
                "Command failed (exit {$result->exitCode}): {$cmd}\n{$result->stderr}"
            );
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the composer binary following the order defined in D2 of deploy-plesk-git.md:
     *   1. $configured (deploy.yaml: composer_bin)
     *   2. getenv('COMPOSER')
     *   3. `which composer` if in PATH
     *   4. `herd composer` if herd is in PATH
     *   throws \RuntimeException if none found
     *
     * @return array<string>
     */
    public static function resolveComposer(string $basePath, ?string $configured = null): array
    {
        // 1. Explicit config
        if ($configured !== null && $configured !== '') {
            return [$configured];
        }

        // 2. COMPOSER env var
        $envComposer = getenv('COMPOSER');
        if ($envComposer !== false && $envComposer !== '') {
            return [$envComposer];
        }

        // 3. `which composer`
        $whichComposer = self::which('composer', $basePath);
        if ($whichComposer !== null) {
            return ['composer'];
        }

        // 4. `herd composer`
        $whichHerd = self::which('herd', $basePath);
        if ($whichHerd !== null) {
            return ['herd', 'composer'];
        }

        throw new \RuntimeException(
            'Composer binary not found. Set `composer_bin` in deploy.yaml, export COMPOSER=/path/to/composer, or install Composer in your PATH.'
        );
    }

    /**
     * @return string|null The full path if found, null otherwise.
     */
    private static function which(string $binary, string $basePath): ?string
    {
        $runner = new self($basePath);
        $result = $runner->run(['which', $binary])->withTimeout(10)->execute();
        if ($result->isSuccess()) {
            $path = trim($result->stdout);
            return $path !== '' ? $path : null;
        }
        return null;
    }
}
