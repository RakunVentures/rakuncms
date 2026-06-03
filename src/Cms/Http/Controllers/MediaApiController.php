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

    /** @var array<string, list<string>> */
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

        // Safe subdirectory (no traversal) and safe filename. The extension is
        // derived from the VERIFIED MIME type, not the client-supplied name.
        $body   = $request->getParsedBody();
        $subDir = is_array($body) && isset($body['directory']) ? (string) $body['directory'] : 'uploads';
        $subDir = $this->sanitizeSubDir($subDir);

        $originalName = $file->getClientFilename() ?? 'upload';
        $stem = $this->sanitizeStem(pathinfo($originalName, PATHINFO_FILENAME));
        $ext  = $this->allowedMimeTypes[$mimeType][0];

        $targetDir = $this->assetsDir . '/' . $subDir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Never silently overwrite an existing asset.
        $filename   = $this->uniqueFilename($targetDir, $stem, $ext);
        $targetPath = $targetDir . '/' . $filename;
        rename($tempPath, $targetPath);

        $width  = null;
        $height = null;
        if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
            $dims = @getimagesize($targetPath);
            if (is_array($dims)) {
                $width  = $dims[0];
                $height = $dims[1];
            }
        }

        $relative = $subDir . '/' . $filename;

        return $this->json(201, [
            'data' => [
                'url'    => '/assets/' . $relative,
                'path'   => 'assets/' . $relative,
                'mime'   => $mimeType,
                'size'   => filesize($targetPath) ?: 0,
                'width'  => $width,
                'height' => $height,
            ],
            'message' => 'File uploaded',
        ]);
    }

    private function sanitizeSubDir(string $dir): string
    {
        $parts = [];
        foreach (explode('/', $dir) as $segment) {
            $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $segment) ?? '';
            if ($clean !== '' && $clean !== '.' && $clean !== '..') {
                $parts[] = $clean;
            }
        }

        return $parts === [] ? 'uploads' : implode('/', $parts);
    }

    private function sanitizeStem(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
        $name = trim($name, '-');

        return $name === '' ? 'file' : substr($name, 0, 80);
    }

    private function uniqueFilename(string $dir, string $stem, string $ext): string
    {
        $candidate = "{$stem}.{$ext}";
        $i = 1;
        while (file_exists($dir . '/' . $candidate)) {
            $candidate = "{$stem}-{$i}.{$ext}";
            $i++;
        }

        return $candidate;
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(int $status, array $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data) ?: '{}');
    }
}
