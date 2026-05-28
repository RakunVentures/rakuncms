<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

/**
 * Thrown when a REST v2 endpoint returns 404.
 *
 * The endpoint is not exposed by this Plesk installation/version. Consumers
 * should either pick a different REST endpoint, fall back to the CLI gateway
 * (POST /api/v2/cli/{id}/call), or report a clear "feature not available" error.
 */
final class PleskEndpointNotFoundException extends PleskApiException {}
