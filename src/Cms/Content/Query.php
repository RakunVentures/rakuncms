<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

final class Query
{
    /** @var array<string, array<string, mixed>> */
    private array $entries;

    /** @var array<string, array<string, list<string>|string>> */
    private array $indices;

    private ?string $collectionFilter = null;
    private ?string $localeFilter = null;
    private ?string $sectionFilter = null;

    /** @var list<array{field: string, operator: string, value: mixed}> */
    private array $conditions = [];

    private ?string $sortField = null;
    private string $sortDirection = 'asc';
    private ?int $limitCount = null;
    private int $offsetCount = 0;

    /**
     * @param array{entries: array<string, array<string, mixed>>, indices: array<string, array<string, list<string>|string>>, meta: array<string, mixed>} $index
     */
    public function __construct(array $index)
    {
        $this->entries = $index['entries'];
        $this->indices = $index['indices'];
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

        $sectionsIndex = $this->indices['sections'][$this->collectionFilter] ?? [];
        if (!is_array($sectionsIndex)) {
            return [];
        }

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
     * @return list<Entry>
     */
    public function get(): array
    {
        $keys = $this->resolveKeys();

        // Apply conditions
        $keys = array_filter($keys, function (string $key) {
            $entry = $this->entries[$key] ?? null;
            if ($entry === null) {
                return false;
            }

            foreach ($this->conditions as $condition) {
                if (!$this->matchCondition($entry, $condition)) {
                    return false;
                }
            }

            return true;
        });

        // Sort
        if ($this->sortField !== null) {
            $field = $this->sortField;
            $dir = $this->sortDirection;
            $entries = &$this->entries;

            usort($keys, function (string $a, string $b) use ($field, $dir, $entries) {
                $va = $entries[$a][$field] ?? '';
                $vb = $entries[$b][$field] ?? '';

                $cmp = is_numeric($va) && is_numeric($vb)
                    ? $va <=> $vb
                    : strcmp((string) $va, (string) $vb);

                return $dir === 'desc' ? -$cmp : $cmp;
            });
        }

        // Offset + Limit
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $keys = array_slice($keys, $this->offsetCount, $this->limitCount);
        }

        return array_map(fn (string $key) => Entry::fromArray($this->entries[$key]), array_values($keys));
    }

    public function first(): ?Entry
    {
        $results = $this->limit(1)->get();
        return $results[0] ?? null;
    }

    public function count(): int
    {
        return count($this->resolveKeys());
    }

    /**
     * Find an entry by collection + locale + slug combination.
     */
    public function findBySlug(string $collection, string $locale, string $slug): ?Entry
    {
        $key = $collection . ':' . $locale . ':' . $slug;
        $entryKey = $this->indices['by_locale_slug'][$key] ?? null;

        if ($entryKey !== null && isset($this->entries[$entryKey])) {
            return Entry::fromArray($this->entries[$entryKey]);
        }

        // Fallback: search through entries (also covers root-only slug matches)
        foreach ($this->entries as $data) {
            if ($data['collection'] !== $collection || $data['locale'] !== $locale) {
                continue;
            }
            $section = (string) ($data['section'] ?? '');
            $entrySlug = $data['slugs'][$locale] ?? $data['slug'];
            $fullSlug = $section !== '' ? $section . '/' . $entrySlug : $entrySlug;
            if ($fullSlug === $slug || $entrySlug === $slug) {
                return Entry::fromArray($data);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveKeys(): array
    {
        $sets = [];

        if ($this->collectionFilter !== null) {
            $sets[] = $this->indices['by_collection'][$this->collectionFilter] ?? [];
        }
        if ($this->localeFilter !== null) {
            $sets[] = $this->indices['by_locale'][$this->localeFilter] ?? [];
        }
        if ($this->sectionFilter !== null && $this->collectionFilter !== null) {
            $sectionKey = $this->collectionFilter . ':' . $this->sectionFilter;
            $sets[] = $this->indices['by_section'][$sectionKey] ?? [];
        }

        if ($sets === []) {
            return array_keys($this->entries);
        }

        $result = array_shift($sets);
        foreach ($sets as $set) {
            $result = array_intersect($result, $set);
        }

        return array_values($result);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array{field: string, operator: string, value: mixed} $condition
     */
    private function matchCondition(array $entry, array $condition): bool
    {
        $field = $condition['field'];
        $operator = $condition['operator'];
        $value = $condition['value'];

        $entryValue = $entry[$field] ?? ($entry['meta'][$field] ?? null);

        return match ($operator) {
            '=' , '==' => $entryValue == $value,
            '===' => $entryValue === $value,
            '!=' , '<>' => $entryValue != $value,
            '>' => $entryValue > $value,
            '<' => $entryValue < $value,
            '>=' => $entryValue >= $value,
            '<=' => $entryValue <= $value,
            'contains' => is_string($entryValue) && str_contains(strtolower($entryValue), strtolower((string) $value)),
            'in' => is_array($value) && in_array($entryValue, $value),
            'has' => is_array($entryValue) && in_array($value, $entryValue),
            default => false,
        };
    }
}
