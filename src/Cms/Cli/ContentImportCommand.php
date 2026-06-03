<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Content\ContentImporter;
use Rkn\Cms\Content\ContentStorageFactory;
use Rkn\Cms\Content\Storage\FileContentStorage;
use Rkn\Cms\Content\Storage\MysqlContentStorage;
use Rkn\Framework\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'content:import', description: 'Import flat-file .md content into the MySQL store (becomes the SSoT)')]
final class ContentImportCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();
        if (Application::getInstance() === null) {
            new Application($basePath);
        }

        $target = ContentStorageFactory::make($basePath);
        if (!$target instanceof MysqlContentStorage) {
            $output->writeln('<error>content:import requires content.driver=mysql — the database is the import target.</error>');

            return Command::FAILURE;
        }

        $source = new FileContentStorage($basePath, $this->defaultLocale());
        $output->writeln('Importing flat-file content into MySQL (SSoT)...');

        $start = microtime(true);
        $count = (new ContentImporter())->importAll(
            $source,
            $target,
            function ($ref) use ($output): void {
                $output->writeln("  + {$ref->collection}/{$ref->slug} [{$ref->locale}]", OutputInterface::VERBOSITY_VERBOSE);
            },
        );
        $elapsed = round((microtime(true) - $start) * 1000);

        $output->writeln(sprintf('Done! Imported %d entries into MySQL in %dms.', $count, $elapsed));

        return Command::SUCCESS;
    }

    private function defaultLocale(): string
    {
        try {
            $locale = \config('site.default_locale') ?? \config('rakun.site.default_locale');
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        } catch (\Throwable) {
            // not booted
        }

        return 'en';
    }

    private function findBasePath(): string
    {
        try {
            return (string) \app('base_path');
        } catch (\Throwable) {
        }

        return getcwd() ?: dirname(__DIR__, 3);
    }
}
