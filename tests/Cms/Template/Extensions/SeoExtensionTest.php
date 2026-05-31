<?php

declare(strict_types=1);

use Rkn\Cms\Template\Extensions\SeoExtension;
use Rkn\Framework\Application;

test('seo extension provides all expected functions', function () {
    $ext = new SeoExtension();
    $functions = $ext->getFunctions();

    $names = array_map(fn ($f) => $f->getName(), $functions);

    expect($names)->toContain('seo_head');
    expect($names)->toContain('seo_jsonld');
    expect($names)->toContain('seo_consent');
    expect($names)->toContain('seo_analytics');
    expect($names)->toContain('seo_webmcp');
});

test('seo extension functions are marked as html safe', function () {
    $ext = new SeoExtension();
    $functions = $ext->getFunctions();

    foreach ($functions as $function) {
        expect($function->getSafe(new \Twig\Node\Expression\ConstantExpression('', 0)))->toContain('html');
    }
});

/**
 * The following tests cover the dual config-layout fallback: SeoExtension must
 * resolve site.url / seo.* whether config lives in per-section files (site.yaml,
 * seo.yaml) or in the monolithic rakun.yaml (rakun.site.*, rakun.seo.*) — the
 * layout every shipped site actually uses.
 */

afterEach(function () {
    // Reset the Application singleton so booted fixtures don't leak across tests.
    $prop = new ReflectionProperty(Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function bootSeoFixture(string $dir, string $relPath, string $yaml): void
{
    $configDir = $dir . '/config';
    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    $target = $dir . '/' . $relPath;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0755, true);
    }
    file_put_contents($target, $yaml);
    // Avoid Dotenv emitting a warning when the fixture has no .env file.
    file_put_contents($dir . '/.env', '');

    new Application($dir);
}

test('seoHead resolves SEO from monolithic rakun.yaml', function () {
    $dir = $this->makeTempDir();
    bootSeoFixture($dir, 'config/rakun.yaml', <<<YAML
    site:
      url: "https://hotel.example.com"
      title: "Test Hotel"
      description: "The best test hotel"
      locales: ["es", "en"]
    seo:
      site_name: "Test Hotel"
      default_image: "/img/og.jpg"
      organization:
        name: "Test Hotel Org"
        url: "https://hotel.example.com"
      local_business:
        type: "Hotel"
        name: "Test Hotel"
        telephone: "+1-555-0100"
        address:
          street: "1 Main St"
          locality: "Town"
    YAML);

    $head = (new SeoExtension())->seoHead();

    // OG image resolves seo.default_image against site.url (dual fallback worked)
    expect($head)->toContain('property="og:image"');
    expect($head)->toContain('https://hotel.example.com/img/og.jpg');
    // JSON-LD schemas come straight from seo.organization / seo.local_business
    expect($head)->toContain('schema.org');
    expect($head)->toContain('"Organization"');
    expect($head)->toContain('"Hotel"');
    expect($head)->toContain('Test Hotel Org');
});

test('seoJsonld emits organization and local business from monolithic config', function () {
    $dir = $this->makeTempDir();
    bootSeoFixture($dir, 'config/rakun.yaml', <<<YAML
    site:
      url: "https://hotel.example.com"
    seo:
      site_name: "Mono Hotel"
      organization:
        name: "Mono Org"
      local_business:
        type: "Hotel"
        name: "Mono Hotel"
    YAML);

    $jsonld = (new SeoExtension())->seoJsonld();

    expect($jsonld)->toContain('"Organization"');
    expect($jsonld)->toContain('Mono Org');
    expect($jsonld)->toContain('"Hotel"');
});

test('seoHead also resolves SEO from per-section config files', function () {
    $dir = $this->makeTempDir();
    mkdir($dir . '/config', 0755, true);
    file_put_contents($dir . '/config/site.yaml', "url: \"https://b.example.com\"\ntitle: \"Site B\"\nlocales: [\"es\"]\n");
    file_put_contents($dir . '/config/seo.yaml', "site_name: \"Site B\"\ndefault_image: \"/og-b.png\"\norganization:\n  name: \"B Org\"\n");
    file_put_contents($dir . '/.env', '');

    new Application($dir);

    $head = (new SeoExtension())->seoHead();

    expect($head)->toContain('https://b.example.com/og-b.png');
    expect($head)->toContain('"Organization"');
    expect($head)->toContain('B Org');
});

test('seoHead degrades gracefully with no SEO config', function () {
    $dir = $this->makeTempDir();
    mkdir($dir . '/config', 0755, true);
    file_put_contents($dir . '/config/rakun.yaml', "debug: false\n");
    file_put_contents($dir . '/.env', '');

    new Application($dir);

    // Should not throw and should not emit organization/local-business schemas.
    $head = (new SeoExtension())->seoHead();

    expect($head)->not->toContain('"Organization"');
    expect($head)->not->toContain('"Hotel"');
});
