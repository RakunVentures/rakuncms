<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Cli\CacheClearCommand;
use Rkn\Cms\Cli\CacheWarmupCommand;
use Rkn\Cms\Cli\IndexRebuildCommand;
use Rkn\Cms\Cli\QueueProcessCommand;
use Rkn\Cms\Cli\SitemapGenerateCommand;
use Rkn\Cms\Cli\TemplateWarmupCommand;
use Rkn\Cms\Mcp\McpException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class RunCommandTool extends AbstractAdminTool
{
    /** @var array<string, class-string<Command>> */
    private const ALLOWED = [
        'cache:clear' => CacheClearCommand::class,
        'cache:warmup' => CacheWarmupCommand::class,
        'templates:warmup' => TemplateWarmupCommand::class,
        'index:rebuild' => IndexRebuildCommand::class,
        'sitemap:generate' => SitemapGenerateCommand::class,
        'queue:process' => QueueProcessCommand::class,
    ];

    public function name(): string
    {
        return 'run-command';
    }

    public function description(): string
    {
        return 'Run an allowlisted RakunCMS maintenance command without user-controlled arguments';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'enum' => array_keys(self::ALLOWED),
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $arguments): array
    {
        $command = $this->requireString($arguments, 'command');
        if (!isset(self::ALLOWED[$command])) {
            throw McpException::invalidParams("Command '{$command}' is not available");
        }

        $class = self::ALLOWED[$command];
        $result = $this->runConsole(new $class(), ['command' => $command]);

        return [
            'ok' => $result['exit'] === 0,
            'command' => $command,
            'exit_code' => $result['exit'],
            'output' => $result['output'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{exit: int, output: string}
     */
    private function runConsole(Command $command, array $input): array
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $previousCwd = getcwd();
        if (is_string($previousCwd)) {
            chdir($this->basePath);
        }

        $output = new BufferedOutput();
        $exitCode = 1;

        try {
            $app = new Application('RakunCMS');
            $app->setAutoExit(false);
            $app->setCatchExceptions(false);
            $app->add($command);
            $exitCode = $app->run(new ArrayInput($input), $output);
        } catch (\Throwable $e) {
            $output->writeln('Error: ' . $e->getMessage());
            $exitCode = 1;
        } finally {
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }
        }

        return ['exit' => $exitCode, 'output' => trim($this->stripAnsi($output->fetch()))];
    }

    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $text);
    }
}

