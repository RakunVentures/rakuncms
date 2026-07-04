<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Clickfwd\Yoyo\Yoyo;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Template\Engine;

/**
 * Intercepts Yoyo requests: serves the runtime asset at GET /yoyo.js and
 * delegates POST /yoyo[/{action}] to the Yoyo component system.
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

        // Serve the Yoyo runtime script. yoyo_scripts() emits <script src="/yoyo.js">,
        // but the file only ships inside the vendored package (it is never published to
        // public/), so serve it straight from vendor for every site, in dev and prod.
        if ($uri === '/yoyo.js') {
            return $this->serveYoyoScript();
        }

        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        // Ensure Yoyo is bootstrapped via Engine
        $basePath = \app('base_path');
        Engine::create($basePath);

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

    /**
     * Serves clickfwd/yoyo's bundled yoyo.js from the vendor directory, located
     * relative to the Yoyo class so it works regardless of the install path.
     */
    private function serveYoyoScript(): ResponseInterface
    {
        $ref = new \ReflectionClass(Yoyo::class);
        $path = dirname($ref->getFileName(), 2) . '/assets/js/yoyo.js';

        if (!is_file($path)) {
            return new Response(404);
        }

        return new Response(
            200,
            [
                'Content-Type' => 'application/javascript; charset=UTF-8',
                'Cache-Control' => 'public, max-age=86400',
            ],
            (string) file_get_contents($path)
        );
    }
}
