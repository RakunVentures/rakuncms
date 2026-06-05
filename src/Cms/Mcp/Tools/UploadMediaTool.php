<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\McpException;

final class UploadMediaTool extends AbstractMediaTool
{
    public function name(): string
    {
        return 'upload-media';
    }

    public function description(): string
    {
        return 'Copy a local media file into public/assets after MIME validation';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_path' => ['type' => 'string'],
                'directory' => ['type' => 'string'],
            ],
            'required' => ['source_path'],
        ];
    }

    public function execute(array $arguments): array
    {
        $source = realpath($this->requireString($arguments, 'source_path'));
        if ($source === false || !is_file($source)) {
            throw McpException::invalidParams('source_path must point to an existing local file');
        }

        // Confine the source to the project tree or a temp dir: prevents copying
        // arbitrary server-readable files (e.g. another vhost's private media) into
        // the public web root.
        $baseReal = realpath($this->basePath) ?: $this->basePath;
        $tmpReal  = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (! str_starts_with($source, $baseReal . DIRECTORY_SEPARATOR)
            && ! str_starts_with($source, $tmpReal . DIRECTORY_SEPARATOR)) {
            throw McpException::invalidParams('source_path must be inside the project or a temp directory');
        }

        if ((filesize($source) ?: 0) > 100 * 1024 * 1024) {
            throw McpException::invalidParams('source file exceeds the 100 MB limit');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo !== false ? finfo_file($finfo, $source) : false;
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        if (!is_string($mimeType) || !isset($this->allowedMimeTypes[$mimeType])) {
            throw McpException::invalidParams('MIME type is not allowed');
        }

        $directory = isset($arguments['directory']) && is_string($arguments['directory'])
            ? $arguments['directory']
            : 'uploads';
        $subDir = $this->sanitizeSubDir($directory);
        $targetDir = $this->assetsDir() . '/' . $subDir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $stem = $this->sanitizeStem(pathinfo($source, PATHINFO_FILENAME));
        $ext = $this->allowedMimeTypes[$mimeType][0];
        $filename = $this->uniqueFilename($targetDir, $stem, $ext);
        $targetPath = $targetDir . '/' . $filename;
        if (!copy($source, $targetPath)) {
            throw McpException::invalidParams('Could not copy media file');
        }

        $width = null;
        $height = null;
        if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
            $dims = @getimagesize($targetPath);
            if (is_array($dims)) {
                $width = $dims[0];
                $height = $dims[1];
            }
        }

        $relative = $subDir . '/' . $filename;

        return [
            'ok' => true,
            'data' => [
                'url' => '/assets/' . $relative,
                'path' => 'assets/' . $relative,
                'mime' => $mimeType,
                'size' => filesize($targetPath) ?: 0,
                'width' => $width,
                'height' => $height,
            ],
        ];
    }
}

