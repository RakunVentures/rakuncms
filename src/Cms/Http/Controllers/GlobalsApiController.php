<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Yaml\Yaml;

final class GlobalsApiController
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function show(string $name): ResponseInterface
    {
        $file = $this->basePath . '/content/_globals/' . $name . '.yaml';

        if (!file_exists($file)) {
            return $this->json(404, ['error' => 'Global not found']);
        }

        $data = Yaml::parseFile($file);

        return $this->json(200, ['data' => is_array($data) ? $data : []]);
    }

    public function update(ServerRequestInterface $request, string $name): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->json(400, ['error' => 'Invalid JSON body']);
        }

        $globalsDir = $this->basePath . '/content/_globals';
        if (!is_dir($globalsDir)) {
            mkdir($globalsDir, 0755, true);
        }

        $file = $globalsDir . '/' . $name . '.yaml';
        $yaml = Yaml::dump($body, 4, 2);
        file_put_contents($file, $yaml);

        return $this->json(200, ['data' => $body, 'message' => 'Global updated']);
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
