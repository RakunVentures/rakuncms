<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rkn\Cms\Content\Entry;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\Query;
use Symfony\Component\Yaml\Yaml;

final class ContentApiController
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $indexer = new Indexer($this->basePath);
        $query = new Query($indexer->load());

        if (!empty($params['collection'])) {
            $query = $query->collection($params['collection']);
        }
        if (!empty($params['locale'])) {
            $query = $query->locale($params['locale']);
        }
        if (!empty($params['tag'])) {
            $query = $query->where('tags', 'has', $params['tag']);
        }

        $total = $query->count();
        $perPage = min((int) ($params['per_page'] ?? 20), 100);
        $page = max(1, (int) ($params['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $entries = $query->offset($offset)->limit($perPage)->get();

        return $this->json(200, [
            'data' => array_map(fn (Entry $e) => $this->serializeEntry($e), $entries),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    public function show(string $collection, string $slug): ResponseInterface
    {
        $indexer = new Indexer($this->basePath);
        $query = new Query($indexer->load());

        // Try current locale first, then any locale
        $locale = '';
        try {
            $locale = (string) \app('locale');
        } catch (\Throwable) {
        }

        $entry = null;
        if ($locale !== '') {
            $entry = $query->findBySlug($collection, $locale, $slug);
        }
        if ($entry === null) {
            // Try all locales
            $entries = $query->collection($collection)->where('slug', '=', $slug)->get();
            $entry = $entries[0] ?? null;
        }

        if ($entry === null) {
            return $this->json(404, ['error' => 'Entry not found']);
        }

        $data = $this->serializeEntry($entry);
        $data['content'] = $entry->content();

        return $this->json(200, ['data' => $data]);
    }

    public function create(ServerRequestInterface $request, string $collection): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->json(400, ['error' => 'Invalid JSON body']);
        }

        $title = $body['title'] ?? '';
        $slug = $body['slug'] ?? $this->slugify($title);
        $locale = $body['locale'] ?? 'en';
        $content = $body['content'] ?? '';
        $meta = $body['meta'] ?? [];

        if ($title === '' || $slug === '') {
            return $this->json(422, ['error' => 'Title and slug are required']);
        }

        // Build frontmatter + content
        $frontmatter = array_merge(['title' => $title], $meta);
        $fileContent = "---\n" . Yaml::dump($frontmatter, 2) . "---\n\n" . $content;

        // Determine filename
        $collectionDir = $this->basePath . '/content/' . $collection;
        if (!is_dir($collectionDir)) {
            mkdir($collectionDir, 0755, true);
        }

        $filename = $slug;
        if ($locale !== '') {
            $filename .= '.' . $locale;
        }
        $filePath = $collectionDir . '/' . $filename . '.md';

        if (file_exists($filePath)) {
            return $this->json(409, ['error' => 'Entry already exists']);
        }

        file_put_contents($filePath, $fileContent);

        // Rebuild index
        $indexer = new Indexer($this->basePath);
        $indexer->rebuild();

        return $this->json(201, [
            'data' => [
                'title' => $title,
                'slug' => $slug,
                'collection' => $collection,
                'locale' => $locale,
                'file' => 'content/' . $collection . '/' . $filename . '.md',
            ],
            'message' => 'Entry created',
        ]);
    }

    public function update(ServerRequestInterface $request, string $collection, string $slug): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->json(400, ['error' => 'Invalid JSON body']);
        }

        // Find the entry file
        $indexer = new Indexer($this->basePath);
        $index = $indexer->load();
        $query = new Query($index);

        $locale = $body['locale'] ?? '';
        $entry = null;
        if ($locale !== '') {
            $entry = $query->findBySlug($collection, $locale, $slug);
        } else {
            $entries = $query->collection($collection)->where('slug', '=', $slug)->get();
            $entry = $entries[0] ?? null;
        }

        if ($entry === null) {
            return $this->json(404, ['error' => 'Entry not found']);
        }

        $filePath = $this->basePath . '/' . $entry->file();
        if (!file_exists($filePath)) {
            return $this->json(404, ['error' => 'Entry file not found']);
        }

        // Build updated content
        $title = $body['title'] ?? $entry->title();
        $meta = $body['meta'] ?? $entry->meta();
        $content = $body['content'] ?? '';

        $frontmatter = array_merge(['title' => $title], $meta);
        $fileContent = "---\n" . Yaml::dump($frontmatter, 2) . "---\n\n" . $content;

        file_put_contents($filePath, $fileContent);

        // Rebuild index
        $indexer->rebuild();

        return $this->json(200, [
            'data' => ['title' => $title, 'slug' => $slug, 'collection' => $collection],
            'message' => 'Entry updated',
        ]);
    }

    public function delete(string $collection, string $slug): ResponseInterface
    {
        $indexer = new Indexer($this->basePath);
        $index = $indexer->load();
        $query = new Query($index);

        $entries = $query->collection($collection)->where('slug', '=', $slug)->get();
        $entry = $entries[0] ?? null;

        if ($entry === null) {
            return $this->json(404, ['error' => 'Entry not found']);
        }

        $filePath = $this->basePath . '/' . $entry->file();
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $indexer->rebuild();

        return $this->json(200, ['message' => 'Entry deleted']);
    }

    public function collections(): ResponseInterface
    {
        $contentDir = $this->basePath . '/content';
        $collections = [];

        if (is_dir($contentDir)) {
            $dirs = glob($contentDir . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($dirs as $dir) {
                $name = basename($dir);
                if (str_starts_with($name, '_')) {
                    continue;
                }
                $files = glob($dir . '/*.md') ?: [];
                $collections[] = [
                    'name' => $name,
                    'entry_count' => count($files),
                ];
            }
        }

        return $this->json(200, ['data' => $collections]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEntry(Entry $entry): array
    {
        return [
            'title' => $entry->title(),
            'slug' => $entry->slug(),
            'collection' => $entry->collection(),
            'locale' => $entry->locale(),
            'url' => $entry->url(),
            'date' => $entry->date(),
            'order' => $entry->order(),
            'draft' => $entry->isDraft(),
            'meta' => $entry->meta(),
            'template' => $entry->template(),
        ];
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text) ?? '';
        $text = preg_replace('/[\s-]+/', '-', $text) ?? '';
        return trim($text, '-');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(int $status, array $data): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}'
        );
    }
}
