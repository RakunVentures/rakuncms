<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\Query;

/**
 * Generates RSS 2.0 feed from content entries.
 */
final class RssController
{
    public function handle(): ResponseInterface
    {
        $basePath = \app('base_path');
        // Dual config layout: per-section site.yaml or monolithic rakun.yaml.
        // Without the fallback a monolithic layout yields an empty base and emits
        // invalid relative links in the feed. Mirrors SeoExtension/SitemapController.
        $baseUrl = rtrim(
            \config('site.base_url')
                ?? \config('site.url')
                ?? \config('rakun.site.base_url')
                ?? \config('rakun.site.url')
                ?? '',
            '/'
        );
        $siteTitle = \config('site.title') ?? \config('rakun.site.title') ?? 'RakunCMS';
        $siteDescription = \config('site.description') ?? \config('rakun.site.description') ?? '';
        $defaultLocale = \config('site.default_locale') ?? \config('rakun.site.default_locale') ?? 'es';

        $query = new Query(\index_store());

        // Get latest entries in default locale, sorted by date
        $entries = $query->locale($defaultLocale)->sort('date', 'desc')->limit(20)->get();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= "  <channel>\n";
        $xml .= "    <title>" . htmlspecialchars($siteTitle) . "</title>\n";
        $xml .= "    <link>" . htmlspecialchars($baseUrl) . "</link>\n";
        $xml .= "    <description>" . htmlspecialchars($siteDescription) . "</description>\n";
        $xml .= "    <language>" . $defaultLocale . "</language>\n";
        $xml .= '    <atom:link href="' . htmlspecialchars($baseUrl . '/rss.xml') . '" rel="self" type="application/rss+xml" />' . "\n";
        $xml .= "    <lastBuildDate>" . date('r') . "</lastBuildDate>\n";

        foreach ($entries as $entry) {
            $url = $baseUrl . $entry->url();
            $title = $entry->title();
            $date = $entry->date();
            $description = $entry->meta()['description'] ?? '';

            $xml .= "    <item>\n";
            $xml .= "      <title>" . htmlspecialchars($title) . "</title>\n";
            $xml .= "      <link>" . htmlspecialchars($url) . "</link>\n";
            $xml .= "      <guid>" . htmlspecialchars($url) . "</guid>\n";

            if ($date) {
                $xml .= "      <pubDate>" . date('r', strtotime($date)) . "</pubDate>\n";
            }

            if ($description) {
                $xml .= "      <description>" . htmlspecialchars($description) . "</description>\n";
            }

            $xml .= "    </item>\n";
        }

        $xml .= "  </channel>\n";
        $xml .= "</rss>\n";

        return new Response(200, ['Content-Type' => 'application/rss+xml; charset=UTF-8'], $xml);
    }
}
