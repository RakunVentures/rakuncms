<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

use Symfony\Component\Yaml\Yaml;

/**
 * PHP-array content index: builds cache/content-index.php from the .md tree and
 * loads it back. Discovery/parsing lives in ContentScanner (shared with the
 * SQLite store); this class owns only the on-disk PHP-array cache lifecycle.
 */
final class Indexer
{
    private string $contentPath;
    private string $cachePath;
    private string $defaultLocale;
    private ContentScanner $scanner;

    public function __construct(string $basePath)
    {
        $this->contentPath = $basePath . '/content';
        $this->cachePath = $basePath . '/cache/content-index.php';
        $this->defaultLocale = $this->resolveDefaultLocale($basePath);
        $this->scanner = new ContentScanner($this->contentPath, $this->defaultLocale);
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
     * Full rebuild of the content index (scan + persist).
     *
     * @return array{entries: array<string, array<string, mixed>>, indices: array<string, mixed>, meta: array<string, mixed>}
     */
    public function rebuild(): array
    {
        $scanned = $this->scanner->scan();
        return $this->save($scanned['entries'], $scanned['indices']);
    }

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
