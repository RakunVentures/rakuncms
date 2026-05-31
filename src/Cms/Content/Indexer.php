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
     * @return array{entries: array<string, array<string, mixed>>, indices: array<string, mixed>, meta: array<string, mixed>}
     */
    public function load(): array
    {
        clearstatcache(true, $this->cachePath);
        if (file_exists($this->cachePath)) {
            return require $this->cachePath;
        }

        return $this->rebuild();
    }

    /**
     * Full rebuild of the content index.
     *
     * @return array{entries: array<string, array<string, mixed>>, indices: array<string, mixed>, meta: array<string, mixed>}
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
            'by_section' => [],
            'sections' => [],
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

            $indices['sections'][$collectionName] = $this->discoverSections($collectionPath);

            $files = $this->collectMarkdownFiles($collectionPath);
            $scheduleChecker = new ScheduleChecker(dirname($this->contentPath));

            foreach ($files as $file) {
                $entry = $this->indexFile($file, $collectionName, $collectionPath);
                if ($entry === null || $entry['draft']) {
                    continue;
                }

                if (!$scheduleChecker->shouldPublish($entry)) {
                    continue;
                }

                $key = $this->buildEntryKey($collectionName, $entry['section'], basename($file, '.md'));
                $entries[$key] = $entry;

                $indices['by_collection'][$collectionName][] = $key;
                $indices['by_locale'][$entry['locale']][] = $key;

                $sectionKey = $collectionName . ':' . $entry['section'];
                $indices['by_section'][$sectionKey][] = $key;

                $localeSlug = $entry['slugs'][$entry['locale']] ?? $entry['slug'];
                $fullSlug = $entry['section'] !== '' ? $entry['section'] . '/' . $localeSlug : $localeSlug;
                $collLocaleSlug = $collectionName . ':' . $entry['locale'] . ':' . $fullSlug;
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
            if (str_starts_with($name, '_')) {
                continue;
            }
            $collections[] = $name;
        }

        return $collections;
    }

    /**
     * Walk a collection directory and return absolute paths of all .md files.
     * Skips files or directory segments starting with "_".
     *
     * @return list<string>
     */
    private function collectMarkdownFiles(string $collectionPath): array
    {
        $files = [];
        $directory = new \RecursiveDirectoryIterator($collectionPath, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, function (\SplFileInfo $current) {
            $name = $current->getFilename();
            return !str_starts_with($name, '_');
        });
        $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY);

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'md') {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    /**
     * Discover sections by walking subdirectories and reading optional _section.yaml manifests.
     * Returns map keyed by section path (relative to collection root).
     *
     * @return array<string, array{section: string, title: string, titles: array<string, string>, order: int, icon: ?string, meta: array<string, mixed>}>
     */
    private function discoverSections(string $collectionPath): array
    {
        $sections = [];
        $directory = new \RecursiveDirectoryIterator($collectionPath, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, function (\SplFileInfo $current) {
            if ($current->isDir()) {
                return !str_starts_with($current->getFilename(), '_');
            }
            return true;
        });
        $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);

        $autoOrder = 0;
        /** @var \SplFileInfo $entryInfo */
        foreach ($iterator as $entryInfo) {
            if (!$entryInfo->isDir()) {
                continue;
            }

            $sectionPath = $this->relativeSectionPath($entryInfo->getPathname(), $collectionPath);
            if ($sectionPath === '') {
                continue;
            }

            $manifestFile = $entryInfo->getPathname() . '/_section.yaml';
            $manifest = [];
            if (is_file($manifestFile)) {
                $parsed = Yaml::parseFile($manifestFile);
                if (is_array($parsed)) {
                    $manifest = $parsed;
                }
            }

            $bareName = basename($sectionPath);
            $cleanName = preg_replace('/^\d+[-_.]/', '', $bareName) ?? $bareName;

            $titles = [];
            if (isset($manifest['titles']) && is_array($manifest['titles'])) {
                foreach ($manifest['titles'] as $loc => $title) {
                    if (is_string($loc) && is_string($title)) {
                        $titles[$loc] = $title;
                    }
                }
            }

            $title = isset($manifest['title']) && is_string($manifest['title'])
                ? $manifest['title']
                : ucwords(str_replace(['-', '_'], ' ', $cleanName));

            $order = isset($manifest['order']) && is_numeric($manifest['order'])
                ? (int) $manifest['order']
                : $this->extractOrder($bareName);
            if ($order === 0 && !isset($manifest['order'])) {
                $autoOrder++;
                $order = $autoOrder * 10;
            }

            $icon = isset($manifest['icon']) && is_string($manifest['icon']) ? $manifest['icon'] : null;

            $sections[$sectionPath] = [
                'section' => $sectionPath,
                'title' => $title,
                'titles' => $titles,
                'order' => $order,
                'icon' => $icon,
                'meta' => $manifest,
            ];
        }

        uasort($sections, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $sections;
    }

    /**
     * Compute relative section path (forward-slash form) from a directory under the collection root.
     */
    private function relativeSectionPath(string $absolutePath, string $collectionPath): string
    {
        $absolutePath = rtrim($absolutePath, '/');
        $collectionPath = rtrim($collectionPath, '/');
        if ($absolutePath === $collectionPath) {
            return '';
        }
        if (!str_starts_with($absolutePath, $collectionPath . '/')) {
            return '';
        }
        return substr($absolutePath, strlen($collectionPath) + 1);
    }

    private function buildEntryKey(string $collectionName, string $section, string $basename): string
    {
        if ($section === '') {
            return $collectionName . '/' . $basename;
        }
        return $collectionName . '/' . $section . '/' . $basename;
    }

    /**
     * Index a single .md file, extracting only frontmatter.
     *
     * @return array<string, mixed>|null
     */
    private function indexFile(string $filePath, string $collectionName, string $collectionPath): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $document = YamlFrontMatter::parse($content);
        $matter = $document->matter();

        $basename = basename($filePath, '.md');
        $locale = $this->detectLocale($basename);
        $slug = $this->extractSlug($basename);
        $order = $this->extractOrder($basename);

        $section = $this->relativeSectionPath(dirname($filePath), $collectionPath);

        $actualSlug = $matter['slugs'][$locale] ?? $matter['slug'] ?? $slug;
        $urlPath = $this->buildUrlPath($collectionName, $section, $actualSlug, $locale);

        return [
            'title' => $matter['title'] ?? ucfirst($slug),
            'slug' => $matter['slug'] ?? $slug,
            'url' => $urlPath,
            'section' => $section,
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
     * Build a public URL for the entry, honoring default locale, pages collection and nested sections.
     */
    private function buildUrlPath(string $collectionName, string $section, string $slug, string $locale): string
    {
        if ($collectionName === 'pages') {
            if (in_array($slug, ['index', 'home', 'inicio', ''], true) && $section === '') {
                return $locale === $this->defaultLocale ? '/' : '/' . $locale . '/';
            }
            $path = '/' . ($section !== '' ? $section . '/' : '') . $slug;
        } else {
            $sectionPart = $section !== '' ? $section . '/' : '';
            $path = '/' . $collectionName . '/' . $sectionPart . $slug;
        }

        if ($locale !== $this->defaultLocale) {
            $path = '/' . $locale . $path;
        }

        return $path;
    }

    /**
     * Detect locale from filename suffix.
     * Examples: "about.en" -> "en", "about" -> default locale
     */
    private function detectLocale(string $basename): string
    {
        $name = preg_replace('/^\d+\./', '', $basename);
        if ($name === null) {
            $name = $basename;
        }

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
        try {
            if (function_exists('config')) {
                $locale = \config('rakun.site.default_locale', null) ?? \config('site.default_locale', 'es');
                if ($locale !== 'es') {
                    return $locale;
                }
            }
        } catch (\Throwable) {
        }

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
        $name = preg_replace('/^\d+\./', '', $basename);
        if ($name === null) {
            $name = $basename;
        }

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
     * Extract order number from filename prefix (supports "01.x", "01-x", "01_x").
     */
    private function extractOrder(string $basename): int
    {
        if (preg_match('/^(\d+)[-_.]/', $basename, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    private function relativePath(string $filePath): string
    {
        $contentParent = dirname($this->contentPath);
        if (str_starts_with($filePath, $contentParent)) {
            return ltrim(substr($filePath, strlen($contentParent)), '/');
        }
        return $filePath;
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     * @param array<string, mixed> $indices
     * @return array{entries: array<string, array<string, mixed>>, indices: array<string, mixed>, meta: array<string, mixed>}
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

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cachePath, true);
        }

        return $data;
    }
}
