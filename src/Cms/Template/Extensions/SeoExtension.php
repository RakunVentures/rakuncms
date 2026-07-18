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
            // Support both config layouts: per-section files (site.yaml -> site.url)
            // and the monolithic rakun.yaml (rakun.site.url). Mirrors the dual
            // fallback already used by LocaleDetector, ApiAuthMiddleware and Indexer.
            $baseUrl = \config('site.url')
                ?? \config('site.base_url')
                ?? \config('rakun.site.url')
                ?? \config('rakun.site.base_url')
                ?? '';
        } catch (\Throwable) {
        }

        try {
            $locales = \config('site.locales')
                ?? \config('rakun.site.locales')
                ?? ['es'];
        } catch (\Throwable) {
        }

        try {
            $nav = \app('globals')['nav'] ?? [];
        } catch (\Throwable) {
        }

        $defaultLocale = 'es';
        try {
            $defaultLocale = \config('rakun.site.default_locale') ?? \config('site.default_locale', 'es');
        } catch (\Throwable) {
        }

        // El prefijo de locale se omite para el default, igual que
        // ContentScanner::buildUrlPath() (fuente de verdad para las URLs
        // indexadas que usa el canonical). Si aquí siempre lo antepusiéramos,
        // el hreflang self-referencing de las páginas del locale default
        // apuntaría a una URL distinta de su propio canonical.
        $alternateUrls = [];
        if ($entry !== null && $baseUrl !== '') {
            foreach ($locales as $loc) {
                $slug = $entry->slugForLocale($loc);
                $collection = $entry->collection();
                $localePrefix = $loc === $defaultLocale ? '' : '/' . $loc;

                if ($collection === 'pages') {
                    // Mismo whitelist que ContentScanner::buildUrlPath(): el
                    // basename de un archivo de home (p.ej. index.en.md) se
                    // extrae literalmente como slug "index" cuando no hay
                    // override explícito en el frontmatter — hay que tratarlo
                    // como home igual que 'home'/'inicio'/''.
                    if (in_array($slug, ['index', 'home', 'inicio', ''], true)) {
                        $path = $localePrefix !== '' ? $localePrefix . '/' : '/';
                    } else {
                        $path = $localePrefix . '/' . $slug;
                    }
                } else {
                    $path = $localePrefix . '/' . $collection . '/' . $slug;
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
            // Dual fallback: per-section seo.yaml or monolithic rakun.yaml (rakun.seo).
            $seo = \config('seo') ?? \config('rakun.seo') ?? [];
            return is_array($seo) ? $seo : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getSiteGlobals(): array
    {
        $site = [];

        try {
            $globals = \app('globals');
            $site = $globals['site'] ?? [];
        } catch (\Throwable) {
        }

        // Fall back to the config site section (dual layout) so meta description,
        // title and OG tags still resolve when template globals are not populated.
        if (!is_array($site) || $site === []) {
            try {
                $site = \config('site') ?? \config('rakun.site') ?? [];
            } catch (\Throwable) {
                $site = [];
            }
        }

        return is_array($site) ? $site : [];
    }
}
