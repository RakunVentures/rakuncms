<?php

declare(strict_types=1);

namespace Rkn\Cms\Template\Extensions;

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Seo\ConsentManager;
use Rkn\Cms\Seo\JsonLdGenerator;
use Rkn\Cms\Seo\MetaTagGenerator;
use Rkn\Cms\Seo\WebMcpGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SeoExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('seo_head', [$this, 'seoHead'], ['is_safe' => ['html']]),
            new TwigFunction('seo_jsonld', [$this, 'seoJsonld'], ['is_safe' => ['html']]),
            new TwigFunction('seo_consent', [$this, 'seoConsent'], ['is_safe' => ['html']]),
            new TwigFunction('seo_analytics', [$this, 'seoAnalytics'], ['is_safe' => ['html']]),
            new TwigFunction('seo_webmcp', [$this, 'seoWebmcp'], ['is_safe' => ['html']]),
        ];
    }

    public function seoHead(): string
    {
        $context = $this->buildContext();
        $seoConfig = $this->getSeoConfig();
        $siteGlobals = $this->getSiteGlobals();

        $metaGen = new MetaTagGenerator($seoConfig, $siteGlobals);
        $jsonLdGen = new JsonLdGenerator($seoConfig, $siteGlobals);

        $parts = array_filter([
            $metaGen->generate($context),
            $jsonLdGen->generate($context),
        ]);

        return implode("\n", $parts);
    }

    public function seoJsonld(): string
    {
        $context = $this->buildContext();
        $seoConfig = $this->getSeoConfig();
        $siteGlobals = $this->getSiteGlobals();

        $jsonLdGen = new JsonLdGenerator($seoConfig, $siteGlobals);

        return $jsonLdGen->generate($context);
    }

    public function seoConsent(): string
    {
        $seoConfig = $this->getSeoConfig();

        $consentManager = new ConsentManager($seoConfig);

        return $consentManager->render();
    }

    public function seoAnalytics(): string
    {
        $seoConfig = $this->getSeoConfig();

        $consentManager = new ConsentManager($seoConfig);

        return $consentManager->renderAnalyticsOnly();
    }

    public function seoWebmcp(): string
    {
        $context = $this->buildContext();
        $siteGlobals = $this->getSiteGlobals();

        $webMcpGen = new WebMcpGenerator($siteGlobals);

        return $webMcpGen->generate($context);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(): array
    {
        $entry = null;
        $locale = 'es';
        $baseUrl = '';
        $locales = ['es'];
        $nav = [];

        try {
            $entry = \app('current_entry');
            if (!$entry instanceof Entry) {
                $entry = null;
            }
        } catch (\Throwable) {
        }

        try {
            $locale = \app('locale') ?? 'es';
        } catch (\Throwable) {
        }

        try {
            $baseUrl = \config('site.url', '');
        } catch (\Throwable) {
        }

        try {
            $locales = \config('site.locales', ['es']);
        } catch (\Throwable) {
        }

        try {
            $nav = \app('globals')['nav'] ?? [];
        } catch (\Throwable) {
        }

        $alternateUrls = [];
        if ($entry !== null && $baseUrl !== '') {
            foreach ($locales as $loc) {
                $slug = $entry->slugForLocale($loc);
                $collection = $entry->collection();

                if ($collection === 'pages') {
                    if ($slug === 'home' || $slug === 'inicio') {
                        $path = '/' . $loc . '/';
                    } else {
                        $path = '/' . $loc . '/' . $slug;
                    }
                } else {
                    $path = '/' . $loc . '/' . $collection . '/' . $slug;
                }

                $alternateUrls[$loc] = rtrim($baseUrl, '/') . $path;
            }
        }

        return [
            'entry' => $entry,
            'locale' => $locale,
            'base_url' => $baseUrl,
            'locales' => $locales,
            'alternate_urls' => $alternateUrls,
            'nav' => $nav,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getSeoConfig(): array
    {
        try {
            return \config('seo', []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getSiteGlobals(): array
    {
        try {
            $globals = \app('globals');
            return $globals['site'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
