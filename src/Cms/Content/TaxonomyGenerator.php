<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

final class TaxonomyGenerator
{
    /**
     * Generate all taxonomy page definitions for the build command.
     *
     * @param array{entries: array<string, array<string, mixed>>, indices: array<string, array<string, list<string>|string>>, meta: array<string, mixed>} $index
     * @param list<string> $locales
     * @return list<array{type: string, collection: string, value: string, locale: string, url: string, entries: Query}>
     */
    public function generate(array $index, array $locales): array
    {
        $pages = [];
        $query = new Query($index);
        $indices = $index['indices'];

        // Tag pages
        $tags = $indices['by_tag'] ?? [];
        foreach ($tags as $tag => $entryKeys) {
            foreach ($locales as $locale) {
                $tagQuery = $query->locale($locale)->where('tags', 'has', $tag);
                if ($tagQuery->count() === 0) {
                    continue;
                }

                // Determine collection from first entry
                $firstEntry = $index['entries'][$entryKeys[0]] ?? null;
                $collection = $firstEntry['collection'] ?? 'blog';

                $pages[] = [
                    'type' => 'tag',
                    'collection' => $collection,
                    'value' => $tag,
                    'locale' => $locale,
                    'url' => '/' . $locale . '/' . $collection . '/tag/' . $tag,
                    'entries' => $tagQuery,
                ];
            }
        }

        // Archive pages (by year-month)
        $dates = $indices['by_date'] ?? [];
        foreach ($dates as $yearMonth => $entryKeys) {
            foreach ($locales as $locale) {
                $dateQuery = $query->locale($locale)->where('date', 'contains', $yearMonth);
                if ($dateQuery->count() === 0) {
                    continue;
                }

                $firstEntry = $index['entries'][$entryKeys[0]] ?? null;
                $collection = $firstEntry['collection'] ?? 'blog';

                [$year, $month] = explode('-', $yearMonth) + ['', ''];

                $pages[] = [
                    'type' => 'archive',
                    'collection' => $collection,
                    'value' => $yearMonth,
                    'locale' => $locale,
                    'url' => '/' . $locale . '/' . $collection . '/archive/' . $year . '/' . $month,
                    'entries' => $dateQuery,
                ];
            }
        }

        return $pages;
    }
}
