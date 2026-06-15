<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

use Spatie\YamlFrontMatter\YamlFrontMatter;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers and parses content (.md) into the canonical index shape
 * ({entries, indices}) shared by every IndexStore. Extracted from Indexer so
 * the PHP-array store and the SQLite store build rows from the SAME parser —
 * parity is structural, not a reimplementation.
 *
 * Stateless beyond contentPath/defaultLocale; safe to call per-file (indexFile)
 * for incremental updates or wholesale (scan) for a full build.
 */
final class ContentScanner
{
    private string $contentPath;
    private string $defaultLocale;

    public function __construct(string $contentPath, string $defaultLocale)
    {
        $this->contentPath = $contentPath;
        $this->defaultLocale = $defaultLocale;
    }

    public function contentPath(): string
    {
        return $this->contentPath;
    }

    /**
     * Full scan of all collections. Returns the same {entries, indices} shape
     * the legacy Indexer::rebuild() produced (minus the saved 'meta' wrapper).
     *
     * @return array{entries: array<string, array<string, mixed>>, indices: array<string, mixed>}
     */
    public function scan(): array
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
            return ['entries' => $entries, 'indices' => $indices];
        }

        $scheduleChecker = new ScheduleChecker(dirname($this->contentPath));

        foreach ($this->discoverCollections() as $collectionName) {
            $collectionPath = $this->contentPath . '/' . $collectionName;
            if (!is_dir($collectionPath)) {
                continue;
            }

            $indices['sections'][$collectionName] = $this->discoverSections($collectionPath);

            foreach ($this->collectMarkdownFiles($collectionPath) as $file) {
                $entry = $this->indexFile($file, $collectionName, $collectionPath);
                if ($entry === null) {
                    continue;
                }

                $entry['status'] = EntryStatus::of($entry, $scheduleChecker);

                $key = $this->buildEntryKey($collectionName, $entry['section'], basename($file, '.md'));
                $entries[$key] = $entry;

                $indices['by_collection'][$collectionName][] = $key;
                $indices['by_locale'][$entry['locale']][] = $key;

                $sectionKey = $collectionName . ':' . $entry['section'];
                $indices['by_section'][$sectionKey][] = $key;

                $indices['by_locale_slug'][$collectionName . ':' . $entry['locale'] . ':' . $this->fullSlug($entry)] = $key;

                // Tags and date indices: only published entries
                if ($entry['status'] === 'published') {
                    if (!empty($entry['tags'])) {
                        foreach ($entry['tags'] as $tag) {
                            $indices['by_tag'][$tag][] = $key;
                        }
                    }

                    if (!empty($entry['date'])) {
                        $indices['by_date'][substr($entry['date'], 0, 7)][] = $key;
                    }
                }
            }
        }

        return ['entries' => $entries, 'indices' => $indices];
    }

    /**
     * The locale-aware "full slug" used as the by_locale_slug lookup key.
     *
     * @param array<string, mixed> $entry
     */
    public function fullSlug(array $entry): string
    {
        $localeSlug = $entry['slugs'][$entry['locale']] ?? $entry['slug'];
        $section = (string) ($entry['section'] ?? '');
        return $section !== '' ? $section . '/' . $localeSlug : $localeSlug;
    }

    /**
     * @return list<string>
     */
    public function discoverCollections(): array
    {
        $collections = [];
        foreach (glob($this->contentPath . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
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
    public function collectMarkdownFiles(string $collectionPath): array
    {
        $files = [];
        $directory = new \RecursiveDirectoryIterator($collectionPath, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, function (\SplFileInfo $current) {
            return !str_starts_with($current->getFilename(), '_');
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
     * @return array<string, array{section: string, title: string, titles: array<string, string>, order: int, icon: ?string, meta: array<string, mixed>}>
     */
    public function discoverSections(string $collectionPath): array
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
                try {
                    $parsed = Yaml::parseFile($manifestFile);
                    if (is_array($parsed)) {
                        $manifest = $parsed;
                    }
                } catch (\Throwable $e) {
                    error_log('[rakun] unparseable section manifest ' . $manifestFile . '; ignoring: ' . $e->getMessage());
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

    public function relativeSectionPath(string $absolutePath, string $collectionPath): string
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

    public function buildEntryKey(string $collectionName, string $section, string $basename): string
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
    public function indexFile(string $filePath, string $collectionName, string $collectionPath): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        try {
            $document = YamlFrontMatter::parse($content);
        } catch (\Throwable $e) {
            error_log('[rakun] skipping unparseable frontmatter in ' . $filePath . ': ' . $e->getMessage());
            return null;
        }
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

    public function buildUrlPath(string $collectionName, string $section, string $slug, string $locale): string
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

    public function detectLocale(string $basename): string
    {
        $name = preg_replace('/^\d+\./', '', $basename) ?? $basename;
        $parts = explode('.', $name);
        if (count($parts) >= 2) {
            $possibleLocale = end($parts);
            if (strlen($possibleLocale) === 2) {
                return $possibleLocale;
            }
        }
        return $this->defaultLocale;
    }

    public function extractSlug(string $basename): string
    {
        $name = preg_replace('/^\d+\./', '', $basename) ?? $basename;
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

    public function extractOrder(string $basename): int
    {
        if (preg_match('/^(\d+)[-_.]/', $basename, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    public function relativePath(string $filePath): string
    {
        $contentParent = dirname($this->contentPath);
        if (str_starts_with($filePath, $contentParent)) {
            return ltrim(substr($filePath, strlen($contentParent)), '/');
        }
        return $filePath;
    }
}
