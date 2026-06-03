<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

/**
 * Input DTO for a content write (create or replace). Immutable.
 */
final class ContentDraft
{
    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public function __construct(
        public readonly string $collection,
        public readonly string $locale,
        public readonly string $slug,
        public readonly array $frontmatter,
        public readonly string $body,
    ) {
    }
}
