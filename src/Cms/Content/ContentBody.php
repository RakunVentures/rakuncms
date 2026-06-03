<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

/**
 * Read result: an entry's frontmatter + raw (unrendered) body. Immutable.
 */
final class ContentBody
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
        public readonly string $file,
    ) {
    }
}
