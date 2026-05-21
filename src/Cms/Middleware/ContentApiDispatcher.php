<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
        
        // SAFE PATH DETECTION
        $basePath = '';
        try {
            $basePath = dirname(\app('config_path'));
        } catch (\Throwable) {
            $basePath = dirname(__DIR__, 5); // Fallback to heuristic
        }
        
        if (!is_dir($basePath . '/content')) {
             $basePath = dirname($_SERVER['SCRIPT_FILENAME'], 2);
        }

        if ($segments[0] === 'media') {
            $mediaController = new MediaApiController($basePath);
            if ($method === 'GET') return $mediaController->list();
            if ($method === 'POST') return $mediaController->upload($request);
            if ($method === 'DELETE') {
                $mediaPath = implode('/', array_slice($segments, 1));
                return $mediaController->delete($mediaPath);
            }
        }

        $controller = new ContentApiController($basePath);

        if ($segments[0] === 'config' && $method === 'GET') {
            return $controller->showConfig();
        }

        if ($segments[0] === 'collections' && $method === 'GET') {
            return $controller->collections();
        }

        if ($segments[0] === 'index' && ($segments[1] ?? '') === 'rebuild' && $method === 'POST') {
            (new \Rkn\Cms\Content\Indexer($basePath))->rebuild();
            return new \Nyholm\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode(['message' => 'Index rebuilt']));
        }

        if ($segments[0] === 'entries') {
            if (count($segments) === 1 && $method === 'GET') {
                return $controller->list($request);
            }
            if (count($segments) === 2 && $method === 'POST') {
                return $controller->create($request, $segments[1]);
            }
            if (count($segments) === 3) {
                if ($method === 'GET') return $controller->show($segments[1], $segments[2]);
                if ($method === 'PUT') return $controller->update($request, $segments[1], $segments[2]);
                if ($method === 'DELETE') return $controller->delete($segments[1], $segments[2]);
            }
        }

        return $handler->handle($request);
    }
}
