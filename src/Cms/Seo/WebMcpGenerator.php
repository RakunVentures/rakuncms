<?php

declare(strict_types=1);

namespace Rkn\Cms\Seo;

use Rkn\Cms\Content\Entry;

final class WebMcpGenerator
{
    /**
     * @param array<string, mixed> $siteGlobals
     */
    public function __construct(
        private array $siteGlobals = [],
    ) {
    }

    /**
     * Generate WebMCP JavaScript.
     *
     * @param array<string, mixed> $context Keys: entry, locale, base_url, nav, entries
     */
    public function generate(array $context): string
    {
        $entry = $context['entry'] ?? null;
        $nav = $context['nav'] ?? [];
        $entries = $context['entries'] ?? [];
        $locale = $context['locale'] ?? 'es';
        $baseUrl = $context['base_url'] ?? '';

        $currentPage = $this->buildCurrentPage($entry, $locale, $baseUrl);
        $navData = $this->encodeJson($nav);
        $entriesData = $this->encodeJson($this->buildEntriesList($entries, $entry));
        $searchData = $this->encodeJson($this->buildSearchIndex($entries, $entry));
        $currentPageData = $this->encodeJson($currentPage);

        $js = "if('modelContext' in navigator){\n";
        $js .= $this->registerSearchTool($searchData);
        $js .= $this->registerNavigationTool($navData);
        $js .= $this->registerListContentTool($entriesData);
        $js .= $this->registerCurrentPageTool($currentPageData);
        $js .= "}";

        return '<script>' . "\n" . $js . "\n" . '</script>';
    }

    private function registerSearchTool(string $searchData): string
    {
        return "navigator.modelContext.registerTool({"
            . "name:'site_search',"
            . "description:'Search site content by keyword',"
            . "inputSchema:{type:'object',properties:{query:{type:'string',description:'Search query'}},required:['query']},"
            . "execute:async function(input){"
            . "var data=" . $searchData . ";"
            . "var q=input.query.toLowerCase();"
            . "return data.filter(function(item){"
            . "return item.title.toLowerCase().indexOf(q)!==-1||item.description.toLowerCase().indexOf(q)!==-1;"
            . "});"
            . "}"
            . "});\n";
    }

    private function registerNavigationTool(string $navData): string
    {
        return "navigator.modelContext.registerTool({"
            . "name:'site_navigation',"
            . "description:'Get the site navigation structure',"
            . "inputSchema:{type:'object',properties:{}},"
            . "execute:async function(){return " . $navData . ";}"
            . "});\n";
    }

    private function registerListContentTool(string $entriesData): string
    {
        return "navigator.modelContext.registerTool({"
            . "name:'list_content',"
            . "description:'List site content entries with title, URL, and collection',"
            . "inputSchema:{type:'object',properties:{collection:{type:'string',description:'Filter by collection name'}}},"
            . "execute:async function(input){"
            . "var data=" . $entriesData . ";"
            . "if(input&&input.collection){"
            . "return data.filter(function(item){return item.collection===input.collection;});"
            . "}"
            . "return data;"
            . "}"
            . "});\n";
    }

    private function registerCurrentPageTool(string $currentPageData): string
    {
        return "navigator.modelContext.registerTool({"
            . "name:'current_page',"
            . "description:'Get metadata about the current page',"
            . "inputSchema:{type:'object',properties:{}},"
            . "execute:async function(){return " . $currentPageData . ";}"
            . "});\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCurrentPage(?Entry $entry, string $locale, string $baseUrl): array
    {
        if ($entry === null) {
            return [
                'title' => $this->siteGlobals['title'] ?? '',
                'description' => $this->siteGlobals['description'] ?? '',
                'locale' => $locale,
                'collection' => '',
                'url' => $baseUrl,
            ];
        }

        return [
            'title' => $entry->title(),
            'description' => $entry->getMeta('description') ?? '',
            'locale' => $entry->locale(),
            'collection' => $entry->collection(),
            'url' => $baseUrl !== '' ? rtrim($baseUrl, '/') . $entry->url() : $entry->url(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>>|array<string, mixed> $entries
     * @return array<int, array<string, string>>
     */
    private function buildEntriesList(array $entries, ?Entry $currentEntry): array
    {
        $list = [];

        foreach ($entries as $data) {
            if ($data instanceof Entry) {
                $list[] = [
                    'title' => $data->title(),
                    'url' => $data->url(),
                    'collection' => $data->collection(),
                ];
            } elseif (is_array($data)) {
                $list[] = [
                    'title' => $data['title'] ?? '',
                    'url' => $data['url'] ?? '',
                    'collection' => $data['collection'] ?? '',
                ];
            }
        }

        // Include current entry if entries list is empty
        if ($list === [] && $currentEntry !== null) {
            $list[] = [
                'title' => $currentEntry->title(),
                'url' => $currentEntry->url(),
                'collection' => $currentEntry->collection(),
            ];
        }

        return $list;
    }

    /**
     * @param array<int, array<string, mixed>>|array<string, mixed> $entries
     * @return array<int, array<string, string>>
     */
    private function buildSearchIndex(array $entries, ?Entry $currentEntry): array
    {
        $index = [];

        foreach ($entries as $data) {
            if ($data instanceof Entry) {
                $index[] = [
                    'title' => $data->title(),
                    'description' => $data->getMeta('description') ?? '',
                    'url' => $data->url(),
                ];
            } elseif (is_array($data)) {
                $index[] = [
                    'title' => $data['title'] ?? '',
                    'description' => $data['description'] ?? $data['meta']['description'] ?? '',
                    'url' => $data['url'] ?? '',
                ];
            }
        }

        if ($index === [] && $currentEntry !== null) {
            $index[] = [
                'title' => $currentEntry->title(),
                'description' => $currentEntry->getMeta('description') ?? '',
                'url' => $currentEntry->url(),
            ];
        }

        return $index;
    }

    private function encodeJson(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
