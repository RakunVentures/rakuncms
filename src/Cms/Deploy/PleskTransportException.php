<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

/**
 * Thrown on network-level failures: timeouts, DNS resolution errors, SSL handshake failures.
 * The Plesk server may be unreachable or the host/port configuration is wrong.
 */
final class PleskTransportException extends PleskApiException {}
