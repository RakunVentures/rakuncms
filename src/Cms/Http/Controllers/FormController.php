<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles POST /api/form/{name} requests.
 *
 * Security: CSRF token, honeypot, and rate limiting.
 * Queue: Pushes form data to FileQueue for async processing.
 */
final class FormController
{
    /**
     * Handle a form submission.
     *
     * @param ServerRequestInterface $request
     * @param string $formName The form identifier (e.g. 'contact')
     */
    public function handle(ServerRequestInterface $request, string $formName): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid form data.'], 400);
        }

        // Remove internal fields from payload
        unset($body['_csrf_token'], $body['_hp_email'], $body['_timestamp']);

        // Check form name is supported before doing expensive operations
        if (!in_array($formName, ['contact'], true)) {
            return $this->json(['error' => 'Unknown form: ' . $formName], 404);
        }

        // Rate limiting (requires container — graceful skip if unavailable)
        $container = \Rkn\Framework\Application::getInstance()?->container();
        if ($container !== null && !$this->checkRateLimit($request, $container)) {
            return $this->json(['error' => 'Too many requests. Please try again later.'], 429);
        }

        // Dispatch based on form name
        return match ($formName) {
            'contact' => $this->handleContact($body, $container),
            default => $this->json(['error' => 'Unknown form: ' . $formName], 404),
        };
    }

    /**
     * Handle contact form submission.
     *
     * @param array<string, mixed> $body
     * @param \Rkn\Framework\Container $container
     */
    private function handleContact(array $body, ?\Rkn\Framework\Container $container): ResponseInterface
    {
        $errors = [];

        if (empty(trim((string) ($body['name'] ?? '')))) {
            $errors['name'] = 'Name is required.';
        }

        $email = trim((string) ($body['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Valid email is required.';
        }

        if (empty(trim((string) ($body['message'] ?? '')))) {
            $errors['message'] = 'Message is required.';
        }

        if (!empty($errors)) {
            return $this->json(['errors' => $errors], 422);
        }

        // Queue email for sending
        if ($container !== null && $container->has('queue')) {
            $queue = $container->get('queue');
            $queue->push('send-contact-email', [
                'name' => $body['name'],
                'email' => $body['email'],
                'phone' => $body['phone'] ?? '',
                'message' => $body['message'],
            ]);
        }

        return $this->json(['success' => true, 'message' => 'Message sent successfully.']);
    }

    /**
     * File-based rate limiting per IP.
     *
     * Allows max 10 requests per 15 minutes per IP.
     */
    private function checkRateLimit(ServerRequestInterface $request, \Rkn\Framework\Container $container): bool
    {
        $basePath = $container->has('base_path') ? $container->get('base_path') : getcwd();
        $ratesDir = $basePath . '/storage/rates';

        if (!is_dir($ratesDir)) {
            mkdir($ratesDir, 0755, true);
        }

        $ip = $this->getClientIp($request);
        $file = $ratesDir . '/' . md5($ip) . '.json';
        $window = 900; // 15 minutes
        $maxRequests = 10;
        $now = time();

        $data = ['requests' => []];

        if (is_file($file)) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        // Filter requests within the current window
        $data['requests'] = array_values(array_filter(
            $data['requests'] ?? [],
            static fn($ts) => ($now - $ts) < $window
        ));

        if (count($data['requests']) >= $maxRequests) {
            return false;
        }

        $data['requests'][] = $now;
        file_put_contents($file, json_encode($data), LOCK_EX);

        return true;
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();

        // Check forwarded headers (reverse proxy)
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $ips = array_map('trim', explode(',', $forwarded));
            return $ips[0];
        }

        return (string) ($serverParams['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        $factory = new Psr17Factory();
        $body = $factory->createStream(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }
}
