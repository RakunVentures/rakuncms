<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Rkn\Cms\Deploy\Process\ProcessResult;
use Rkn\Cms\Deploy\Process\Runner;
use RuntimeException;

/**
 * Helper for managing apple/container runtime in integration tests.
 *
 * All container CLI interactions go through Runner (zero shell_exec/exec/passthru).
 * Tests that require a running container system must call isAvailable() first
 * and markTestSkipped() if it returns false.
 *
 * Usage:
 *   if (!ContainerHelper::isAvailable()) { markTestSkipped(...); }
 *   $h = new ContainerHelper();
 *   $h->pull('alpine:3.20');
 *   $id = $h->run('my-test', 'alpine:3.20', [8080 => 80]);
 *   $h->waitForPort('127.0.0.1', 8080);
 *   $result = $h->exec('my-test', ['ls', '/']);
 *   $h->stop('my-test');
 *   $h->remove('my-test');
 */
final class ContainerHelper
{
    private Runner $runner;

    public function __construct(?Runner $runner = null)
    {
        $this->runner = $runner ?? new Runner(sys_get_temp_dir());
    }

    /**
     * Returns true only if the `container` binary is in PATH
     * AND `container system status` reports the apiserver is running.
     *
     * Never throws — all failures return false.
     */
    public static function isAvailable(): bool
    {
        try {
            // Step 1: binary must exist
            $helper = new self();
            $which = $helper->runner->run(['which', 'container'])->withTimeout(5)->execute();
            if (!$which->isSuccess() || trim($which->stdout) === '') {
                return false;
            }

            // Step 2: daemon must be running
            $status = $helper->runner->run(['container', 'system', 'status'])->withTimeout(10)->execute();
            if (!$status->isSuccess()) {
                return false;
            }

            // apple/container exits 0 only when running; the output contains "running"
            // Handle both "apiserver is running" and generic "running" in stdout
            $output = strtolower($status->stdout . $status->stderr);
            if (str_contains($output, 'not running') || str_contains($output, 'is not running')) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Pull a container image.
     *
     * @throws RuntimeException with stderr if the pull fails.
     */
    public function pull(string $image): void
    {
        $result = $this->runner
            ->run(['container', 'image', 'pull', $image])
            ->withTimeout(300)
            ->execute();

        if (!$result->isSuccess()) {
            throw new RuntimeException(
                "container pull {$image} failed (exit {$result->exitCode}): {$result->stderr}"
            );
        }
    }

    /**
     * Run a container in detached mode. Returns the container ID.
     *
     * If a container with $name already exists (running or stopped),
     * it is stopped and removed first (idempotent).
     *
     * @param array<int, int>    $portMap  [hostPort => containerPort]
     * @param array<string, string> $volumes  [hostPath => containerPath]
     * @param array<string, string> $env      [KEY => value]
     */
    public function run(
        string $name,
        string $image,
        array $portMap = [],
        array $volumes = [],
        array $env = [],
    ): string {
        // Ensure no stale container with the same name exists
        $this->stop($name);
        $this->remove($name);

        $cmd = ['container', 'run', '--detach', '--name', $name];

        foreach ($portMap as $hostPort => $containerPort) {
            $cmd[] = '-p';
            $cmd[] = "{$hostPort}:{$containerPort}";
        }

        foreach ($volumes as $hostPath => $containerPath) {
            $cmd[] = '-v';
            $cmd[] = "{$hostPath}:{$containerPath}";
        }

        foreach ($env as $key => $value) {
            $cmd[] = '-e';
            $cmd[] = "{$key}={$value}";
        }

        $cmd[] = $image;

        $result = $this->runner
            ->run($cmd)
            ->withTimeout(60)
            ->execute();

        if (!$result->isSuccess()) {
            throw new RuntimeException(
                "container run {$name} failed (exit {$result->exitCode}): {$result->stderr}"
            );
        }

        return trim($result->stdout);
    }

    /**
     * Wait until a TCP port is open on $host:$port.
     *
     * Polls every 250ms using fsockopen (no exec).
     *
     * @throws RuntimeException when $timeoutSec is exceeded.
     */
    public function waitForPort(string $host, int $port, int $timeoutSec = 30): void
    {
        $deadline = microtime(true) + $timeoutSec;

        while (microtime(true) < $deadline) {
            $fp = @fsockopen($host, $port, $errno, $errstr, 1.0);
            if ($fp !== false) {
                fclose($fp);
                return;
            }
            usleep(250_000); // 250ms
        }

        throw new RuntimeException(
            "Timed out after {$timeoutSec}s waiting for {$host}:{$port} to become available"
        );
    }

    /**
     * Execute a command inside a running container.
     *
     * @param array<string> $cmd
     */
    public function exec(string $name, array $cmd): ProcessResult
    {
        $fullCmd = array_merge(['container', 'exec', $name], $cmd);

        return $this->runner
            ->run($fullCmd)
            ->withTimeout(30)
            ->execute();
    }

    /**
     * Stop a container. Idempotent — does not throw if the container does not exist.
     */
    public function stop(string $name): void
    {
        $this->runner
            ->run(['container', 'stop', $name])
            ->withTimeout(30)
            ->execute();
        // Intentionally ignoring exit code — idempotent
    }

    /**
     * Remove a container. Idempotent — does not throw if the container does not exist.
     */
    public function remove(string $name): void
    {
        $this->runner
            ->run(['container', 'rm', $name])
            ->withTimeout(30)
            ->execute();
        // Intentionally ignoring exit code — idempotent
    }

    /**
     * Pick a free TCP port on the loopback interface using stream_socket_server.
     *
     * Binds to port 0 (OS-assigned), reads the assigned port, closes the socket.
     *
     * @throws RuntimeException if no free port can be allocated.
     */
    public function pickFreePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Cannot allocate free port: [{$errno}] {$errstr}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false) {
            throw new RuntimeException('Cannot determine assigned port from stream_socket_server');
        }

        // Format is "127.0.0.1:PORT"
        $parts = explode(':', $name);
        $port  = (int) end($parts);

        if ($port <= 1024) {
            throw new RuntimeException("Allocated port {$port} is unexpectedly in privileged range");
        }

        return $port;
    }
}
