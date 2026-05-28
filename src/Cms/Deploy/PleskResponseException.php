<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

/**
 * Thrown when Plesk returns a 5xx status, a 4xx that is not auth/404, or a body
 * that cannot be parsed as JSON.
 */
final class PleskResponseException extends PleskApiException {}
