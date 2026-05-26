<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\Process;

/**
 * Immutable value object representing the outcome of a process execution.
 */
final class ProcessResult
{
    /**
     * @param array<string> $command
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly array $command,
        public readonly float $duration,
    ) {}

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }
}
