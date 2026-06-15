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
use SQLite3;

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
            new TwigFunction('views', [$this, 'getViews']),
        ];
    }

    public function collection(string $name): Query
    {
        return (new Query(\index_store()))->collection($name);
    }

    public function entry(string $path): ?Entry
    {
        $locale = 'es';
        try {
            $locale = \app('locale');
        } catch (\Throwable) {
        }

        $row = \index_store()->findEntryByPath($path, $locale);

        return $row !== null ? Entry::fromArray($row) : null;
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

        try {
            $data = Yaml::parseFile($file);
        } catch (\Throwable $e) {
            error_log('[rakun] unparseable global ' . $file . '; using empty: ' . $e->getMessage());
            return [];
        }
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
        $tags = [];

        foreach (\index_store()->each() as $entryData) {
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

    /**
     * Get view count for a slug from SQLite.
     */
    public function getViews(string $slug): int
    {
        try {
            $basePath = \app('base_path');
            $dbPath = $basePath . '/storage/analytics.db';
            
            if (!file_exists($dbPath)) {
                return 0;
            }

            $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
            // Wait out a concurrent analytics write instead of erroring as
            // SQLITE_BUSY (the DB is WAL, set by AnalyticsMiddleware on write).
            $db->exec('PRAGMA busy_timeout=5000');
            $stmt = $db->prepare('SELECT views FROM hits WHERE slug = :slug');
            $stmt->bindValue(':slug', $slug, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $db->close();

            return $row ? (int) $row['views'] : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
