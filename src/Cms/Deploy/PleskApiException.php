<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

use RuntimeException;

/**
 * Base exception for all Plesk API errors.
 *
 * Hierarchy:
 *   PleskApiException
 *   ├── PleskAuthException              (401/403 responses)
 *   ├── PleskEndpointNotFoundException  (404 — endpoint absent in this Plesk version)
 *   ├── PleskTransportException         (timeouts, DNS failures, SSL errors)
 *   └── PleskResponseException          (5xx, malformed JSON, body decode error)
 */
class PleskApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
