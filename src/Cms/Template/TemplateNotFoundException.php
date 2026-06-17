<?php

declare(strict_types=1);

namespace Rkn\Cms\Template;

/**
 * Thrown when a template explicitly declared by config or frontmatter
 * cannot be located on disk. Fails loud rather than silently falling back —
 * silent fallback was the original RakunCMS footgun that hid an
 * orphan `default_template:` config for an entire site.
 */
final class TemplateNotFoundException extends \RuntimeException
{
}
