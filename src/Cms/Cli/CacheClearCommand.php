<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:clear', description: 'Clear all cache levels (templates, pages, content index)')]
class CacheClearCommand extends Command
{

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = getcwd();
        $cacheDir = $basePath . '/cache';
        $cleared = [];

        // L1: Twig compiled templates
        $twigDir = $cacheDir . '/templates';
        if (is_dir($twigDir)) {
            $this->deleteDirectory($twigDir, false);
            $cleared[] = 'Twig templates';
        }

        // L2: Content index
        $indexFile = $cacheDir . '/content-index.php';
        if (is_file($indexFile)) {
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($indexFile, true);
            }
            unlink($indexFile);
            $cleared[] = 'Content index';
        }

        // L3: Page HTML cache
        $pagesDir = $cacheDir . '/pages';
        if (is_dir($pagesDir)) {
            $this->deleteDirectory($pagesDir, false);
            $cleared[] = 'Page HTML cache';
        }

        // General PSR-16 cache
        $dataDir = $cacheDir . '/data';
        if (is_dir($dataDir)) {
            $this->deleteDirectory($dataDir, false);
            $cleared[] = 'Data cache';
        }

        // Dependency tracking
        $trackingFile = $cacheDir . '/dependencies.php';
        if (is_file($trackingFile)) {
            unlink($trackingFile);
            $cleared[] = 'Dependency tracking';
        }

        if (empty($cleared)) {
            $output->writeln('<info>Cache is already clean.</info>');
        } else {
            foreach ($cleared as $item) {
                $output->writeln("<info>Cleared: {$item}</info>");
            }
        }

        return Command::SUCCESS;
    }

    private function deleteDirectory(string $dir, bool $removeSelf = true): void
    {
        if (!is_dir($dir)) return;
        $items = new \DirectoryIterator($dir);
        foreach ($items as $item) {
            if ($item->isDot()) continue;
            if ($item->isDir()) {
                $this->deleteDirectory($item->getPathname(), true);
            } else {
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($item->getPathname(), true);
                }
                unlink($item->getPathname());
            }
        }
        if ($removeSelf) {
            rmdir($dir);
        }
    }
}
