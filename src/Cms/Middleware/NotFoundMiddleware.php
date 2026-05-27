<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Terminal middleware that always returns a 404 response.
 * Should be placed at the very end of the pipeline.
 */
final class NotFoundMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new Response(404, ['Content-Type' => 'text/html'], '404 Page Not Found');
    }
}
