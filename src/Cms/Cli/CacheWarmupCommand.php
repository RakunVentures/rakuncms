<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:warmup', description: 'Pre-compile templates and pre-cache all pages')]
class CacheWarmupCommand extends Command
{

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = getcwd();
        $cacheDir = $basePath . '/cache';

        // Boot the application so config(), app() and the CMS Twig extensions
        // (t, collection, global, asset, seo_*, csrf, yoyo, …) are registered.
        // Without this, templates using those functions fail to compile.
        if (\Rkn\Framework\Application::getInstance() === null) {
            new \Rkn\Framework\Application($basePath);
        }

        // Step 1: Rebuild content index
        $output->writeln('<info>Rebuilding content index...</info>');
        $indexer = new \Rkn\Cms\Content\Indexer($basePath);
        $index = $indexer->rebuild();
        $output->writeln(sprintf('  Indexed %d entries.', count($index['entries'])));

        // Step 2: Warmup Twig templates
        $output->writeln('<info>Pre-compiling Twig templates...</info>');
        $templateDir = $basePath . '/templates';
        $twigCacheDir = $cacheDir . '/templates';
        if (!is_dir($twigCacheDir)) {
            mkdir($twigCacheDir, 0775, true);
        }

        $twig = \Rkn\Cms\Template\Engine::create($basePath)->twig();

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'twig') {
                $relativePath = str_replace($templateDir . '/', '', $file->getPathname());
                try {
                    $twig->load($relativePath);
                    $count++;
                } catch (\Throwable $e) {
                    $output->writeln(sprintf('  <error>Error compiling %s: %s</error>', $relativePath, $e->getMessage()));
                }
            }
        }
        $output->writeln(sprintf('  Compiled %d templates.', $count));

        $output->writeln('<info>Cache warmup complete.</info>');

        return Command::SUCCESS;
    }
}
