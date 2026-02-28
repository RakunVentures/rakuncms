<?php

declare(strict_types=1);

namespace Rkn\Cms\Search;

use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\Parser;

final class SearchIndexer
{
    private string $basePath;
    private string $cachePath;

    /** @var list<string> */
    private static array $stopWords = [
        // English
        'a', 'an', 'the', 'is', 'it', 'in', 'on', 'at', 'to', 'of', 'for',
        'and', 'or', 'but', 'not', 'with', 'this', 'that', 'are', 'was',
        'be', 'has', 'had', 'have', 'do', 'does', 'did', 'will', 'would',
        'can', 'could', 'should', 'may', 'might', 'from', 'by', 'as', 'if',
        'so', 'no', 'up', 'out', 'about', 'into', 'than', 'then', 'its',
        // Spanish
        'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'de', 'del',
        'en', 'con', 'por', 'para', 'es', 'son', 'está', 'fue', 'ser',
        'como', 'más', 'pero', 'sus', 'al', 'se', 'lo', 'ya', 'que',
        'su', 'si', 'nos', 'muy', 'sin', 'sobre', 'todo', 'también',
    ];

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->cachePath = $basePath . '/cache/search-index.php';
    }

    /**
     * Build or rebuild the search index.
     *
     * @return array{entries: array<string, array<string, mixed>>, inverted: array<string, list<string>>}
     */
    public function build(): array
    {
        $contentIndexer = new Indexer($this->basePath);
        $contentIndex = $contentIndexer->load();

        $entries = [];
        $inverted = [];

        foreach ($contentIndex['entries'] as $key => $entryData) {
            $parser = new Parser();
            $content = '';
            $file = $this->basePath . '/' . $entryData['file'];
            if (file_exists($file)) {
                $rawContent = file_get_contents($file);
                if ($rawContent !== false) {
                    // Strip frontmatter
                    $content = preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $rawContent) ?? '';
                    // Strip Markdown/HTML tags for plain text
                    $content = strip_tags($content);
                }
            }

            $words = $this->tokenize($content);
            $titleWords = $this->tokenize($entryData['title'] ?? '');
            $descWords = $this->tokenize($entryData['meta']['description'] ?? '');
            $allWords = array_unique(array_merge($titleWords, $descWords, $words));

            $searchEntry = [
                'title' => $entryData['title'] ?? '',
                'description' => $entryData['meta']['description'] ?? '',
                'url' => $this->buildUrl($entryData),
                'collection' => $entryData['collection'] ?? '',
                'locale' => $entryData['locale'] ?? '',
                'tags' => $entryData['tags'] ?? [],
                'words' => $allWords,
            ];

            $entries[$key] = $searchEntry;

            // Build inverted index
            foreach ($allWords as $word) {
                $inverted[$word][] = $key;
            }
        }

        // Deduplicate inverted index entries
        foreach ($inverted as $word => $keys) {
            $inverted[$word] = array_values(array_unique($keys));
        }

        $index = [
            'entries' => $entries,
            'inverted' => $inverted,
        ];

        $this->save($index);

        return $index;
    }

    /**
     * Load the search index from cache.
     *
     * @return array{entries: array<string, array<string, mixed>>, inverted: array<string, list<string>>}|null
     */
    public function load(): ?array
    {
        if (!file_exists($this->cachePath)) {
            return null;
        }

        return require $this->cachePath;
    }

    /**
     * Tokenize text into normalized words, excluding stop words.
     *
     * @return list<string>
     */
    public function tokenize(string $text): array
    {
        if ($text === '') {
            return [];
        }

        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Remove punctuation, keep alphanumeric and spaces
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? '';

        // Split into words
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return [];
        }

        // Filter stop words and short words
        $words = array_filter($words, function (string $word): bool {
            return strlen($word) >= 2 && !in_array($word, self::$stopWords, true);
        });

        return array_values(array_unique($words));
    }

    /**
     * Export as JSON for client-side search.
     */
    public function exportJson(): string
    {
        $index = $this->load() ?? $this->build();

        // Simplified version for client-side — just entries with titles, descriptions, URLs
        $clientIndex = [];
        foreach ($index['entries'] as $entry) {
            $clientIndex[] = [
                't' => $entry['title'],
                'd' => $entry['description'],
                'u' => $entry['url'],
                'c' => $entry['collection'],
                'l' => $entry['locale'],
                'g' => $entry['tags'],
            ];
        }

        return json_encode($clientIndex, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * @param array{entries: array<string, array<string, mixed>>, inverted: array<string, list<string>>} $index
     */
    private function save(array $index): void
    {
        $dir = dirname($this->cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $export = '<?php return ' . var_export($index, true) . ';' . PHP_EOL;
        file_put_contents($this->cachePath, $export);
    }

    /**
     * @param array<string, mixed> $entryData
     */
    private function buildUrl(array $entryData): string
    {
        $locale = $entryData['locale'] ?? 'en';
        $collection = $entryData['collection'] ?? '';
        $slug = $entryData['slugs'][$locale] ?? $entryData['slug'] ?? '';

        if ($collection === 'pages') {
            if (in_array($slug, ['home', 'inicio', 'index', ''], true)) {
                return '/' . $locale . '/';
            }
            return '/' . $locale . '/' . $slug;
        }

        return '/' . $locale . '/' . $collection . '/' . $slug;
    }
}
