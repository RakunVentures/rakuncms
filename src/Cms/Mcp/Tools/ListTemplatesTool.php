<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\ToolInterface;

final class ListTemplatesTool implements ToolInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function name(): string
    {
        return 'list-templates';
    }

    public function description(): string
    {
        return 'List all Twig templates with hierarchy (extends/includes relationships)';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function execute(array $arguments): array
    {
        $templatesDir = $this->basePath . '/templates';
        if (!is_dir($templatesDir)) {
            return ['error' => 'Templates directory not found', 'templates' => []];
        }

        $templates = [];
        $this->scanTemplates($templatesDir, '', $templates);

        return ['templates' => $templates];
    }

    /**
     * @param list<array<string, mixed>> &$templates
     */
    private function scanTemplates(string $dir, string $prefix, array &$templates): void
    {
        $files = glob($dir . '/*.twig') ?: [];
        foreach ($files as $file) {
            $relativePath = $prefix . basename($file);
            $content = file_get_contents($file);
            $template = [
                'path' => $relativePath,
                'size' => filesize($file),
            ];

            // Detect extends
            if (preg_match('/\{%\s*extends\s+["\']([^"\']+)["\']\s*%\}/', $content, $matches)) {
                $template['extends'] = $matches[1];
            }

            // Detect includes
            $includes = [];
            if (preg_match_all('/\{%\s*include\s+["\']([^"\']+)["\']\s*/', $content, $matches)) {
                $includes = array_values(array_unique($matches[1]));
            }
            if (!empty($includes)) {
                $template['includes'] = $includes;
            }

            // Detect blocks
            $blocks = [];
            if (preg_match_all('/\{%\s*block\s+(\w+)\s*%\}/', $content, $matches)) {
                $blocks = array_values(array_unique($matches[1]));
            }
            if (!empty($blocks)) {
                $template['blocks'] = $blocks;
            }

            $templates[] = $template;
        }

        $subdirs = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($subdirs as $subdir) {
            $this->scanTemplates($subdir, $prefix . basename($subdir) . '/', $templates);
        }
    }
}
