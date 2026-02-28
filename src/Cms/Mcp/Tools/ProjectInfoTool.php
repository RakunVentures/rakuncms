<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\ToolInterface;

final class ProjectInfoTool implements ToolInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function name(): string
    {
        return 'project-info';
    }

    public function description(): string
    {
        return 'Get project information: PHP version, composer packages, directory structure, collection and template counts';
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
        $info = [
            'php_version' => PHP_VERSION,
            'project_path' => $this->basePath,
        ];

        $composerFile = $this->basePath . '/composer.json';
        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true);
            $info['project_name'] = $composer['name'] ?? 'unknown';
            $info['require'] = array_keys($composer['require'] ?? []);
            $info['require_dev'] = array_keys($composer['require-dev'] ?? []);
        }

        $lockFile = $this->basePath . '/composer.lock';
        if (file_exists($lockFile)) {
            $lock = json_decode(file_get_contents($lockFile), true);
            $info['installed_packages'] = count($lock['packages'] ?? []);
        }

        // Count content collections
        $contentDir = $this->basePath . '/content';
        if (is_dir($contentDir)) {
            $collections = [];
            $dirs = glob($contentDir . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($dirs as $dir) {
                $name = basename($dir);
                if (!str_starts_with($name, '_')) {
                    $files = glob($dir . '/*.md') ?: [];
                    $collections[$name] = count($files);
                }
            }
            $info['collections'] = $collections;
        }

        // Count templates
        $templatesDir = $this->basePath . '/templates';
        if (is_dir($templatesDir)) {
            $twigFiles = $this->countFiles($templatesDir, '*.twig');
            $info['template_count'] = $twigFiles;
        }

        // Count components
        $componentsDir = $this->basePath . '/src/Components';
        if (is_dir($componentsDir)) {
            $phpFiles = glob($componentsDir . '/*.php') ?: [];
            $info['component_count'] = count($phpFiles);
        }

        // Check for key directories
        $info['directories'] = [];
        foreach (['content', 'templates', 'config', 'public', 'lang', 'cache', 'storage'] as $dir) {
            $info['directories'][$dir] = is_dir($this->basePath . '/' . $dir);
        }

        return $info;
    }

    private function countFiles(string $dir, string $pattern): int
    {
        $count = 0;
        $files = glob($dir . '/' . $pattern) ?: [];
        $count += count($files);

        $subdirs = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($subdirs as $subdir) {
            $count += $this->countFiles($subdir, $pattern);
        }

        return $count;
    }
}
