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
        $config = function_exists('config') ? (\config() ?? []) : [];
        return $this->json(200, $config);
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filterCollection = isset($params['collection']) ? (string) $params['collection'] : null;

        $collectionsToScan = $filterCollection !== null
            ? [$filterCollection]
            : $this->discoverCollections();

        $data = [];
        foreach ($collectionsToScan as $collection) {
            $dir = $this->basePath . '/content/' . $collection;
            if (!is_dir($dir)) {
                continue;
            }

            foreach (new \DirectoryIterator($dir) as $file) {
                if ($file->getExtension() !== 'md' || str_starts_with($file->getFilename(), '.')) {
                    continue;
                }

                $raw   = (string) file_get_contents($file->getPathname());
                $parts = explode('---', $raw, 3);
                $meta  = [];
                if (count($parts) >= 3) {
                    $parsed = Yaml::parse($parts[1]);
                    if (is_array($parsed)) {
                        $meta = $parsed;
                    }
                }

                $basename = $file->getBasename('.md');
                $slug     = $this->stripLocale($basename);

                $data[] = [
                    'title'      => $meta['title'] ?? $basename,
                    'slug'       => $slug,
                    'collection' => $collection,
                    'date'       => $meta['date'] ?? date('Y-m-d', $file->getMTime()),
                    'meta'       => $meta,
                ];
            }
        }

        return $this->json(200, [
            'data' => $data,
            'meta' => [
                'total' => count($data),
                'page'  => 1,
            ],
        ]);
    }

    public function show(string $collection, string $slug): ResponseInterface
    {
        $indexer = new Indexer($this->basePath);
        $query   = new Query($indexer->load());
        $entries = $query->collection($collection)->where('slug', '=', $slug)->get();
        $entry   = $entries[0] ?? null;

        if ($entry === null) {
            return $this->json(404, ['error' => "Entry '{$slug}' not found"]);
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

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return $this->json(422, ['error' => 'Title is required']);
        }

        $locale  = (string) ($body['locale'] ?? 'en');
        $slug    = (string) ($body['slug'] ?? $this->slugify($title));
        $meta    = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $content = (string) ($body['content'] ?? '');

        $dir      = $this->basePath . '/content/' . $collection;
        $filePath = "{$dir}/{$slug}.{$locale}.md";

        if (file_exists($filePath)) {
            return $this->json(409, ['error' => "Entry '{$slug}' already exists in '{$collection}'"]);
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $frontmatter = array_merge(
            ['title' => $title, 'date' => date('Y-m-d H:i:s')],
            $meta,
        );
        $fileContent = "---\n" . Yaml::dump($frontmatter, 2) . "---\n\n" . $content;

        file_put_contents($filePath, $fileContent);

        return $this->json(201, [
            'data' => [
                'title'      => $title,
                'slug'       => $slug,
                'collection' => $collection,
                'locale'     => $locale,
                'meta'       => $meta,
            ],
            'message' => 'Created',
        ]);
    }

    public function delete(string $collection, string $slug): ResponseInterface
    {
        $dir = $this->basePath . '/content/' . $collection;

        $matched = glob("{$dir}/{$slug}.*.md") ?: [];
        $fallback = "{$dir}/{$slug}.md";
        if (is_file($fallback)) {
            $matched[] = $fallback;
        }

        if (empty($matched)) {
            return $this->json(404, ['error' => "Entry '{$slug}' not found in '{$collection}'"]);
        }

        foreach ($matched as $file) {
            unlink($file);
        }

        return $this->json(200, ['message' => 'Deleted']);
    }

    public function collections(): ResponseInterface
    {
        $data = [];
        foreach ($this->discoverCollections() as $name) {
            $dir   = $this->basePath . '/content/' . $name;
            $count = 0;
            if (is_dir($dir)) {
                foreach (new \DirectoryIterator($dir) as $f) {
                    if ($f->getExtension() === 'md') {
                        $count++;
                    }
                }
            }
            $data[] = [
                'id'          => $name,
                'name'        => $name,
                'entry_count' => $count,
            ];
        }
        return $this->json(200, ['data' => $data]);
    }

    /**
     * @return list<string>
     */
    private function discoverCollections(): array
    {
        $dir = $this->basePath . '/content';
        if (!is_dir($dir)) {
            return [];
        }

        $names = [];
        foreach (new \DirectoryIterator($dir) as $f) {
            if (!$f->isDir() || $f->isDot() || str_starts_with($f->getFilename(), '_')) {
                continue;
            }
            $names[] = $f->getFilename();
        }
        return $names;
    }

    private function stripLocale(string $basename): string
    {
        $parts = explode('.', $basename);
        if (count($parts) >= 2 && strlen((string) end($parts)) === 2) {
            array_pop($parts);
        }
        return implode('.', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEntry(Entry $entry): array
    {
        return [
            'title'      => $entry->title(),
            'slug'       => $entry->slug(),
            'collection' => $entry->collection(),
            'date'       => $entry->date(),
            'meta'       => $entry->meta(),
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
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
        );
    }
}
