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

    public static function driver(): string
    {
        $driver = self::config('content.driver') ?? self::config('rakun.content.driver') ?? 'file';

        return is_string($driver) ? $driver : 'file';
    }

    /**
     * Normalized MySQL connection config (with defaults applied). Single source
     * of truth shared by self::pdo() and the db:dump CLI command.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public static function mysqlConfig(): array
    {
        $cfg = self::config('content.mysql') ?? self::config('rakun.content.mysql') ?? [];
        if (!is_array($cfg)) {
            $cfg = [];
        }

        return [
            'host'     => (string) ($cfg['host'] ?? '127.0.0.1'),
            'port'     => (int) ($cfg['port'] ?? 3306),
            'database' => (string) ($cfg['database'] ?? ''),
            'username' => (string) ($cfg['username'] ?? 'root'),
            'password' => (string) ($cfg['password'] ?? ''),
        ];
    }

    public static function pdo(): PDO
    {
        $cfg = self::mysqlConfig();
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4";

        return new PDO($dsn, $cfg['username'], $cfg['password'], [
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
