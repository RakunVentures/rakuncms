<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Mcp\McpServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:serve', description: 'Start the MCP stdio server for AI assistance')]
final class McpServeCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();

        $server = new McpServer();
        $this->registerAll($server, $basePath);
        $server->run();

        return Command::SUCCESS;
    }

    private function registerAll(McpServer $server, string $basePath): void
    {
        // Tools
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\ProjectInfoTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\ListCommandsTool());
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\GetConfigTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\ListCollectionsTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\ListEntriesTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\ReadEntryTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\SearchContentTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\CreateEntryTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\UpdateEntryTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\DeleteEntryTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\PublishEntryTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\ScheduleEntryTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\ListTemplatesTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\ReadTemplateTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\ListComponentsTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\ListMediaTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\UploadMediaTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\DeleteMediaTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\RunCommandTool($basePath));
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\BoostArchetypesTool());
        $server->registerTool(new \Rkn\Cms\Mcp\Tools\BoostApplyTool($basePath));

        // Resources
        $server->registerResource(new \Rkn\Cms\Mcp\Resources\GuidelinesResource($basePath));
        $server->registerResource(new \Rkn\Cms\Mcp\Resources\ArchitectureResource($basePath));
        $server->registerResource(new \Rkn\Cms\Mcp\Resources\ConfigResource($basePath));
        $server->registerResource(new \Rkn\Cms\Mcp\Resources\SiteInfoResource($basePath));
        $server->registerResource(new \Rkn\Cms\Mcp\Resources\CollectionsResource($basePath));
        $server->registerResource(new \Rkn\Cms\Mcp\Resources\CommandsResource());

        // Prompts
        $server->registerPrompt(new \Rkn\Cms\Mcp\Prompts\CreateEntryPrompt($basePath));
        $server->registerPrompt(new \Rkn\Cms\Mcp\Prompts\CreateCollectionPrompt());
        $server->registerPrompt(new \Rkn\Cms\Mcp\Prompts\CreateComponentPrompt());
        $server->registerPrompt(new \Rkn\Cms\Mcp\Prompts\BoostWizardPrompt());
    }

    private function findBasePath(): string
    {
        try {
            return \app('base_path');
        } catch (\Throwable) {
        }

        return getcwd() ?: dirname(__DIR__, 3);
    }
}
