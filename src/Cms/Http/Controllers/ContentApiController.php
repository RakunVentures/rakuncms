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

    public function showConfig(): ResponseInterface
    {
        return $this->json(200, \config() ?? []);
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $collection = $params['collection'] ?? 'blog';
        
        $dir = $this->basePath . '/content/' . $collection;
        $data = [];

        if (is_dir($dir)) {
            $it = new \RecursiveDirectoryIterator($dir);
            $display = new \RecursiveIteratorIterator($it);
            foreach ($display as $file) {
                if ($file->getExtension() === 'md' && !str_starts_with($file->getFilename(), '.')) {
                    $raw = file_get_contents($file->getPathname());
                    $parts = explode('---', $raw, 3);
                    $meta = count($parts) >= 3 ? Yaml::parse($parts[1]) : [];
                    
                    $data[] = [
                        'title' => $meta['title'] ?? $file->getBasename('.md'),
                        'slug' => $file->getBasename('.md'),
                        'collection' => $collection,
                        'date' => $meta['date'] ?? date('Y-m-d', $file->getMTime()),
                        'meta' => $meta,
                    ];
                }
            }
        }

        return $this->json(200, ['data' => $data]);
    }

    public function show(string $collection, string $slug): ResponseInterface
    {
        $indexer = new Indexer($this->basePath);
        $query = new Query($indexer->load());
        $entries = $query->collection($collection)->where('slug', '=', $slug)->get();
        $entry = $entries[0] ?? null;

        if ($entry === null) {
            return $this->json(404, ['error' => "Entry '$slug' not found"]);
        }

        $data = $this->serializeEntry($entry);
        $data['content'] = $entry->content();
        return $this->json(200, ['data' => $data]);
    }

    public function create(ServerRequestInterface $request, string $collection): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) return $this->json(400, ['error' => 'Invalid JSON body']);

        $title = $body['title'] ?? '';
        $slug = $body['slug'] ?? $this->slugify($title);
        $meta = $body['meta'] ?? [];
        $content = $body['content'] ?? '';

        $frontmatter = array_merge(['title' => $title, 'date' => date('Y-m-d H:i:s')], $meta);
        $fileContent = "---\n" . Yaml::dump($frontmatter, 2) . "---\n\n" . $content;

        $dir = $this->basePath . '/content/' . $collection;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filePath = $dir . '/' . $slug . '.md';

        file_put_contents($filePath, $fileContent);
        (new Indexer($this->basePath))->rebuild();

        return $this->json(201, ['data' => ['slug' => $slug], 'message' => 'Created']);
    }

    public function delete(string $collection, string $slug): ResponseInterface
    {
        $dir = $this->basePath . '/content/' . $collection;
        $filePath = $dir . '/' . $slug . '.md';
        if (file_exists($filePath)) unlink($filePath);
        (new Indexer($this->basePath))->rebuild();
        return $this->json(200, ['message' => 'Deleted']);
    }

    public function collections(): ResponseInterface
    {
        $configCollections = \config('collections') ?? \config('rakun.collections') ?? [];
        $data = [];
        foreach ($configCollections as $key => $config) {
            if (!($config['active'] ?? true)) continue;
            $dir = $this->basePath . '/content/' . $key;
            $count = 0;
            if (is_dir($dir)) {
                foreach (new \DirectoryIterator($dir) as $f) if ($f->getExtension() === 'md') $count++;
            }
            $data[] = array_merge(['id' => $key], $config, ['entry_count' => $count]);
        }
        return $this->json(200, ['data' => $data]);
    }

    private function serializeEntry(Entry $entry): array
    {
        return [
            'title' => $entry->title(),
            'slug' => $entry->slug(),
            'collection' => $entry->collection(),
            'date' => $entry->date(),
            'meta' => $entry->meta(),
        ];
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text) ?? '';
        $text = preg_replace('/[\s-]+/', '-', $text) ?? '';
        return trim($text, '-');
    }

    private function json(int $status, array $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], 
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');
    }
}
