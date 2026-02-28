<?php

declare(strict_types=1);

namespace Rkn\Cms\Search;

final class SearchEngine
{
    /** @var array{entries: array<string, array<string, mixed>>, inverted: array<string, list<string>>} */
    private array $index;

    /**
     * @param array{entries: array<string, array<string, mixed>>, inverted: array<string, list<string>>} $index
     */
    public function __construct(array $index)
    {
        $this->index = $index;
    }

    /**
     * Search the index.
     *
     * @return list<array{key: string, title: string, url: string, score: float, snippet: string}>
     */
    public function search(string $queryText, ?string $locale = null, int $limit = 10): array
    {
        $indexer = new SearchIndexer(''); // Only need tokenize()
        $queryWords = $indexer->tokenize($queryText);

        if (empty($queryWords)) {
            return [];
        }

        $scores = [];

        foreach ($queryWords as $word) {
            // Exact match in inverted index
            $matchingKeys = $this->index['inverted'][$word] ?? [];
            foreach ($matchingKeys as $key) {
                $scores[$key] = ($scores[$key] ?? 0) + $this->scoreWord($key, $word);
            }

            // Prefix match for partial words
            foreach ($this->index['inverted'] as $indexWord => $keys) {
                $indexWord = (string) $indexWord;
                if ($indexWord !== $word && str_starts_with($indexWord, $word)) {
                    foreach ($keys as $key) {
                        $scores[$key] = ($scores[$key] ?? 0) + $this->scoreWord($key, $word) * 0.5;
                    }
                }
            }
        }

        // Multi-word bonus
        if (count($queryWords) > 1) {
            foreach ($scores as $key => $score) {
                $matchCount = 0;
                $entry = $this->index['entries'][$key] ?? null;
                if ($entry === null) {
                    continue;
                }
                foreach ($queryWords as $word) {
                    if (in_array($word, $entry['words'] ?? [], true)) {
                        $matchCount++;
                    }
                }
                if ($matchCount > 1) {
                    $scores[$key] *= (1 + ($matchCount / count($queryWords)));
                }
            }
        }

        // Filter by locale
        if ($locale !== null) {
            $scores = array_filter($scores, function (string $key) use ($locale): bool {
                $entry = $this->index['entries'][$key] ?? null;
                return $entry !== null && ($entry['locale'] ?? '') === $locale;
            }, ARRAY_FILTER_USE_KEY);
        }

        // Sort by score descending
        arsort($scores);

        // Build results
        $results = [];
        $count = 0;
        foreach ($scores as $key => $score) {
            if ($count >= $limit) {
                break;
            }

            $entry = $this->index['entries'][$key] ?? null;
            if ($entry === null) {
                continue;
            }

            $results[] = [
                'key' => $key,
                'title' => $entry['title'] ?? '',
                'url' => $entry['url'] ?? '',
                'score' => round($score, 2),
                'snippet' => $entry['description'] ?? '',
            ];
            $count++;
        }

        return $results;
    }

    private function scoreWord(string $entryKey, string $word): float
    {
        $entry = $this->index['entries'][$entryKey] ?? null;
        if ($entry === null) {
            return 0;
        }

        $score = 0;
        $title = mb_strtolower($entry['title'] ?? '', 'UTF-8');
        $description = mb_strtolower($entry['description'] ?? '', 'UTF-8');
        $tags = $entry['tags'] ?? [];

        // Title match: weight 10
        if (str_contains($title, $word)) {
            $score += 10;
        }

        // Tag match: weight 8
        foreach ($tags as $tag) {
            if (mb_strtolower($tag, 'UTF-8') === $word || str_contains(mb_strtolower($tag, 'UTF-8'), $word)) {
                $score += 8;
                break;
            }
        }

        // Description match: weight 5
        if (str_contains($description, $word)) {
            $score += 5;
        }

        // Content match (word exists in entry words): weight 1
        if (in_array($word, $entry['words'] ?? [], true)) {
            $score += 1;
        }

        return $score;
    }
}
