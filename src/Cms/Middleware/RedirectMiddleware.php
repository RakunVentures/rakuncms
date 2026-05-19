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
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        
        // We only care about paths that look like old WordPress URLs or common patterns
        // E.g. /2022/03/slug, /?p=123, /category/name
        
        $basePath = \app('base_path');
        $indexFile = $basePath . '/cache/content-index.php';
        
        if (is_file($indexFile)) {
            $index = require $indexFile;
            $entries = $index['entries'] ?? [];
            
            foreach ($entries as $entry) {
                $oldUrl = $entry['old_url'] ?? null;
                if ($oldUrl && ($oldUrl === $path || rtrim($oldUrl, '/') === rtrim($path, '/'))) {
                    $locale = $entry['locale'] ?? 'es';
                    $newUrl = '/' . $locale . $entry['url'];
                    
                    return new Response(301, ['Location' => $newUrl]);
                }
            }
        }

        return $handler->handle($request);
    }
}
