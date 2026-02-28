<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\I18n\Translator;

final class LocaleDetector implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $config = [];
        $basePath = '';

        try {
            $config = \app('config');
            $basePath = \app('base_path');
        } catch (\Throwable) {
        }

        $defaultLocale = $config['site']['default_locale'] ?? 'es';
        $supportedLocales = $config['site']['locales'] ?? ['es', 'en'];

        // 1. Detect from URL prefix
        $locale = $this->detectFromUrl($path, $supportedLocales);

        // 2. Redirect root "/" to "/{default_locale}/"
        if ($path === '/' || $path === '') {
            // Check cookie preference
            $cookieLocale = $this->detectFromCookie($request, $supportedLocales);
            // Check Accept-Language header
            $headerLocale = $this->detectFromHeader($request, $supportedLocales);

            $redirectLocale = $cookieLocale ?? $headerLocale ?? $defaultLocale;

            return new Response(302, [
                'Location' => '/' . $redirectLocale . '/',
            ]);
        }

        // If no locale detected in URL, use fallback chain
        if ($locale === null) {
            $locale = $this->detectFromCookie($request, $supportedLocales)
                ?? $this->detectFromHeader($request, $supportedLocales)
                ?? $defaultLocale;
        }

        // Register translator in container
        try {
            $container = \app();
            $container->set('locale', $locale);

            $langPath = $basePath . '/lang';
            $translator = new Translator($langPath, $locale, $defaultLocale);
            $container->set('translator', $translator);
        } catch (\Throwable) {
        }

        // Store locale in request attributes
        $request = $request->withAttribute('locale', $locale);

        return $handler->handle($request);
    }

    /**
     * @param list<string> $supported
     */
    private function detectFromUrl(string $path, array $supported): ?string
    {
        $segments = explode('/', trim($path, '/'));
        $first = $segments[0] ?? '';

        if (strlen($first) === 2 && in_array($first, $supported, true)) {
            return $first;
        }

        return null;
    }

    /**
     * @param list<string> $supported
     */
    private function detectFromCookie(ServerRequestInterface $request, array $supported): ?string
    {
        $cookies = $request->getCookieParams();
        $locale = $cookies['rakun_locale'] ?? null;

        if ($locale !== null && in_array($locale, $supported, true)) {
            return $locale;
        }

        return null;
    }

    /**
     * @param list<string> $supported
     */
    private function detectFromHeader(ServerRequestInterface $request, array $supported): ?string
    {
        $header = $request->getHeaderLine('Accept-Language');
        if (empty($header)) {
            return null;
        }

        // Parse Accept-Language: es-MX,es;q=0.9,en;q=0.8
        preg_match_all('/([a-zA-Z]{1,8}(?:-[a-zA-Z]{1,8})?)(?:;q=([0-9.]+))?/', $header, $matches);

        $langs = [];
        foreach ($matches[1] as $i => $lang) {
            $quality = !empty($matches[2][$i]) ? (float) $matches[2][$i] : 1.0;
            $langs[$lang] = $quality;
        }

        arsort($langs);

        foreach ($langs as $lang => $quality) {
            // Try exact match
            $short = substr($lang, 0, 2);
            if (in_array($short, $supported, true)) {
                return $short;
            }
        }

        return null;
    }
}
