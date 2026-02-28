<?php

declare(strict_types=1);

namespace Rkn\Cms\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache using var_export + OPcache.
 * Pattern proven by Symfony PhpFilesAdapter.
 */
class PhpFileCache implements CacheInterface
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return $default;
        }
        $data = require $file;
        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            return $default;
        }
        if ($data['expires'] !== 0 && $data['expires'] < time()) {
            $this->delete($key);
            return $default;
        }
        return $data['value'];
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $expires = 0;
        if ($ttl instanceof \DateInterval) {
            $expires = time() + (int) (new \DateTime())->add($ttl)->format('U') - time();
        } elseif (is_int($ttl)) {
            $expires = $ttl > 0 ? time() + $ttl : 0;
        }

        $content = '<?php return ' . var_export(['expires' => $expires, 'value' => $value], true) . ';';
        $file = $this->path($key);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (file_put_contents($tmp, $content) !== false) {
            rename($tmp, $file);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
            return true;
        }
        return false;
    }

    public function delete(string $key): bool
    {
        $file = $this->path($key);
        if (is_file($file)) {
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
            return unlink($file);
        }
        return true;
    }

    public function clear(): bool
    {
        return $this->deleteDirectory($this->directory, false);
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $ok = false;
            }
        }
        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $ok = false;
            }
        }
        return $ok;
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . str_replace(['/', '\\', ':'], '-', $key) . '.php';
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
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($item->getPathname(), true);
                }
                unlink($item->getPathname());
            }
        }
        if ($removeSelf) {
            rmdir($dir);
        }
        return true;
    }
}
