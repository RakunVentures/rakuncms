<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Content\Entry;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\Parser;
use Rkn\Cms\Content\Query;

final class WpApiDispatcher implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        if ($path === '') {
            $path = '/';
        }

        if ($path === '/wp-json') {
            if ($request->getMethod() === 'GET') {
                return $this->handleDiscovery($request);
            }
        }

        if (str_starts_with($path, '/wp-json/wp/v2')) {
            $method = $request->getMethod();

            if ($path === '/wp-json/wp/v2/posts') {
                if ($method === 'GET')
                    return $this->handleEntries($request, 'blog', 'post');
                if ($method === 'POST')
                    return $this->handleCreateEntry($request, 'blog', 'post');
            }
            if (preg_match('#^/wp-json/wp/v2/posts/(\d+)$#', $path, $matches)) {
                $id = (int) $matches[1];
                if ($method === 'GET')
                    return $this->handleSingleEntry($request, 'blog', 'post', $id);
                if ($method === 'PUT' || $method === 'POST' || $method === 'PATCH')
                    return $this->handleUpdateEntry($request, 'blog', 'post', $id);
                if ($method === 'DELETE')
                    return $this->handleDeleteEntry($request, 'blog', $id);
                return $this->jsonResponse(405, ['code' => 'rest_invalid_method', 'message' => 'Method not allowed.']);
            }

            if ($path === '/wp-json/wp/v2/pages') {
                if ($method === 'GET')
                    return $this->handleEntries($request, 'pages', 'page');
                if ($method === 'POST')
                    return $this->handleCreateEntry($request, 'pages', 'page');
            }
            if (preg_match('#^/wp-json/wp/v2/pages/(\d+)$#', $path, $matches)) {
                $id = (int) $matches[1];
                if ($method === 'GET')
                    return $this->handleSingleEntry($request, 'pages', 'page', $id);
                if ($method === 'PUT' || $method === 'POST' || $method === 'PATCH')
                    return $this->handleUpdateEntry($request, 'pages', 'page', $id);
                if ($method === 'DELETE')
                    return $this->handleDeleteEntry($request, 'pages', $id);
                return $this->jsonResponse(405, ['code' => 'rest_invalid_method', 'message' => 'Method not allowed.']);
            }

            if ($path === '/wp-json/wp/v2/media') {
                if ($method === 'POST')
                    return $this->handleCreateMedia($request);
                if ($method === 'GET')
                    return $this->jsonResponse(200, []);
            }

            if ($path === '/wp-json/wp/v2/categories' && $method === 'GET')
                return $this->handleCategories($request);
            if ($path === '/wp-json/wp/v2/tags' && $method === 'GET')
                return $this->handleTags($request);
            if ($path === '/wp-json/wp/v2/users/me' && $method === 'GET')
                return $this->handleUsersMe($request);

            if ($path === '/wp-json/wp/v2/users/me') {
                return $this->jsonResponse(401, [
                    'code' => 'rest_not_logged_in',
                    'message' => 'No has accedido.',
                    'data' => ['status' => 401],
                ]);
            }

            return $this->jsonResponse(404, [
                'code' => 'rest_no_route',
                'message' => 'No route was found matching the URL and request method.',
                'data' => ['status' => 404],
            ]);
        }

        return $handler->handle($request);
    }

    private function handleDiscovery(ServerRequestInterface $request): ResponseInterface
    {
        $siteUrl = \config('site.url', 'http://localhost');
        $data = [
            'name' => \config('site.title', 'RakunCMS'),
            'description' => \config('site.description', ''),
            'url' => $siteUrl,
            'home' => $siteUrl,
            'namespaces' => ['wp/v2'],
            'authentication' => [
                'application-passwords' => [
                    'endpoints' => [
                        'authorization' => rtrim($siteUrl, '/') . '/wp-admin/authorize-application',
                    ],
                ],
            ],
            'routes' => [
                '/' => ['methods' => ['GET']],
                '/wp/v2' => ['methods' => ['GET']],
                '/wp/v2/posts' => ['methods' => ['GET', 'POST']],
                '/wp/v2/posts/(?P<id>[\d]+)' => ['methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                '/wp/v2/pages' => ['methods' => ['GET', 'POST']],
                '/wp/v2/categories' => ['methods' => ['GET']],
                '/wp/v2/tags' => ['methods' => ['GET']],
                '/wp/v2/media' => ['methods' => ['GET', 'POST']],
                '/wp/v2/users/me' => ['methods' => ['GET']],
            ],
        ];
        return $this->jsonResponse(200, $data);
    }

    private function handleUsersMe(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->authenticateWpRequest($request);
        if (!$user) {
            return $this->jsonResponse(401, [
                'code' => 'rest_not_logged_in',
                'message' => 'No has accedido.',
                'data' => ['status' => 401],
            ]);
        }

        $siteUrl = \config('site.url', 'http://localhost');
        return $this->jsonResponse(200, [
            'id' => $user['id'],
            'name' => $user['name'],
            'url' => $siteUrl,
            'description' => '',
            'link' => rtrim($siteUrl, '/') . '/author/' . $this->slugify($user['name']),
            'slug' => $this->slugify($user['name']),
            'avatar_urls' => ['24' => '', '48' => '', '96' => ''],
            'meta' => [],
            'capabilities' => ['edit_posts' => true, 'upload_files' => true],
            'extra_capabilities' => ['administrator' => in_array('admin', $user['permissions'], true)],
        ]);
    }

    private function authenticateWpRequest(ServerRequestInterface $request): ?array
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (!str_starts_with($authHeader, 'Basic ')) {
            return null;
        }

        $decoded = base64_decode(substr($authHeader, 6));
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);

        $apiKeys = [];
        try {
            $apiKeys = \config('api.keys', []);
        } catch (\Throwable) {
        }

        foreach ($apiKeys as $keyConfig) {
            if (isset($keyConfig['key']) && hash_equals($keyConfig['key'], $password)) {
                return [
                    'id' => 1,
                    'name' => $username,
                    'permissions' => $keyConfig['permissions'] ?? [],
                ];
            }
        }
        return null;
    }

    private function handleEntries(
        ServerRequestInterface $request,
        string $collection,
        string $wpType,
    ): ResponseInterface {
        $basePath = \app('base_path');
        $params = $request->getQueryParams();

        $indexer = new Indexer($basePath);
        $query = new Query($indexer->load());
        $query = $query->collection($collection);

        $total = $query->count();
        $perPage = min((int) ($params['per_page'] ?? 10), 100);
        $page = max(1, (int) ($params['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $entries = $query->offset($offset)->limit($perPage)->get();

        $wpPosts = [];
        $parser = new Parser();
        $siteUrl = \config('site.url', 'http://localhost');

        foreach ($entries as $entry) {
            $wpPosts[] = $this->formatWpPost($entry, $parser, $siteUrl, $wpType);
        }

        return new Response(
            200,
            [
                'Content-Type' => 'application/json',
                'X-WP-Total' => (string) $total,
                'X-WP-TotalPages' => (string) max(1, (int) ceil($total / $perPage)),
            ],
            json_encode($wpPosts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== false ? json_encode($wpPosts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '[]',
        );
    }

    private function handleCreateEntry(
        ServerRequestInterface $request,
        string $collection,
        string $wpType,
    ): ResponseInterface {
        $user = $this->authenticateWpRequest($request);
        if (!$user) {
            return $this->jsonResponse(401, [
                'code' => 'rest_not_logged_in',
                'message' => 'Unauthorized',
                'data' => ['status' => 401],
            ]);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];

        $title = $body['title'] ?? 'Draft ' . date('Y-m-d H:i:s');
        $content = $body['content'] ?? '';
        $slug = $body['slug'] ?? $this->slugify(is_array($title) ? $title['raw'] ?? '' : $title);
        if ($slug === '') {
            $slug = 'post-' . uniqid();
        }

        $status = $body['status'] ?? 'draft';
        $date = $body['date'] ?? date('Y-m-d H:i:s');
        $wpId = mt_rand(100000, 999999);

        $frontmatter = [
            'title' => is_array($title) ? $title['raw'] ?? '' : $title,
            'date' => $date,
            'status' => $status,
            'template' => $wpType === 'page' ? 'page' : 'blog-post',
            'wp_id' => (string) $wpId,
            'wp_type' => $wpType,
        ];

        $basePath = \app('base_path');
        
        $timestamp = strtotime($date) ?: time();
        $year = date('Y', $timestamp);
        $month = date('m', $timestamp);
        
        $collectionDir = $basePath . '/content/' . $collection . '/' . $year . '/' . $month;
        if (!is_dir($collectionDir)) {
            mkdir($collectionDir, 0o755, true);
        }

        $filePath = $collectionDir . '/' . $slug . '.md';
        $fileContent =
            '---
'
            . \Symfony\Component\Yaml\Yaml::dump($frontmatter, 2)
            . '---

'
            . (is_array($content) ? $content['raw'] ?? '' : $content);
        file_put_contents($filePath, $fileContent);

        new Indexer($basePath)->rebuild();

        $indexer = new Indexer($basePath);
        $query = new Query($indexer->load());
        $entry = $query->collection($collection)->where('slug', '=', $slug)->get()[0] ?? null;

        $siteUrl = \config('site.url', 'http://localhost');
        return $this->jsonResponse(201, $this->formatWpPost($entry, new Parser(), $siteUrl, $wpType));
    }

    private function handleSingleEntry(
        ServerRequestInterface $request,
        string $collection,
        string $wpType,
        int $id,
    ): ResponseInterface {
        $basePath = \app('base_path');
        $indexer = new Indexer($basePath);
        $query = new Query($indexer->load());

        $entries = $query->collection($collection)->get();
        $entry = null;
        foreach ($entries as $e) {
            if (
                (int) ($e->meta()['wp_id'] ?? 0) === $id
                || crc32($e->slug()) === $id
                || (int) crc32($e->slug()) === $id
            ) {
                $entry = $e;
                break;
            }
        }

        if (!$entry) {
            return $this->jsonResponse(404, [
                'code' => 'rest_post_invalid_id',
                'message' => 'Invalid post ID.',
                'data' => ['status' => 404],
            ]);
        }

        $siteUrl = \config('site.url', 'http://localhost');
        return $this->jsonResponse(200, $this->formatWpPost($entry, new Parser(), $siteUrl, $wpType));
    }

    private function handleUpdateEntry(
        ServerRequestInterface $request,
        string $collection,
        string $wpType,
        int $id,
    ): ResponseInterface {
        $user = $this->authenticateWpRequest($request);
        if (!$user) {
            return $this->jsonResponse(401, [
                'code' => 'rest_not_logged_in',
                'message' => 'Unauthorized',
                'data' => ['status' => 401],
            ]);
        }

        $basePath = \app('base_path');
        $indexer = new Indexer($basePath);
        $query = new Query($indexer->load());

        $entries = $query->collection($collection)->get();
        $entry = null;
        foreach ($entries as $e) {
            if (
                (int) ($e->meta()['wp_id'] ?? 0) === $id
                || crc32($e->slug()) === $id
                || (int) crc32($e->slug()) === $id
            ) {
                $entry = $e;
                break;
            }
        }

        if (!$entry) {
            return $this->jsonResponse(404, [
                'code' => 'rest_post_invalid_id',
                'message' => 'Invalid post ID.',
                'data' => ['status' => 404],
            ]);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $meta = $entry->meta();

        if (isset($body['title'])) {
            $meta['title'] = is_array($body['title']) ? $body['title']['raw'] : $body['title'];
        }
        if (isset($body['status'])) {
            $meta['status'] = $body['status'];
        }

        $content = $entry->content();
        if (isset($body['content'])) {
            $content = is_array($body['content'])
                ? $body['content']['raw'] ?? $body['content']['rendered'] ?? ''
                : $body['content'];
        }

        $filePath = $basePath . '/' . $entry->file();
        $fileContent = '---
' . \Symfony\Component\Yaml\Yaml::dump($meta, 2) . '---

' . $content;
        file_put_contents($filePath, $fileContent);

        new Indexer($basePath)->rebuild();

        $entry = new Query(new Indexer($basePath)->load())
            ->collection($collection)
            ->where('slug', '=', $entry->slug())
            ->get()[0];

        $siteUrl = \config('site.url', 'http://localhost');
        return $this->jsonResponse(200, $this->formatWpPost($entry, new Parser(), $siteUrl, $wpType));
    }

    private function handleDeleteEntry(ServerRequestInterface $request, string $collection, int $id): ResponseInterface
    {
        $user = $this->authenticateWpRequest($request);
        if (!$user) {
            return $this->jsonResponse(401, [
                'code' => 'rest_not_logged_in',
                'message' => 'Unauthorized',
                'data' => ['status' => 401],
            ]);
        }

        $basePath = \app('base_path');
        $indexer = new Indexer($basePath);
        $query = new Query($indexer->load());

        $entries = $query->collection($collection)->get();
        $entry = null;
        foreach ($entries as $e) {
            if (
                (int) ($e->meta()['wp_id'] ?? 0) === $id
                || crc32($e->slug()) === $id
                || (int) crc32($e->slug()) === $id
            ) {
                $entry = $e;
                break;
            }
        }

        if (!$entry) {
            return $this->jsonResponse(404, [
                'code' => 'rest_post_invalid_id',
                'message' => 'Invalid post ID.',
                'data' => ['status' => 404],
            ]);
        }

        $filePath = $basePath . '/' . $entry->file();
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        new Indexer($basePath)->rebuild();

        return $this->jsonResponse(200, ['deleted' => true, 'previous' => ['id' => $id]]);
    }

    private function handleCategories(ServerRequestInterface $request): ResponseInterface
    {
        $basePath = \app('base_path');
        $indexer = new Indexer($basePath);
        $index = $indexer->load();

        $categories = [];
        $catIdMap = [];
        $nextId = 1;

        foreach ($index['entries'] ?? [] as $entryData) {
            if (($entryData['collection'] ?? '') === 'blog') {
                $cats = $entryData['meta']['categories'] ?? [];
                if (is_array($cats)) {
                    foreach ($cats as $cat) {
                        $cat = (string) $cat;
                        if (!isset($catIdMap[$cat])) {
                            $catIdMap[$cat] = $nextId++;
                            $categories[] = [
                                'id' => $catIdMap[$cat],
                                'count' => 1,
                                'description' => '',
                                'link' => '/category/' . $this->slugify($cat),
                                'name' => $cat,
                                'slug' => $this->slugify($cat),
                                'taxonomy' => 'category',
                                'parent' => 0,
                                'meta' => [],
                            ];
                        } else {
                            foreach ($categories as &$c) {
                                if ($c['name'] === $cat) {
                                    $c['count']++;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $this->jsonResponse(200, array_values($categories));
    }

    private function handleTags(ServerRequestInterface $request): ResponseInterface
    {
        $basePath = \app('base_path');
        $indexer = new Indexer($basePath);
        $index = $indexer->load();

        $tags = [];
        $tagIdMap = [];
        $nextId = 1;

        foreach ($index['entries'] ?? [] as $entryData) {
            if (($entryData['collection'] ?? '') === 'blog') {
                $ts = $entryData['meta']['tags'] ?? [];
                if (is_array($ts)) {
                    foreach ($ts as $t) {
                        $t = (string) $t;
                        if (!isset($tagIdMap[$t])) {
                            $tagIdMap[$t] = $nextId++;
                            $tags[] = [
                                'id' => $tagIdMap[$t],
                                'count' => 1,
                                'description' => '',
                                'link' => '/tag/' . $this->slugify($t),
                                'name' => $t,
                                'slug' => $this->slugify($t),
                                'taxonomy' => 'post_tag',
                                'meta' => [],
                            ];
                        } else {
                            foreach ($tags as &$c) {
                                if ($c['name'] === $t) {
                                    $c['count']++;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $this->jsonResponse(200, array_values($tags));
    }

    private function formatWpPost(Entry $entry, Parser $parser, string $siteUrl, string $wpType): array
    {
        $meta = $entry->meta();
        $date = $entry->date() ?? date('Y-m-d\TH:i:s');
        if (str_contains($date, ' ')) {
            $date = str_replace(' ', 'T', $date);
        }

        $renderedContent = $parser->renderString($entry->content());

        return [
            'id' => (int) ($meta['wp_id'] ?? crc32($entry->slug())),
            'date' => $date,
            'date_gmt' => $date,
            'guid' => ['rendered' => rtrim($siteUrl, '/') . $entry->url()],
            'modified' => $date,
            'modified_gmt' => $date,
            'slug' => $entry->slug(),
            'status' => $meta['status'] ?? 'publish',
            'type' => $wpType,
            'link' => rtrim($siteUrl, '/') . $entry->url(),
            'title' => ['rendered' => $entry->title()],
            'content' => ['rendered' => $renderedContent, 'protected' => false],
            'excerpt' => ['rendered' => '', 'protected' => false],
            'author' => 1,
            'featured_media' => 0,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'sticky' => false,
            'template' => '',
            'format' => 'standard',
            'meta' => [],
            'categories' => $meta['categories'] ?? [],
            'tags' => $meta['tags'] ?? [],
        ];
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text) ?? '';
        $text = preg_replace('/[\s-]+/', '-', $text) ?? '';
        return trim($text, '-');
    }

    private function jsonResponse(int $status, array|object $data): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== false ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '{}',
        );
    }

    private function handleCreateMedia(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->authenticateWpRequest($request);
        if (!$user) {
            return $this->jsonResponse(401, [
                'code' => 'rest_not_logged_in',
                'message' => 'Unauthorized',
                'data' => ['status' => 401],
            ]);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $fileContent = '';
        $filename = 'upload-' . time() . '.jpg';

        if ($uploadedFiles !== [] && isset($uploadedFiles['file'])) {
            $file = $uploadedFiles['file'];
            if (is_array($file))
                $file = $file[0];
            $filename = $file->getClientFilename() !== '' ? $file->getClientFilename() : $filename;
            $fileContent = (string) $file->getStream();
        } else {
            // Raw binary upload (e.g. Ulysses)
            $contentDisposition = $request->getHeaderLine('Content-Disposition');
            if ($contentDisposition && preg_match('/filename="?([^"]+)"?/', $contentDisposition, $matches)) {
                $filename = basename($matches[1]);
            }
            $fileContent = (string) $request->getBody();
        }

        if ($fileContent === '') {
            return $this->jsonResponse(400, [
                'code' => 'rest_upload_no_data',
                'message' => 'No data supplied.',
                'data' => ['status' => 400],
            ]);
        }

        // Sanitize filename
        $filename = preg_replace('/[^\p{L}\p{N}\.\_-]/u', '-', $filename) ?? $filename;

        // Ensure unique
        $basePath = \app('base_path');
        $uploadDir = $basePath . '/public/assets/images';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0o755, true);
        }

        $targetPath = $uploadDir . '/' . $filename;
        $counter = 1;
        $info = pathinfo($filename);
        while (file_exists($targetPath)) {
            $newFilename = ($info['filename'] ?? 'media') . '-' . $counter . '.' . ($info['extension'] ?? 'jpg');
            $targetPath = $uploadDir . '/' . $newFilename;
            $filename = $newFilename;
            $counter++;
        }

        file_put_contents($targetPath, $fileContent);

        $siteUrl = rtrim(\config('site.url', 'http://localhost'), '/');
        $sourceUrl = $siteUrl . '/assets/images/' . $filename;
        $id = crc32($filename);
        $date = date('Y-m-d\TH:i:s');
        $mimeType = $request->getHeaderLine('Content-Type') ?: 'image/jpeg';
        if (str_contains($mimeType, ';')) {
            $mimeType = explode(';', $mimeType)[0];
        }

        return $this->jsonResponse(201, [
            'id' => $id,
            'date' => $date,
            'date_gmt' => $date,
            'guid' => ['rendered' => $sourceUrl],
            'modified' => $date,
            'modified_gmt' => $date,
            'slug' => $info['filename'] ?? 'media',
            'status' => 'inherit',
            'type' => 'attachment',
            'link' => $sourceUrl,
            'title' => ['rendered' => $filename],
            'author' => 1,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'template' => '',
            'meta' => [],
            'description' => ['rendered' => ''],
            'caption' => ['rendered' => ''],
            'alt_text' => '',
            'media_type' => 'image',
            'mime_type' => $mimeType,
            'media_details' => [
                'width' => 1024,
                'height' => 1024,
                'file' => $filename,
                'sizes' => [
                    'full' => [
                        'file' => $filename,
                        'width' => 1024,
                        'height' => 1024,
                        'mime_type' => $mimeType,
                        'source_url' => $sourceUrl,
                    ],
                ],
            ],
            'source_url' => $sourceUrl,
        ]);
    }
}
