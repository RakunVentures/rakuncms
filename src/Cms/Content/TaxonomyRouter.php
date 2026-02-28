<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

final class TaxonomyRouter
{
    /**
     * Try to resolve a taxonomy URL pattern.
     *
     * Patterns:
     *   /{collection}/tag/{tag}
     *   /{collection}/category/{category}
     *   /{collection}/archive/{year}
     *   /{collection}/archive/{year}/{month}
     *
     * @param list<string> $segments URL segments (without locale)
     * @return array{type: string, collection: string, value: string, query: Query}|null
     */
    public function resolve(array $segments, string $locale, Query $query): ?array
    {
        // Minimum: collection/type/value = 3 segments
        if (count($segments) < 3) {
            return null;
        }

        $collection = $segments[0];
        $type = $segments[1];
        $value = $segments[2];

        // Tag: /{collection}/tag/{tag}
        if ($type === 'tag' && count($segments) === 3) {
            return [
                'type' => 'tag',
                'collection' => $collection,
                'value' => $value,
                'query' => $query->collection($collection)
                    ->locale($locale)
                    ->where('tags', 'has', $value),
            ];
        }

        // Category: /{collection}/category/{category}
        if ($type === 'category' && count($segments) === 3) {
            return [
                'type' => 'category',
                'collection' => $collection,
                'value' => $value,
                'query' => $query->collection($collection)
                    ->locale($locale)
                    ->where('category', '=', $value),
            ];
        }

        // Archive: /{collection}/archive/{year} or /{collection}/archive/{year}/{month}
        if ($type === 'archive') {
            if (count($segments) === 3 && ctype_digit($value) && strlen($value) === 4) {
                // Year only
                return [
                    'type' => 'archive',
                    'collection' => $collection,
                    'value' => $value,
                    'query' => $query->collection($collection)
                        ->locale($locale)
                        ->where('date', 'contains', $value),
                ];
            }

            if (count($segments) === 4 && ctype_digit($value) && strlen($value) === 4) {
                $month = $segments[3];
                if (ctype_digit($month) && strlen($month) <= 2) {
                    $monthPadded = str_pad($month, 2, '0', STR_PAD_LEFT);
                    $datePrefix = $value . '-' . $monthPadded;
                    return [
                        'type' => 'archive',
                        'collection' => $collection,
                        'value' => $datePrefix,
                        'query' => $query->collection($collection)
                            ->locale($locale)
                            ->where('date', 'contains', $datePrefix),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Get all unique tags from a collection.
     *
     * @param array<string, array<string, list<string>|string>> $indices
     * @return list<string>
     */
    public function allTags(array $indices): array
    {
        $tags = array_keys($indices['by_tag'] ?? []);
        sort($tags);
        return $tags;
    }

    /**
     * Get all unique date periods (year-month) from a collection.
     *
     * @param array<string, array<string, list<string>|string>> $indices
     * @return list<string>
     */
    public function allDatePeriods(array $indices): array
    {
        $periods = array_keys($indices['by_date'] ?? []);
        sort($periods);
        return $periods;
    }
}
