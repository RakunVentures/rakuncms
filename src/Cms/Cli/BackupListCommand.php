<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:list', description: 'List available backups')]
final class BackupListCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('cleanup', null, InputOption::VALUE_REQUIRED, 'Keep only the N most recent backups');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();
        $backupDir = $basePath . '/storage/backups';

        if (!is_dir($backupDir)) {
            $output->writeln('<comment>No backups directory found.</comment>');
            return Command::SUCCESS;
        }

        $files = glob($backupDir . '/*.zip') ?: [];
        if (empty($files)) {
            $output->writeln('<comment>No backups found.</comment>');
            return Command::SUCCESS;
        }

        // Sort by modification time (newest first)
        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        $output->writeln(sprintf('<info>Found %d backups:</info>', count($files)));
        foreach ($files as $file) {
            $size = filesize($file);
            $sizeStr = $size > 1048576
                ? round($size / 1048576, 1) . 'MB'
                : round($size / 1024, 1) . 'KB';
            $date = date('Y-m-d H:i:s', filemtime($file) ?: 0);

            $output->writeln(sprintf('  %s  %s  %s', $date, $sizeStr, basename($file)));
        }

        // Cleanup old backups
        $cleanupCount = $input->getOption('cleanup');
        if ($cleanupCount !== null) {
            $keep = max(1, (int) $cleanupCount);
            if (count($files) > $keep) {
                $toDelete = array_slice($files, $keep);
                foreach ($toDelete as $file) {
                    unlink($file);
                    $output->writeln('  Deleted: ' . basename($file));
                }
                $output->writeln(sprintf('<info>Cleaned up %d old backups, kept %d.</info>', count($toDelete), $keep));
            }
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
