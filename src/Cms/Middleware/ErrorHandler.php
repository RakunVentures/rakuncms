<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Catches exceptions and renders error templates (404, 500).
 *
 * Must be the first middleware in the pipeline to catch everything.
 */
final class ErrorHandler implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);

            // If the response is a 404 and has no body, render the 404 template
            if ($response->getStatusCode() === 404 && $response->getBody()->getSize() === 0) {
                return $this->renderError($request, 404);
            }

            return $response;
        } catch (\Throwable $e) {
            $statusCode = $this->resolveStatusCode($e);

            return $this->renderError($request, $statusCode, $e);
        }
    }

    private function renderError(ServerRequestInterface $request, int $statusCode, ?\Throwable $exception = null): ResponseInterface
    {
        $html = $this->renderTemplate($request, $statusCode, $exception);

        return new Response($statusCode, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    private function renderTemplate(ServerRequestInterface $request, int $statusCode, ?\Throwable $exception = null): string
    {
        try {
            $basePath = \app('base_path');
            $engine = \Rkn\Cms\Template\Engine::create($basePath);
            $locale = $request->getAttribute('locale', 'es');

            $templateName = 'errors/' . $statusCode . '.twig';
            $templateDir = $basePath . '/templates';

            if (!file_exists($templateDir . '/' . $templateName)) {
                $templateName = 'errors/500.twig';
                if (!file_exists($templateDir . '/' . $templateName)) {
                    return $this->fallbackHtml($statusCode, $exception);
                }
            }

            return $engine->render($templateName, [
                'status_code' => $statusCode,
                'locale' => $locale,
                'exception' => $this->isDebug() ? $exception : null,
            ]);
        } catch (\Throwable) {
            return $this->fallbackHtml($statusCode, $exception);
        }
    }

    private function fallbackHtml(int $statusCode, ?\Throwable $exception = null): string
    {
        $title = match ($statusCode) {
            404 => 'Page Not Found',
            403 => 'Forbidden',
            500 => 'Internal Server Error',
            default => 'Error',
        };

        $debug = '';
        if ($this->isDebug() && $exception !== null) {
            $debug = '<pre style="margin-top:2rem;padding:1rem;background:#f5f5f5;overflow:auto;font-size:0.8rem">'
                . htmlspecialchars($exception->getMessage() . "\n\n" . $exception->getTraceAsString())
                . '</pre>';
        }

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"><title>{$statusCode} {$title}</title></head>
        <body style="font-family:sans-serif;text-align:center;padding:4rem">
            <h1>{$statusCode}</h1>
            <p>{$title}</p>
            <a href="/">Go Home</a>
            {$debug}
        </body>
        </html>
        HTML;
    }

    private function resolveStatusCode(\Throwable $e): int
    {
        if ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'Unhandled request')) {
            return 404;
        }

        return 500;
    }

    private function isDebug(): bool
    {
        try {
            return (bool) \config('debug', false);
        } catch (\Throwable) {
            return false;
        }
    }
}
