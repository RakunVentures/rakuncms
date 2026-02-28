<?php

declare(strict_types=1);

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Seo\ConsentManager;
use Rkn\Cms\Seo\JsonLdGenerator;
use Rkn\Cms\Seo\MetaTagGenerator;
use Rkn\Cms\Seo\WebMcpGenerator;

test('MetaTagGenerator + JsonLdGenerator combined output is valid', function () {
    $entry = Entry::fromArray([
        'title' => 'Integration Test Page',
        'slug' => 'integration',
        'collection' => 'blog',
        'locale' => 'es',
        'file' => 'content/blog/integration.md',
        'date' => '2024-06-15',
        'mtime' => strtotime('2024-07-01'),
        'meta' => [
            'description' => 'Integration test description',
            'image' => '/assets/images/test.jpg',
            'author' => 'Test Author',
        ],
        'slugs' => ['es' => 'integration', 'en' => 'integration'],
    ]);

    $seoConfig = [
        'site_name' => 'Test Site',
        'default_image' => '/assets/images/default.jpg',
        'twitter_handle' => '@testsite',
        'google_verification' => 'gv123',
        'organization' => [
            'name' => 'Test Corp',
            'url' => 'https://test.com',
            'logo' => '/logo.png',
        ],
    ];

    $context = [
        'entry' => $entry,
        'locale' => 'es',
        'base_url' => 'https://test.com',
        'locales' => ['es', 'en'],
        'alternate_urls' => [
            'es' => 'https://test.com/es/blog/integration',
            'en' => 'https://test.com/en/blog/integration',
        ],
    ];

    $metaGen = new MetaTagGenerator($seoConfig, ['title' => 'Test Site']);
    $jsonLdGen = new JsonLdGenerator($seoConfig, ['title' => 'Test Site']);

    $metaHtml = $metaGen->generate($context);
    $jsonLdHtml = $jsonLdGen->generate($context);

    $combined = $metaHtml . "\n" . $jsonLdHtml;

    // Meta tags present
    expect($combined)->toContain('<meta name="description"');
    expect($combined)->toContain('<meta property="og:title"');
    expect($combined)->toContain('<meta name="twitter:card"');
    expect($combined)->toContain('<link rel="canonical"');
    expect($combined)->toContain('<link rel="alternate" hreflang="es"');

    // JSON-LD present
    expect($combined)->toContain('application/ld+json');
    expect($combined)->toContain('"@type": "WebSite"');
    expect($combined)->toContain('"@type": "Organization"');
    expect($combined)->toContain('"@type": "BreadcrumbList"');
    expect($combined)->toContain('"@type": "BlogPosting"');
});

test('ConsentManager does not render when no tracking configured', function () {
    $manager = new ConsentManager([]);
    expect($manager->render())->toBe('');
    expect($manager->renderAnalyticsOnly())->toBe('');
});

test('ConsentManager renders with both GA and Pixel', function () {
    $manager = new ConsentManager([
        'google_analytics' => 'G-COMBINED',
        'facebook_pixel' => '111222333',
    ]);
    $html = $manager->render();

    expect($html)->toContain('G-COMBINED');
    expect($html)->toContain('111222333');
    expect($html)->toContain('rkn-consent');
    expect($html)->toContain('<template data-consent');
});

test('WebMcpGenerator output contains valid JS structure', function () {
    $entry = Entry::fromArray([
        'title' => 'Test',
        'slug' => 'test',
        'collection' => 'pages',
        'locale' => 'es',
        'file' => 'content/pages/test.md',
        'meta' => ['description' => 'Test desc'],
    ]);

    $gen = new WebMcpGenerator(['title' => 'Site']);
    $html = $gen->generate([
        'entry' => $entry,
        'locale' => 'es',
        'base_url' => 'https://example.com',
        'nav' => [['label' => 'Home', 'url' => '/']],
    ]);

    expect($html)->toContain('<script>');
    expect($html)->toContain('</script>');
    expect($html)->toContain("navigator.modelContext.registerTool");
    expect($html)->toContain("'site_search'");
    expect($html)->toContain("'site_navigation'");
    expect($html)->toContain("'list_content'");
    expect($html)->toContain("'current_page'");
});

test('all generators handle null entry without errors', function () {
    $metaGen = new MetaTagGenerator();
    $jsonLdGen = new JsonLdGenerator();
    $webMcpGen = new WebMcpGenerator();

    $context = ['entry' => null, 'locale' => 'es', 'base_url' => ''];

    // These should not throw exceptions
    $metaHtml = $metaGen->generate($context);
    $jsonLdHtml = $jsonLdGen->generate($context);
    $webMcpHtml = $webMcpGen->generate($context);

    expect($metaHtml)->toBeString();
    expect($jsonLdHtml)->toBeString();
    expect($webMcpHtml)->toBeString();
});

test('all generators handle empty config without errors', function () {
    $metaGen = new MetaTagGenerator([], []);
    $jsonLdGen = new JsonLdGenerator([], []);
    $consentMgr = new ConsentManager([]);
    $webMcpGen = new WebMcpGenerator([]);

    $context = [];

    $metaHtml = $metaGen->generate($context);
    $jsonLdHtml = $jsonLdGen->generate($context);
    $consentHtml = $consentMgr->render();
    $webMcpHtml = $webMcpGen->generate($context);

    expect($metaHtml)->toBeString();
    expect($jsonLdHtml)->toBeString();
    expect($consentHtml)->toBe('');
    expect($webMcpHtml)->toBeString();
});
