<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApiAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Only apply to /api/ routes
        if (!str_starts_with($path, '/api/')) {
            return $handler->handle($request);
        }

        // Check if API is enabled
        $apiEnabled = false;
        try {
            $apiEnabled = (bool) \config('api.enabled', false);
        } catch (\Throwable) {
        }

        if (!$apiEnabled) {
            return $this->jsonResponse(404, ['error' => 'API not enabled']);
        }

        // Extract Bearer token
        $authHeader = $request->getHeaderLine('Authorization');
        $token = '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        }

        if ($token === '') {
            return $this->jsonResponse(401, ['error' => 'Authentication required']);
        }

        // Validate against configured API keys
        $apiKeys = [];
        try {
            $apiKeys = \config('api.keys', []);
        } catch (\Throwable) {
        }

        $matchedKey = null;
        foreach ($apiKeys as $keyConfig) {
            if (isset($keyConfig['key']) && hash_equals($keyConfig['key'], $token)) {
                $matchedKey = $keyConfig;
                break;
            }
        }

        if ($matchedKey === null) {
            return $this->jsonResponse(401, ['error' => 'Invalid API key']);
        }

        // Store API key info in request attributes
        $request = $request->withAttribute('api_key', $matchedKey);
        $request = $request->withAttribute('api_permissions', $matchedKey['permissions'] ?? []);

        return $handler->handle($request);
    }

    /**
     * Check if the request has a specific permission.
     *
     * @param list<string> $permissions
     */
    public static function hasPermission(array $permissions, string $required): bool
    {
        return in_array($required, $permissions, true) || in_array('admin', $permissions, true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(int $status, array $data): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
        );
    }
}
