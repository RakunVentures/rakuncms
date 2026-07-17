<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RedirectMiddleware implements MiddlewareInterface
{
    /**
     * Builds the redirect target from an entry's locale and url without
     * double-prefixing: `ContentScanner::buildUrlPath()` already bakes the
     * locale prefix into `url` for non-default-locale entries (`/es/...`),
     * while default-locale entries stay unprefixed. Prepending the locale
     * unconditionally produced `/es/es/...` (a 404) for every non-default
     * `old_url` redirect sitewide.
     */
    public static function localePrefixedUrl(string $locale, string $url): string
    {
        $prefix = '/' . $locale;
        if ($url === $prefix || str_starts_with($url, $prefix . '/')) {
            return $url;
        }

        return $prefix . $url;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        
        // Normalize path: leading slash and no trailing slash
        $cleanPath = '/' . trim($path, '/');
        
        // Strip common WordPress suffixes like /feed/ or /feed
        if (str_ends_with($path, '/feed/')) {
            $cleanPath = '/' . trim(substr($path, 0, -6), '/');
        } elseif (str_ends_with($path, '/feed')) {
            $cleanPath = '/' . trim(substr($path, 0, -5), '/');
        }

        $basePath = \app('base_path');
        $dbPath = $basePath . '/cache/index.sqlite';
        
        // Resolve database path from configuration if defined
        try {
            $configPath = \config('rakun.index.path') ?? \config('index.path', null);
            if (is_string($configPath) && $configPath !== '') {
                $dbPath = str_starts_with($configPath, '/') ? $configPath : $basePath . '/' . ltrim($configPath, '/');
            }
        } catch (\Throwable) {
        }

        // 1. SQLite Store Redirect lookup (Fast-path direct query)
        if (file_exists($dbPath) && extension_loaded('pdo_sqlite')) {
            try {
                $pdo = new \PDO('sqlite:' . $dbPath);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                $match = $this->findRedirectInSqlite($pdo, $cleanPath);
                if ($match !== null) {
                    return new Response(301, ['Location' => $match]);
                }
            } catch (\Throwable) {
            }
        }

        // 2. Fallback to PHP array-index lookup (PhpArrayIndexStore)
        $indexFile = $basePath . '/cache/content-index.php';
        if (is_file($indexFile)) {
            try {
                $index = require $indexFile;
                $entries = $index['entries'] ?? [];
                
                $match = $this->findRedirectInPhpArray($entries, $cleanPath);
                if ($match !== null) {
                    return new Response(301, ['Location' => $match]);
                }
            } catch (\Throwable) {
            }
        }

        return $handler->handle($request);
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     */
    private function findRedirectInPhpArray(array $entries, string $cleanPath): ?string
    {
        // 1. Try direct/normalized clean path match first
        foreach ($entries as $entry) {
            $oldUrl = $entry['meta']['old_url'] ?? $entry['old_url'] ?? null;
            if ($oldUrl) {
                $normalizedOldUrl = '/' . trim($oldUrl, '/');
                if ($normalizedOldUrl === $cleanPath) {
                    $locale = $entry['locale'] ?? 'es';
                    return self::localePrefixedUrl($locale, $entry['url']);
                }
            }
        }

        // 2. Fallback for image attachment pages: /articulos/{year}/{month}/{post-slug}/{attachment-slug}
        // Redirect to parent post: /articulos/{year}/{month}/{post-slug}
        $segments = explode('/', trim($cleanPath, '/'));
        if (count($segments) === 5 && $segments[0] === 'articulos') {
            $parentPath = '/articulos/' . $segments[1] . '/' . $segments[2] . '/' . $segments[3];
            foreach ($entries as $entry) {
                $oldUrl = $entry['meta']['old_url'] ?? $entry['old_url'] ?? null;
                if ($oldUrl) {
                    $normalizedOldUrl = '/' . trim($oldUrl, '/');
                    if ($normalizedOldUrl === $parentPath) {
                        $locale = $entry['locale'] ?? 'es';
                        return self::localePrefixedUrl($locale, $entry['url']);
                    }
                }
            }
        }

        return null;
    }

    private function findRedirectInSqlite(\PDO $pdo, string $cleanPath): ?string
    {
        // We normalize formats to ensure matching (with or without trailing slashes)
        $pathNoSlash = '/' . trim($cleanPath, '/');
        $pathWithSlash = $pathNoSlash . '/';

        // 1. Try exact match on cleanPath first
        $stmt = $pdo->prepare("
            SELECT locale, url 
            FROM entries 
            WHERE status = 'published' 
              AND (
                json_extract(meta_json, '$.old_url') = ? 
                OR json_extract(meta_json, '$.old_url') = ?
                OR json_extract(meta_json, '$.old_url') = ?
              )
            LIMIT 1
        ");
        $stmt->execute([$cleanPath, $pathNoSlash, $pathWithSlash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            $locale = $row['locale'] ?? 'es';
            return self::localePrefixedUrl($locale, $row['url']);
        }

        // 2. Fallback for image attachment pages: /articulos/{year}/{month}/{post-slug}/{attachment-slug}
        // Redirect to parent post: /articulos/{year}/{month}/{post-slug}/
        $segments = explode('/', trim($cleanPath, '/'));
        if (count($segments) === 5 && $segments[0] === 'articulos') {
            $parentPathNoSlash = '/articulos/' . $segments[1] . '/' . $segments[2] . '/' . $segments[3];
            $parentPathWithSlash = $parentPathNoSlash . '/';

            $stmt = $pdo->prepare("
                SELECT locale, url 
                FROM entries 
                WHERE status = 'published' 
                  AND (
                    json_extract(meta_json, '$.old_url') = ? 
                    OR json_extract(meta_json, '$.old_url') = ?
                  )
                LIMIT 1
            ");
            $stmt->execute([$parentPathNoSlash, $parentPathWithSlash]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row !== false) {
                $locale = $row['locale'] ?? 'es';
                return self::localePrefixedUrl($locale, $row['url']);
            }
        }

        return null;
    }
}
