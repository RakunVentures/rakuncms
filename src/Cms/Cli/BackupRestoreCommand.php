<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Content\Indexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:restore', description: 'Restore a site from a backup ZIP')]
final class BackupRestoreCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to the backup ZIP file');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backupFile = $input->getArgument('file');
        $basePath = $this->findBasePath();

        if (!file_exists($backupFile)) {
            $output->writeln('<error>Backup file not found: ' . $backupFile . '</error>');
            return Command::FAILURE;
        }

        $zip = new \ZipArchive();
        if ($zip->open($backupFile) !== true) {
            $output->writeln('<error>Failed to open ZIP file: ' . $backupFile . '</error>');
            return Command::FAILURE;
        }

        // Check manifest
        $manifestJson = $zip->getFromName('manifest.json');
        if ($manifestJson !== false) {
            $manifest = json_decode($manifestJson, true);
            if (is_array($manifest)) {
                $output->writeln(sprintf(
                    '  Backup info: %s, created %s, %d files',
                    $manifest['cms'] ?? 'Unknown',
                    $manifest['created_at'] ?? 'unknown',
                    $manifest['file_count'] ?? 0
                ));
            }
        }

        if (!$input->getOption('force')) {
            $output->writeln('<comment>This will overwrite existing files. Use --force to skip this warning.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>Restoring backup...</info>');

        $restored = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $name === 'manifest.json') {
                continue;
            }

            // Security: prevent directory traversal
            if (str_contains($name, '..')) {
                continue;
            }

            $targetPath = $basePath . '/' . $name;
            $targetDir = dirname($targetPath);

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                file_put_contents($targetPath, $content);
                $restored++;
            }
        }

        $zip->close();

        $output->writeln(sprintf('  Restored %d files', $restored));

        // Rebuild index
        $indexer = new Indexer($basePath);
        $indexer->rebuild();
        $output->writeln('  Index rebuilt');

        $output->writeln('<info>Restore complete.</info>');

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
