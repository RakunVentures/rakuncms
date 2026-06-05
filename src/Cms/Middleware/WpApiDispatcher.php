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
use Rkn\Cms\Http\Controllers\ContentApiController;

/**
 * Proxy/Normalization layer for WordPress REST API.
 * Delegates all logic to the native ContentApiController.
 */
final class WpApiDispatcher implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        if ($path === '') {
            $path = '/';
        }

        // Honor the api.enabled switch: when the API is disabled, the WordPress-proxy
        // write surface must be off too (otherwise it bypasses the kill switch).
        if (str_starts_with($path, '/wp-json')
            && in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && !$this->apiWritesEnabled()) {
            return $this->jsonResponse(403, [
                'code' => 'rest_api_disabled',
                'message' => 'The API is disabled.',
                'data' => ['status' => 403],
            ]);
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
                    return $this->handleProxyAction($request, 'blog', 'post', 'create');
            }
            if (preg_match('#^/wp-json/wp/v2/posts/(\d+)$#', $path, $matches)) {
                $id = (int) $matches[1];
                if ($method === 'GET')
                    return $this->handleSingleEntry($request, 'blog', 'post', $id);
                if ($method === 'PUT' || $method === 'POST' || $method === 'PATCH')
                    return $this->handleProxyAction($request, 'blog', 'post', 'update', $id);
                if ($method === 'DELETE')
                    return $this->handleProxyAction($request, 'blog', 'post', 'delete', $id);
                return $this->jsonResponse(405, ['code' => 'rest_invalid_method', 'message' => 'Method not allowed.']);
            }

            if ($path === '/wp-json/wp/v2/pages') {
                if ($method === 'GET')
                    return $this->handleEntries($request, 'pages', 'page');
                if ($method === 'POST')
                    return $this->handleProxyAction($request, 'pages', 'page', 'create');
            }
            if (preg_match('#^/wp-json/wp/v2/pages/(\d+)$#', $path, $matches)) {
                $id = (int) $matches[1];
                if ($method === 'GET')
                    return $this->handleSingleEntry($request, 'pages', 'page', $id);
                if ($method === 'PUT' || $method === 'POST' || $method === 'PATCH')
                    return $this->handleProxyAction($request, 'pages', 'page', 'update', $id);
                if ($method === 'DELETE')
                    return $this->handleProxyAction($request, 'pages', 'page', 'delete', $id);
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

    /** Whether the content API (and thus its WP-proxy writes) is enabled. Default: on. */
    private function apiWritesEnabled(): bool
    {
        $enabled = \config('api.enabled') ?? \config('rakun.api.enabled') ?? true;

        return filter_var($enabled, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
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

    /**
     * @return array{id: mixed, name: string, permissions: list<string>}|null
     */
    private function authenticateWpRequest(ServerRequestInterface $request): ?array
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (!str_starts_with($authHeader, 'Basic ')) {
            return null;
        }

        $decoded = base64_decode(substr($authHeader, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);

        // FIX: Search in both standard and rakun prefixed config
        $apiKeys = \config('api.keys') ?? \config('rakun.api.keys') ?? [];

        foreach ($apiKeys as $keyConfig) {
            if (isset($keyConfig['key']) && hash_equals($keyConfig['key'], $password)) {
                return [
                    'id' => random_int(100000, 999999),
                    'name' => $keyConfig['name'] ?? $username,
                    'permissions' => $keyConfig['permissions'] ?? [],
                ];
            }
        }
        return null;
    }

    private function handleProxyAction(
        ServerRequestInterface $request,
        string $collection,
        string $wpType,
        string $action,
        ?int $id = null
    ): ResponseInterface {
        $user = $this->authenticateWpRequest($request);
        if (!$user) {
            return $this->jsonResponse(401, ['code' => 'rest_not_logged_in', 'message' => 'Unauthorized']);
        }

        $request = $request->withAttribute('api_key', $user);
        $basePath = \app('base_path');
        $controller = new ContentApiController($basePath);

        if ($action === 'delete' && $id !== null) {
            $entry = $this->findEntryByWpId($collection, $id);
            if (!$entry) return $this->jsonResponse(404, ['error' => 'Not found']);
            return $controller->delete($collection, $entry->slug());
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $nativeBody = [
            'title' => is_array($body['title'] ?? null) ? $body['title']['raw'] ?? '' : $body['title'] ?? '',
            'content' => is_array($body['content'] ?? null) ? $body['content']['raw'] ?? '' : $body['content'] ?? '',
            'slug' => $body['slug'] ?? null,
            'status' => $body['status'] ?? 'publish',
            'date' => $body['date'] ?? date('Y-m-d H:i:s'),
            'meta' => [
                'categories' => $body['categories'] ?? [],
                'tags' => $body['tags'] ?? [],
                'wp_type' => $wpType,
            ]
        ];

        if ($action === 'create') {
            $nativeBody['meta']['wp_id'] = (string) mt_rand(100000, 999999);
        }

        $request = $request->withBody(\Nyholm\Psr7\Stream::create(json_encode($nativeBody)));

        if ($action === 'create') {
            $response = $controller->create($request, $collection);
        } else {
            $entry = $this->findEntryByWpId($collection, $id);
            if (!$entry) return $this->jsonResponse(404, ['error' => 'Not found']);
            $response = $controller->update($request, $collection, $entry->slug());
        }

        if ($response->getStatusCode() >= 400) return $response;

        $query = new Query(\index_store());
        $resData = json_decode((string) $response->getBody(), true);
        $slug = $resData['data']['slug'] ?? '';
        $entry = $query->collection($collection)->where('slug', '=', $slug)->get()[0] ?? null;

        if (!$entry) return $response;

        $siteUrl = \config('site.url', 'http://localhost');
        return $this->jsonResponse($response->getStatusCode(), $this->formatWpPost($entry, new Parser(), $siteUrl, $wpType));
    }

    private function findEntryByWpId(string $collection, int $id): ?Entry
    {
        $query = new Query(\index_store());
        foreach ($query->collection($collection)->get() as $entry) {
            if (
                (int) ($entry->meta()['wp_id'] ?? 0) === $id
                || crc32($entry->slug()) === $id
                || (int) crc32($entry->slug()) === $id
            ) {
                return $entry;
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

        $query = new Query(\index_store());
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
            json_encode($wpPosts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        );
    }

    private function handleSingleEntry(
        ServerRequestInterface $request,
        string $collection,
        string $wpType,
        int $id,
    ): ResponseInterface {
        $entry = $this->findEntryByWpId($collection, $id);

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

    private function handleCategories(ServerRequestInterface $request): ResponseInterface
    {
        return $this->jsonResponse(200, []);
    }

    private function handleTags(ServerRequestInterface $request): ResponseInterface
    {
        return $this->jsonResponse(200, []);
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed>|object $data
     */
    private function jsonResponse(int $status, array|object $data): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }

    private function handleCreateMedia(ServerRequestInterface $request): ResponseInterface
    {
        return $this->jsonResponse(501, ['error' => 'Not implemented in proxy']);
    }
}
