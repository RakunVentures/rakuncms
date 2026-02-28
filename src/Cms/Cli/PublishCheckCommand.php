<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\ScheduleChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'publish:check', description: 'Check and publish scheduled entries')]
final class PublishCheckCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();
        $checker = new ScheduleChecker($basePath);
        $publishable = $checker->findPublishableEntries();

        if (empty($publishable)) {
            $output->writeln('<info>No entries to publish.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Found %d entries to publish:</info>', count($publishable)));
        foreach ($publishable as $entry) {
            $output->writeln(sprintf('  - [%s] %s', $entry['collection'], $entry['title']));
        }

        // Rebuild index to include newly publishable entries
        $indexer = new Indexer($basePath);
        $index = $indexer->rebuild();

        $output->writeln(sprintf('<info>Index rebuilt with %d entries.</info>', $index['meta']['entry_count']));

        // Clear page cache
        $cachePath = $basePath . '/cache/pages';
        if (is_dir($cachePath)) {
            $files = glob($cachePath . '/*.html') ?: [];
            foreach ($files as $file) {
                @unlink($file);
            }
            $output->writeln('<info>Page cache cleared.</info>');
        }

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
