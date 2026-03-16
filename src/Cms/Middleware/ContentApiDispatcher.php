<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Http\Controllers\BoostApiController;
use Rkn\Cms\Http\Controllers\ContentApiController;
use Rkn\Cms\Http\Controllers\GlobalsApiController;
use Rkn\Cms\Http\Controllers\MediaApiController;
use Rkn\Cms\Http\Controllers\SearchApiController;

final class ContentApiDispatcher implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (!str_starts_with($path, '/api/v1/')) {
            return $handler->handle($request);
        }

        $method = $request->getMethod();
        $apiPath = substr($path, strlen('/api/v1/'));
        $segments = $apiPath ? explode('/', trim($apiPath, '/')) : [];
        $permissions = $request->getAttribute('api_permissions', []);
        $basePath = \app('base_path');

        // Route: /api/v1/entries
        if ($segments[0] === 'entries') {
            $controller = new ContentApiController($basePath);

            if (count($segments) === 1 && $method === 'GET') {
                return $controller->list($request);
            }

            if (count($segments) === 2 && $method === 'POST') {
                if (!ApiAuthMiddleware::hasPermission($permissions, 'write')) {
                    return $this->forbidden();
                }
                return $controller->create($request, $segments[1]);
            }

            if (count($segments) === 3 && $method === 'GET') {
                return $controller->show($segments[1], $segments[2]);
            }

            if (count($segments) === 3 && $method === 'PUT') {
                if (!ApiAuthMiddleware::hasPermission($permissions, 'write')) {
                    return $this->forbidden();
                }
                return $controller->update($request, $segments[1], $segments[2]);
            }

            if (count($segments) === 3 && $method === 'DELETE') {
                if (!ApiAuthMiddleware::hasPermission($permissions, 'write')) {
                    return $this->forbidden();
                }
                return $controller->delete($segments[1], $segments[2]);
            }
        }

        // Route: /api/v1/globals/{name}
        if ($segments[0] === 'globals') {
            $controller = new GlobalsApiController($basePath);

            if (count($segments) === 2 && $method === 'GET') {
                return $controller->show($segments[1]);
            }

            if (count($segments) === 2 && $method === 'PUT') {
                if (!ApiAuthMiddleware::hasPermission($permissions, 'write')) {
                    return $this->forbidden();
                }
                return $controller->update($request, $segments[1]);
            }
        }

        // Route: /api/v1/search
        if ($segments[0] === 'search' && count($segments) === 1 && $method === 'GET') {
            $controller = new SearchApiController($basePath);
            return $controller->search($request);
        }

        // Route: /api/v1/media
        if ($segments[0] === 'media') {
            $controller = new MediaApiController($basePath);

            if (count($segments) === 1 && $method === 'GET') {
                return $controller->list();
            }

            if (count($segments) === 1 && $method === 'POST') {
                if (!ApiAuthMiddleware::hasPermission($permissions, 'media')) {
                    return $this->forbidden();
                }
                return $controller->upload($request);
            }

            if ($method === 'DELETE' && count($segments) >= 2) {
                if (!ApiAuthMiddleware::hasPermission($permissions, 'media')) {
                    return $this->forbidden();
                }
                $mediaPath = implode('/', array_slice($segments, 1));
                return $controller->delete($mediaPath);
            }
        }

        // Route: /api/v1/collections
        if ($segments[0] === 'collections' && count($segments) === 1 && $method === 'GET') {
            $controller = new ContentApiController($basePath);
            return $controller->collections();
        }

        // Route: /api/v1/config
        if ($segments[0] === 'config' && count($segments) === 1 && $method === 'GET') {
            return $this->configResponse();
        }

        // Route: /api/v1/cache/clear
        if ($segments[0] === 'cache' && ($segments[1] ?? '') === 'clear' && $method === 'POST') {
            if (!ApiAuthMiddleware::hasPermission($permissions, 'admin')) {
                return $this->forbidden();
            }
            return $this->clearCache($basePath);
        }

        // Route: /api/v1/boost/archetypes
        if ($segments[0] === 'boost' && ($segments[1] ?? '') === 'archetypes' && $method === 'GET') {
            $controller = new BoostApiController($basePath);
            return $controller->archetypes();
        }

        // Route: /api/v1/boost/apply
        if ($segments[0] === 'boost' && ($segments[1] ?? '') === 'apply' && $method === 'POST') {
            if (!ApiAuthMiddleware::hasPermission($permissions, 'admin')) {
                return $this->forbidden();
            }
            $controller = new BoostApiController($basePath);
            return $controller->apply($request);
        }

        // Route: /api/v1/index/rebuild
        if ($segments[0] === 'index' && ($segments[1] ?? '') === 'rebuild' && $method === 'POST') {
            if (!ApiAuthMiddleware::hasPermission($permissions, 'admin')) {
                return $this->forbidden();
            }
            return $this->rebuildIndex($basePath);
        }

        return $this->jsonResponse(404, ['error' => 'Endpoint not found']);
    }

    private function configResponse(): ResponseInterface
    {
        $config = [];
        try {
            $config = \app()->get('config');
        } catch (\Throwable) {
        }

        // Sanitize: remove secrets
        unset($config['api']['keys'], $config['preview']['token'], $config['mail']);
        foreach ($config['webhooks'] ?? [] as $i => $webhook) {
            unset($config['webhooks'][$i]['secret']);
        }

        return $this->jsonResponse(200, ['data' => $config]);
    }

    private function clearCache(string $basePath): ResponseInterface
    {
        $dirs = ['cache/pages', 'cache/templates', 'cache/content-index.php'];
        $cleared = [];

        foreach ($dirs as $dir) {
            $path = $basePath . '/' . $dir;
            if (is_file($path)) {
                @unlink($path);
                $cleared[] = $dir;
            } elseif (is_dir($path)) {
                $files = glob($path . '/*') ?: [];
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                $cleared[] = $dir;
            }
        }

        return $this->jsonResponse(200, ['message' => 'Cache cleared', 'cleared' => $cleared]);
    }

    private function rebuildIndex(string $basePath): ResponseInterface
    {
        $indexer = new \Rkn\Cms\Content\Indexer($basePath);
        $index = $indexer->rebuild();

        return $this->jsonResponse(200, [
            'message' => 'Index rebuilt',
            'entry_count' => $index['meta']['entry_count'],
            'collections' => $index['meta']['collections'],
        ]);
    }

    private function forbidden(): ResponseInterface
    {
        return $this->jsonResponse(403, ['error' => 'Insufficient permissions']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(int $status, array $data): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}'
        );
    }
}
