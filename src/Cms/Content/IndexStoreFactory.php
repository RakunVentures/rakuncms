<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

use Rkn\Cms\Content\Stores\PhpArrayIndexStore;
use Rkn\Cms\Content\Stores\SqliteIndexStore;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the active IndexStore from config('index.driver'):
 *  - "sqlite": SqliteIndexStore (requires pdo_sqlite; builds the DB on first use).
 *  - "php" (default) or any failure: PhpArrayIndexStore over the legacy index.
 *
 * Falls back to "php" gracefully when SQLite is unavailable so a misconfigured
 * host never fatals.
 */
final class IndexStoreFactory
{
    public static function make(string $basePath): IndexStore
    {
        if (self::driver() === 'sqlite' && extension_loaded('pdo_sqlite')) {
            try {
                $dbPath = self::dbPath($basePath);
                $scanner = new ContentScanner($basePath . '/content', self::defaultLocale($basePath));
                $store = new SqliteIndexStore($dbPath, $scanner);
                if (!file_exists($dbPath)) {
                    // First run: build in-process. Deploys should pre-build via
                    // `rakun index:rebuild` so requests never pay this cost.
                    $store->sync();
                }
                return $store;
            } catch (\Throwable $e) {
                error_log('[rakun] SQLite index unavailable, falling back to php driver: ' . $e->getMessage());
            }
        }

        return new PhpArrayIndexStore((new Indexer($basePath))->load());
    }

    private static function driver(): string
    {
        try {
            $driver = \config('rakun.index.driver') ?? \config('index.driver', 'php');
            return is_string($driver) && $driver !== '' ? $driver : 'php';
        } catch (\Throwable) {
            return 'php';
        }
    }

    private static function dbPath(string $basePath): string
    {
        $path = null;
        try {
            $path = \config('rakun.index.path') ?? \config('index.path', null);
        } catch (\Throwable) {
        }
        if (is_string($path) && $path !== '') {
            return str_starts_with($path, '/') ? $path : $basePath . '/' . ltrim($path, '/');
        }
        return $basePath . '/cache/index.sqlite';
    }

    private static function defaultLocale(string $basePath): string
    {
        try {
            $locale = \config('rakun.site.default_locale') ?? \config('site.default_locale', 'es');
            if (is_string($locale) && $locale !== 'es') {
                return $locale;
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
}
