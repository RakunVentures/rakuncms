<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

final class ListMediaTool extends AbstractMediaTool
{
    public function name(): string
    {
        return 'list-media';
    }

    public function description(): string
    {
        return 'List files under public/assets with size, modified time, and MIME type hint';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments): array
    {
        $files = [];
        $assetsDir = $this->assetsDir();
        if (is_dir($assetsDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($assetsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $relativePath = str_replace($assetsDir . '/', '', $file->getPathname());
                $files[] = [
                    'path' => 'assets/' . $relativePath,
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                    'type' => $this->mimeFromExtension($file->getExtension()),
                ];
            }
        }

        return ['data' => $files, 'meta' => ['count' => count($files)]];
    }
}

