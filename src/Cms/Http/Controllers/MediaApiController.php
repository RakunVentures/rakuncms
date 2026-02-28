<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MediaApiController
{
    private string $basePath;
    private string $assetsDir;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->assetsDir = $basePath . '/public/assets';
    }

    public function list(): ResponseInterface
    {
        $files = [];

        if (is_dir($this->assetsDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->assetsDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $relativePath = str_replace($this->assetsDir . '/', '', $file->getPathname());
                $files[] = [
                    'path' => 'assets/' . $relativePath,
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                    'type' => mime_content_type($file->getPathname()) ?: 'application/octet-stream',
                ];
            }
        }

        return $this->json(200, [
            'data' => $files,
            'meta' => ['count' => count($files)],
        ]);
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json(400, ['error' => 'No file uploaded or upload error']);
        }

        // Determine target directory from body or default
        $body = $request->getParsedBody();
        $subDir = is_array($body) ? ($body['directory'] ?? 'uploads') : 'uploads';
        $subDir = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $subDir) ?? 'uploads';

        $targetDir = $this->assetsDir . '/' . $subDir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Sanitize filename
        $originalName = $file->getClientFilename() ?? 'upload';
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '', $originalName) ?? 'upload';
        if ($safeName === '') {
            $safeName = 'upload_' . time();
        }

        $targetPath = $targetDir . '/' . $safeName;

        // Don't overwrite existing files
        if (file_exists($targetPath)) {
            $ext = pathinfo($safeName, PATHINFO_EXTENSION);
            $base = pathinfo($safeName, PATHINFO_FILENAME);
            $safeName = $base . '_' . time() . ($ext ? '.' . $ext : '');
            $targetPath = $targetDir . '/' . $safeName;
        }

        $file->moveTo($targetPath);

        return $this->json(201, [
            'data' => [
                'path' => 'assets/' . $subDir . '/' . $safeName,
                'size' => filesize($targetPath),
                'url' => '/assets/' . $subDir . '/' . $safeName,
            ],
            'message' => 'File uploaded',
        ]);
    }

    public function delete(string $mediaPath): ResponseInterface
    {
        // Prevent directory traversal
        $realBase = realpath($this->basePath . '/public') ?: '';
        $targetPath = $this->basePath . '/public/' . $mediaPath;
        $realTarget = realpath($targetPath);

        if ($realTarget === false || !str_starts_with($realTarget, $realBase)) {
            return $this->json(400, ['error' => 'Invalid path']);
        }

        if (!file_exists($realTarget)) {
            return $this->json(404, ['error' => 'File not found']);
        }

        unlink($realTarget);

        return $this->json(200, ['message' => 'File deleted']);
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
