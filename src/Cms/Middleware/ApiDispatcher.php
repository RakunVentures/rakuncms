<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Http\Controllers\FormController;

/**
 * Dispatches API routes (POST /api/form/{name}) to the appropriate controller.
 */
final class ApiDispatcher implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        // POST /api/form/{name}
        if ($method === 'POST' && preg_match('#^/api/form/([a-zA-Z0-9_-]+)$#', $path, $matches)) {
            $formName = $matches[1];

            // Parse JSON body
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $body = (string) $request->getBody();
                $parsed = json_decode($body, true);
                if (is_array($parsed)) {
                    $request = $request->withParsedBody($parsed);
                }
            }

            $controller = new FormController();
            return $controller->handle($request, $formName);
        }

        return $handler->handle($request);
    }
}
