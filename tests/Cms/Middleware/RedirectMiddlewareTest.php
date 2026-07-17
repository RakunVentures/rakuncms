<?php

declare(strict_types=1);

use Rkn\Cms\Middleware\RedirectMiddleware;

/**
 * Regresión del doble prefijo de locale (2026-07-17): `ContentScanner::buildUrlPath()`
 * ya hornea el prefijo `/es` en `url` para entradas de locale no-default, así que
 * anteponer el locale incondicionalmente producía `/es/es/...` (404) en TODO
 * redirect `old_url` de locale no-default del sitio. `localePrefixedUrl` debe ser
 * idempotente respecto al prefijo.
 */
it('prefixes default-locale urls that carry no locale prefix', function () {
    expect(RedirectMiddleware::localePrefixedUrl('en', '/download'))->toBe('/en/download');
});

it('does not double-prefix non-default-locale urls', function () {
    expect(RedirectMiddleware::localePrefixedUrl('es', '/es/download'))->toBe('/es/download');
});

it('leaves an exact locale-root url untouched', function () {
    expect(RedirectMiddleware::localePrefixedUrl('es', '/es'))->toBe('/es');
});

it('still prefixes urls whose first segment merely starts with the locale string', function () {
    // '/esencia' NO es un url prefijado con locale 'es' — el prefijo cuenta solo
    // como segmento completo.
    expect(RedirectMiddleware::localePrefixedUrl('es', '/esencia'))->toBe('/es/esencia');
});
