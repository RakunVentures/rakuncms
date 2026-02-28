<?php

declare(strict_types=1);

namespace Rkn\Cms\Cache;

/**
 * Full-page HTML cache (Level 3).
 * Reads/writes HTML files to cache/pages/{uri}.html
 * These can be served directly by Apache without PHP.
 */
class PageCache
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    public function get(string $uri): ?string
    {
        $file = $this->path($uri);
        if (is_file($file)) {
            return file_get_contents($file);
        }
        return null;
    }

    public function set(string $uri, string $html): bool
    {
        $file = $this->path($uri);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (file_put_contents($tmp, $html) !== false) {
            rename($tmp, $file);
            return true;
        }
        return false;
    }

    public function has(string $uri): bool
    {
        return is_file($this->path($uri));
    }

    public function delete(string $uri): bool
    {
        $file = $this->path($uri);
        if (is_file($file)) {
            return unlink($file);
        }
        return true;
    }

    public function clear(): bool
    {
        return $this->deleteDirectory($this->directory, false);
    }

    private function path(string $uri): string
    {
        $uri = trim($uri, '/');
        if ($uri === '') {
            return $this->directory . '/index.html';
        }
        return $this->directory . '/' . $uri . '.html';
    }

    private function deleteDirectory(string $dir, bool $removeSelf = true): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        $items = new \DirectoryIterator($dir);
        foreach ($items as $item) {
            if ($item->isDot()) continue;
            if ($item->isDir()) {
                $this->deleteDirectory($item->getPathname(), true);
            } else {
                unlink($item->getPathname());
            }
        }
        if ($removeSelf) {
            rmdir($dir);
        }
        return true;
    }
}
