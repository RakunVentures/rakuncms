<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Clickfwd\Yoyo\Yoyo;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Intercepts POST /yoyo/ requests and delegates to the Yoyo component system.
 */
class YoyoHandler implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri()->getPath();

        // Only handle Yoyo requests
        if (!str_starts_with($uri, '/yoyo')) {
            return $handler->handle($request);
        }

        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        // Process Yoyo request
        $yoyo = Yoyo::getInstance();
        if ($yoyo === null) {
            return $handler->handle($request);
        }

        $output = $yoyo->update();

        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $output
        );
    }
}
