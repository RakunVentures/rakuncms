<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

/**
 * Immutable value object representing a raw HTTP response from Plesk.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        /** @var array<string, string> */
        public readonly array $headers = [],
    ) {}
}
