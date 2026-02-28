<?php

declare(strict_types=1);

namespace Rkn\Cms\Cache;

/**
 * Tracks dependencies between pages and content files.
 * When a content file changes, only affected cached pages are invalidated.
 */
class Invalidator
{
    private PageCache $pageCache;
    private string $trackingFile;

    /** @var array<string, list<string>> Maps URI -> list of dependency file paths */
    private array $dependencies = [];

    public function __construct(PageCache $pageCache, string $trackingFile)
    {
        $this->pageCache = $pageCache;
        $this->trackingFile = $trackingFile;
        $this->load();
    }

    /**
     * Register that a page URI depends on certain files.
     *
     * @param string $uri The cached page URI
     * @param list<string> $files Files that this page depends on
     */
    public function track(string $uri, array $files): void
    {
        $this->dependencies[$uri] = $files;
        $this->save();
    }

    /**
     * Invalidate all pages that depend on the given file.
     *
     * @return list<string> URIs that were invalidated
     */
    public function invalidateByFile(string $file): array
    {
        $invalidated = [];
        foreach ($this->dependencies as $uri => $deps) {
            if (in_array($file, $deps, true)) {
                $this->pageCache->delete($uri);
                unset($this->dependencies[$uri]);
                $invalidated[] = $uri;
            }
        }
        if (!empty($invalidated)) {
            $this->save();
        }
        return $invalidated;
    }

    /**
     * Invalidate a specific URI.
     */
    public function invalidateUri(string $uri): void
    {
        $this->pageCache->delete($uri);
        unset($this->dependencies[$uri]);
        $this->save();
    }

    /**
     * Clear all tracking data and page cache.
     */
    public function clearAll(): void
    {
        $this->pageCache->clear();
        $this->dependencies = [];
        $this->save();
    }

    /**
     * Get all tracked dependencies.
     *
     * @return array<string, list<string>>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    private function load(): void
    {
        if (is_file($this->trackingFile)) {
            $data = require $this->trackingFile;
            if (is_array($data)) {
                $this->dependencies = $data;
            }
        }
    }

    private function save(): void
    {
        $dir = dirname($this->trackingFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $content = '<?php return ' . var_export($this->dependencies, true) . ';';
        $tmp = $this->trackingFile . '.' . uniqid('', true) . '.tmp';
        file_put_contents($tmp, $content);
        rename($tmp, $this->trackingFile);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->trackingFile, true);
        }
    }
}
