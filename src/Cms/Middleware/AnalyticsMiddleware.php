<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SQLite3;

final class AnalyticsMiddleware implements MiddlewareInterface
{
    private string $dbPath;

    public function __construct(string $storagePath)
    {
        $this->dbPath = $storagePath . '/analytics.db';
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        
        // Solo contar peticiones GET exitosas
        if ($request->getMethod() !== 'GET' || $response->getStatusCode() !== 200) {
            return $response;
        }

        $path = $request->getUri()->getPath();
        
        // Solo contar vistas de artículos de blog o revista
        // Formato esperado: /es/blog/slug o /es/revista/slug
        if (!preg_match('/^\/[a-z]{2}\/(blog|revista)\/([^\/]+)$/', $path, $matches)) {
            return $response;
        }

        $type = $matches[1];
        $slug = $matches[2];

        try {
            $db = $this->getDatabase();
            $stmt = $db->prepare('INSERT INTO hits (slug, type, views) VALUES (:slug, :type, 1) 
                                 ON CONFLICT(slug) DO UPDATE SET views = views + 1');
            $stmt->bindValue(':slug', $slug, SQLITE3_TEXT);
            $stmt->bindValue(':type', $type, SQLITE3_TEXT);
            $stmt->execute();
            $db->close();
        } catch (\Throwable) {
            // No bloquear la petición si falla la analítica
        }

        return $response;
    }

    private function getDatabase(): SQLite3
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $db = new SQLite3($this->dbPath);
        // WAL + busy_timeout let the template reader (ContentExtension::getViews)
        // and this writer coexist instead of colliding as SQLITE_BUSY noise.
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec('CREATE TABLE IF NOT EXISTS hits (
            slug TEXT PRIMARY KEY,
            type TEXT,
            views INTEGER DEFAULT 0
        )');
        
        return $db;
    }
}
