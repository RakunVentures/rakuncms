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

#[AsCommand(name: 'content:rebuild-cache', description: 'Regenerate the .md cache from the MySQL store (SSoT)')]
final class ContentRebuildCacheCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();
        if (Application::getInstance() === null) {
            new Application($basePath);
        }

        $source = ContentStorageFactory::make($basePath);
        if (!$source instanceof MysqlContentStorage) {
            $output->writeln('<error>content:rebuild-cache requires content.driver=mysql — the database is the source of truth.</error>');

            return Command::FAILURE;
        }

        $target = new FileContentStorage($basePath, $this->defaultLocale());
        $output->writeln('Regenerating the .md cache from MySQL...');

        $start = microtime(true);
        $count = (new ContentImporter())->importAll($source, $target);
        $elapsed = round((microtime(true) - $start) * 1000);

        $output->writeln(sprintf('Done! Regenerated %d cache files in %dms. Run index:rebuild to refresh the query index.', $count, $elapsed));

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
