<?php

declare(strict_types=1);

use Rkn\Cms\Template\Extensions\I18nExtension;

beforeEach(function () {
    $this->originalUri = $_SERVER['REQUEST_URI'] ?? null;
    $GLOBALS['__test_config_site_locales'] = ['en', 'es', 'pt', 'fr'];

    if (!function_exists('config')) {
        function config(string $key, mixed $default = null): mixed
        {
            if ($key === 'site.locales') {
                return $GLOBALS['__test_config_site_locales'] ?? $default;
            }
            return $default;
        }
    }
    if (!function_exists('app')) {
        function app(string $key): mixed
        {
            if ($key === 'current_entry') {
                throw new RuntimeException('no current entry');
            }
            return null;
        }
    }
    if (!function_exists('t')) {
        function t(string $key, array $params = []): string
        {
            return $key;
        }
    }
});

afterEach(function () {
    if ($this->originalUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $this->originalUri;
    }
});

test('swaps locale prefix on a nested path', function () {
    $_SERVER['REQUEST_URI'] = '/en/docs/core-concepts/architecture';
    $ext = new I18nExtension();

    expect($ext->urlForLocale('es'))->toBe('/es/docs/core-concepts/architecture');
    expect($ext->urlForLocale('fr'))->toBe('/fr/docs/core-concepts/architecture');
});

test('preserves query string', function () {
    $_SERVER['REQUEST_URI'] = '/en/search?q=hello&page=2';
    $ext = new I18nExtension();

    expect($ext->urlForLocale('pt'))->toBe('/pt/search?q=hello&page=2');
});

test('handles root locale path', function () {
    $_SERVER['REQUEST_URI'] = '/en/';
    $ext = new I18nExtension();

    expect($ext->urlForLocale('es'))->toBe('/es/');
});

test('handles bare slash with no locale prefix', function () {
    $_SERVER['REQUEST_URI'] = '/';
    $ext = new I18nExtension();

    expect($ext->urlForLocale('fr'))->toBe('/fr/');
});

test('handles missing REQUEST_URI gracefully', function () {
    unset($_SERVER['REQUEST_URI']);
    $ext = new I18nExtension();

    expect($ext->urlForLocale('es'))->toBe('/es/');
});
