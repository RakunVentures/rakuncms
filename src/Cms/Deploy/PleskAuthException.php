<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

/**
 * Thrown when Plesk returns 401 or 403.
 * The API key may be invalid, expired, or lack admin privileges.
 */
final class PleskAuthException extends PleskApiException {}
