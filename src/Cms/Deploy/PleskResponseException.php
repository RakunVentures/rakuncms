<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

/**
 * Thrown when Plesk returns a 5xx status or a body that cannot be parsed
 * (malformed JSON in REST responses, malformed XML in XML-RPC responses).
 */
final class PleskResponseException extends PleskApiException {}
