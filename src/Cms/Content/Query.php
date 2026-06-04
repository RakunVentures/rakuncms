<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

use Rkn\Cms\Content\Stores\PhpArrayIndexStore;

/**
 * Fluent content query. Public API is unchanged; internally it accumulates a
 * QuerySpec and delegates to an IndexStore (PhpArray or SQLite). Constructing
 * with the legacy {entries, indices} array stays supported for back-compat.
 */
final class Query
{
    private IndexStore $store;

    private ?string $collectionFilter = null;
    private ?string $localeFilter = null;
    private ?string $sectionFilter = null;

    /** @var list<array{field: string, operator: string, value: mixed}> */
    private array $conditions = [];

    private ?string $sortField = null;
    private string $sortDirection = 'asc';
    private ?int $limitCount = null;
    private int $offsetCount = 0;
    private ?string $statusFilter = 'published';

    /**
     * @param array{entries: array<string, array<string, mixed>>, indices: array<string, mixed>, meta?: array<string, mixed>}|IndexStore $index
     */
    public function __construct(array|IndexStore $index)
    {
        $this->store = $index instanceof IndexStore ? $index : new PhpArrayIndexStore($index);
    }

    public function collection(string $name): self
    {
        $clone = clone $this;
        $clone->collectionFilter = $name;
        return $clone;
    }

    public function locale(string $locale): self
    {
        $clone = clone $this;
        $clone->localeFilter = $locale;
        return $clone;
    }

    /**
     * Filter entries to a single section path (relative to collection root).
     * Pass an empty string to match root-level entries only.
     */
    public function section(string $section): self
    {
        $clone = clone $this;
        $clone->sectionFilter = $section;
        return $clone;
    }

    /**
     * Enumerate sections declared for a collection (typically set via .collection() first).
     * Returns ordered list of section descriptors with locale-resolved titles.
     *
     * @return list<array{section: string, title: string, titles: array<string, string>, order: int, icon: ?string, meta: array<string, mixed>}>
     */
    public function sections(?string $locale = null): array
    {
        if ($this->collectionFilter === null) {
            return [];
        }

        $sectionsIndex = $this->store->sectionsFor($this->collectionFilter);
        $resolvedLocale = $locale ?? $this->localeFilter;

        $result = [];
        foreach ($sectionsIndex as $descriptor) {
            if (!is_array($descriptor)) {
                continue;
            }
            $title = $descriptor['title'] ?? '';
            if ($resolvedLocale !== null && isset($descriptor['titles'][$resolvedLocale])) {
                $title = $descriptor['titles'][$resolvedLocale];
            }
            $result[] = [
                'section' => (string) ($descriptor['section'] ?? ''),
                'title' => (string) $title,
                'titles' => is_array($descriptor['titles'] ?? null) ? $descriptor['titles'] : [],
                'order' => (int) ($descriptor['order'] ?? 0),
                'icon' => isset($descriptor['icon']) ? (string) $descriptor['icon'] : null,
                'meta' => is_array($descriptor['meta'] ?? null) ? $descriptor['meta'] : [],
            ];
        }

        usort($result, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $result;
    }

    public function where(string $field, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $clone->conditions[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        return $clone;
    }

    public function sort(string $field, string $direction = 'asc'): self
    {
        $clone = clone $this;
        $clone->sortField = $field;
        $clone->sortDirection = $direction;
        return $clone;
    }

    public function limit(int $count): self
    {
        $clone = clone $this;
        $clone->limitCount = $count;
        return $clone;
    }

    public function offset(int $count): self
    {
        $clone = clone $this;
        $clone->offsetCount = $count;
        return $clone;
    }

    /**
     * Filter results to the given status ('published', 'draft', 'scheduled').
     * Pass null or use includeAllStatuses() to remove the filter.
     */
    public function withStatus(?string $status): self
    {
        $clone = clone $this;
        $clone->statusFilter = $status;
        return $clone;
    }

    /**
     * Remove the status filter — include entries of all statuses.
     */
    public function includeAllStatuses(): self
    {
        $clone = clone $this;
        $clone->statusFilter = 'all';
        return $clone;
    }

    /**
     * @return list<Entry>
     */
    public function get(): array
    {
        $rows = $this->store->query($this->spec());
        return array_map(fn (array $row) => Entry::fromArray($row), $rows);
    }

    public function first(): ?Entry
    {
        $results = $this->limit(1)->get();
        return $results[0] ?? null;
    }

    public function count(): int
    {
        return $this->store->count($this->spec());
    }

    /**
     * Find an entry by collection + locale + slug combination.
     */
    public function findBySlug(string $collection, string $locale, string $slug): ?Entry
    {
        $row = $this->store->findBySlug($collection, $locale, $slug);
        return $row !== null ? Entry::fromArray($row) : null;
    }

    private function spec(): QuerySpec
    {
        return new QuerySpec(
            collection: $this->collectionFilter,
            locale: $this->localeFilter,
            section: $this->sectionFilter,
            conditions: $this->conditions,
            sortField: $this->sortField,
            sortDirection: $this->sortDirection,
            limit: $this->limitCount,
            offset: $this->offsetCount,
            status: $this->statusFilter,
        );
    }
}
