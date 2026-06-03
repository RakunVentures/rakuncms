<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

use PDO;
use Rkn\Cms\Content\Storage\FileContentStorage;
use Rkn\Cms\Content\Storage\MysqlContentStorage;

/**
 * Builds the active ContentStorage from config `content.driver` (file|mysql).
 * Mirrors IndexStoreFactory. On mysql the FileContentStorage is injected as the
 * regenerable `.md` cache; if pdo_mysql is missing or the connection fails, it
 * logs and falls back to the flat-file driver (never fatal).
 */
final class ContentStorageFactory
{
    public static function make(string $basePath): ContentStorage
    {
        $defaultLocale = self::defaultLocale($basePath);
        $cache = new FileContentStorage($basePath, $defaultLocale);

        if (self::driver() === 'mysql' && extension_loaded('pdo_mysql')) {
            try {
                return new MysqlContentStorage(self::pdo(), $cache);
            } catch (\Throwable $e) {
                error_log('[rakun] MySQL content storage unavailable, falling back to file driver: ' . $e->getMessage());
            }
        }

        return $cache;
    }

    private static function driver(): string
    {
        $driver = self::config('content.driver') ?? self::config('rakun.content.driver') ?? 'file';

        return is_string($driver) ? $driver : 'file';
    }

    private static function pdo(): PDO
    {
        $cfg = self::config('content.mysql') ?? self::config('rakun.content.mysql') ?? [];
        if (!is_array($cfg)) {
            $cfg = [];
        }

        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (int) ($cfg['port'] ?? 3306);
        $db   = (string) ($cfg['database'] ?? '');
        $user = (string) ($cfg['username'] ?? 'root');
        $pass = (string) ($cfg['password'] ?? '');

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }

    private static function defaultLocale(string $basePath): string
    {
        $locale = self::config('site.default_locale') ?? self::config('rakun.site.default_locale');
        if (is_string($locale) && $locale !== '') {
            return $locale;
        }

        return 'en';
    }

    private static function config(string $key): mixed
    {
        if (!function_exists('config')) {
            return null;
        }
        try {
            return \config($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
