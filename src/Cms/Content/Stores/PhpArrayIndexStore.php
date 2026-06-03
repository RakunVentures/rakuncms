<?php

declare(strict_types=1);

namespace Rkn\Cms\Content\Stores;

use Rkn\Cms\Content\IndexStore;
use Rkn\Cms\Content\QuerySpec;

/**
 * In-memory IndexStore over the legacy {entries, indices} array (cache/content-index.php).
 * Preserves the exact filter/sort/slice/lookup semantics that lived in Query, so
 * it is behaviourally identical to the historical implementation.
 */
final class PhpArrayIndexStore implements IndexStore
{
    /** @var array<string, array<string, mixed>> */
    private array $entries;

    /** @var array<string, mixed> */
    private array $indices;

    /**
     * @param array{entries?: array<string, array<string, mixed>>, indices?: array<string, mixed>, meta?: array<string, mixed>} $index
     */
    public function __construct(array $index)
    {
        $this->entries = $index['entries'] ?? [];
        $this->indices = $index['indices'] ?? [];
    }

    public function query(QuerySpec $spec): array
    {
        $keys = $this->resolveKeys($spec);

        // Conditions
        if ($spec->conditions !== []) {
            $keys = array_filter($keys, function (string $key) use ($spec) {
                $entry = $this->entries[$key] ?? null;
                if ($entry === null) {
                    return false;
                }
                foreach ($spec->conditions as $condition) {
                    if (!$this->matchCondition($entry, $condition)) {
                        return false;
                    }
                }
                return true;
            });
        }

        // Sort
        if ($spec->sortField !== null) {
            $field = $spec->sortField;
            $dir = $spec->sortDirection;
            $entries = $this->entries;
            $keys = array_values($keys);
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
        $keys = array_values($keys);
        if ($spec->offset > 0 || $spec->limit !== null) {
            $keys = array_slice($keys, $spec->offset, $spec->limit);
        }

        return array_map(fn (string $key) => $this->entries[$key], array_values($keys));
    }

    public function count(QuerySpec $spec): int
    {
        return count($this->resolveKeys($spec));
    }

    public function findBySlug(string $collection, string $locale, string $slug): ?array
    {
        $key = $collection . ':' . $locale . ':' . $slug;
        $bySlug = $this->indices['by_locale_slug'] ?? [];
        $entryKey = is_array($bySlug) ? ($bySlug[$key] ?? null) : null;

        if ($entryKey !== null && isset($this->entries[$entryKey])) {
            return $this->entries[$entryKey];
        }

        // Fallback: scan (covers root-only slug matches)
        foreach ($this->entries as $data) {
            if (($data['collection'] ?? null) !== $collection || ($data['locale'] ?? null) !== $locale) {
                continue;
            }
            $section = (string) ($data['section'] ?? '');
            $entrySlug = $data['slugs'][$locale] ?? $data['slug'];
            $fullSlug = $section !== '' ? $section . '/' . $entrySlug : $entrySlug;
            if ($fullSlug === $slug || $entrySlug === $slug) {
                return $data;
            }
        }

        return null;
    }

    public function sectionsFor(string $collection): array
    {
        $sections = $this->indices['sections'][$collection] ?? [];
        return is_array($sections) ? $sections : [];
    }

    public function findEntryByPath(string $path, ?string $locale = null): ?array
    {
        if (isset($this->entries[$path])) {
            return $this->entries[$path];
        }
        foreach ($this->entries as $key => $data) {
            if (str_starts_with($key, $path) && ($locale === null || ($data['locale'] ?? null) === $locale)) {
                return $data;
            }
        }
        return null;
    }

    public function allTags(): array
    {
        $byTag = $this->indices['by_tag'] ?? [];
        return is_array($byTag) ? array_keys($byTag) : [];
    }

    public function allDatePeriods(): array
    {
        $byDate = $this->indices['by_date'] ?? [];
        return is_array($byDate) ? array_keys($byDate) : [];
    }

    public function each(): iterable
    {
        foreach ($this->entries as $row) {
            yield $row;
        }
    }

    /**
     * @return list<string>
     */
    private function resolveKeys(QuerySpec $spec): array
    {
        $sets = [];
        if ($spec->collection !== null) {
            $sets[] = $this->indices['by_collection'][$spec->collection] ?? [];
        }
        if ($spec->locale !== null) {
            $sets[] = $this->indices['by_locale'][$spec->locale] ?? [];
        }
        if ($spec->section !== null && $spec->collection !== null) {
            $sets[] = $this->indices['by_section'][$spec->collection . ':' . $spec->section] ?? [];
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
            '=', '==' => $entryValue == $value,
            '===' => $entryValue === $value,
            '!=', '<>' => $entryValue != $value,
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
