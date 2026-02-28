<?php

declare(strict_types=1);

namespace Rkn\Cms\Seo;

use Rkn\Cms\Content\Entry;

final class MetaTagGenerator
{
    /**
     * @param array<string, mixed> $seoConfig
     * @param array<string, mixed> $siteGlobals
     */
    public function __construct(
        private array $seoConfig = [],
        private array $siteGlobals = [],
    ) {
    }

    /**
     * Generate all meta tags HTML.
     *
     * @param array<string, mixed> $context Keys: entry, locale, base_url, locales, alternate_urls
     */
    public function generate(array $context): string
    {
        $entry = $context['entry'] ?? null;
        $parts = array_filter([
            $this->descriptionTag($entry),
            $this->keywordsTag($entry),
            $this->authorTag($entry),
            $this->robotsTag($entry),
            $this->canonicalTag($entry, $context['base_url'] ?? ''),
            $this->openGraphTags($entry, $context),
            $this->twitterCardTags($entry, $context),
            $this->hreflangTags($context),
            $this->verificationTags(),
        ]);

        return implode("\n", $parts);
    }

    private function descriptionTag(?Entry $entry): string
    {
        $description = $entry?->getMeta('description')
            ?? $this->siteGlobals['description']
            ?? '';

        if ($description === '') {
            return '';
        }

        return '<meta name="description" content="' . $this->escape($description) . '">';
    }

    private function keywordsTag(?Entry $entry): string
    {
        $keywords = $entry?->getMeta('keywords') ?? '';

        if ($keywords === '') {
            return '';
        }

        return '<meta name="keywords" content="' . $this->escape($keywords) . '">';
    }

    private function authorTag(?Entry $entry): string
    {
        $author = $entry?->getMeta('author')
            ?? $this->siteGlobals['author']
            ?? '';

        if ($author === '') {
            return '';
        }

        return '<meta name="author" content="' . $this->escape($author) . '">';
    }

    private function robotsTag(?Entry $entry): string
    {
        $robots = $entry?->getMeta('robots') ?? '';

        if ($robots === '') {
            return '';
        }

        return '<meta name="robots" content="' . $this->escape($robots) . '">';
    }

    private function canonicalTag(?Entry $entry, string $baseUrl): string
    {
        if ($entry === null) {
            return '';
        }

        $canonical = $entry->getMeta('canonical');
        if ($canonical !== null && $canonical !== '') {
            return '<link rel="canonical" href="' . $this->escape($canonical) . '">';
        }

        if ($baseUrl === '') {
            return '';
        }

        $url = rtrim($baseUrl, '/') . $entry->url();

        return '<link rel="canonical" href="' . $this->escape($url) . '">';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function openGraphTags(?Entry $entry, array $context): string
    {
        $baseUrl = $context['base_url'] ?? '';
        $title = $entry?->title() ?? $this->siteGlobals['title'] ?? '';
        $description = $entry?->getMeta('description') ?? $this->siteGlobals['description'] ?? '';
        $siteName = $this->seoConfig['site_name'] ?? $this->siteGlobals['title'] ?? '';

        if ($title === '' && $siteName === '') {
            return '';
        }

        $type = $entry?->getMeta('type') ?? $this->resolveOgType($entry);
        $url = ($entry !== null && $baseUrl !== '') ? rtrim($baseUrl, '/') . $entry->url() : $baseUrl;
        $image = $entry?->getMeta('image') ?? $this->seoConfig['default_image'] ?? '';
        $locale = $context['locale'] ?? '';

        $tags = [];
        if ($title !== '') {
            $tags[] = '<meta property="og:title" content="' . $this->escape($title) . '">';
        }
        if ($description !== '') {
            $tags[] = '<meta property="og:description" content="' . $this->escape($description) . '">';
        }
        if ($url !== '') {
            $tags[] = '<meta property="og:url" content="' . $this->escape($url) . '">';
        }
        $tags[] = '<meta property="og:type" content="' . $this->escape($type) . '">';
        if ($image !== '') {
            $imageUrl = str_starts_with($image, 'http') ? $image : rtrim($baseUrl, '/') . '/' . ltrim($image, '/');
            $tags[] = '<meta property="og:image" content="' . $this->escape($imageUrl) . '">';
        }
        if ($locale !== '') {
            $tags[] = '<meta property="og:locale" content="' . $this->escape($locale) . '">';
        }
        if ($siteName !== '') {
            $tags[] = '<meta property="og:site_name" content="' . $this->escape($siteName) . '">';
        }

        return implode("\n", $tags);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function twitterCardTags(?Entry $entry, array $context): string
    {
        $title = $entry?->title() ?? $this->siteGlobals['title'] ?? '';
        $description = $entry?->getMeta('description') ?? $this->siteGlobals['description'] ?? '';
        $baseUrl = $context['base_url'] ?? '';

        if ($title === '' && $description === '') {
            return '';
        }

        $image = $entry?->getMeta('image') ?? $this->seoConfig['default_image'] ?? '';
        $cardType = $image !== '' ? 'summary_large_image' : 'summary';
        $twitterHandle = $this->seoConfig['twitter_handle'] ?? '';

        $tags = [];
        $tags[] = '<meta name="twitter:card" content="' . $cardType . '">';
        if ($title !== '') {
            $tags[] = '<meta name="twitter:title" content="' . $this->escape($title) . '">';
        }
        if ($description !== '') {
            $tags[] = '<meta name="twitter:description" content="' . $this->escape($description) . '">';
        }
        if ($image !== '') {
            $imageUrl = str_starts_with($image, 'http') ? $image : rtrim($baseUrl, '/') . '/' . ltrim($image, '/');
            $tags[] = '<meta name="twitter:image" content="' . $this->escape($imageUrl) . '">';
        }
        if ($twitterHandle !== '') {
            $tags[] = '<meta name="twitter:site" content="' . $this->escape($twitterHandle) . '">';
        }

        return implode("\n", $tags);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function hreflangTags(array $context): string
    {
        $alternateUrls = $context['alternate_urls'] ?? [];

        if ($alternateUrls === []) {
            return '';
        }

        $tags = [];
        $firstUrl = '';
        foreach ($alternateUrls as $locale => $url) {
            $tags[] = '<link rel="alternate" hreflang="' . $this->escape($locale) . '" href="' . $this->escape($url) . '">';
            if ($firstUrl === '') {
                $firstUrl = $url;
            }
        }

        if ($firstUrl !== '') {
            $tags[] = '<link rel="alternate" hreflang="x-default" href="' . $this->escape($firstUrl) . '">';
        }

        return implode("\n", $tags);
    }

    private function verificationTags(): string
    {
        $tags = [];

        $google = $this->seoConfig['google_verification'] ?? '';
        if ($google !== '') {
            $tags[] = '<meta name="google-site-verification" content="' . $this->escape($google) . '">';
        }

        $bing = $this->seoConfig['bing_verification'] ?? '';
        if ($bing !== '') {
            $tags[] = '<meta name="msvalidate.01" content="' . $this->escape($bing) . '">';
        }

        return implode("\n", $tags);
    }

    private function resolveOgType(?Entry $entry): string
    {
        if ($entry === null) {
            return 'website';
        }

        $collection = $entry->collection();
        if ($collection === 'blog' || $collection === 'articles' || $collection === 'posts') {
            return 'article';
        }

        return 'website';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
