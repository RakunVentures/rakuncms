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

    private array $allowedMimeTypes = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/svg+xml' => ['svg'],
        'application/pdf' => ['pdf'],
        'video/mp4' => ['mp4'],
        'video/quicktime' => ['mov'],
        'video/webm' => ['webm']
    ];

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
                if (!$file->isFile()) continue;
                $relativePath = str_replace($this->assetsDir . '/', '', $file->getPathname());
                $files[] = [
                    'path' => 'assets/' . $relativePath,
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                    'type' => mime_content_type($file->getPathname()) ?: 'application/octet-stream',
                ];
            }
        }
        return $this->json(200, ['data' => $files, 'meta' => ['count' => count($files)]]);
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json(400, ['error' => 'No file uploaded or upload error']);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'rkn_upload');
        file_put_contents($tempPath, (string) $file->getStream());

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tempPath);
        finfo_close($finfo);

        if (!isset($this->allowedMimeTypes[$mimeType])) {
            @unlink($tempPath);
            return $this->json(415, ['error' => "MIME type $mimeType not allowed"]);
        }

        $originalName = $file->getClientFilename() ?? 'upload';
        $body = $request->getParsedBody();
        $subDir = is_array($body) ? ($body['directory'] ?? 'uploads') : 'uploads';
        
        $targetDir = $this->assetsDir . '/' . $subDir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $targetPath = $targetDir . '/' . $originalName;
        rename($tempPath, $targetPath);

        return $this->json(201, [
            'data' => [
                'url' => '/assets/' . $subDir . '/' . $originalName,
                'path' => 'assets/' . $subDir . '/' . $originalName,
            ],
            'message' => 'File uploaded',
        ]);
    }

    public function delete(string $mediaPath): ResponseInterface
    {
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

    private function json(int $status, array $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
    }
}
