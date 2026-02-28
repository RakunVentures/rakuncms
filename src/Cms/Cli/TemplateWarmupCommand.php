<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'templates:warmup', description: 'Pre-compile all Twig templates')]
class TemplateWarmupCommand extends Command
{

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = getcwd();
        $templateDir = $basePath . '/templates';
        $cacheDir = $basePath . '/cache/templates';

        if (!is_dir($templateDir)) {
            $output->writeln('<error>Templates directory not found.</error>');
            return Command::FAILURE;
        }

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $twig = new \Twig\Environment(
            new \Twig\Loader\FilesystemLoader($templateDir),
            ['cache' => $cacheDir, 'auto_reload' => false]
        );

        $count = 0;
        $errors = 0;
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
                    $output->writeln(sprintf('<error>  Error: %s — %s</error>', $relativePath, $e->getMessage()));
                    $errors++;
                }
            }
        }

        $output->writeln(sprintf('<info>Compiled %d templates (%d errors).</info>', $count, $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
