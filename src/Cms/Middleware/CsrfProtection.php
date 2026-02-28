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
 *
 * Security layers:
 * 1. HMAC token — signed with secret + timestamp, configurable validity
 * 2. Honeypot field — invisible CSS field, bots fill it
 * 3. Temporal check — reject submissions < 3 seconds after page load
 */
final class CsrfProtection implements MiddlewareInterface
{
    private string $secret;
    private int $tokenLifetime;

    /**
     * @param string $secret HMAC signing secret
     * @param int $tokenLifetime Token validity in seconds (default 3600 = 1 hour)
     */
    public function __construct(string $secret, int $tokenLifetime = 3600)
    {
        $this->secret = $secret;
        $this->tokenLifetime = $tokenLifetime;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only protect state-changing methods
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $handler->handle($request);
        }

        // Skip Yoyo requests (Yoyo has its own protection)
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/yoyo')) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->forbidden('Invalid request body.');
        }

        // Layer 2: Honeypot — if the hidden field is filled, it's a bot
        if (!empty($body['_hp_email'])) {
            // Silently reject — bots don't need error messages
            return $this->forbidden('Request rejected.');
        }

        // Layer 1: CSRF token validation
        $token = $body['_csrf_token'] ?? '';
        if ($token === '' || !$this->validateToken((string) $token)) {
            return $this->forbidden('Invalid or expired CSRF token.');
        }

        // Layer 3: Temporal check — reject if submitted too fast (< 3 seconds)
        $timestamp = $this->extractTimestamp((string) $token);
        if ($timestamp !== null && (time() - $timestamp) < 3) {
            return $this->forbidden('Request submitted too quickly.');
        }

        return $handler->handle($request);
    }

    /**
     * Generate a CSRF token for embedding in forms.
     */
    public function generateToken(): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', (string) $timestamp, $this->secret);

        return $timestamp . '.' . $signature;
    }

    /**
     * Validate a CSRF token.
     */
    public function validateToken(string $token): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$timestampStr, $signature] = $parts;
        $timestamp = (int) $timestampStr;

        // Check expiration
        if ((time() - $timestamp) > $this->tokenLifetime) {
            return false;
        }

        // Verify signature
        $expected = hash_hmac('sha256', (string) $timestamp, $this->secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Extract the timestamp from a token.
     */
    private function extractTimestamp(string $token): ?int
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        return (int) $parts[0];
    }

    private function forbidden(string $message): ResponseInterface
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(403);
        $body = $factory->createStream(json_encode([
            'error' => $message,
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }
}
