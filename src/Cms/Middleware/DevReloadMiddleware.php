<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Dev-only middleware: browser live-reload via short polling.
 *
 * Two responsibilities:
 *   1. Serve GET /__dev/reload as a stateless JSON endpoint that returns the
 *      stamp file's mtime: {"v": <mtime>}. The client polls every ~800ms.
 *   2. On HTML responses, inject a tiny <script> before </body> that runs the
 *      polling loop and calls location.reload() when the version moves forward.
 *
 * Activated only when env RAKUN_DEV_RELOAD=1 (set by ServeCommand). Zero prod footprint.
 *
 * Why short polling instead of long polling / SSE / WS:
 *   - PHP's built-in server has a tiny worker pool; holding a worker waiting for
 *     file changes starves the rest of the site.
 *   - On localhost a 5ms request every 800ms costs nothing.
 *   - Stateless endpoint = trivial to reason about and debug with curl.
 */
final class DevReloadMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $stampPath,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'GET' && $request->getUri()->getPath() === '/__dev/reload') {
            return $this->handleStampQuery();
        }

        $response = $handler->handle($request);

        return $this->maybeInjectScript($response);
    }

    private function handleStampQuery(): ResponseInterface
    {
        return new Response(
            200,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
            json_encode(['v' => $this->readStampMtime()], JSON_THROW_ON_ERROR),
        );
    }

    private function readStampMtime(): int
    {
        clearstatcache(true, $this->stampPath);
        if (!file_exists($this->stampPath)) {
            return 0;
        }
        $mtime = filemtime($this->stampPath);
        return $mtime === false ? 0 : $mtime;
    }

    private function maybeInjectScript(ResponseInterface $response): ResponseInterface
    {
        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && stripos($contentType, 'text/html') !== 0) {
            return $response;
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return $response;
        }

        $snippet = $this->scriptSnippet();

        $pos = stripos($body, '</body>');
        if ($pos === false) {
            $newBody = $body . $snippet;
        } else {
            $newBody = substr($body, 0, $pos) . $snippet . substr($body, $pos);
        }

        return $response
            ->withBody(Stream::create($newBody))
            ->withHeader('Content-Length', (string) strlen($newBody));
    }

    private function scriptSnippet(): string
    {
        return <<<'HTML'
<script data-rakun-dev-reload>
(function(){
  var v=0,interval=800,backoff=2000;
  function tick(){
    fetch('/__dev/reload?since='+v,{cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(j){
        if(j.v>v&&v){location.reload();return;}
        v=j.v;setTimeout(tick,interval);
      })
      .catch(function(){setTimeout(tick,backoff);});
  }
  tick();
})();
</script>
HTML;
    }
}
