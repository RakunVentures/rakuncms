<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

/**
 * Result of a Plesk CLI gateway call (POST /api/v2/cli/{id}/call).
 *
 * Plesk returns a JSON body shaped as {"code": int, "stdout": str, "stderr": str}.
 * A non-zero $code is NOT a transport error — it is the CLI tool's own exit code.
 * The caller decides how to react (parse stdout, swallow, throw).
 */
final class CliResult
{
    public function __construct(
        public readonly int $code,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {}

    public function isSuccess(): bool
    {
        return $this->code === 0;
    }
}
