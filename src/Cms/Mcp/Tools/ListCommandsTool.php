<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\ToolInterface;

final class ListCommandsTool implements ToolInterface
{
    public function name(): string
    {
        return 'list-commands';
    }

    public function description(): string
    {
        return 'List all available RakunCMS CLI commands with descriptions';
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
        return [
            'commands' => [
                ['name' => 'init', 'description' => 'Scaffold a new RakunCMS project with directories, config, and templates'],
                ['name' => 'serve', 'description' => 'Start the PHP built-in development server'],
                ['name' => 'index:rebuild', 'description' => 'Rebuild the content index from filesystem'],
                ['name' => 'cache:clear', 'description' => 'Delete all cache files (pages, templates, content index)'],
                ['name' => 'cache:warmup', 'description' => 'Pre-cache common pages for faster first load'],
                ['name' => 'template:warmup', 'description' => 'Pre-compile Twig templates to PHP cache'],
                ['name' => 'queue:process', 'description' => 'Process pending async jobs (emails, etc.)'],
                ['name' => 'make:component', 'description' => 'Generate a new Yoyo reactive component with template'],
                ['name' => 'make:collection', 'description' => 'Generate a new content collection scaffold'],
                ['name' => 'email:publish', 'description' => 'Export or preview email templates'],
                ['name' => 'sitemap:generate', 'description' => 'Build XML sitemap from content index'],
                ['name' => 'build', 'description' => 'Static site build: render all pages to HTML files'],
                ['name' => 'mcp:serve', 'description' => 'Start the MCP stdio server for AI assistance'],
                ['name' => 'boost:install', 'description' => 'Generate CLAUDE.md and .mcp.json for AI integration'],
            ],
        ];
    }
}
