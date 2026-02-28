<?php

declare(strict_types=1);

namespace Rkn\Cms\Seo;

use Rkn\Cms\Content\Entry;

final class JsonLdGenerator
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
     * Generate all JSON-LD scripts.
     *
     * @param array<string, mixed> $context Keys: entry, locale, base_url, locales, alternate_urls
     */
    public function generate(array $context): string
    {
        $scripts = array_filter([
            $this->webSiteSchema($context),
            $this->organizationSchema($context),
            $this->localBusinessSchema($context),
            $this->breadcrumbSchema($context),
            $this->articleSchema($context),
        ]);

        return implode("\n", $scripts);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function webSiteSchema(array $context): string
    {
        $name = $this->seoConfig['site_name'] ?? $this->siteGlobals['title'] ?? '';
        $url = $context['base_url'] ?? '';

        if ($name === '' && $url === '') {
            return '';
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
        ];

        if ($name !== '') {
            $schema['name'] = $name;
        }
        if ($url !== '') {
            $schema['url'] = $url;
            $schema['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => rtrim($url, '/') . '/search?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $this->wrapScript($schema);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function organizationSchema(array $context): string
    {
        $org = $this->seoConfig['organization'] ?? [];

        if ($org === []) {
            return '';
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
        ];

        if (!empty($org['name'])) {
            $schema['name'] = $org['name'];
        }
        if (!empty($org['url'])) {
            $schema['url'] = $org['url'];
        }
        if (!empty($org['logo'])) {
            $baseUrl = $org['url'] ?? $context['base_url'] ?? '';
            $logo = $org['logo'];
            if (!str_starts_with($logo, 'http') && $baseUrl !== '') {
                $logo = rtrim($baseUrl, '/') . '/' . ltrim($logo, '/');
            }
            $schema['logo'] = $logo;
        }

        return $this->wrapScript($schema);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function localBusinessSchema(array $context): string
    {
        $business = $this->seoConfig['local_business'] ?? [];

        if ($business === []) {
            return '';
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $business['type'] ?? 'LocalBusiness',
        ];

        $name = $this->seoConfig['organization']['name']
            ?? $this->seoConfig['site_name']
            ?? $this->siteGlobals['title']
            ?? '';
        if ($name !== '') {
            $schema['name'] = $name;
        }

        $address = $business['address'] ?? [];
        if ($address !== []) {
            $schema['address'] = [
                '@type' => 'PostalAddress',
            ];
            if (!empty($address['street'])) {
                $schema['address']['streetAddress'] = $address['street'];
            }
            if (!empty($address['city'])) {
                $schema['address']['addressLocality'] = $address['city'];
            }
            if (!empty($address['region'])) {
                $schema['address']['addressRegion'] = $address['region'];
            }
            if (!empty($address['postal_code'])) {
                $schema['address']['postalCode'] = $address['postal_code'];
            }
            if (!empty($address['country'])) {
                $schema['address']['addressCountry'] = $address['country'];
            }
        }

        if (!empty($business['phone'])) {
            $schema['telephone'] = $business['phone'];
        }
        if (!empty($business['price_range'])) {
            $schema['priceRange'] = $business['price_range'];
        }

        $url = $this->seoConfig['organization']['url'] ?? $context['base_url'] ?? '';
        if ($url !== '') {
            $schema['url'] = $url;
        }

        return $this->wrapScript($schema);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function breadcrumbSchema(array $context): string
    {
        $entry = $context['entry'] ?? null;
        $baseUrl = $context['base_url'] ?? '';

        if (!$entry instanceof Entry || $baseUrl === '') {
            return '';
        }

        $items = [];
        $position = 1;

        // Home
        $items[] = [
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => 'Home',
            'item' => rtrim($baseUrl, '/') . '/' . $entry->locale() . '/',
        ];

        $collection = $entry->collection();

        // Collection level (for non-pages)
        if ($collection !== 'pages') {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => ucfirst($collection),
                'item' => rtrim($baseUrl, '/') . '/' . $entry->locale() . '/' . $collection,
            ];
        }

        // Entry level
        $items[] = [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $entry->title(),
            'item' => rtrim($baseUrl, '/') . $entry->url(),
        ];

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];

        return $this->wrapScript($schema);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function articleSchema(array $context): string
    {
        $entry = $context['entry'] ?? null;

        if (!$entry instanceof Entry) {
            return '';
        }

        $collection = $entry->collection();
        if (!in_array($collection, ['blog', 'articles', 'posts'], true)) {
            return '';
        }

        $baseUrl = $context['base_url'] ?? '';

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $entry->title(),
        ];

        if ($entry->date() !== null) {
            $schema['datePublished'] = $entry->date();
        }

        if ($entry->mtime() > 0) {
            $schema['dateModified'] = date('Y-m-d', $entry->mtime());
        }

        $author = $entry->getMeta('author') ?? $this->siteGlobals['author'] ?? '';
        if ($author !== '') {
            $schema['author'] = [
                '@type' => 'Person',
                'name' => $author,
            ];
        }

        $image = $entry->getMeta('image');
        if ($image !== null && $image !== '') {
            if (!str_starts_with($image, 'http') && $baseUrl !== '') {
                $image = rtrim($baseUrl, '/') . '/' . ltrim($image, '/');
            }
            $schema['image'] = $image;
        }

        $description = $entry->getMeta('description');
        if ($description !== null && $description !== '') {
            $schema['description'] = $description;
        }

        if ($baseUrl !== '') {
            $schema['url'] = rtrim($baseUrl, '/') . $entry->url();
        }

        return $this->wrapScript($schema);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function wrapScript(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
    }
}
