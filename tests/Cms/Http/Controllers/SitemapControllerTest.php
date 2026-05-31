<?php

declare(strict_types=1);

use Rkn\Cms\Http\Controllers\SitemapController;
use Rkn\Framework\Application;

/**
 * Regression coverage for the dual config-layout fallback in SitemapController.
 * Sitemaps require absolute <loc> URLs (sitemaps.org protocol). When config lives
 * in the monolithic rakun.yaml (rakun.site.base_url) rather than per-section
 * site.yaml, the controller must still resolve an absolute base — otherwise it
 * emits invalid relative <loc> entries. Mirrors the SeoExtension dual-fallback tests.
 */

afterEach(function () {
    $prop = new ReflectionProperty(Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function bootSitemapFixture(string $dir, string $rakunYaml): void
{
    mkdir($dir . '/config', 0755, true);
    file_put_contents($dir . '/config/rakun.yaml', $rakunYaml);
    file_put_contents($dir . '/.env', '');

    // A couple of published entries so the index yields routable URLs.
    mkdir($dir . '/content/pages', 0755, true);
    file_put_contents($dir . '/content/pages/home.md', "---\ntitle: Inicio\nslugs:\n  es: \"\"\n  en: \"\"\n---\nHola\n");

    mkdir($dir . '/content/habitaciones', 0755, true);
    file_put_contents($dir . '/content/habitaciones/coco.md', "---\ntitle: Coco\n---\nHabitacion Coco\n");

    new Application($dir);
}

test('sitemap emits absolute loc URLs from monolithic rakun.yaml base_url', function () {
    $dir = $this->makeTempDir();
    bootSitemapFixture($dir, <<<YAML
    site:
      base_url: "https://mono.example.com"
      title: "Mono Hotel"
      locales: ["es", "en"]
    YAML);

    $xml = (string) (new SitemapController())->handle()->getBody();

    // Every <loc> must be absolute and rooted at the monolithic base_url.
    expect($xml)->toContain('<loc>https://mono.example.com/');

    // And there must be no relative <loc> entries (the bug this guards against).
    preg_match_all('/<loc>([^<]+)<\/loc>/', $xml, $m);
    expect($m[1])->not->toBeEmpty();
    foreach ($m[1] as $loc) {
        expect($loc)->toStartWith('https://mono.example.com/');
    }
});

test('sitemap also resolves base_url from site.url when base_url is absent', function () {
    $dir = $this->makeTempDir();
    bootSitemapFixture($dir, <<<YAML
    site:
      url: "https://siteurl.example.com"
      locales: ["es"]
    YAML);

    $xml = (string) (new SitemapController())->handle()->getBody();

    expect($xml)->toContain('<loc>https://siteurl.example.com/');
});
