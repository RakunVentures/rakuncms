<?php

declare(strict_types=1);

namespace Rkn\Cms\Template\Extensions;

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\Paginator;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Search\SearchEngine;
use Rkn\Cms\Search\SearchIndexer;
use Symfony\Component\Yaml\Yaml;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ContentExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('collection', [$this, 'collection']),
            new TwigFunction('entry', [$this, 'entry']),
            new TwigFunction('global', [$this, 'global']),
            new TwigFunction('config', [$this, 'config']),
            new TwigFunction('paginate', [$this, 'paginate']),
            new TwigFunction('search', [$this, 'search']),
            new TwigFunction('request_param', [$this, 'requestParam']),
            new TwigFunction('unique_tags', [$this, 'uniqueTags']),
        ];
    }

    public function collection(string $name): Query
    {
        $basePath = \app('base_path');
        $indexer = new Indexer($basePath);
        $index = $indexer->load();

        return (new Query($index))->collection($name);
    }

    public function entry(string $path): ?Entry
    {
        $basePath = \app('base_path');
        $indexer = new Indexer($basePath);
        $index = $indexer->load();

        // Path can be "collection/slug" or "collection/slug.locale"
        $entries = $index['entries'];

        // Direct key match
        if (isset($entries[$path])) {
            return Entry::fromArray($entries[$path]);
        }

        // Try with locale suffix
        $locale = 'es';
        try {
            $locale = \app('locale');
        } catch (\Throwable) {
        }

        foreach ($entries as $key => $data) {
            if (str_starts_with($key, $path) && $data['locale'] === $locale) {
                return Entry::fromArray($data);
            }
        }

        return null;
    }

    /**
     * Load a global YAML file from content/_globals/.
     *
     * @return array<string, mixed>
     */
    public function global(string $name): array
    {
        $basePath = \app('base_path');
        $file = $basePath . '/content/_globals/' . $name . '.yaml';

        if (!file_exists($file)) {
            return [];
        }

        $data = Yaml::parseFile($file);
        return is_array($data) ? $data : [];
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return \config($key, $default);
    }

    public function paginate(Query $query, int $perPage = 10): Paginator
    {
        $currentPage = 1;
        try {
            $page = \app('current_page_number');
            if (is_int($page) && $page > 0) {
                $currentPage = $page;
            }
        } catch (\Throwable) {
        }

        return new Paginator($query, $perPage, $currentPage);
    }

    /**
     * @return list<array{key: string, title: string, url: string, score: float, snippet: string}>
     */
    public function search(string $query, int $limit = 20): array
    {
        if ($query === '') {
            return [];
        }

        $basePath = \app('base_path');
        $indexer = new SearchIndexer($basePath);
        $index = $indexer->load() ?? $indexer->build();
        $engine = new SearchEngine($index);

        $locale = null;
        try {
            $locale = \app('locale');
        } catch (\Throwable) {
        }

        return $engine->search($query, $locale, $limit);
    }

    public function requestParam(string $key, string $default = ''): string
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Collect unique tags from a collection.
     *
     * @return list<string>
     */
    public function uniqueTags(string $collectionName): array
    {
        $basePath = \app('base_path');
        $indexer = new Indexer($basePath);
        $index = $indexer->load();
        $tags = [];

        foreach ($index['entries'] as $entryData) {
            if (($entryData['collection'] ?? '') !== $collectionName) {
                continue;
            }
            foreach ($entryData['tags'] ?? [] as $tag) {
                $tags[$tag] = true;
            }
        }

        $result = array_keys($tags);
        sort($result);

        return $result;
    }
}
