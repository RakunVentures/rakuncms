<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

/**
 * Thrown when a REST v2 endpoint returns 404.
 *
 * This typically signals that the feature is not available via REST v2
 * and the consumer should use XML-RPC instead. Consumers are responsible
 * for catching this and routing to the appropriate protocol — the Client
 * does NOT auto-fallback (by design, per D1 in deploy-plesk-api.md).
 */
final class PleskEndpointNotFoundException extends PleskApiException {}
