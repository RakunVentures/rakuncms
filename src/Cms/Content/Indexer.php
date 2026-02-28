<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

use Spatie\YamlFrontMatter\YamlFrontMatter;
use Symfony\Component\Yaml\Yaml;

final class Indexer
{
    private string $contentPath;
    private string $cachePath;
    private string $defaultLocale;

    public function __construct(string $basePath)
    {
        $this->contentPath = $basePath . '/content';
        $this->cachePath = $basePath . '/cache/content-index.php';
        $this->defaultLocale = $this->resolveDefaultLocale($basePath);
    }

    /**
     * Load the index from cache, or build it if missing.
     *
     * @return array{entries: array<string, array<string, mixed>>, indices: array<string, array<string, list<string>>>, meta: array<string, mixed>}
     */
    public function load(): array
    {
        if (file_exists($this->cachePath)) {
            return require $this->cachePath;
        }

        return $this->rebuild();
    }

    /**
     * Full rebuild of the content index.
     *
     * @return array{entries: array<string, array<string, mixed>>, indices: array<string, array<string, list<string>>>, meta: array<string, mixed>}
     */
    public function rebuild(): array
    {
        $entries = [];
        $indices = [
            'by_tag' => [],
            'by_date' => [],
            'by_collection' => [],
            'by_locale' => [],
            'by_locale_slug' => [],
        ];

        if (!is_dir($this->contentPath)) {
            return $this->save($entries, $indices);
        }

        $collections = $this->discoverCollections();

        foreach ($collections as $collectionName) {
            $collectionPath = $this->contentPath . '/' . $collectionName;
            if (!is_dir($collectionPath)) {
                continue;
            }

            $files = glob($collectionPath . '/*.md') ?: [];
            foreach ($files as $file) {
                $entry = $this->indexFile($file, $collectionName);
                if ($entry === null || $entry['draft']) {
                    continue;
                }

                $key = $collectionName . '/' . basename($file, '.md');
                $entries[$key] = $entry;

                // Build indices
                $indices['by_collection'][$collectionName][] = $key;
                $indices['by_locale'][$entry['locale']][] = $key;

                // Locale+slug lookup
                $localeSlugKey = $entry['locale'] . ':' . ($entry['slugs'][$entry['locale']] ?? $entry['slug']);
                $indices['by_locale_slug'][$localeSlugKey] = $key;

                // Also index by collection+locale+slug
                $collLocaleSlug = $collectionName . ':' . $entry['locale'] . ':' . ($entry['slugs'][$entry['locale']] ?? $entry['slug']);
                $indices['by_locale_slug'][$collLocaleSlug] = $key;

                if (!empty($entry['tags'])) {
                    foreach ($entry['tags'] as $tag) {
                        $indices['by_tag'][$tag][] = $key;
                    }
                }

                if (!empty($entry['date'])) {
                    $month = substr($entry['date'], 0, 7);
                    $indices['by_date'][$month][] = $key;
                }
            }
        }

        return $this->save($entries, $indices);
    }

    /**
     * @return list<string>
     */
    private function discoverCollections(): array
    {
        $collections = [];
        $dirs = glob($this->contentPath . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($dirs as $dir) {
            $name = basename($dir);
            // Skip special directories
            if (str_starts_with($name, '_')) {
                continue;
            }
            $collections[] = $name;
        }

        return $collections;
    }

    /**
     * Index a single .md file, extracting only frontmatter.
     *
     * @return array<string, mixed>|null
     */
    private function indexFile(string $filePath, string $collectionName): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $document = YamlFrontMatter::parse($content);
        $matter = $document->matter();

        // Determine locale from filename: slug.en.md -> en, slug.md -> default
        $basename = basename($filePath, '.md');
        $locale = $this->detectLocale($basename);
        $slug = $this->extractSlug($basename);
        $order = $this->extractOrder($basename);

        return [
            'title' => $matter['title'] ?? ucfirst($slug),
            'slug' => $matter['slug'] ?? $slug,
            'collection' => $collectionName,
            'locale' => $locale,
            'file' => $this->relativePath($filePath),
            'template' => $matter['template'] ?? null,
            'date' => isset($matter['date']) ? (string) $matter['date'] : null,
            'order' => (int) ($matter['order'] ?? $order),
            'draft' => (bool) ($matter['draft'] ?? false),
            'meta' => $matter['meta'] ?? $matter,
            'slugs' => $matter['slugs'] ?? [],
            'tags' => $matter['tags'] ?? [],
            'mtime' => filemtime($filePath) ?: 0,
        ];
    }

    /**
     * Detect locale from filename suffix.
     * Examples: "about.en" -> "en", "about" -> default locale
     */
    private function detectLocale(string $basename): string
    {
        // Remove order prefix first
        $name = preg_replace('/^\d+\./', '', $basename);
        if ($name === null) {
            $name = $basename;
        }

        // Check for locale suffix
        $parts = explode('.', $name);
        if (count($parts) >= 2) {
            $possibleLocale = end($parts);
            if (strlen($possibleLocale) === 2) {
                return $possibleLocale;
            }
        }

        return $this->defaultLocale;
    }

    /**
     * Resolve default locale from config file or Application container.
     */
    private function resolveDefaultLocale(string $basePath): string
    {
        // Try Application container first
        try {
            return \config('site.default_locale', 'es');
        } catch (\Throwable) {
        }

        // Fallback: read config file directly
        $configFile = $basePath . '/config/rakun.yaml';
        if (file_exists($configFile)) {
            $config = Yaml::parseFile($configFile);
            if (is_array($config) && isset($config['site']['default_locale'])) {
                return (string) $config['site']['default_locale'];
            }
        }

        return 'es';
    }

    /**
     * Extract slug from filename, removing order prefix and locale suffix.
     */
    private function extractSlug(string $basename): string
    {
        // Remove order prefix: "01.about" -> "about"
        $name = preg_replace('/^\d+\./', '', $basename);
        if ($name === null) {
            $name = $basename;
        }

        // Remove locale suffix: "about.en" -> "about"
        $parts = explode('.', $name);
        if (count($parts) >= 2) {
            $possibleLocale = end($parts);
            if (strlen($possibleLocale) === 2) {
                array_pop($parts);
                return implode('.', $parts);
            }
        }

        return $name;
    }

    /**
     * Extract order number from filename prefix.
     */
    private function extractOrder(string $basename): int
    {
        if (preg_match('/^(\d+)\./', $basename, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    private function relativePath(string $filePath): string
    {
        // Make path relative to project root
        $contentParent = dirname($this->contentPath);
        if (str_starts_with($filePath, $contentParent)) {
            return ltrim(substr($filePath, strlen($contentParent)), '/');
        }
        return $filePath;
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     * @param array<string, array<string, list<string>|string>> $indices
     * @return array{entries: array<string, array<string, mixed>>, indices: array<string, array<string, list<string>|string>>, meta: array<string, mixed>}
     */
    private function save(array $entries, array $indices): array
    {
        $data = [
            'entries' => $entries,
            'indices' => $indices,
            'meta' => [
                'built_at' => time(),
                'entry_count' => count($entries),
                'collections' => array_unique(array_keys($indices['by_collection'] ?? [])),
            ],
        ];

        $dir = dirname($this->cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $export = '<?php return ' . var_export($data, true) . ';' . PHP_EOL;
        file_put_contents($this->cachePath, $export);

        return $data;
    }
}
