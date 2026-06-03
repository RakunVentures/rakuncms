<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\IndexStoreFactory;
use Rkn\Cms\Content\Stores\SqliteIndexStore;
use Rkn\Framework\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'index:rebuild', description: 'Rebuild the content index from filesystem')]
final class IndexRebuildCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Rebuilding content index...');

        $basePath = $this->findBasePath();
        // Boot the app so config('index.driver') resolves (selects php vs sqlite).
        if (Application::getInstance() === null) {
            new Application($basePath);
        }

        $start = microtime(true);
        $store = IndexStoreFactory::make($basePath);

        if ($store instanceof SqliteIndexStore) {
            $report = $store->sync();
            $elapsed = round((microtime(true) - $start) * 1000);
            $output->writeln(sprintf(
                'Done (sqlite)! scanned %d, inserted %d, updated %d, deleted %d in %dms.',
                $report['scanned'],
                $report['inserted'],
                $report['updated'],
                $report['deleted'],
                $elapsed
            ));
            return Command::SUCCESS;
        }

        // PHP-array driver.
        $index = (new Indexer($basePath))->rebuild();
        $elapsed = round((microtime(true) - $start) * 1000);
        $entryCount = $index['meta']['entry_count'] ?? 0;
        $collections = $index['meta']['collections'] ?? [];

        $output->writeln(sprintf(
            'Done! Indexed %d entries across %d collections in %dms.',
            $entryCount,
            count($collections),
            $elapsed
        ));
        if (!empty($collections)) {
            $output->writeln('Collections: ' . implode(', ', $collections));
        }

        return Command::SUCCESS;
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
