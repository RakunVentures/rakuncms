<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Content\Indexer;
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
        $indexer = new Indexer($basePath);

        $start = microtime(true);
        $index = $indexer->rebuild();
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
        // Try to get from app container
        try {
            return \app('base_path');
        } catch (\Throwable) {
        }

        // Fallback: assume we're in the project root
        return getcwd() ?: dirname(__DIR__, 3);
    }
}
