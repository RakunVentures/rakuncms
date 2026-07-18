<?php

declare(strict_types=1);

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Template\Extensions\I18nExtension;
use Rkn\Framework\Application;

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

    // Reset the Application singleton so booted fixtures (current_entry
    // tests below) don't leak into the plain swapLocaleInCurrentUri tests,
    // which rely on app('current_entry') throwing because no Application
    // is initialized. Safe no-op when no Application was ever booted.
    $prop = new ReflectionProperty(Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

/**
 * `app()` is defined once, globally, by src/Framework/helpers.php (a
 * Composer "files" autoload entry loaded before any test runs) — it always
 * resolves from the real Application container, never from a local stub.
 * So exercising the current_entry path means booting a real (temp-dir)
 * Application and registering the entry in its container, the same way
 * SeoExtensionTest does for SeoExtension.
 */
function bootI18nFixture(string $dir): void
{
    mkdir($dir . '/config', 0755, true);
    file_put_contents($dir . '/config/rakun.yaml', "site:\n  locales: [\"en\", \"es\"]\n");
    file_put_contents($dir . '/.env', '');

    new Application($dir);
}

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

/**
 * G3 follow-up: el nav locale switcher usa current_entry cuando está
 * disponible (en vez del fallback swapLocaleInCurrentUri). Un archivo de
 * home sin override de slug en el frontmatter (index.en.md, index.es.md)
 * indexa el slug literal "index" — Entry::isHomeSlug() (compartido con
 * ContentScanner::buildUrlPath() y SeoExtension) debe reconocerlo como
 * home para que el switcher no emita "/es/index".
 */
test('resolves home entry with the literal "index" slug via current_entry', function () {
    $dir = $this->makeTempDir();
    bootI18nFixture($dir);

    app()->set('current_entry', Entry::fromArray([
        'title' => 'Home',
        'slug' => 'index',
        'collection' => 'pages',
        'locale' => 'en',
        'file' => '',
        'slugs' => [],
    ]));

    $ext = new I18nExtension();

    expect($ext->urlForLocale('es'))->toBe('/es/');
    expect($ext->urlForLocale('en'))->toBe('/en/');
});

test('resolves home entry with the "home"/"inicio" slug via current_entry', function () {
    $dir = $this->makeTempDir();
    bootI18nFixture($dir);

    app()->set('current_entry', Entry::fromArray([
        'title' => 'Inicio',
        'slug' => 'inicio',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => '',
        'slugs' => ['en' => 'home', 'es' => 'inicio'],
    ]));

    $ext = new I18nExtension();

    expect($ext->urlForLocale('en'))->toBe('/en/');
    expect($ext->urlForLocale('es'))->toBe('/es/');
});

test('resolves a normal (non-home) page entry via current_entry', function () {
    $dir = $this->makeTempDir();
    bootI18nFixture($dir);

    app()->set('current_entry', Entry::fromArray([
        'title' => 'Pricing',
        'slug' => 'pricing',
        'collection' => 'pages',
        'locale' => 'en',
        'file' => '',
        'slugs' => ['en' => 'pricing', 'es' => 'pricing'],
    ]));

    $ext = new I18nExtension();

    expect($ext->urlForLocale('es'))->toBe('/es/pricing');
    expect($ext->urlForLocale('en'))->toBe('/en/pricing');
});
