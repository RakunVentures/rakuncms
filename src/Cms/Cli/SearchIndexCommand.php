<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Search\SearchIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'search:index', description: 'Build or rebuild the search index')]
final class SearchIndexCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();
        $indexer = new SearchIndexer($basePath);

        $output->writeln('<info>Building search index...</info>');

        $index = $indexer->build();
        $entryCount = count($index['entries']);
        $wordCount = count($index['inverted']);

        $output->writeln(sprintf('  Indexed %d entries, %d unique words', $entryCount, $wordCount));

        // Export JSON for client-side search
        $jsonPath = $basePath . '/public/search-index.json';
        $dir = dirname($jsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($jsonPath, $indexer->exportJson());
        $output->writeln('  Exported search-index.json for client-side search');

        $output->writeln('<info>Search index built successfully.</info>');

        return Command::SUCCESS;
    }

    private function findBasePath(): string
    {
        try {
            $app = \Rkn\Framework\Application::getInstance();
            if ($app !== null) {
                return $app->getBasePath();
            }
        } catch (\Throwable) {
        }

        return getcwd() ?: dirname(__DIR__, 3);
    }
}
