<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF protection using HMAC-based temporal tokens (no sessions needed).
 */
final class CsrfProtection implements MiddlewareInterface
{
    private string $secret;
    private int $tokenLifetime;

    public function __construct(string $secret, int $tokenLifetime = 3600)
    {
        $this->secret = $secret;
        $this->tokenLifetime = $tokenLifetime;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        
        // Skip API routes and Yoyo
        if (str_starts_with($path, '/yoyo') || str_starts_with($path, '/api/v1/') || str_starts_with($path, '/wp-json/')) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->forbidden('Invalid request body.');
        }

        if (!empty($body['_hp_email'])) {
            return $this->forbidden('Request rejected.');
        }

        $token = $body['_csrf_token'] ?? '';
        if ($token === '' || !$this->validateToken((string) $token)) {
            return $this->forbidden('Invalid or expired CSRF token.');
        }

        $timestamp = $this->extractTimestamp((string) $token);
        if ($timestamp !== null && (time() - $timestamp) < 3) {
            return $this->forbidden('Request submitted too quickly.');
        }

        return $handler->handle($request);
    }

    public function generateToken(): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', (string) $timestamp, $this->secret);
        return $timestamp . '.' . $signature;
    }

    public function validateToken(string $token): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return false;
        [$timestampStr, $signature] = $parts;
        $timestamp = (int) $timestampStr;
        if ((time() - $timestamp) > $this->tokenLifetime) return false;
        $expected = hash_hmac('sha256', (string) $timestamp, $this->secret);
        return hash_equals($expected, $signature);
    }

    private function extractTimestamp(string $token): ?int
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return null;
        return (int) $parts[0];
    }

    private function forbidden(string $message): ResponseInterface
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(403);
        $body = $factory->createStream(json_encode(['error' => $message]));
        return $response->withHeader('Content-Type', 'application/json')->withBody($body);
    }
}
