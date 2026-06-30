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
        $keys = $this->filterByConditions($this->resolveKeys($spec), $spec);

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
        // count() DEBE aplicar las condiciones igual que query(); si no,
        // sobrecuenta (devuelve toda la colección) y rompe la paginación de las
        // páginas de tag/categoría.
        return count($this->filterByConditions($this->resolveKeys($spec), $spec));
    }

    /**
     * Filtra las keys por las condiciones del spec (has/=/contains/...). Mismo
     * criterio que usa query(); extraído para que count() no diverja.
     *
     * @param  array<string, string>  $keys
     * @return array<string, string>
     */
    private function filterByConditions(array $keys, QuerySpec $spec): array
    {
        if ($spec->conditions === []) {
            return $keys;
        }

        return array_filter($keys, function (string $key) use ($spec): bool {
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

    public function findBySlug(string $collection, string $locale, string $slug): ?array
    {
        $key = $collection . ':' . $locale . ':' . $slug;
        $bySlug = $this->indices['by_locale_slug'] ?? [];
        $entryKey = is_array($bySlug) ? ($bySlug[$key] ?? null) : null;

        if ($entryKey !== null && isset($this->entries[$entryKey])) {
            $data = $this->entries[$entryKey];
            return ($data['status'] ?? 'published') === 'published' ? $data : null;
        }

        // Fallback: scan (covers root-only slug matches)
        foreach ($this->entries as $data) {
            if (($data['collection'] ?? null) !== $collection || ($data['locale'] ?? null) !== $locale) {
                continue;
            }
            if (($data['status'] ?? 'published') !== 'published') {
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
            $data = $this->entries[$path];
            return ($data['status'] ?? 'published') === 'published' ? $data : null;
        }
        foreach ($this->entries as $key => $data) {
            if (str_starts_with($key, $path)
                && ($locale === null || ($data['locale'] ?? null) === $locale)
                && ($data['status'] ?? 'published') === 'published'
            ) {
                return $data;
            }
        }
        return null;
    }

    public function allTags(): array
    {
        // Derive from published entries only (by_tag index now may include draft keys).
        $tags = [];
        foreach ($this->entries as $data) {
            if (($data['status'] ?? 'published') !== 'published') {
                continue;
            }
            if (!empty($data['tags']) && is_array($data['tags'])) {
                foreach ($data['tags'] as $tag) {
                    $tags[(string) $tag] = true;
                }
            }
        }
        $result = array_keys($tags);
        sort($result);
        return $result;
    }

    public function allDatePeriods(): array
    {
        // Derive from published entries only.
        $periods = [];
        foreach ($this->entries as $data) {
            if (($data['status'] ?? 'published') !== 'published') {
                continue;
            }
            $date = $data['date'] ?? null;
            if ($date !== null && $date !== '') {
                $periods[substr((string) $date, 0, 7)] = true;
            }
        }
        $result = array_keys($periods);
        sort($result);
        return $result;
    }

    public function each(?string $status = null): iterable
    {
        foreach ($this->entries as $row) {
            if ($status === 'all') {
                yield $row;
            } elseif ($status !== null) {
                if (($row['status'] ?? 'published') === $status) {
                    yield $row;
                }
            } else {
                // null → published only (safe default)
                if (($row['status'] ?? 'published') === 'published') {
                    yield $row;
                }
            }
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
            $keys = array_keys($this->entries);
        } else {
            $result = array_shift($sets);
            foreach ($sets as $set) {
                $result = array_intersect($result, $set);
            }
            $keys = array_values($result);
        }

        // Status filter: null or 'all' means no filter; anything else filters by that status.
        $statusFilter = $spec->status;
        if ($statusFilter !== null && $statusFilter !== 'all') {
            $keys = array_values(array_filter($keys, function (string $key) use ($statusFilter) {
                $entry = $this->entries[$key] ?? null;
                return $entry !== null && ($entry['status'] ?? 'published') === $statusFilter;
            }));
        }

        return $keys;
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
            // 'has' case-insensitive para strings (paridad con SqliteIndexStore).
            'has' => is_array($entryValue) && self::hasValue($entryValue, $value),
            default => false,
        };
    }

    /** @param array<array-key, mixed> $haystack */
    private static function hasValue(array $haystack, mixed $needle): bool
    {
        if (is_string($needle)) {
            $n = mb_strtolower(trim($needle));
            foreach ($haystack as $item) {
                if (is_string($item) && mb_strtolower(trim($item)) === $n) {
                    return true;
                }
            }
            return false;
        }
        return in_array($needle, $haystack);
    }
}
