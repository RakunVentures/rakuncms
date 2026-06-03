<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

/**
 * Reference to the canonical location of a stored entry. Immutable.
 */
final class ContentRef
{
    public function __construct(
        public readonly string $collection,
        public readonly string $locale,
        public readonly string $slug,
        public readonly string $file,
    ) {
    }
}
