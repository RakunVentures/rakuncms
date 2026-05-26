<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

interface TransportInterface
{
    /**
     * Validate pre-conditions before attempting deployment.
     *
     * @return array<string> Empty array means validation passed; non-empty = list of error messages.
     */
    public function validate(DeployConfig $config, callable $logger): array;

    /**
     * Execute the deployment process.
     *
     * @return bool True if successful, false otherwise.
     */
    public function deploy(DeployConfig $config, callable $logger): bool;

    /**
     * Attempt a best-effort rollback after a failed deploy or health check.
     *
     * Implementations that do not support rollback MUST return true (no-op).
     *
     * @return bool True if rollback succeeded or was a no-op; false if it failed.
     */
    public function rollback(DeployConfig $config, callable $logger): bool;
}
