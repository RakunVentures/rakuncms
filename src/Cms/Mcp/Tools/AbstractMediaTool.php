<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

abstract class AbstractMediaTool extends AbstractAdminTool
{
    /** @var array<string, list<string>> */
    protected array $allowedMimeTypes = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        // SVG intentionally excluded: served same-origin it can execute JS (stored XSS).
        'application/pdf' => ['pdf'],
        'video/mp4' => ['mp4'],
        'video/quicktime' => ['mov'],
        'video/webm' => ['webm'],
    ];

    protected function assetsDir(): string
    {
        return $this->basePath . '/public/assets';
    }

    protected function sanitizeSubDir(string $dir): string
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

    protected function sanitizeStem(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
        $name = trim($name, '-');

        return $name === '' ? 'file' : substr($name, 0, 80);
    }

    protected function uniqueFilename(string $dir, string $stem, string $ext): string
    {
        $candidate = "{$stem}.{$ext}";
        $i = 1;
        while (file_exists($dir . '/' . $candidate)) {
            $candidate = "{$stem}-{$i}.{$ext}";
            $i++;
        }

        return $candidate;
    }

    protected function mimeFromExtension(string $ext): string
    {
        static $map = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'txt' => 'text/plain',
            'json' => 'application/json',
            'css' => 'text/css',
            'js' => 'text/javascript',
        ];

        return $map[strtolower($ext)] ?? 'application/octet-stream';
    }
}

