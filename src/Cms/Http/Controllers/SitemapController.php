<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Rkn\Cms\Content\Entry;
use Rkn\Cms\Content\Indexer;

/**
 * Generates sitemap.xml with hreflang annotations for bilingual support.
 */
final class SitemapController
{
    public function handle(): ResponseInterface
    {
        $basePath = \app('base_path');
        $baseUrl = rtrim(\config('site.base_url', ''), '/');
        $locales = \config('site.locales', ['es', 'en']);

        $indexer = new Indexer($basePath);
        $index = $indexer->load();
        $entries = $index['entries'] ?? [];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $xml .= ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        // Group entries by slug identity (same content in different locales)
        $grouped = $this->groupByContent($entries, $locales);

        foreach ($grouped as $group) {
            foreach ($group as $locale => $entry) {
                $url = $baseUrl . ($entry['url'] ?? '');

                $xml .= "  <url>\n";
                $xml .= "    <loc>" . htmlspecialchars($url) . "</loc>\n";

                // Add hreflang links for all locale variants
                foreach ($group as $altLocale => $altEntry) {
                    $altUrl = $baseUrl . ($altEntry['url'] ?? '');
                    $xml .= '    <xhtml:link rel="alternate" hreflang="' . $altLocale . '" href="' . htmlspecialchars($altUrl) . '" />' . "\n";
                }

                if (!empty($entry['date'])) {
                    $xml .= "    <lastmod>" . date('Y-m-d', strtotime((string) $entry['date'])) . "</lastmod>\n";
                }

                $xml .= "    <changefreq>weekly</changefreq>\n";
                $xml .= "  </url>\n";
            }
        }

        $xml .= "</urlset>\n";

        return new Response(200, ['Content-Type' => 'application/xml; charset=UTF-8'], $xml);
    }

    /**
     * Group entries by their content identity across locales.
     *
     * @param array<string, array<string, mixed>> $entries
     * @param list<string> $locales
     * @return list<array<string, array<string, mixed>>>
     */
    private function groupByContent(array $entries, array $locales): array
    {
        $groups = [];
        $seen = [];

        foreach ($entries as $key => $entry) {
            $locale = $entry['locale'] ?? 'es';
            $collection = $entry['collection'] ?? 'pages';
            $slug = $entry['slug'] ?? '';

            // Create a canonical identifier independent of locale
            $identity = $collection . '/' . $slug;

            // For entries with bilingual slugs, try to find matching entry
            $matchKey = null;
            foreach ($groups as $idx => $group) {
                foreach ($group as $gLocale => $gEntry) {
                    // Same file base means same content
                    $gFile = $gEntry['file'] ?? '';
                    $eFile = $entry['file'] ?? '';

                    // Match by file path pattern (remove locale suffix)
                    $gBase = preg_replace('/\.(en|es)\.md$/', '.md', $gFile);
                    $eBase = preg_replace('/\.(en|es)\.md$/', '.md', $eFile);

                    if ($gBase === $eBase && $gBase !== '') {
                        $matchKey = $idx;
                        break 2;
                    }
                }
            }

            if ($matchKey !== null) {
                $groups[$matchKey][$locale] = $entry;
            } else {
                $groups[] = [$locale => $entry];
            }
        }

        return array_values($groups);
    }
}
