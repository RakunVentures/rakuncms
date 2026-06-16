<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Http\Controllers\CommandApiController;
use Rkn\Cms\Http\Controllers\ContentApiController;
use Rkn\Cms\Http\Controllers\MediaApiController;

final class ContentApiDispatcher implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $path = ltrim($uri->getPath(), '/');

        if (!str_starts_with($path, 'api/v1/')) {
            return $handler->handle($request);
        }

        $apiPath = substr($path, 7);
        $segments = explode('/', $apiPath);
        $method = $request->getMethod();
        
        // Resolve the site base path. Prefer the Application's bound base_path
        // (the same one the request pipeline / index_store use) so the API reads
        // the same content/index as the rendered site. Fall back to the script
        // location only if the app isn't available.
        $basePath = '';
        try {
            $basePath = (string) \app('base_path');
        } catch (\Throwable) {
            $basePath = '';
        }

        if ($basePath === '' || !is_dir($basePath . '/content')) {
            $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
            $basePath = $script !== '' ? dirname($script, 2) : dirname(__DIR__, 5);
        }

        if ($segments[0] === 'media') {
            $mediaController = new MediaApiController($basePath);
            if ($method === 'GET') return $mediaController->list();
            if ($method === 'POST') {
                if ($denied = $this->requirePermission($request, 'media')) return $denied;
                return $mediaController->upload($request);
            }
            if ($method === 'DELETE') {
                if ($denied = $this->requirePermission($request, 'media')) return $denied;
                $mediaPath = implode('/', array_slice($segments, 1));
                return $mediaController->delete($mediaPath);
            }
        }

        $controller = new ContentApiController($basePath);

        if ($segments[0] === 'config' && $method === 'GET') {
            return $controller->showConfig();
        }

        if ($segments[0] === 'schema' && $method === 'GET') {
            return $controller->schema();
        }

        if ($segments[0] === 'collections' && $method === 'GET') {
            return $controller->collections();
        }

        if ($segments[0] === 'index' && ($segments[1] ?? '') === 'rebuild' && $method === 'POST') {
            if ($denied = $this->requirePermission($request, 'write')) return $denied;
            $store = \app('index_store');
            if ($store instanceof \Rkn\Cms\Content\Stores\SqliteIndexStore) {
                $store->sync();
            } else {
                (new \Rkn\Cms\Content\Indexer($basePath))->rebuild();
            }
            return new \Nyholm\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode(['message' => 'Index rebuilt']));
        }

        // Comandos de mantenimiento del CLI (allowlist en CommandApiController).
        // GET /api/v1/commands           → lista los comandos disponibles.
        // POST /api/v1/commands/{cmd}    → ejecuta uno (requiere permiso 'admin').
        if ($segments[0] === 'commands') {
            $commandController = new CommandApiController($basePath);
            if ($method === 'GET' && count($segments) === 1) {
                return $commandController->list();
            }
            // Import de WordPress (multipart: file + opciones). Va ANTES del genérico
            // para no caer en run('wxr-import') (que no está en la allowlist → 404).
            if ($method === 'POST' && ($segments[1] ?? '') === 'wxr-import') {
                if ($denied = $this->requirePermission($request, 'admin')) return $denied;

                return $commandController->importWxr($request);
            }
            if ($method === 'POST' && isset($segments[1]) && $segments[1] !== '') {
                if ($denied = $this->requirePermission($request, 'admin')) return $denied;

                return $commandController->run($segments[1]);
            }
        }

        if ($segments[0] === 'entries') {
            if (count($segments) === 1 && $method === 'GET') {
                return $controller->list($request);
            }
            if (count($segments) === 2 && $method === 'POST') {
                if ($denied = $this->requirePermission($request, 'write')) return $denied;
                return $controller->create($request, $segments[1]);
            }
            if (count($segments) === 3) {
                if ($method === 'GET') {
                    $qp         = $request->getQueryParams();
                    $rawParam   = $qp['raw'] ?? '';
                    $raw        = $rawParam === '1' || $rawParam === 'true';
                    $statusParam = isset($qp['status']) && $qp['status'] !== '' ? (string) $qp['status'] : null;

                    return $controller->show($segments[1], $segments[2], $raw, $statusParam);
                }
                if ($method === 'PUT') {
                    if ($denied = $this->requirePermission($request, 'write')) return $denied;
                    return $controller->update($request, $segments[1], $segments[2]);
                }
                if ($method === 'DELETE') {
                    if ($denied = $this->requirePermission($request, 'write')) return $denied;
                    return $controller->delete($request, $segments[1], $segments[2]);
                }
            }
        }

        return $handler->handle($request);
    }

    /**
     * Gate a mutating action by permission. Reads the `api_permissions` attribute
     * set by ApiAuthMiddleware ('admin' grants all). Returns a 403 response when
     * the key lacks the permission, or null to proceed.
     */
    private function requirePermission(ServerRequestInterface $request, string $required): ?ResponseInterface
    {
        $permissions = $request->getAttribute('api_permissions', []);
        if (!is_array($permissions)) {
            $permissions = [];
        }
        /** @var list<string> $permissions */
        if (ApiAuthMiddleware::hasPermission($permissions, $required)) {
            return null;
        }

        return new \Nyholm\Psr7\Response(
            403,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => "Permission '{$required}' required"]) ?: '{}',
        );
    }
}
