<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

/**
 * Immutable description of a content query (filters + sort + pagination),
 * produced by Query and consumed by an IndexStore. Backend-agnostic: the
 * PhpArray store interprets it in-memory; the SQLite store translates it to SQL.
 */
final class QuerySpec
{
    /**
     * @param list<array{field: string, operator: string, value: mixed}> $conditions
     */
    public function __construct(
        public readonly ?string $collection = null,
        public readonly ?string $locale = null,
        public readonly ?string $section = null,
        public readonly array $conditions = [],
        public readonly ?string $sortField = null,
        public readonly string $sortDirection = 'asc',
        public readonly ?int $limit = null,
        public readonly int $offset = 0,
    ) {
    }
}
